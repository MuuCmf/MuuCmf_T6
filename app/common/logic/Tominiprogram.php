<?php

namespace app\common\logic;

class Tominiprogram extends Base
{

    /**
     * 小程序类型映射
     * 兼容历史 _app 命名，避免旧数据格式化时报未定义下标
     */
    protected $_type = [
        'weixin_mp'  => '微信小程序',
        'alipay_mp'  => '支付宝小程序',
        'baidu_mp'   => '百度小程序'
    ];
    /**
     * @title 格式化数据
     */
    public function formatData($data)
    {
        $type = $data['type'] ?? '';
        $data['type_str'] = $this->_type[$type] ?? $type;
        
        $data = $this->setImgAttr($data, '1:1', 'qrcode');
        $data = $this->setTimeAttr($data);

        return $data;
    }
}
