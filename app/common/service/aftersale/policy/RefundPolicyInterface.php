<?php

namespace app\common\service\aftersale\policy;

/**
 * 退款策略接口
 * 各业务模块只需实现本接口，退款执行流程统一由 RefundExecutor 负责
 */
interface RefundPolicyInterface
{
    /** 应用标识，对应订单的 app 字段 */
    public function app(): string;

    /** 可退性校验，抛异常即拒绝退款 */
    public function validate(array $order, array $refund): void;

    /** 可退金额（分） */
    public function refundableAmount(array $order, array $items = []): int;

    /** 允许的退款去向：0 余额 1 原路 2 线下 */
    public function allowedTargets(array $order): array;

    /** 事务内：收回权益（必须幂等） */
    public function onRefundedInTx(array $order, array $refund): void;

    /** 事务外：外部系统同步（异常不阻断退款） */
    public function onRefundedAfterTx(array $order, array $refund): void;
}
