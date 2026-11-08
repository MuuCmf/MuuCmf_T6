<?php

namespace app\common\service\message;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use app\common\model\MessageRecord;
use app\common\model\MessageTask;
use app\common\model\MessageSuppression;

/**
 * 消息中心统一门面
 *
 * 业务方只需传「模板编码 + 用户 + 变量」，渠道由模板配置与用户偏好决定。
 * 服务契约（冻结）：
 *   send(code, targets, vars, options): array{task_id:int, accepted:int, blocked:int}
 *   sendNow(code, uid, vars, options): array
 *   cancel(taskId, shopid): bool
 *   retry(taskId, shopid): int
 */
class MessageCenter
{
    /**
     * 统一发送（异步，落库后由队列按批执行）
     *
     * @param string $code    模板编码
     * @param array  $targets ['type'=>'uids|groups','value'=>mixed]
     * @param array  $vars    模板变量
     * @param array  $options shopid/biz_key/channels/send_time/operator_uid/is_test/remark
     * @return array{task_id:int,accepted:int,blocked:int}
     * @throws MessageException
     */
    public static function send(string $code, array $targets, array $vars = [], array $options = []): array
    {
        $shopid = (int)($options['shopid'] ?? ShopContext::currentShopid());
        ShopContext::assert($shopid);

        $resolver = new TemplateResolver();
        $template = self::resolveTemplate($shopid, $code, $resolver);

        $level = self::resolveLevel($template, $options);
        $options['is_test'] = (int)($options['is_test'] ?? 0);
        $bizKey = self::resolveBizKey($shopid, $code, $level, $options);
        if ($options['is_test'] === 1) {
            // test_ 前缀是在长度校验之后追加的，必须保证最终仍落在 biz_key varchar(64) 内
            $bizKey = 'test_' . $bizKey;
            if (mb_strlen($bizKey) > 64) {
                $bizKey = 'test_' . substr(md5($bizKey), 0, 32);
            }
        }
        $options['biz_key'] = $bizKey;

        $audience = new AudienceResolver();
        $userTotal = $audience->count($targets, $shopid);

        $channels = [];
        if (!empty($options['channels']) && is_array($options['channels'])) {
            $channels = array_values(array_unique(array_filter(array_map('strval', $options['channels']))));
        }

        // 任务落库时即确定最终渠道链（含系统追加的 inbox），便于审计与前端展示
        $manager = new ChannelManager();
        $resolvedChain = $resolver->buildChain($template, ['channels' => $channels], $manager->configProvider()->get($shopid));
        if (empty($resolvedChain)) {
            $resolvedChain = ['inbox'];
        }
        $channels = $resolvedChain;

        $sendTime = (int)($options['send_time'] ?? 0);
        $isImmediate = $sendTime <= 0 || $sendTime <= time();

        $payload = [
            'type'    => (string)($targets['type'] ?? 'uids'),
            'value'   => $targets['value'] ?? [],
            'cursor'  => 0,
            'vars'    => $vars,
            'options' => [
                'channels'      => $channels,
                'is_test'       => (int)$options['is_test'],
                'remark'        => (string)($options['remark'] ?? ''),
                // 幂等键与站内信分类需随任务持久化，队列回读时唯一键才能生效
                'biz_key'       => (string)$options['biz_key'],
                'inbox_type_id' => (int)($options['inbox_type_id'] ?? 0),
            ],
        ];

        $now = time();
        $taskId = Db::name('message_task')->insertGetId([
            'shopid'           => $shopid,
            'template_code'    => $code,
            'template_version' => (int)$template['version'],
            'title'            => (string)($options['title'] ?? $template['name']),
            'level'            => $level,
            'channels'         => json_encode($channels, JSON_UNESCAPED_UNICODE),
            'target_type'      => (string)($payload['type']),
            'target_payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'target_hash'      => $audience->hash($targets),
            'user_total'       => $userTotal,
            'status'           => $isImmediate ? MessageTask::STATUS_PENDING : MessageTask::STATUS_SCHEDULED,
            'send_time'        => $isImmediate ? 0 : $sendTime,
            'operator_uid'     => (int)($options['operator_uid'] ?? ShopContext::currentUid()),
            'remark'           => mb_substr((string)($options['remark'] ?? ''), 0, 255),
            'create_time'      => $now,
            'update_time'      => $now,
        ]);

        if ($userTotal <= 0) {
            // 空目标直接置终态，避免任务永久 pending 被定时任务反复重投
            Db::name('message_task')->where('id', $taskId)->update([
                'status'      => MessageTask::STATUS_FINISHED,
                'finish_time' => $now,
                'update_time' => $now,
            ]);
        } elseif ($isImmediate) {
            self::pushTask((int)$taskId);
        }

        return [
            'task_id'  => (int)$taskId,
            'accepted' => $userTotal,
            'blocked'  => 0,
        ];
    }

    /**
     * 单用户同步直发（验证码等强同步场景）
     *
     * 返回值兼容历史调用方：服务商原始响应既放在 raw，也同时展开到顶层
     * （现网 Verify 调用方按 $res['Message'] == 'OK' 判断）。
     *
     * @param string $code
     * @param int    $uid
     * @param array  $vars
     * @param array  $options
     * @return array
     */
    public static function sendNow(string $code, int $uid, array $vars = [], array $options = []): array
    {
        $shopid = (int)($options['shopid'] ?? ShopContext::currentShopid());
        ShopContext::assert($shopid);

        $resolver = new TemplateResolver();
        $template = self::resolveTemplate($shopid, $code, $resolver);
        $level = self::resolveLevel($template, $options);

        $channels = !empty($options['channels']) && is_array($options['channels'])
            ? array_values(array_unique(array_filter(array_map('strval', $options['channels']))))
            : [];

        if (empty($channels)) {
            $manager = new ChannelManager();
            $chain = $resolver->buildChain($template, [], $manager->configProvider()->get($shopid));
            $channels = [$chain[0] ?? 'sms'];
        }

        $bizKey = (string)($options['biz_key'] ?? '');
        if ($bizKey === '') {
            $bizKey = 'now_' . substr(md5($code . $uid . microtime(true) . mt_rand()), 0, 20);
        }
        if (mb_strlen($bizKey) > 64) {
            throw new MessageException('biz_key_too_long', 'biz_key 长度不能超过 64');
        }
        $options['biz_key'] = $bizKey;

        $dispatcher = new Dispatcher();
        $last = null;

        foreach ($channels as $channel) {
            $row = $dispatcher->sendChannelNow($shopid, $uid, $code, $template, $channel, $vars, $options);
            $last = $row;
            if ($row['result']->success) {
                break;
            }
        }

        if ($last === null) {
            return [
                'success'    => false,
                'channel'    => '',
                'record_id'  => 0,
                'raw'        => [],
                'error_code' => 'channel_not_configured',
                'error_msg'  => '没有可用的发送渠道',
            ];
        }

        $result = $last['result'];
        $out = [
            'success'    => $result->success,
            'channel'    => $result->channel,
            'record_id'  => (int)$last['record_id'],
            'raw'        => $result->raw,
            'error_code' => $result->errorCode,
            'error_msg'  => $result->errorMsg,
            'level'      => $level,
        ];

        // 兼容服务商原始响应（Message / Code 等顶层字段）
        if (is_array($result->raw)) {
            $out = array_merge($result->raw, $out);
        }

        return $out;
    }

    /**
     * 按手机号同步直发（验证码入参是手机号，不强制反查 uid）
     *
     * @param string $code
     * @param string $mobile
     * @param array  $vars
     * @param array  $options
     * @return array
     */
    public static function sendNowToMobile(string $code, string $mobile, array $vars = [], array $options = []): array
    {
        return self::sendNowToAddress('sms', $code, $mobile, $vars, $options);
    }

    /**
     * 按邮箱地址同步直发（验证码入参是邮箱，不强制反查 uid）
     *
     * @param string $code
     * @param string $email
     * @param array  $vars
     * @param array  $options
     * @return array
     */
    public static function sendNowToEmail(string $code, string $email, array $vars = [], array $options = []): array
    {
        return self::sendNowToAddress('email', $code, $email, $vars, $options);
    }

    /**
     * 按联系方式同步直发（手机号/邮箱共用的实现）
     *
     * 已注册目标复用 sendNow（走完整渠道链与发送记录）；
     * 未注册目标直接调用对应渠道，不落用户记录。
     *
     * @param string $channel sms|email
     * @param string $code    模板编码
     * @param string $target  手机号或邮箱
     * @param array  $vars    模板变量
     * @param array  $options shopid/uid/biz_key
     * @return array
     */
    protected static function sendNowToAddress(string $channel, string $code, string $target, array $vars = [], array $options = []): array
    {
        $shopid = (int)($options['shopid'] ?? ShopContext::currentShopid());
        ShopContext::assert($shopid);

        // 邮箱是必填项，手机号沿用历史口径不做空值校验
        $field = $channel === 'email' ? 'email' : 'mobile';
        $target = trim($target);
        if ($field === 'email' && $target === '') {
            return self::directFailure($channel, 'email_empty', '邮箱不能为空');
        }

        $uid = (int)($options['uid'] ?? 0);
        if ($uid <= 0) {
            $uid = (int)Db::name('member')->where($field, $target)->value('uid');
        }
        $options[$field] = $target;

        // 直发每次都是独立一次发送，未指定幂等键时生成唯一值，
        // 否则 uid=0 的多次直发会命中唯一键被合并成同一条记录
        if (empty($options['biz_key'])) {
            $options['biz_key'] = 'now_' . substr(md5($code . $field . $target . microtime(true) . mt_rand()), 0, 20);
        }
        if (mb_strlen((string)$options['biz_key']) > 64) {
            throw new MessageException('biz_key_too_long', 'biz_key 长度不能超过 64');
        }

        $resolver = new TemplateResolver();
        $template = self::resolveTemplate($shopid, $code, $resolver);
        $template = self::ensurePresetDefaults($shopid, $code, $template, $resolver);

        if ($uid > 0) {
            // 邮箱直发默认只走邮件渠道，避免回落到站内信造成「邮件已发」的错觉
            if ($channel === 'email' && empty($options['channels'])) {
                $options['channels'] = ['email'];
            }
            return self::sendNow($code, $uid, $vars, $options);
        }

        // 未注册手机号/邮箱（注册、找回密码、绑定场景）直接走对应渠道，
        // 仍经 Dispatcher 落一条 uid=0 的记录，便于排查「到底发出去了没」
        if ($channel === 'email') {
            $configProvider = (new ChannelManager())->configProvider();
            if (!$configProvider->configured($shopid, 'email') || !$configProvider->enabled($shopid, 'email')) {
                return self::directFailure($channel, 'channel_not_configured', '邮件渠道未启用，请检查系统邮件配置与消息中心渠道开关');
            }
        }

        $row = (new Dispatcher())->sendChannelNow($shopid, 0, $code, $template, $channel, $vars, $options);
        $result = $row['result'];

        $out = [
            'success'    => $result->success,
            'channel'    => $result->channel,
            'record_id'  => (int)$row['record_id'],
            'raw'        => $result->raw,
            'error_code' => $result->errorCode,
            'error_msg'  => $result->errorMsg,
        ];
        if (is_array($result->raw)) {
            $out = array_merge($result->raw, $out);
        }
        return $out;
    }

    /**
     * 直发失败返回结构（未落发送记录）
     *
     * @param string $channel   渠道
     * @param string $errorCode 错误码
     * @param string $errorMsg  错误信息
     * @return array
     */
    protected static function directFailure(string $channel, string $errorCode, string $errorMsg): array
    {
        return [
            'success'    => false,
            'channel'    => $channel,
            'record_id'  => 0,
            'raw'        => [],
            'error_code' => $errorCode,
            'error_msg'  => $errorMsg,
        ];
    }

    /**
     * 存量店铺预置模板缺失关键配置时自动补齐并刷新缓存
     *
     * 仅处理验证码模板：补齐邮件渠道 / 短信服务商模板 ID / 变量声明，
     * 避免升级后验证码链路因模板未配置而整体不可用。
     *
     * @param int              $shopid
     * @param string           $code
     * @param array            $template
     * @param TemplateResolver $resolver
     * @return array
     */
    protected static function ensurePresetDefaults(int $shopid, string $code, array $template, TemplateResolver $resolver): array
    {
        if ($code !== 'common.verify_code') {
            return $template;
        }

        $need = empty($template['channels']['email']['content']);

        if (!$need) {
            $provider = $template['channels']['sms']['extra']['provider_template_id'] ?? [];
            $need = !MessageSeed::hasProviderTemplateId($provider);
        }

        if (!$need) {
            return $template;
        }

        (new MessageSeed())->ensure($shopid);
        $resolver->clearCache($shopid);

        return $resolver->get($shopid, $code, false) ?: $template;
    }

    /**
     * 取消任务
     *
     * @param int $taskId
     * @param int $shopid
     * @return bool
     */
    public static function cancel(int $taskId, int $shopid): bool
    {
        ShopContext::assert($shopid);
        return (new Dispatcher())->cancel($taskId, $shopid);
    }

    /**
     * 重试任务失败项
     *
     * @param int $taskId
     * @param int $shopid
     * @return array{retried:int,skipped:int,failed:int}
     */
    public static function retry(int $taskId, int $shopid): array
    {
        ShopContext::assert($shopid);
        $dispatcher = new Dispatcher();

        $config = (new ChannelConfigProvider())->get($shopid);
        $retryMax = max(0, (int)($config['retry_max'] ?? 3));
        $ids = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('task_id', $taskId)
            ->where('status', MessageRecord::STATUS_FAILED)
            // 超过重试上限的记录只允许一次兜底降级，反复点击后台不能无限触达
            ->where('attempts', '<=', $retryMax + 1)
            ->order('id asc')
            ->limit(2000)
            ->column('id');

        $summary = ['retried' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $result = $dispatcher->retryRecord((int)$id, true);
            if ($result['status'] === 'success') {
                $summary['retried']++;
            } elseif ($result['status'] === 'failed') {
                $summary['failed']++;
            } else {
                $summary['skipped']++;
            }
        }
        return $summary;
    }

    /**
     * 发送前预览（后端同源渲染，前端不得自行拼装文案）
     *
     * @param int    $shopid
     * @param string $code
     * @param array  $vars
     * @param array  $targets
     * @param array  $channels
     * @return array
     * @throws MessageException
     */
    public static function preview(int $shopid, string $code, array $vars = [], array $targets = [], array $channels = []): array
    {
        ShopContext::assert($shopid);

        $resolver = new TemplateResolver();
        $template = self::resolveTemplate($shopid, $code, $resolver);
        $manager = new ChannelManager();
        $config = $manager->configProvider()->get($shopid);

        $chain = $resolver->buildChain($template, ['channels' => $channels], $config);
        $rows = [];

        foreach ($chain as $channel) {
            $configured = $channel === 'inbox' ? true : $manager->configured($shopid, $channel);
            $row = ['channel' => $channel, 'available' => $configured, 'reason' => '', 'render' => ['title' => '', 'content' => '']];
            if (!$configured) {
                $row['reason'] = 'channel_not_configured';
                $rows[] = $row;
                continue;
            }
            try {
                $rendered = $resolver->render($template['channels'][$channel] ?? [], $vars, $channel, $template);
                $row['render'] = ['title' => $rendered['title'], 'content' => $rendered['content']];
            } catch (MessageException $e) {
                $row['available'] = false;
                $row['reason'] = $e->getErrorCode();
                $row['error'] = $e->getMessage();
            }
            $rows[] = $row;
        }

        $estimated = ['users' => 0, 'blocked' => 0, 'breakdown' => ['blacklisted' => 0, 'unsubscribed' => 0, 'quiet' => 0, 'freq' => 0]];
        if (!empty($targets)) {
            $audience = new AudienceResolver();
            $uids = $audience->all($targets, $shopid, 20000);
            $estimated['users'] = count($uids);

            if (!empty($uids)) {
                $blacklisted = count(MessageSuppression::activeMap($shopid, $uids));
                $unsubscribed = MessageLevel::isMarketing((string)$template['level'])
                    ? Db::name('message_subscription')
                        ->where('shopid', $shopid)
                        ->where('template_code', '*')
                        ->where('channel', '*')
                        ->where('subscribed', 0)
                        ->where('uid', 'in', $uids)
                        ->count()
                    : 0;
                $estimated['breakdown']['blacklisted'] = $blacklisted;
                $estimated['breakdown']['unsubscribed'] = $unsubscribed;
                $policyBlocked = $blacklisted + $unsubscribed;

                // 免打扰是全店同一时点判定，命中即剩余用户全部顺延；
                // 未命中时再按窗口内成功次数估算频控拦截人数（只读，不预留配额）
                $isMarketing = MessageLevel::isMarketing((string)$template['level']);
                if ($isMarketing && (new SuppressionGuard())->quietDelayTo($config['quiet'] ?? []) > 0) {
                    $estimated['breakdown']['quiet'] = max(0, count($uids) - $policyBlocked);
                } else {
                    $rule = !empty($template['frequency_rule'])
                        ? $template['frequency_rule']
                        : ($config['frequency'] ?? ['max_times' => 3, 'window_hours' => 24]);
                    $estimated['breakdown']['freq'] = self::estimateFreqLimited($shopid, $uids, $code, $rule);
                }

                $estimated['blocked'] = min(count($uids), $policyBlocked
                    + $estimated['breakdown']['quiet'] + $estimated['breakdown']['freq']);
            }
        }

        return [
            'channels'  => $rows,
            'estimated' => $estimated,
            'level'     => $template['level'],
            'variables' => $template['variables'],
        ];
    }

    /**
     * 解析模板（不存在时按店铺补齐系统模板后重试一次）
     *
     * @param int              $shopid
     * @param string           $code
     * @param TemplateResolver $resolver
     * @return array
     * @throws MessageException
     */
    protected static function resolveTemplate(int $shopid, string $code, TemplateResolver $resolver): array
    {
        try {
            return $resolver->get($shopid, $code);
        } catch (MessageException $e) {
            if ($e->getErrorCode() !== 'template_not_found') {
                throw $e;
            }
            (new MessageSeed())->ensure($shopid);
            $template = $resolver->get($shopid, $code, false);
            if ($template === null) {
                throw $e;
            }
            return $template;
        }
    }

    /**
     * 解析消息级别（level_override 仅平台内部可用）
     *
     * @param array $template
     * @param array $options
     * @return string
     * @throws MessageException
     */
    protected static function resolveLevel(array $template, array $options): string
    {
        $level = (string)($template['level'] ?? MessageLevel::MARKETING);
        if (!empty($options['level_override'])) {
            if (!ShopContext::isPlatformAdmin()) {
                throw new MessageException('template_invalid', '业务方不允许覆盖消息级别');
            }
            $override = (string)$options['level_override'];
            if (!MessageLevel::isValid($override)) {
                throw new MessageException('template_invalid', '非法消息级别：' . $override);
            }
            $level = $override;
        }
        return MessageLevel::isValid($level) ? $level : MessageLevel::MARKETING;
    }

    /**
     * 估算窗口内已达频控上限的用户数（只读，不预留配额）
     *
     * @param int    $shopid
     * @param array  $uids
     * @param string $code
     * @param array  $rule
     * @return int
     */
    protected static function estimateFreqLimited(int $shopid, array $uids, string $code, array $rule): int
    {
        if ($code === '' || empty($uids)) {
            return 0;
        }
        $maxTimes = max(1, (int)($rule['max_times'] ?? 3));
        $window = max(1, (int)($rule['window_hours'] ?? 24)) * 3600;
        try {
            $rows = Db::name('message_record')
                ->where('shopid', $shopid)
                ->where('template_code', $code)
                ->where('status', MessageRecord::STATUS_SUCCESS)
                ->where('is_test', 0)
                ->where('create_time', '>=', time() - $window)
                ->whereIn('uid', $uids)
                ->field('uid, COUNT(*) AS cnt')
                ->group('uid')
                ->having('cnt >= ' . $maxTimes)
                ->select()
                ->toArray();
            return count($rows);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 解析幂等键
     *
     * @param int    $shopid
     * @param string $code
     * @param string $level
     * @param array  $options
     * @return string
     * @throws MessageException
     */
    protected static function resolveBizKey(int $shopid, string $code, string $level, array $options): string
    {
        $bizKey = (string)($options['biz_key'] ?? '');

        if ($bizKey === '') {
            if (MessageLevel::requiresBizKey($level)) {
                throw new MessageException('biz_key_required', '事务消息与安全消息必须传 biz_key');
            }
            return 'auto_' . substr(md5($shopid . $code . microtime(true) . mt_rand()), 0, 24);
        }

        if (mb_strlen($bizKey) > 64) {
            throw new MessageException('biz_key_too_long', 'biz_key 长度不能超过 64');
        }

        return $bizKey;
    }

    /**
     * 投递任务（失败不抛出，交给 MessageRetry 补偿）
     *
     * @param int $taskId
     * @return void
     */
    protected static function pushTask(int $taskId): void
    {
        try {
            $queueName = config('queue.connections.redis.queue') ?: 'default';
            Queue::push('\app\common\queue\Message@handle', ['task_id' => $taskId], $queueName);
        } catch (\Throwable $e) {
            Log::error('[message] push task failed: ' . $e->getMessage(), ['task_id' => $taskId]);
        }
    }
}
