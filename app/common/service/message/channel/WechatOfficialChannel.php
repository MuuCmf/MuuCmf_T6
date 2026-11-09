<?php

namespace app\common\service\message\channel;

use app\common\service\wechat\OfficialAccount;

/**
 * 微信公众号模板消息渠道（wechat_oa）
 *
 * openid 取 member_sync.type=weixin_h5；模板 ID 只从消息模板渠道配置读取，
 * 不再回退 wechat_config.tmplmsg 等历史配置。
 */
class WechatOfficialChannel extends WechatChannel
{
    protected function channelKey(): string
    {
        return 'wechat_oa';
    }

    protected function syncTypes(): array
    {
        return ['weixin_h5'];
    }

    protected function dispatch(int $shopid, array $message): array
    {
        // 直接实例化而非走 Facade：Facade 会缓存单例，多店铺队列场景会串用配置
        return (array)(new OfficialAccount($shopid))->sendTemplateMsg($message);
    }

    protected function decorateMessage(array $message, array $extra): array
    {
        $url = trim((string)($extra['url'] ?? ''));
        if ($url !== '' && (strpos($url, 'http') === 0 || strpos($url, '/') === 0)) {
            $message['url'] = $url;
        }

        $appid = trim((string)($extra['miniprogram_appid'] ?? ''));
        $page = trim((string)($extra['miniprogram_page'] ?? ''));
        if ($appid !== '' && $page !== '') {
            $message['miniprogram'] = ['appid' => $appid, 'pagepath' => $page];
        }
        return $message;
    }
}
