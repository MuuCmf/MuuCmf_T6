<?php

namespace app\common\service\aftersale\channel;

/**
 * 积分退回渠道
 * 无外部动作；积分回退与积分日志在订单状态落库的同一事务内完成
 */
class ScoreChannel implements RefundChannelInterface
{
    public function transfer(array $order, array $refund, int $amount): array
    {
        return [];
    }

    public function settle(array $order, array $refund, int $amount, array $transferResult): array
    {
        $uid = intval($order['uid'] ?? 0);
        if ($uid <= 0) {
            throw new \Exception('订单用户信息异常，无法退回积分');
        }
        if ($amount <= 0) {
            return [
                'channel' => 'score',
                'score'   => 0,
            ];
        }

        $affected = (new \app\common\model\Member())->where('uid', $uid)->inc('score1', $amount)->update();
        if (intval($affected) !== 1) {
            throw new \Exception('订单用户不存在，无法退回积分');
        }
        $scoreRemark = trim((string)($refund['remark'] ?? ''));
        (new \app\common\model\ScoreLog())->addScoreLog($uid, 1, 'inc', $amount, '', 0, '订单退款退回积分' . ($scoreRemark !== '' ? '（' . $scoreRemark . '）' : ''));

        return [
            'channel' => 'score',
            'score'   => $amount,
        ];
    }
}
