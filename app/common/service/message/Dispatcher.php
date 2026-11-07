<?php

namespace app\common\service\message;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use app\common\model\MessageRecord;
use app\common\model\MessageSuppression;
use app\common\model\MessageSubscription;
use app\common\model\MessageTask;
use app\common\service\message\channel\ChannelResult;

/**
 * 分发执行器
 *
 * 队列粒度：每批（默认 500 用户）一个 Message job，由任务 target_payload 里的游标滚动推进。
 * 计数一律原子累加；批次执行前重新读取任务状态，已取消直接丢弃（检查点制）。
 */
class Dispatcher
{
    /** @var ChannelManager */
    protected $channels;

    /** @var TemplateResolver */
    protected $resolver;

    /** @var SuppressionGuard */
    protected $guard;

    /** @var AudienceResolver */
    protected $audience;

    public function __construct(?ChannelManager $channels = null, ?TemplateResolver $resolver = null, ?SuppressionGuard $guard = null)
    {
        $this->channels = $channels ?: new ChannelManager();
        $this->resolver = $resolver ?: new TemplateResolver();
        $this->guard = $guard ?: new SuppressionGuard();
        $this->audience = new AudienceResolver();
    }

    /**
     * 执行一批任务（同任务进程间互斥）
     *
     * 队列重投、MessageRetry 补偿与在途 job 可能同时处理同一个任务，
     * 没有互斥时两边会读到同一游标各自发送，造成整批重复触达（站内信会真实写入两条消息）。
     *
     * @param int $taskId
     * @return array
     */
    public function runBatch(int $taskId): array
    {
        $lockName = 'muucmf_msg_task_' . $taskId;
        $locked = true;

        try {
            $result = Db::query('SELECT GET_LOCK(?, 0) AS locked', [$lockName]);
            // 只有明确返回 0 才表示其他进程持有锁；查询异常或返回 NULL 时降级放行，
            // 避免锁故障导致任务永久停滞（与 MessageRetry 的店铺锁口径一致）
            $locked = !isset($result[0]['locked']) || (int)$result[0]['locked'] !== 0;
        } catch (\Throwable $e) {
            $locked = true;
        }

        if (!$locked) {
            return ['executed' => 0, 'reason' => 'batch_locked'];
        }

        try {
            return $this->executeBatch($taskId);
        } finally {
            if ($locked) {
                try {
                    Db::query('SELECT RELEASE_LOCK(?)', [$lockName]);
                } catch (\Throwable $e) {
                    // 连接断开时 MySQL 会自动释放命名锁，忽略
                }
            }
        }
    }

    /**
     * 执行一批任务（实际逻辑）
     *
     * @param int $taskId
     * @return array
     */
    protected function executeBatch(int $taskId): array
    {
        $task = Db::name('message_task')->where('id', $taskId)->find();
        if (empty($task)) {
            return ['executed' => 0, 'reason' => 'task_not_found'];
        }
        if (in_array((string)$task['status'], MessageTask::TERMINAL_STATUS, true)) {
            return ['executed' => 0, 'reason' => 'task_terminal'];
        }
        if ($task['status'] === MessageTask::STATUS_SCHEDULED && (int)$task['send_time'] > time()) {
            return ['executed' => 0, 'reason' => 'not_due'];
        }

        // 检查点：批次开始前把任务置为 running
        if ($task['status'] !== MessageTask::STATUS_RUNNING) {
            Db::name('message_task')->where('id', $taskId)->whereIn('status', [
                MessageTask::STATUS_PENDING,
                MessageTask::STATUS_SCHEDULED,
            ])->update([
                'status'      => MessageTask::STATUS_RUNNING,
                'start_time'  => (int)$task['start_time'] ?: time(),
                'update_time' => time(),
            ]);
        }

        $shopid = (int)$task['shopid'];
        $payload = json_decode((string)$task['target_payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $vars = is_array($payload['vars'] ?? null) ? $payload['vars'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $cursor = (int)($payload['cursor'] ?? 0);

        $config = $this->channels->configProvider()->get($shopid);
        $batchSize = (int)($config['batch_size'] ?? 500);

        $uids = $this->audience->fetch($payload, $shopid, $cursor, $batchSize);
        if (empty($uids)) {
            // 目标集合可能因删号/分组调整而收缩：游标直接推到尾部再收尾，
            // 否则 finalize 会因 cursor < user_total 直接返回，任务永久停留 running。
            $endCursor = max($cursor, (int)$task['user_total']);
            $this->advance($taskId, $endCursor, []);
            $this->finalize($taskId, $endCursor);
            return ['executed' => 0, 'reason' => 'no_more_users'];
        }

        try {
            $template = $this->resolver->get($shopid, (string)$task['template_code']);
        } catch (MessageException $e) {
            $batchCount = count($uids);
            $this->markTemplateFailure($task, $uids, $e, $options);
            // 模板缺失/停用属于确定性失败，直接终止整个任务，
            // 避免游标停在中间导致后续批次不再推进、任务永久 running。
            $endCursor = max($cursor + $batchCount, (int)$task['user_total']);
            $this->advance($taskId, $endCursor, [
                'user_done'      => $batchCount,
                'user_failed'    => $batchCount,
                'record_total'   => $batchCount,
                'record_failed'  => $batchCount,
            ]);
            $this->finalize($taskId, $endCursor);
            return ['executed' => $batchCount, 'reason' => $e->getErrorCode()];
        }

        $options['channels'] = $task['channels'] !== '' ? (json_decode((string)$task['channels'], true) ?: []) : ($options['channels'] ?? []);
        $chain = $this->resolver->buildChain($template, $options, $config);
        if (empty($chain)) {
            $chain = ['inbox'];
        }

        // 批量预取：黑名单 / 退订 / 可达性 / 用户级幂等
        $pre = [
            'blacklisted'  => MessageSuppression::activeMap($shopid, $uids),
            'unsubscribed' => MessageLevel::isMarketing((string)$template['level'])
                ? $this->unsubscribedMap($shopid, $uids, (string)$template['app'])
                : [],
        ];
        $available = [];
        foreach (array_unique($chain) as $channel) {
            $available[$channel] = $this->channels->availableBatch($shopid, $uids, $channel);
        }

        $bizKey = (string)($options['biz_key'] ?? '');
        $reached = $bizKey !== '' ? $this->reachedMap($shopid, (string)$task['template_code'], $uids, $bizKey) : [];

        $stats = [
            'users'          => 0,
            'user_success'   => 0,
            'user_failed'    => 0,
            'user_blocked'   => 0,
            'record_total'   => 0,
            'record_success' => 0,
            'record_failed'  => 0,
            'record_blocked' => 0,
        ];

        foreach ($uids as $uid) {
            $uid = (int)$uid;
            $stats['users']++;

            if (isset($reached[$uid])) {
                // 用户级幂等：该 biz_key 已通过任一渠道成功，整条链跳过
                $stats['user_success']++;
                continue;
            }

            try {
                $result = $this->dispatchUser($task, $template, $chain, $available, $uid, $vars, $options, $pre, $config);
            } catch (\Throwable $e) {
                Log::error('[message] dispatch user failed: ' . $e->getMessage(), [
                    'task_id' => $taskId,
                    'uid'     => $uid,
                ]);
                $result = ['user_success' => 0, 'user_failed' => 1, 'user_blocked' => 0, 'records' => 0, 'record_success' => 0, 'record_failed' => 0, 'record_blocked' => 0];
            }

            $stats['user_success'] += $result['user_success'];
            $stats['user_failed'] += $result['user_failed'];
            $stats['user_blocked'] += $result['user_blocked'];
            $stats['record_total'] += $result['records'];
            $stats['record_success'] += $result['record_success'];
            $stats['record_failed'] += $result['record_failed'];
            $stats['record_blocked'] += $result['record_blocked'];
        }

        $nextCursor = $cursor + count($uids);
        $this->advance($taskId, $nextCursor, [
            'user_done'      => $stats['users'],
            'user_success'   => $stats['user_success'],
            'user_failed'    => $stats['user_failed'],
            'user_blocked'   => $stats['user_blocked'],
            'record_total'   => $stats['record_total'],
            'record_success' => $stats['record_success'],
            'record_failed'  => $stats['record_failed'],
            'record_blocked' => $stats['record_blocked'],
        ]);

        if ($nextCursor < (int)$task['user_total']) {
            $this->pushNextBatch($taskId);
        } else {
            $this->finalize($taskId, $nextCursor);
        }

        return [
            'executed' => $stats['users'],
            'cursor'   => $nextCursor,
            'stats'    => $stats,
        ];
    }

    /**
     * 单用户分发
     *
     * @param array $task
     * @param array $template
     * @param array $chain
     * @param array $available
     * @param int   $uid
     * @param array $vars
     * @param array $options
     * @param array $pre
     * @param array $config
     * @return array
     */
    protected function dispatchUser(array $task, array $template, array $chain, array $available, int $uid, array $vars, array $options, array $pre, array $config): array
    {
        $shopid = (int)$task['shopid'];
        $decision = $this->guard->check($shopid, $uid, $template, $chain, $config, [
            'blacklisted'  => isset($pre['blacklisted'][$uid]),
            'unsubscribed' => isset($pre['unsubscribed'][$uid]),
        ]);

        if ($decision['action'] === 'block') {
            $count = $this->writeBlocked($task, $template, $chain, $uid, $decision['reason'], $options);
            return ['user_success' => 0, 'user_failed' => 0, 'user_blocked' => 1, 'records' => $count, 'record_success' => 0, 'record_failed' => 0, 'record_blocked' => $count];
        }

        if ($decision['action'] === 'delay') {
            $result = $this->handleDelay($task, $template, $chain, $available, $uid, $vars, $options, $decision['delay_to'], $pre, $config);
            return $result;
        }

        $stats = ['user_success' => 0, 'user_failed' => 0, 'user_blocked' => 0, 'records' => 0, 'record_success' => 0, 'record_failed' => 0, 'record_blocked' => 0];
        $success = false;

        // 站内信必发：先发站内信，然后继续走外部兜底链（站内信成功不终止链路）
        $forceInbox = !empty($template['always_inbox']) && isset($chain[0]) && $chain[0] === 'inbox';
        $startIndex = 0;
        if ($forceInbox) {
            $startIndex = 1;
            if (!empty($available['inbox'][$uid])) {
                $row = $this->sendOne($task, $template, 'inbox', 0, $uid, $vars, $options);
                $stats['records']++;
                if ($row['result']->success) {
                    $stats['record_success']++;
                    $success = true;
                } else {
                    $stats['record_failed']++;
                }
            }
        }

        foreach ($chain as $index => $channel) {
            if ((int)$index < $startIndex) {
                continue;
            }
            if ($channel !== 'inbox' && empty($available[$channel][$uid])) {
                $reason = $this->channels->configured($shopid, $channel) ? 'no_binding' : 'channel_not_configured';
                if ($this->writeBlockedRecord($task, $template, $channel, (int)$index, $uid, $reason, $options)) {
                    $stats['records']++;
                    $stats['record_blocked']++;
                }
                continue;
            }

            $row = $this->sendOne($task, $template, $channel, (int)$index, $uid, $vars, $options);
            $stats['records']++;
            if ($row['result']->success) {
                $stats['record_success']++;
                $success = true;
                break;
            }
            $stats['record_failed']++;
        }

        if ($success) {
            $stats['user_success'] = 1;
        } else {
            $stats['user_failed'] = 1;
            // 兜底链全挂，回退频控预留
            $this->guard->release($decision['reserved'] ?? []);
        }

        return $stats;
    }

    /**
     * 免打扰顺延处理
     *
     * 站内信不参与免打扰：always_inbox=1 时立即发送站内信；
     * 外部渠道落 delayed 记录，由 MessageRetry 到期重投。
     *
     * @return array
     */
    protected function handleDelay(array $task, array $template, array $chain, array $available, int $uid, array $vars, array $options, int $delayTo, array $pre, array $config): array
    {
        $stats = ['user_success' => 0, 'user_failed' => 0, 'user_blocked' => 0, 'records' => 0, 'record_success' => 0, 'record_failed' => 0, 'record_blocked' => 0];
        $sentInbox = false;

        if (!empty($template['always_inbox']) && in_array('inbox', $chain, true)) {
            $index = array_search('inbox', $chain, true);
            if (!empty($available['inbox'][$uid])) {
                $row = $this->sendOne($task, $template, 'inbox', (int)$index, $uid, $vars, $options);
                $stats['records']++;
                if ($row['result']->success) {
                    $stats['record_success']++;
                    $sentInbox = true;
                } else {
                    $stats['record_failed']++;
                }
            }
        }

        // 只为链首外部渠道落一条 delayed 记录，避免多渠道重复触达
        $external = null;
        $externalIndex = 0;
        foreach ($chain as $index => $channel) {
            if ($channel !== 'inbox') {
                $external = $channel;
                $externalIndex = (int)$index;
                break;
            }
        }

        if ($external !== null && $this->writeDelayed($task, $template, $external, $externalIndex, $uid, $delayTo, $options)) {
            // delayed 既不算成功也不计拦截，避免污染送达率；统计以记录表状态为准
            $stats['records']++;
        }

        // 顺延不计入成功/失败：只有真正发出站内信才算该用户已触达
        if ($sentInbox) {
            $stats['user_success'] = 1;
        }

        return $stats;
    }

    /**
     * 发送单个渠道并落记录
     *
     * @return array{result:ChannelResult,record_id:int}
     */
    protected function sendOne(array $task, array $template, string $channel, int $index, int $uid, array $vars, array $options): array
    {
        $shopid = (int)$task['shopid'];
        $base = $this->recordBase($task, $template, $channel, $index, $uid, $options);
        $context = [
            'shopid'    => $shopid,
            'uid'       => $uid,
            'task_id'   => (int)$task['id'],
            'biz_key'   => (string)($options['biz_key'] ?? ''),
            'is_test'   => (int)($options['is_test'] ?? 0),
            // 验证码等场景按传入手机号发送，未注册/乱填 uid 时仍走真实号码
            'mobile'    => (string)($options['mobile'] ?? ''),
            // 邮箱验证码场景按传入邮箱发送，未注册邮箱不依赖 uid 反查
            'email'     => (string)($options['email'] ?? ''),
            // 历史入口可直接指定 message_type.id（站内信渠道专用）
            'type_id'   => (int)($options['inbox_type_id'] ?? 0),
        ];

        $handler = $this->channels->get($channel);
        if (!$handler) {
            $result = ChannelResult::failure($channel, 'channel_not_configured', '渠道未实现：' . $channel);
            return ['result' => $result, 'record_id' => $this->writeRecord($base, $result, $channel)];
        }

        try {
            $rendered = $this->resolver->render($template['channels'][$channel] ?? [], $vars, $channel, $template);
        } catch (MessageException $e) {
            $result = ChannelResult::failure($channel, $e->getErrorCode(), $e->getMessage(), ['retryable' => false]);
            return ['result' => $result, 'record_id' => $this->writeRecord($base, $result, $channel)];
        }

        if ($channel === 'inbox') {
            // 站内信：message + message_content + message_record 同事务
            try {
                $pair = Db::transaction(function () use ($handler, $rendered, $context, $base, $channel) {
                    $result = $handler->send($rendered, $context);
                    $recordId = $this->writeRecord($base, $result, $channel);
                    return [$result, $recordId];
                });
                return ['result' => $pair[0], 'record_id' => $pair[1]];
            } catch (\Throwable $e) {
                $result = ChannelResult::failure('inbox', 'provider_error', $e->getMessage());
                return ['result' => $result, 'record_id' => $this->writeRecord($base, $result, $channel)];
            }
        }

        try {
            $result = $handler->send($rendered, $context);
        } catch (\Throwable $e) {
            $result = ChannelResult::failure($channel, 'provider_error', $e->getMessage());
        }

        return ['result' => $result, 'record_id' => $this->writeRecord($base, $result, $channel)];
    }

    /**
     * 同步直发单个渠道（验证码等场景）
     *
     * @param int    $shopid
     * @param int    $uid
     * @param string $code
     * @param array  $template
     * @param string $channel
     * @param array  $vars
     * @param array  $options
     * @return array{result:ChannelResult,record_id:int}
     */
    public function sendChannelNow(int $shopid, int $uid, string $code, array $template, string $channel, array $vars, array $options): array
    {
        $task = [
            'id'               => 0,
            'shopid'           => $shopid,
            'template_code'    => $code,
            'template_version' => (int)($template['version'] ?? 0),
        ];

        return $this->sendOne($task, $template, $channel, 0, $uid, $vars, $options);
    }

    /**
     * 重试单条记录（后台单条重试 / MessageRetry）
     *
     * @param int $recordId
     * @param bool $force 后台人工重试（仍需重新过策略）
     * @return array
     */
    public function retryRecord(int $recordId, bool $force = false): array
    {
        $record = Db::name('message_record')->where('id', $recordId)->find();
        if (empty($record)) {
            return ['status' => 'skipped', 'reason' => 'record_not_found'];
        }
        if (in_array((string)$record['status'], [MessageRecord::STATUS_SUCCESS, MessageRecord::STATUS_CANCELED], true)) {
            return ['status' => 'skipped', 'reason' => 'already_terminal'];
        }

        $task = Db::name('message_task')->where('id', (int)$record['task_id'])->find();
        if (empty($task)) {
            return ['status' => 'skipped', 'reason' => 'task_not_found'];
        }
        if ((string)$task['status'] === MessageTask::STATUS_CANCELED) {
            Db::name('message_record')->where('id', $recordId)->update([
                'status'      => MessageRecord::STATUS_CANCELED,
                'error_code'  => 'user_canceled',
                'update_time' => time(),
            ]);
            return ['status' => 'canceled', 'reason' => 'task_canceled'];
        }

        $shopid = (int)$record['shopid'];
        $uid = (int)$record['uid'];
        $bizKey = (string)$record['biz_key'];

        // 用户级成功判定：同 biz_key 任一渠道成功即置终态
        if ($bizKey !== '' && $this->reachedMap($shopid, (string)$record['template_code'], [$uid], $bizKey)) {
            Db::name('message_record')->where('id', $recordId)->update([
                'status'       => MessageRecord::STATUS_BLOCKED,
                'block_reason' => 'already_reached',
                'error_code'   => 'already_reached',
                'update_time'  => time(),
            ]);
            return ['status' => 'blocked', 'reason' => 'already_reached'];
        }

        try {
            $template = $this->resolver->get($shopid, (string)$record['template_code']);
        } catch (MessageException $e) {
            return ['status' => 'failed', 'reason' => $e->getErrorCode()];
        }

        $payload = json_decode((string)$task['target_payload'], true) ?: [];
        $vars = is_array($payload['vars'] ?? null) ? $payload['vars'] : [];
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $options['biz_key'] = $bizKey;
        $config = $this->channels->configProvider()->get($shopid);
        $chain = $this->resolver->buildChain($template, ['channels' => json_decode((string)$task['channels'], true) ?: []], $config);
        if (empty($chain)) {
            $chain = ['inbox'];
        }

        $channel = (string)$record['channel'];
        $index = array_search($channel, $chain, true);
        if ($index === false) {
            // 模板链路已变，从链首重新开始
            $index = 0;
        }

        $pre = [
            'blacklisted'  => MessageSuppression::isActive($shopid, $uid),
            'unsubscribed' => MessageLevel::isMarketing((string)$template['level'])
                ? MessageSubscription::isUnsubscribed($shopid, $uid, (string)$template['app'])
                : false,
        ];
        // 频控只对「同一次发送的后续尝试」豁免：failed 记录是重试，不能再占一次配额；
        // delayed / blocked 当初没有扣减（免打扰在频控之前返回），重投时正常判定并预留。
        $reserveFrequency = (string)$record['status'] !== MessageRecord::STATUS_FAILED;
        $decision = $this->guard->check($shopid, $uid, $template, $chain, $config, $pre, $reserveFrequency);
        if ($decision['action'] === 'block') {
            Db::name('message_record')->where('id', $recordId)->update([
                'status'       => MessageRecord::STATUS_BLOCKED,
                'block_reason' => $decision['reason'],
                'error_code'   => $decision['reason'],
                'update_time'  => time(),
            ]);
            return ['status' => 'blocked', 'reason' => $decision['reason']];
        }
        if ($decision['action'] === 'delay') {
            Db::name('message_record')->where('id', $recordId)->update([
                'status'         => MessageRecord::STATUS_DELAYED,
                'block_reason'   => 'quiet',
                'delay_to'       => $decision['delay_to'],
                'scheduled_time' => $decision['delay_to'],
                'update_time'    => time(),
            ]);
            return ['status' => 'delayed', 'reason' => 'quiet'];
        }

        $available = $this->channels->availableBatch($shopid, [$uid], $channel);
        if ($channel !== 'inbox' && empty($available[$uid])) {
            $reason = $this->channels->configured($shopid, $channel) ? 'no_binding' : 'channel_not_configured';
            Db::name('message_record')->where('id', $recordId)->update([
                'status'       => MessageRecord::STATUS_BLOCKED,
                'block_reason' => $reason,
                'error_code'   => $reason,
                'attempts'     => Db::raw('attempts + 1'),
                'update_time'  => time(),
            ]);
            // 没真正发出，回退已预留的频控次数
            $this->guard->release($decision['reserved'] ?? []);
            return ['status' => 'blocked', 'reason' => $reason];
        }

        $retryMax = max(0, (int)($config['retry_max'] ?? 3));

        // 重试次数已耗尽：不再重复打同一渠道，直接走兜底链（每次耗尽只降级一轮）
        if ($force && (int)$record['attempts'] > $retryMax) {
            $degraded = null;
            foreach ($chain as $nextIndex => $nextChannel) {
                if ($nextIndex <= (int)$index) {
                    continue;
                }
                if ($nextChannel !== 'inbox') {
                    $nextAvailable = $this->channels->availableBatch($shopid, [$uid], $nextChannel);
                    if (empty($nextAvailable[$uid])) {
                        continue;
                    }
                }
                $next = $this->sendOne($task, $template, $nextChannel, $nextIndex, $uid, $vars, $options);
                if ($next['result']->success) {
                    $degraded = ['status' => 'success', 'record_id' => $next['record_id'], 'degraded_from' => $channel];
                    // 记录降级来源，便于追溯是哪条渠道失败后兜底成功的
                    Db::name('message_record')->where('id', $next['record_id'])->update([
                        'from_channel' => $channel,
                    ]);
                    break;
                }
            }
            // 推进 attempts，避免该记录在后续补偿中被反复降级
            Db::name('message_record')->where('id', $recordId)->update([
                'attempts'    => Db::raw('attempts + 1'),
                'update_time' => time(),
            ]);
            if ($degraded === null) {
                $this->guard->release($decision['reserved'] ?? []);
            }
            return $degraded ?? ['status' => 'failed', 'record_id' => $recordId];
        }

        // 同一渠道重试
        $row = $this->sendOne($task, $template, $channel, (int)$index, $uid, $vars, $options);
        if ($row['result']->success) {
            return ['status' => 'success', 'record_id' => $row['record_id']];
        }

        // 本次尝试仍然失败，回退预留的频控次数（下次重试会重新判定）
        $this->guard->release($decision['reserved'] ?? []);
        return ['status' => 'failed', 'record_id' => $row['record_id']];
    }

    /**
     * 任务状态归并（幂等）
     *
     * @param int $taskId
     * @param int $cursor
     * @return void
     */
    public function finalize(int $taskId, int $cursor = 0): void
    {
        $task = Db::name('message_task')->where('id', $taskId)->find();
        if (empty($task)) {
            return;
        }
        if (in_array((string)$task['status'], MessageTask::TERMINAL_STATUS, true)) {
            return;
        }
        if ($cursor < (int)$task['user_total']) {
            return;
        }

        $status = MessageTask::STATUS_FINISHED;
        if ((int)$task['user_failed'] > 0) {
            $status = (int)$task['user_success'] > 0 || (int)$task['user_blocked'] > 0
                ? MessageTask::STATUS_PARTIAL
                : MessageTask::STATUS_FAILED;
        }

        Db::name('message_task')->where('id', $taskId)->whereNotIn('status', MessageTask::TERMINAL_STATUS)->update([
            'status'      => $status,
            'finish_time' => time(),
            'update_time' => time(),
        ]);
    }

    /**
     * 取消任务：任务 + 记录双重生效
     *
     * @param int $taskId
     * @param int $shopid
     * @return bool
     */
    public function cancel(int $taskId, int $shopid): bool
    {
        $task = Db::name('message_task')->where('id', $taskId)->where('shopid', $shopid)->find();
        if (empty($task)) {
            return false;
        }
        if (in_array((string)$task['status'], MessageTask::TERMINAL_STATUS, true)) {
            return false;
        }

        $affected = 0;
        Db::transaction(function () use ($taskId, $shopid, &$affected) {
            // 事务内复核状态：批次任务可能在上面的校验之后已推进到终态
            $affected = Db::name('message_task')
                ->where('id', $taskId)
                ->where('shopid', $shopid)
                ->whereNotIn('status', MessageTask::TERMINAL_STATUS)
                ->update([
                    'status'      => MessageTask::STATUS_CANCELED,
                    'finish_time' => time(),
                    'update_time' => time(),
                ]);
            if ($affected <= 0) {
                return;
            }
            Db::name('message_record')
                ->where('shopid', $shopid)
                ->where('task_id', $taskId)
                ->whereIn('status', [MessageRecord::STATUS_PENDING, MessageRecord::STATUS_DELAYED])
                ->update([
                    'status'      => MessageRecord::STATUS_CANCELED,
                    'error_code'  => 'user_canceled',
                    'update_time' => time(),
                ]);
        });

        return $affected > 0;
    }

    /**
     * 投递下一批
     *
     * @param int $taskId
     * @return void
     */
    protected function pushNextBatch(int $taskId): void
    {
        try {
            $queueName = config('queue.connections.redis.queue') ?: 'default';
            Queue::later(1, '\app\common\queue\Message@handle', ['task_id' => $taskId], $queueName);
        } catch (\Throwable $e) {
            Log::error('[message] push next batch failed: ' . $e->getMessage(), ['task_id' => $taskId]);
        }
    }

    /**
     * 推进游标并原子累加计数
     *
     * @param int   $taskId
     * @param int   $cursor
     * @param array $counters
     * @return void
     */
    protected function advance(int $taskId, int $cursor, array $counters): void
    {
        $task = Db::name('message_task')->where('id', $taskId)->field('target_payload')->find();
        $payload = json_decode((string)($task['target_payload'] ?? ''), true) ?: [];
        $payload['cursor'] = $cursor;

        $update = [
            'target_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'update_time'    => time(),
        ];
        foreach ($counters as $field => $value) {
            if ((int)$value > 0) {
                $update[$field] = Db::raw($field . ' + ' . (int)$value);
            }
        }

        Db::name('message_task')->where('id', $taskId)->update($update);
    }

    /**
     * 记录基础字段
     *
     * @return array
     */
    protected function recordBase(array $task, array $template, string $channel, int $index, int $uid, array $options): array
    {
        $now = time();
        return [
            'shopid'           => (int)$task['shopid'],
            'task_id'          => (int)$task['id'],
            'template_code'    => (string)$task['template_code'],
            'template_version' => (int)($task['template_version'] ?? 0),
            'channel'          => $channel,
            'channel_index'    => $index,
            'uid'              => $uid,
            'biz_key'          => (string)($options['biz_key'] ?? ''),
            'is_test'          => (int)($options['is_test'] ?? 0),
            'create_time'      => $now,
            'update_time'      => $now,
        ];
    }

    /**
     * 写入发送记录（成功/失败），重复键只更新尝试类字段
     *
     * @param array         $base
     * @param ChannelResult $result
     * @param string        $channel
     * @return int
     */
    protected function writeRecord(array $base, ChannelResult $result, string $channel): int
    {
        $data = $base;
        $data['title']          = mb_substr((string)$result->title, 0, 255);
        $data['content_digest'] = mb_substr((string)$result->contentDigest, 0, 255);
        $data['third_msg_id']   = mb_substr((string)$result->thirdMsgId, 0, 128);
        $data['inbox_id']       = (int)$result->inboxId;
        $data['status']         = $result->success ? MessageRecord::STATUS_SUCCESS : MessageRecord::STATUS_FAILED;
        $data['error_code']     = (string)$result->errorCode;
        $data['error_msg']      = mb_substr((string)$result->errorMsg, 0, 500);
        $data['attempts']       = 1;
        $data['send_time']      = time();
        $data['receipt_time']   = time();

        return $this->upsertRecord($data);
    }

    /**
     * 写入 blocked 记录
     *
     * @return int
     */
    protected function writeBlockedRecord(array $task, array $template, string $channel, int $index, int $uid, string $reason, array $options): int
    {
        $data = $this->recordBase($task, $template, $channel, $index, $uid, $options);
        $data['status']       = MessageRecord::STATUS_BLOCKED;
        $data['block_reason'] = $reason;
        $data['error_code']   = $reason;
        $data['attempts']     = 0;

        return $this->upsertRecord($data);
    }

    /**
     * 写入 delayed 记录
     *
     * @return int
     */
    protected function writeDelayed(array $task, array $template, string $channel, int $index, int $uid, int $delayTo, array $options): int
    {
        $data = $this->recordBase($task, $template, $channel, $index, $uid, $options);
        $data['status']         = MessageRecord::STATUS_DELAYED;
        $data['block_reason']   = 'quiet';
        $data['delay_to']       = $delayTo;
        $data['scheduled_time'] = $delayTo;

        return $this->upsertRecord($data);
    }

    /**
     * 整链拦截：每个渠道各落一条 blocked 记录
     *
     * @return int 记录数
     */
    protected function writeBlocked(array $task, array $template, array $chain, int $uid, string $reason, array $options): int
    {
        $count = 0;
        foreach ($chain as $index => $channel) {
            $this->writeBlockedRecord($task, $template, $channel, (int)$index, $uid, $reason, $options);
            $count++;
        }
        return $count;
    }

    /**
     * 记录 upsert：唯一键冲突时只更新尝试类字段，不覆盖 success / canceled
     *
     * @param array $data
     * @return int
     */
    protected function upsertRecord(array $data): int
    {
        try {
            return (int)Db::name('message_record')->insertGetId($data);
        } catch (\Throwable $e) {
            try {
                $row = Db::name('message_record')
                    ->where('shopid', $data['shopid'])
                    ->where('template_code', $data['template_code'])
                    ->where('uid', $data['uid'])
                    ->where('biz_key', $data['biz_key'])
                    ->where('channel', $data['channel'])
                    ->find();
            } catch (\Throwable $inner) {
                Log::error('[message] write record failed: ' . $e->getMessage(), $data);
                return 0;
            }

            if (empty($row)) {
                Log::error('[message] write record failed: ' . $e->getMessage(), $data);
                return 0;
            }

            if (in_array((string)$row['status'], [MessageRecord::STATUS_SUCCESS, MessageRecord::STATUS_CANCELED], true)) {
                return (int)$row['id'];
            }

            $update = [
                'task_id'          => $data['task_id'],
                'template_version' => $data['template_version'],
                'channel_index'    => $data['channel_index'],
                'title'            => $data['title'] ?? ($row['title'] ?? ''),
                'content_digest'   => $data['content_digest'] ?? ($row['content_digest'] ?? ''),
                'third_msg_id'     => $data['third_msg_id'] ?? ($row['third_msg_id'] ?? ''),
                'inbox_id'         => $data['inbox_id'] ?? ($row['inbox_id'] ?? 0),
                'status'           => $data['status'] ?? $row['status'],
                'block_reason'     => $data['block_reason'] ?? '',
                'delay_to'         => $data['delay_to'] ?? 0,
                'scheduled_time'   => $data['scheduled_time'] ?? 0,
                'error_code'       => $data['error_code'] ?? '',
                'error_msg'        => $data['error_msg'] ?? '',
                'attempts'         => Db::raw('attempts + 1'),
                'send_time'        => $data['send_time'] ?? 0,
                'receipt_time'     => $data['receipt_time'] ?? 0,
                'update_time'      => time(),
            ];

            Db::name('message_record')->where('id', (int)$row['id'])->update($update);
            return (int)$row['id'];
        }
    }

    /**
     * 已成功触达的用户（用户级幂等）
     *
     * @param int    $shopid
     * @param string $code
     * @param array  $uids
     * @param string $bizKey
     * @return array [uid => true]
     */
    protected function reachedMap(int $shopid, string $code, array $uids, string $bizKey): array
    {
        if ($bizKey === '' || empty($uids)) {
            return [];
        }
        $rows = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('template_code', $code)
            ->where('biz_key', $bizKey)
            ->where('status', MessageRecord::STATUS_SUCCESS)
            ->where('uid', 'in', $uids)
            ->column('uid');

        $map = [];
        foreach ($rows as $uid) {
            $map[(int)$uid] = true;
        }
        return $map;
    }

    /**
     * 已退订用户映射
     *
     * @param int    $shopid
     * @param array  $uids
     * @param string $app
     * @return array
     */
    protected function unsubscribedMap(int $shopid, array $uids, string $app): array
    {
        if (empty($uids)) {
            return [];
        }
        $rows = Db::name('message_subscription')
            ->where('shopid', $shopid)
            ->where('template_code', '*')
            ->where('channel', '*')
            ->where('subscribed', 0)
            ->where('uid', 'in', $uids)
            ->column('uid');

        $map = [];
        foreach ($rows as $uid) {
            $map[(int)$uid] = true;
        }
        return $map;
    }

    /**
     * 模板不可用时批量落失败记录
     *
     * @param array            $task
     * @param array            $uids
     * @param MessageException $e
     * @param array            $options
     * @return void
     */
    protected function markTemplateFailure(array $task, array $uids, MessageException $e, array $options = []): void
    {
        $channel = 'inbox';
        foreach ($uids as $uid) {
            $data = $this->recordBase($task, [], $channel, 0, (int)$uid, $options);
            $data['status']     = MessageRecord::STATUS_FAILED;
            $data['error_code'] = $e->getErrorCode();
            $data['error_msg']  = mb_substr($e->getMessage(), 0, 500);
            $data['attempts']   = 1;
            $this->upsertRecord($data);
        }
    }
}
