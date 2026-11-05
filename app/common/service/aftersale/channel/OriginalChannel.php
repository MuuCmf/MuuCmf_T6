<?php

namespace app\common\service\aftersale\channel;

/**
 * 原路退回渠道
 * 事务外调用支付网关；固定派生退款单号由执行器生成，重试时网关幂等去重
 */
class OriginalChannel implements RefundChannelInterface
{
    public function transfer(array $order, array $refund, int $amount): array
    {
        $orderNo = (string)($order['order_no'] ?? '');
        $orderId = intval($order['id'] ?? 0);
        $channel = (string)($order['channel'] ?? '');
        $payChannel = (string)($order['pay_channel'] ?? '');
        $shopid = intval($order['shopid'] ?? 0);
        $refundNo = (string)($refund['refund_no'] ?? '');

        if ($orderId <= 0 || $orderNo === '' || $refundNo === '') {
            throw new \Exception('退款参数错误');
        }
        if ($channel === '' || $channel === 'balance'
            || in_array($payChannel, ['balance', 'offline', 'admin', ''], true)) {
            throw new \Exception('该订单支付渠道不支持原路退回，请选择退回余额或线下处理');
        }

        $config = \app\common\facade\channel\Channel::config($channel, $shopid);
        if (empty($config['appid'])) {
            throw new \Exception('支付渠道配置缺失，无法原路退回');
        }
        $payService = \app\common\facade\channel\Pay::init($config['appid'], $payChannel);
        if (empty($payService) || empty($payService->server)) {
            throw new \Exception('支付渠道初始化失败');
        }

        $result = $payService->server->refund([
            'order_no'   => $orderNo,
            'refund_no'  => $refundNo,
            'total_fee'  => intval($order['paid_fee'] ?? 0),
            'refund_fee' => $amount,
            'title'      => $orderNo,
        ]);
        if (empty($result['return_code']) || $result['return_code'] !== 'SUCCESS'
            || empty($result['result_code']) || $result['result_code'] !== 'SUCCESS') {
            $errorMsg = (string)($result['err_code_des'] ?? $result['return_msg'] ?? '未知错误');
            throw new \Exception('原路退回失败：' . $errorMsg);
        }
        return $result;
    }

    public function settle(array $order, array $refund, int $amount, array $transferResult): array
    {
        $shopid = intval($order['shopid'] ?? 0);
        $uid = intval($order['uid'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        $channel = (string)($order['channel'] ?? '');
        // 资金流水备注按来源区分，便于后台手动退款与用户申请退款对账
        $remark = intval($refund['source'] ?? 2) === 1 ? '用户申请退款' : '后台手动退款';

        $flowNo = (new \app\common\model\CapitalFlow())->createFlow([
            'shopid'   => $shopid,
            'app'      => (string)($order['app'] ?? ''),
            'uid'      => $uid,
            'order_no' => $orderNo,
            'channel'  => $channel,
            'type'     => 2,
            'price'    => $amount,
            'remark'   => $remark,
            'status'   => 1,
        ]);
        if ($flowNo === false) {
            throw new \Exception('写入退款流水失败');
        }

        return [
            'channel' => $channel,
            'flow_no' => (string)$flowNo,
            'gateway' => $transferResult,
        ];
    }
}
