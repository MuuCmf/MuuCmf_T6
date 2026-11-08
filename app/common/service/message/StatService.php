<?php

namespace app\common\service\message;

use think\facade\Db;
use app\common\model\MessageRecord;

/**
 * 送达统计（基于 message_record 实时聚合，首版不建日表）
 *
 * 口径：
 * - 送达率 = success / (total - blocked)
 * - 发送量 = 渠道记录数；触达人数 = 去重 uid
 * - canceled 不计入统计
 */
class StatService
{
    /**
     * 任务维度的渠道进度与失败原因 Top5
     *
     * @param int $taskId
     * @param int $shopid
     * @return array
     */
    public function task(int $taskId, int $shopid): array
    {
        $channels = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('task_id', $taskId)
            ->where('status', '<>', MessageRecord::STATUS_CANCELED)
            // 测试发送不计入业务统计；pending / delayed 不属于已发送分母
            ->where('is_test', 0)
            ->field("channel, COUNT(*) AS total, SUM(IF(status='success',1,0)) AS success, SUM(IF(status='failed',1,0)) AS failed, SUM(IF(status='blocked',1,0)) AS blocked, SUM(IF(status='pending' OR status='delayed',1,0)) AS pending")
            ->group('channel')
            ->select()
            ->toArray();

        $byChannel = [];
        foreach ($channels as $row) {
            $total = (int)$row['total'];
            $success = (int)$row['success'];
            $blocked = (int)$row['blocked'];
            $pending = (int)$row['pending'];
            $byChannel[] = [
                'channel'      => $row['channel'],
                'total'        => $total,
                'success'      => $success,
                'failed'       => (int)$row['failed'],
                'blocked'      => $blocked,
                'pending'      => $pending,
                'success_rate' => round($success * 100 / max(1, $total - $blocked - $pending), 2),
            ];
        }

        $top = Db::name('message_record')
            ->where('shopid', $shopid)
            ->where('task_id', $taskId)
            ->where('status', MessageRecord::STATUS_FAILED)
            ->where('error_code', '<>', '')
            ->field('error_code, error_msg, COUNT(*) AS total')
            ->group('error_code, error_msg')
            ->order('total desc')
            ->limit(5)
            ->select()
            ->toArray();

        $topErrors = [];
        foreach ($top as $row) {
            $topErrors[] = [
                'error_code' => $row['error_code'],
                'error_text' => MessageException::text((string)$row['error_code']),
                'error_msg'  => $row['error_msg'],
                'total'      => (int)$row['total'],
            ];
        }

        return ['channels' => $byChannel, 'top_errors' => $topErrors];
    }

    /**
     * 记录维度聚合（任务列表批量使用）
     *
     * 任务表计数器只在批处理时累加，重试/降级不会回写，故展示值以记录明细为准。
     * canceled 不计入统计；无记录的任务返回全 0。
     *
     * @param array $taskIds
     * @param int   $shopid
     * @return array [task_id => counters]
     */
    public function recordCounters(array $taskIds, int $shopid): array
    {
        $taskIds = array_values(array_unique(array_filter(array_map('intval', $taskIds))));
        if (empty($taskIds)) {
            return [];
        }

        $map = [];
        foreach ($taskIds as $taskId) {
            $map[$taskId] = [
                'record_total'   => 0,
                'record_success' => 0,
                'record_failed'  => 0,
                'record_blocked' => 0,
            ];
        }

        $rows = Db::name('message_record')
            ->where('shopid', $shopid)
            ->whereIn('task_id', $taskIds)
            ->where('status', '<>', MessageRecord::STATUS_CANCELED)
            ->field("task_id, COUNT(*) AS record_total, SUM(IF(status='success',1,0)) AS record_success, SUM(IF(status='failed',1,0)) AS record_failed, SUM(IF(status='blocked',1,0)) AS record_blocked")
            ->group('task_id')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $map[(int)$row['task_id']] = [
                'record_total'   => (int)$row['record_total'],
                'record_success' => (int)$row['record_success'],
                'record_failed'  => (int)$row['record_failed'],
                'record_blocked' => (int)$row['record_blocked'],
            ];
        }

        return $map;
    }

    /**
     * 用户维度聚合（单任务详情使用，口径与批处理计数一致：三类互斥）
     *
     * @param int $taskId
     * @param int $shopid
     * @return array
     */
    public function userCounters(int $taskId, int $shopid): array
    {
        $table = Db::name('message_record')->getTable();
        $rows = Db::query(
            "SELECT COUNT(*) AS user_done,
                    SUM(has_success) AS user_success,
                    SUM(has_success = 0 AND has_failed = 1) AS user_failed,
                    SUM(has_success = 0 AND has_failed = 0 AND has_blocked = 1) AS user_blocked
             FROM (
                 SELECT uid,
                        MAX(status = 'success') AS has_success,
                        MAX(status = 'failed') AS has_failed,
                        MAX(status = 'blocked') AS has_blocked
                 FROM {$table}
                 WHERE shopid = ? AND task_id = ? AND status <> 'canceled'
                 GROUP BY uid
             ) t",
            [$shopid, $taskId]
        );

        $row = $rows[0] ?? [];
        return [
            'user_done'    => (int)($row['user_done'] ?? 0),
            'user_success' => (int)($row['user_success'] ?? 0),
            'user_failed'  => (int)($row['user_failed'] ?? 0),
            'user_blocked' => (int)($row['user_blocked'] ?? 0),
        ];
    }

    /**
     * 概览（最近 N 天）
     *
     * @param int $shopid
     * @param int $days
     * @return array
     */
    public function overview(int $shopid, int $days = 7): array
    {
        // 只统计最近 90 天；顺延记录按 scheduled_time 所在日归档
        $days = min(90, max(1, $days));
        $start = strtotime('-' . $days . ' days');

        $rows = Db::name('message_record')
            ->where('shopid', $shopid)
            ->whereRaw('COALESCE(NULLIF(scheduled_time, 0), create_time) >= ?', [$start])
            ->where('status', '<>', MessageRecord::STATUS_CANCELED)
            ->where('is_test', 0)
            ->field("channel, COUNT(*) AS total, SUM(IF(status='success',1,0)) AS success, SUM(IF(status='failed',1,0)) AS failed, SUM(IF(status='blocked',1,0)) AS blocked, SUM(IF(status='pending' OR status='delayed',1,0)) AS pending, COUNT(DISTINCT uid) AS users")
            ->group('channel')
            ->select()
            ->toArray();

        $total = 0;
        $success = 0;
        $failed = 0;
        $blocked = 0;
        $pending = 0;
        $byChannel = [];
        foreach ($rows as $row) {
            $total += (int)$row['total'];
            $success += (int)$row['success'];
            $failed += (int)$row['failed'];
            $blocked += (int)$row['blocked'];
            $pending += (int)$row['pending'];
            $byChannel[] = [
                'channel' => $row['channel'],
                'total'   => (int)$row['total'],
                'success' => (int)$row['success'],
                'failed'  => (int)$row['failed'],
                'blocked' => (int)$row['blocked'],
                'users'   => (int)$row['users'],
            ];
        }

        return [
            'total'        => $total,
            'success'      => $success,
            'failed'       => $failed,
            'blocked'      => $blocked,
            'pending'      => $pending,
            'success_rate' => round($success * 100 / max(1, $total - $blocked - $pending), 2),
            'channels'     => $byChannel,
        ];
    }
}
