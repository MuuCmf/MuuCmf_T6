<?php

namespace app\common\model;

use think\facade\Db;

/**
 * 售后单状态流转日志
 */
class RefundLog extends Base
{
    // 仅创建时间，由调用方显式写入
    protected $autoWriteTimestamp = false;

    /**
     * 写入一条状态流转日志（失败不阻断主流程）
     */
    public static function write(string $refundNo, string $orderNo, int $fromStatus, int $toStatus, string $action, string $remark = '', int $operatorType = 0, int $operatorId = 0): void
    {
        try {
            Db::name('refund_log')->insert([
                'refund_no'    => $refundNo,
                'order_no'     => $orderNo,
                'from_status'  => $fromStatus,
                'to_status'    => $toStatus,
                'action'       => $action,
                'operator_type' => $operatorType,
                'operator_id'  => $operatorId,
                'remark'       => $remark,
                'create_time'  => time(),
            ]);
        } catch (\Throwable $e) {
            // 审计日志失败不影响退款主流程
        }
    }
}
