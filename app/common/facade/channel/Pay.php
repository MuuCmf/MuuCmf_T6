<?php

namespace app\common\facade\channel;

use think\Facade;

/**
 * Class Channel
 * @method \app\common\service\channel\Pay init(string $appid, string $pay_channel) static
 */
class Pay extends Facade
{
    // getFacadeClass: 获取当前Facade对应类名
    protected static function getFacadeClass()
    {
        // 返回当前类代理的类
        return 'app\common\service\channel\Pay';
    }
}
