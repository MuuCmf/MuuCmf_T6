<?php

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\service\aftersale\RefundCenter;

/**
 * @title 售后退款审核超时自动关闭
 */
class Refund
{
    /**
     * 审核超时小时数（固定 12 小时）
     */
    protected const AUDIT_EXPIRE_HOURS = 12;

    /**
     * @title 业务处理
     * @param int $shopid
     * @param int $task_id
     * @return bool
     */
    public function handle(int $shopid, int $task_id)
    {
        try {
            $hours = self::AUDIT_EXPIRE_HOURS;
            $closed = RefundCenter::closeExpired($hours, 200, $shopid);

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => 'success，关闭 ' . $closed . ' 单，超时阈值 ' . $hours . ' 小时',
            ]);
            return true;
        } catch (\Throwable $e) {
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => $e->getMessage(),
                'status'      => 0,
            ]);
            return false;
        }
    }
}
