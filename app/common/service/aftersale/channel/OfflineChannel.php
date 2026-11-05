<?php

namespace app\common\service\aftersale\channel;

/**
 * 线下人工退款渠道
 * 不产生资金动作，仅登记退款结果与备注，便于后续对账
 */
class OfflineChannel implements RefundChannelInterface
{
    public function transfer(array $order, array $refund, int $amount): array
    {
        return [];
    }

    public function settle(array $order, array $refund, int $amount, array $transferResult): array
    {
        return [
            'channel' => 'manual',
            'remark'  => (string)($refund['remark'] ?? ''),
        ];
    }
}
