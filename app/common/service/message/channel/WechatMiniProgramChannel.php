<?php

namespace app\common\service\message\channel;

use app\common\service\wechat\MiniProgram;

/**
 * 微信小程序订阅消息渠道（wechat_mp）
 *
 * openid 取 member_sync.type=weixin_mp；调用 sendSubscribeMsg（订阅消息），
 * 模板 ID 只从消息模板渠道配置读取，不再回退 wechat_mp_config.tmplmsg。
 * 小程序端需自行调用 wx.requestSubscribeMessage 获取订阅额度。
 */
class WechatMiniProgramChannel extends WechatChannel
{
    protected function channelKey(): string
    {
        return 'wechat_mp';
    }

    protected function syncTypes(): array
    {
        // 小程序登录主用 weixin_mp，部分历史账号写在 weixin_app
        return ['weixin_mp', 'weixin_app'];
    }

    protected function dispatch(int $shopid, array $message): array
    {
        return (array)(new MiniProgram($shopid))->sendSubscribeMsg($message);
    }

    protected function decorateMessage(array $message, array $extra): array
    {
        $page = trim((string)($extra['page'] ?? ''));
        if ($page !== '') {
            $message['page'] = $page;
        }
        $state = trim((string)($extra['miniprogram_state'] ?? ''));
        if (in_array($state, ['formal', 'trial', 'developer'], true)) {
            $message['miniprogram_state'] = $state;
        }
        return $message;
    }
}
