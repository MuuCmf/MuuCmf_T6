<?php

namespace app\common\service\aftersale\channel;

/**
 * 退回余额渠道
 * 无外部动作；钱包入账与资金流水在订单状态落库的同一事务内完成
 */
class BalanceChannel implements RefundChannelInterface
{
    public function transfer(array $order, array $refund, int $amount): array
    {
        return [];
    }

    public function settle(array $order, array $refund, int $amount, array $transferResult): array
    {
        $shopid = intval($order['shopid'] ?? 0);
        $uid = intval($order['uid'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($uid <= 0) {
            throw new \Exception('订单用户信息异常，无法退回余额');
        }

        (new \app\common\model\MemberWallet())->income($shopid, $uid, $amount, true);

        $flowNo = (new \app\common\model\CapitalFlow())->createFlow([
            'shopid'   => $shopid,
            'app'      => (string)($order['app'] ?? ''),
            'uid'      => $uid,
            'order_no' => $orderNo,
            'channel'  => 'balance',
            'type'     => 2,
            'price'    => $amount,
            'remark'   => (string)($refund['remark'] ?? '') ?: '后台手动退款',
            'status'   => 1,
        ]);
        if ($flowNo === false) {
            throw new \Exception('写入资金流水失败');
        }

        return [
            'channel' => 'balance',
            'flow_no' => (string)$flowNo,
        ];
    }
}
