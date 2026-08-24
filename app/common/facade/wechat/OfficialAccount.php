<?php

namespace app\common\facade\wechat;

use think\Facade;

/**
 * Class OfficialAccount
 * @method void serverOAath() static
 * @method mixed getWechatServerIps() static
 * @method mixed getWechatServerIp() static
 * @method mixed getMenu() static
 * @method \EasyWeChat\OfficialAccount\Application getApp() static
 * @method mixed currentMenu() static
 * @method mixed createMenu($menu) static
 * @method mixed currentMessage() static
 * @method mixed getMaterialList($type,$offset,$count) static
 * @method mixed getMaterial($media_id) static
 * @method mixed getToken() static
 * @method mixed oauth(array $params) static
 * @method mixed createQrcode(string $content ,$expiration_time) static
 * @method string getQrcodeUrl(string $ticket) static
 * @method mixed sendTemplateMsg(array $data) static
 */
class OfficialAccount extends Facade
{
    // getFacadeClass: 获取当前Facade对应类名
    protected static function getFacadeClass()
    {
        // 返回当前类代理的类
        return 'app\common\service\wechat\OfficialAccount';
    }
}
