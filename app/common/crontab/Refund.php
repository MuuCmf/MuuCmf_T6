<?php

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\service\aftersale\RefundCenter;

/**
 * @title 售后退款审核超时关闭与滞留单对账
 */
class Refund
{
    /**
     * 审核超时小时数（固定 12 小时）
     */
    protected const AUDIT_EXPIRE_HOURS = 12;

    /**
     * 打款滞留分钟数（固定 30 分钟，超时未收敛即回置待重试）
     */
    protected const RECONCILE_MINUTES = 30;

    /**
     * 退货超时天数（固定 7 天，与订单自动收货周期一致）
     */
    protected const RETURN_EXPIRE_DAYS = 7;

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
            // 打款成功但落账失败的单据会停在「打款中」，定期回置为「已同意」恢复后台重试入口
            $minutes = self::RECONCILE_MINUTES;
            $reverted = RefundCenter::reconcileStuck($minutes, 200, $shopid);
            // 退货流程兜底：同意退货后长期未收到商品，关闭售后单并释放订单退货状态
            $days = self::RETURN_EXPIRE_DAYS;
            $returnClosed = RefundCenter::closeReturnTimeout($days, 200, $shopid);

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => 'success，关闭 ' . $closed . ' 单，回置 ' . $reverted . ' 单，关闭退货超时 ' . $returnClosed . ' 单，超时阈值 '
                    . $hours . ' 小时/' . $minutes . ' 分钟/' . $days . ' 天',
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
