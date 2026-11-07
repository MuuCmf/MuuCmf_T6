<?php

namespace app\common\model;

/**
 * 用户消息订阅偏好（首版只写整店营销退订行：template_code=* / channel=*）
 */
class MessageSubscription extends MessageBaseModel
{
    protected $name = 'message_subscription';

    /**
     * 用户是否已退订营销消息
     *
     * @param int $shopid
     * @param int $uid
     * @param string $app
     * @return bool
     */
    public static function isUnsubscribed(int $shopid, int $uid, string $app = 'common'): bool
    {
        // shopid=0 是默认店铺的合法取值，不能短路，否则默认店铺的退订偏好完全不生效
        if ($shopid < 0 || $uid <= 0) {
            return false;
        }
        $row = self::where('shopid', $shopid)
            ->where('uid', $uid)
            ->where('template_code', '*')
            ->where('channel', '*')
            ->find();

        return !empty($row) && (int)$row->getData('subscribed') === 0;
    }
}
