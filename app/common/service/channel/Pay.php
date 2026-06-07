<?php

namespace app\common\service\channel;

class Pay
{
    //支付服务类
    protected $_class_name = [
        'weixin' => 'WechatPayment',
        'alipay' => 'AlipayPayment',
    ];

    public $server;//支付服务类实例

    /**
     * @title 初始化支付服务
     * @param $appid
     * @param $pay_channel
     * @return $this
     */
    public function init($appid, $pay_channel)
    {
        if (!isset($this->_class_name[$pay_channel])) {
            throw new \Exception('不支持的支付渠道: ' . $pay_channel);
        }
        //获取实例化的服务
        $pay_namespace = "app\\common\\service\\pay\\{$this->_class_name[$pay_channel]}";
        $this->server = new $pay_namespace($appid);
        return $this;
    }
}
