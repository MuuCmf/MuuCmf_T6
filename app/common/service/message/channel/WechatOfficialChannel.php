<?php

namespace app\common\service\message\channel;

use think\facade\Db;
use app\common\service\wechat\OfficialAccount;

/**
 * 微信公众号模板消息渠道（wechat_oa）
 *
 * openid 取 member_sync.type=weixin_h5；模板 ID 缺省时按 scene 回退
 * wechat_config.tmplmsg.tmplmsg.{scene}（兼容历史「支付成功」等配置）。
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

    protected function legacyTemplateId(int $shopid, string $scene): string
    {
        $config = json_decode((string)Db::name('wechat_config')
            ->where('shopid', $shopid)->value('tmplmsg'), true) ?: [];
        return trim((string)($config['tmplmsg'][$scene] ?? ''));
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
