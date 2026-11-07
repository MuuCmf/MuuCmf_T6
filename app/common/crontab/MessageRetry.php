<?php

namespace app\common\crontab;

use think\facade\Db;
use think\facade\Log;
use think\facade\Queue;
use app\common\model\MessageRecord;
use app\common\model\MessageTask;
use app\common\service\message\ChannelConfigProvider;
use app\common\service\message\Dispatcher;

/**
 * 消息重试与补偿（每分钟，多进程安全）
 *
 * 三类兜底：
 * 1. delay 到期后重新投递
 * 2. failed 按 1/5/15 分钟退避重试，重试耗尽按兜底链转下一渠道
 * 3. pending/scheduled/running 任务超时未推进 → 重置并重新投递
 */
class MessageRetry
{
    /** 每次每店最多处理的记录数 */
    protected const LIMIT = 500;

    /**
     * 定时任务入口
     *
     * 框架调度器按 handle(int $shopid, int $task_id) 反射调用，
     * 缺少该方法会导致任务只写一条「缺少 handle 方法」日志而永不执行。
     *
     * @param int $shopid  定时任务行的店铺 ID（本任务为全局任务，固定 0）
     * @param int $task_id 定时任务 ID
     * @return bool
     */
    public function handle(int $shopid = 0, int $task_id = 0)
    {
        return $this->execute();
    }

    /**
     * @param mixed $item 定时任务行
     * @return bool
     */
    public function execute($item = null)
    {
        $shops = Db::name('message_task')->distinct(true)->column('shopid');
        if (empty($shops)) {
            return true;
        }

        $dispatcher = new Dispatcher();

        foreach ($shops as $shopid) {
            $shopid = (int)$shopid;
            if ($shopid < 0) {
                continue;
            }
            if (!$this->lock($shopid)) {
                continue;
            }

            try {
                $this->retryDelayed($dispatcher, $shopid);
                $this->retryFailed($dispatcher, $shopid);
                $this->resumeStaleTasks($shopid);
            } catch (\Throwable $e) {
                Log::error('[message] retry cron failed: ' . $e->getMessage(), ['shopid' => $shopid]);
            } finally {
                $this->unlock($shopid);
            }
        }

        return true;
    }

    /**
     * 顺延到期
     *
     * @param Dispatcher $dispatcher
     * @param int        $shopid
     * @return void
     */
    protected function retryDelayed(Dispatcher $dispatcher, int $shopid): void
    {
        $now = time();
        $rows = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('status', MessageRecord::STATUS_DELAYED)
            ->where('scheduled_time', '>', 0)
            ->where('scheduled_time', '<=', $now)
            ->order('id asc')
            ->limit(self::LIMIT)
            ->column('id');

        foreach ($rows as $id) {
            $dispatcher->retryRecord((int)$id, false);
        }
    }

    /**
     * 失败退避重试
     *
     * 重试次数统一从 ChannelConfigProvider 读取（与发送侧同源，CLI 下可回退读库）；
     * 同一渠道重试耗尽后，再用一次调度触发兜底渠道降级。
     *
     * @param Dispatcher $dispatcher
     * @param int        $shopid
     * @return void
     */
    protected function retryFailed(Dispatcher $dispatcher, int $shopid): void
    {
        $now = time();
        $config = (new ChannelConfigProvider())->get($shopid);
        $retryMax = max(0, (int)($config['retry_max'] ?? 3));
        $intervals = [1 => 60, 2 => 300, 3 => 900];

        for ($attempts = 1; $attempts <= $retryMax; $attempts++) {
            $this->retryByAttempts($dispatcher, $shopid, $attempts, $intervals[$attempts] ?? 900, $now);
        }

        // attempts = retry_max + 1：retryRecord 会跳过同渠道重发，直接转兜底渠道
        $degradeAttempts = $retryMax + 1;
        $this->retryByAttempts($dispatcher, $shopid, $degradeAttempts, $intervals[$degradeAttempts] ?? 900, $now);
    }

    /**
     * 按尝试次数取失败记录并重试
     *
     * @param Dispatcher $dispatcher
     * @param int        $shopid
     * @param int        $attempts
     * @param int        $delay
     * @param int        $now
     * @return void
     */
    protected function retryByAttempts(Dispatcher $dispatcher, int $shopid, int $attempts, int $delay, int $now): void
    {
        // 服务商限流（provider_rate_limit）按 1 小时退避，其余错误按 1/5/15 分钟退避
        $rows = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('status', MessageRecord::STATUS_FAILED)
            ->where('attempts', $attempts)
            ->where('is_test', 0)
            ->whereRaw('update_time <= ? - IF(error_code = ?, 3600, ?)', [$now, 'provider_rate_limit', $delay])
            ->order('id asc')
            ->limit(self::LIMIT)
            ->column('id');

        foreach ($rows as $id) {
            $dispatcher->retryRecord((int)$id, true);
        }
    }

    /**
     * 重置超时未推进的任务
     *
     * @param int $shopid
     * @return void
     */
    protected function resumeStaleTasks(int $shopid): void
    {
        $now = time();

        // scheduled：到点即投递，只保留 60 秒防重复投递窗口，
        // 不能套用 pending 的 600 秒窗口，否则定时发送会比设定时间晚至少 10 分钟。
        $due = Db::name('message_task')
            ->where('shopid', $shopid)
            ->where('status', MessageTask::STATUS_SCHEDULED)
            ->where('send_time', '>', 0)
            ->where('send_time', '<=', $now)
            ->where('update_time', '<=', $now - 60)
            ->limit(50)
            ->column('id');

        // pending：队列投递失败或 worker 未启动，10 分钟后补偿重投
        $stale = Db::name('message_task')
            ->where('shopid', $shopid)
            ->where('status', MessageTask::STATUS_PENDING)
            ->where('update_time', '<=', $now - 600)
            ->where(function ($query) use ($now) {
                $query->where('send_time', 0)->whereOr('send_time', '<=', $now);
            })
            ->limit(50)
            ->column('id');

        // running 任务不会自行回退：队列 job 重投耗尽被删除、worker 崩溃或进程被 kill
        // 都会让任务永久停留 running。用更长的超时（30 分钟）避免与仍在执行的大批次并发。
        $stuck = Db::name('message_task')
            ->where('shopid', $shopid)
            ->where('status', MessageTask::STATUS_RUNNING)
            ->where('update_time', '<=', $now - 1800)
            ->limit(50)
            ->column('id');

        foreach (array_merge($due, $stale, $stuck) as $taskId) {
            $this->repushTask((int)$taskId, $now);
        }
    }

    /**
     * 重新投递任务
     *
     * @param int $taskId
     * @param int $now
     * @return void
     */
    protected function repushTask(int $taskId, int $now): void
    {
        try {
            $queueName = config('queue.connections.redis.queue') ?: 'default';
            Queue::push('\app\common\queue\Message@handle', ['task_id' => $taskId], $queueName);
            Db::name('message_task')->where('id', $taskId)->update(['update_time' => $now]);
        } catch (\Throwable $e) {
            Log::error('[message] resume task failed: ' . $e->getMessage(), ['task_id' => $taskId]);
        }
    }

    /**
     * 店铺级互斥锁
     *
     * @param int $shopid
     * @return bool
     */
    protected function lock(int $shopid): bool
    {
        try {
            $name = 'muucmf_msg_retry_' . $shopid;
            $result = Db::query('SELECT GET_LOCK(?, 5) AS locked', [$name]);
            return !empty($result[0]['locked']);
        } catch (\Throwable $e) {
            Log::warning('[message] lock failed, skip shop: ' . $e->getMessage(), ['shopid' => $shopid]);
            return false;
        }
    }

    /**
     * 释放锁
     *
     * @param int $shopid
     * @return void
     */
    protected function unlock(int $shopid): void
    {
        try {
            Db::query('SELECT RELEASE_LOCK(?)', ['muucmf_msg_retry_' . $shopid]);
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
