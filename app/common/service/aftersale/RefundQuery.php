<?php

namespace app\common\service\aftersale;

use think\facade\Db;

/**
 * 售后单查询
 *
 * 统一各应用后台订单列表/详情对售后单的读取口径，避免每个模块各写一份 getRefundMap。
 * 同一订单存在多次申请（重新申请会重置同一条单据）时取最新一条。
 */
class RefundQuery
{
    /** 售后单状态文案 */
    public const STATUS_TEXT = [
        RefundStatus::WAIT_AUDIT  => '待审核',
        RefundStatus::AGREED      => '已同意',
        RefundStatus::REJECTED    => '已拒绝',
        RefundStatus::PROCESSING  => '打款中',
        RefundStatus::COMPLETED   => '已完成',
        RefundStatus::CLOSED      => '已关闭',
        RefundStatus::RETURNING   => '退货中',
    ];

    /** 退款去向文案 */
    public const TARGET_TEXT = [
        0 => '用户余额',
        1 => '原路退回',
        2 => '线下处理',
        3 => '积分',
    ];

    /**
     * 售后单状态文案
     */
    public static function statusText(int $status): string
    {
        return self::STATUS_TEXT[$status] ?? '未知';
    }

    /**
     * 退款去向文案
     */
    public static function targetText(int $target): string
    {
        return self::TARGET_TEXT[$target] ?? '未知';
    }

    /**
     * 批量获取订单最新售后单（以订单号为键）
     *
     * @param array $orderNos 订单号列表
     * @return array [order_no => 售后单信息]
     */
    public static function mapByOrderNos(array $orderNos): array
    {
        $orderNos = array_values(array_filter(array_map('strval', $orderNos)));
        if (empty($orderNos)) {
            return [];
        }
        $rows = Db::name('refund')
            ->whereIn('order_no', $orderNos)
            ->order('id desc')
            ->select()
            ->toArray();
        $map = [];
        foreach ($rows as $row) {
            // 同一订单存在多次申请时仅保留最新售后单
            if (isset($map[$row['order_no']])) {
                continue;
            }
            $map[$row['order_no']] = self::format($row);
        }
        return $map;
    }

    /**
     * 获取指定订单最新售后单
     *
     * @param string $orderNo 订单号
     * @return array|null
     */
    public static function latestByOrderNo(string $orderNo): ?array
    {
        if ($orderNo === '') {
            return null;
        }
        $row = Db::name('refund')->where('order_no', $orderNo)->order('id desc')->find();
        return empty($row) ? null : self::format($row);
    }

    /**
     * 售后单输出格式化
     */
    private static function format(array $row): array
    {
        $status = intval($row['status'] ?? 0);
        $target = intval($row['refund_to'] ?? 0);
        return [
            'refund_no'       => (string)($row['refund_no'] ?? ''),
            'order_no'        => (string)($row['order_no'] ?? ''),
            'status'          => $status,
            'status_str'      => self::statusText($status),
            'refund_to'       => $target,
            'refund_to_str'   => self::targetText($target),
            'refund_fee'      => number_format(intval($row['refund_fee'] ?? 0) / 100, 2, '.', ''),
            'refund_fee_fen'  => intval($row['refund_fee'] ?? 0),
            'reason'          => (string)($row['reason'] ?? ''),
            'source'          => intval($row['source'] ?? 2),
            'create_time_str' => !empty($row['create_time']) ? date('Y-m-d H:i:s', intval($row['create_time'])) : '',
            'update_time_str' => !empty($row['update_time']) ? date('Y-m-d H:i:s', intval($row['update_time'])) : '',
        ];
    }
}
