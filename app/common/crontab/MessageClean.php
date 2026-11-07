<?php

namespace app\common\crontab;

use think\facade\Db;
use think\facade\Log;
use app\common\service\message\ChannelConfigProvider;

/**
 * 消息数据清理（每天 04:00）
 *
 * 保留期：
 * - 发送记录默认 30 天（extend MESSAGE_RECORD_RETAIN_DAYS）
 * - 已解黑记录 30 天
 * - 已过期黑名单记录 30 天
 * - 队列失败任务 7 天
 */
class MessageClean
{
    /** 单次删除上限，避免长事务锁表 */
    protected const CHUNK = 2000;

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
     * @param mixed $item
     * @return bool
     */
    public function execute($item = null)
    {
        // CLI 下不经过 DbConfig 中间件，config('extend.*') 为空，
        // 必须走 ChannelConfigProvider 的「回库直读」口径，否则后台配置的保留期永远不生效
        $retainDays = max(7, (int)(ChannelConfigProvider::configValue('MESSAGE_RECORD_RETAIN_DAYS', '30') ?: 30));
        $deadline = time() - $retainDays * 86400;

        try {
            $this->cleanRecords($deadline);
            $this->cleanSuppression();
            $this->cleanFailedJobs();
        } catch (\Throwable $e) {
            Log::error('[message] clean cron failed: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * 清理过期发送记录
     *
     * @param int $deadline
     * @return void
     */
    protected function cleanRecords(int $deadline): void
    {
        do {
            $ids = Db::name('message_record')
                ->where('create_time', '<', $deadline)
                ->order('id asc')
                ->limit(self::CHUNK)
                ->column('id');
            if (empty($ids)) {
                break;
            }
            Db::name('message_record')->whereIn('id', $ids)->delete();
        } while (count($ids) === self::CHUNK);
    }

    /**
     * 清理黑名单历史行
     *
     * 两类都要清：
     * - 已解黑（status=0）且解黑超过 30 天；
     * - 已过期（expire_time>0 且过期超过 30 天）的生效行，否则过期行永久堆积。
     *
     * @return void
     */
    protected function cleanSuppression(): void
    {
        $now = time();
        $deadline = $now - 30 * 86400;

        Db::name('message_suppression')
            ->where('status', 0)
            ->where('release_time', '>', 0)
            ->where('release_time', '<', $deadline)
            ->limit(self::CHUNK)
            ->delete();

        Db::name('message_suppression')
            ->where('status', 1)
            ->where('expire_time', '>', 0)
            ->where('expire_time', '<', $deadline)
            ->limit(self::CHUNK)
            ->delete();
    }

    /**
     * 清理过期队列失败任务
     *
     * @return void
     */
    protected function cleanFailedJobs(): void
    {
        try {
            Db::name('failed_jobs')
                ->where('fail_time', '<', date('Y-m-d H:i:s', time() - 7 * 86400))
                ->limit(self::CHUNK)
                ->delete();
        } catch (\Throwable $e) {
            // failed_jobs 未创建时忽略
        }
    }
}
