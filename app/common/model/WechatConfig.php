<?php

namespace app\common\model;

/**
 * 公众号配置表
 * Class WechatConfig
 * @package app\common\model
 */
class WechatConfig extends Base
{

    //自动写入创建和更新的时间戳字段
    protected $autoWriteTimestamp = true;
    /**
     * @title 根据shopid获取公众号配置
     * @param int $shopid
     * @return WechatConfig|array|\think\Model|null
     */
    function getWechatConfigByShopId($shopid = 0)
    {
        $config = $this->where([['shopid', '=', $shopid]])->find();
        if ($config) {
            $config = $config->toArray();
            // 固定URL地址
            $config['url'] = $this->callbackUrl($shopid);

            // 处理封面图和二维码
            if (!empty($config['cover'])) {
                $config['cover_url'] = get_attachment_src($config['cover']);
            }
            if (!empty($config['qrcode'])) {
                $config['qrcode_url'] = get_attachment_src($config['qrcode']);
            }

            // 处理创建时间
            if (!empty($config['create_time'])) {
                $config['create_time_str'] = time_format($config['create_time']);
                $config['create_time_friendly_str'] = friendly_date($config['create_time']);
            }
            // 处理更新时间
            if (!empty($config['update_time'])) {
                $config['update_time_str'] = time_format($config['update_time']);
                $config['update_time_friendly_str'] = friendly_date($config['update_time']);
            }
        } else {
            //初始化数据
            $config['id'] = 0;
            $config['title'] = '';
            $config['cover'] = '';
            $config['desc'] = '';
            $config['qrcode'] = '';
            $config['appid'] = '';
            $config['secret'] = '';
            $config['url'] = $this->callbackUrl($shopid);
            $config['auth_login'] = 1;
        }

        return $config;
    }

    /**
     * @title 获取回调地址
     * @return string
     */
    public function callbackUrl($shopid = 0)
    {
        $url = url('api/wechat/callback', ['shopid' => $shopid], false, true);
        return (string)$url;
    }
}
