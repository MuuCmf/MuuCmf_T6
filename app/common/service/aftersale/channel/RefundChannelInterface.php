<?php

namespace app\common\service\aftersale\channel;

/**
 * 退款渠道接口
 * transfer 负责事务外的外部动作（如支付网关），settle 负责事务内的本地落账
 */
interface RefundChannelInterface
{
    /**
     * 事务外执行：原路退回调用支付网关；余额/线下渠道无外部动作
     * @param array $order 订单
     * @param array $refund 退款参数（含 refund_no）
     * @param int $amount 退款金额（分）
     * @return array 渠道返回，供 settle 与流水记录使用
     */
    public function transfer(array $order, array $refund, int $amount): array;

    /**
     * 事务内执行：钱包入账/流水等本地落账，返回写入订单 refund_result 的数据
     * @param array $order 订单
     * @param array $refund 退款参数
     * @param int $amount 退款金额（分）
     * @param array $transferResult transfer 返回数据
     * @return array
     */
    public function settle(array $order, array $refund, int $amount, array $transferResult): array;
}
