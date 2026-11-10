<?php

namespace app\common\service\trade;

/**
 * 交易领域事件订阅契约
 *
 * 由框架定义、业务应用实现，保证依赖方向为「模块 → 框架」：
 * 框架不得反向引用任何可卸载应用（含 scrm）的具体类。
 * 约定实现类名：app\{app}\service\trade\TradeEventSubscriber
 */
interface TradeEventSubscriberInterface
{
    /**
     * 订单支付成功
     *
     * @param array $payload shopid / order_no / uid / app / paid_fee(分) / unionid / paid_time
     */
    public function onOrderPaid(array $payload): void;

    /**
     * 订单退款完成
     *
     * @param array $payload shopid / order_no / uid / app / unionid / refund_no / refund_fee(分) / refund_time
     */
    public function onOrderRefunded(array $payload): void;
}
