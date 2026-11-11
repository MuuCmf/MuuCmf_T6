<?php

namespace app\common\service\wechat;

use app\common\model\WechatConfig;
use EasyWeChat\Factory;
use think\Exception;

/**
 * 微信公众号类
 * Class OfficialAccount
 * @package app\common\service\wechat
 */
class OfficialAccount extends Wechat
{
    function __construct($shopid = null)
    {
        // 队列 / CLI 场景没有请求上下文，由消息渠道显式传入店铺 ID
        $this->shopid = $shopid === null ? (request()->param('shopid') ?? 0) : (int)$shopid;
        $this->type = 'wechat_official_account';
        //服务配置文件
        $config = $this->config =  $this->initConfig();
        $app =  Factory::officialAccount($config);
        parent::__construct($app);
    }
    public function initConfig()
    {
        //获取配置信息
        $data = (new WechatConfig())->getWechatConfigByShopId($this->shopid);
        if (empty($data)) {
            throw  new Exception('公众号配置文件不存在');
        }
        return [
            'app_id' => $data['appid'],
            'secret' => $data['secret'],
            //服务器配置校验与安全模式（加密消息）依赖 token / aes_key，缺失会导致签名校验失败、消息无法解密
            'token' => $data['token'] ?? '',
            'aes_key' => $data['encoding_aes_key'] ?? '',
            'response_type' => 'array',
            //生成日志
            'log' => $this->log(),
            'oauth' => [
                'scopes'   => ['snsapi_userinfo'],
                'callback' => request()->domain() . "/api/wechat/oauth_callback",
            ],
        ];
    }

    /**
     * @title 公众号授权验证
     */
    public function serverOAath()
    {
        $response = $this->app->server->serve();
        $response->send();
        exit();
    }

    /**
     * @title 获取微信服务器IP
     * @return mixed
     */
    public function getWechatServerIps()
    {
        return $this->app->base->getValidIps();
    }

    /**
     * @title 读取（查询）已设置菜单
     * @return mixed
     */
    public function getMenu()
    {
        return $this->app->menu->list();
    }

    /**
     * @title 获取当前菜单
     * @return mixed
     */
    public function currentMenu()
    {
        return $this->app->menu->current();
    }

    /**
     * @title 设置菜单
     * @param $menu
     * @return mixed
     */
    public function createMenu($menu)
    {

        $menu = $this->handleMenu($menu);
        return $this->app->menu->create($menu);
    }

    /**
     * @title 处理菜单数据
     */
    protected function handleMenu($menu)
    {
        $new_menu = [];
        foreach ($menu as $m) {
            $item = [];
            $item['name'] = $m['name'];
            if (isset($m['sub_button']) && count($m['sub_button']) > 0) {
                $item['sub_button'] = $this->handleMenu($m['sub_button']);
            } else {
                switch ($m['type']) {
                    case 'view':
                        $item['url'] = $m['url'];
                        break;
                    case 'media_id':
                        $item['media_id'] = $m['media_id'];
                        break;
                    case 'miniprogram':
                        $item['appid'] = $m['appid'];
                        $item['pagepath'] = $m['pagepath'];
                        $item['url'] = $m['url'];
                        break;
                }
                $item['type'] = $m['type'];
            }
            array_push($new_menu, $item);
        }
        return $new_menu;
    }

    /**
     * @title 获取当前设置的回复规则
     * @return mixed
     */
    public function currentMessage()
    {
        return $this->app->auto_reply->current();
    }

    /**
     * @title 获取素材列表
     * @param $type **图片(image)、视频(video)、语音（voice）、图文（news）
     * @param int $offset 从全部素材的该偏移位置开始返回，可选，默认 0，0 表示从第一个素材 返回
     * @param int $count 返回素材的数量，可选，默认 20, 取值在 1 到 20 之间
     * @return mixed
     */
    public function getMaterialList($type, $offset = 0, $count = 20)
    {
        return $this->app->material->list($type, $offset, $count);
    }

    /**
     * @title 根据素材id获取详情
     * @param $media_id
     * @return mixed
     */
    public function getMaterial($media_id)
    {
        return $this->app->material->get($media_id);
    }

    /**
     * @title 网页授权
     * @param string $target_url
     * @throws Exception
     */
    public function oauth(array $params = [])
    {
        //授权回调参数处理
        if ($params) {
            //重新初始化
            $config = $this->initConfig();
            $config['oauth']['callback'] .= "?muu=muucmf";
            foreach ($params as $key => $item) {
                $config['oauth']['callback'] .= "&{$key}={$item}";
            }
        }
        $response = $this->app->oauth->scopes(['snsapi_userinfo'])->redirect($config['oauth']['callback']);
        return $response;
    }

    /**
     * @title 获取access token
     * @return mixed
     */
    public function getToken()
    {
        return $this->app->access_token->getToken();
    }

    /**
     * @title 创建二维码
     *
     * @param $content 内容
     * @param $expiration_time 过期时间
     * @return mixed
     */
    public function createQrcode($content, $expiration_time = 0)
    {
        if ($expiration_time > 0) {
            $qrcode = $this->app->qrcode->temporary($content, $expiration_time);
        } else {
            $qrcode = $this->app->qrcode->forever($content);
        }
        return $qrcode;
    }

    /**
     * @title 获取qrcode
     * @param $ticket
     * @return mixed
     */
    public function getQrcodeUrl($ticket)
    {
        return $this->app->qrcode->url($ticket);
    }

    /**
     * @title 发送模板消息
     * @param $data
     * @return mixed
     */
    public function sendTemplateMsg($data)
    {
        return $this->app->template_message->send($data);
    }

    /**
     * 获取单个用户信息
     */
    public function getUserByOpenid($openid)
    {
        return $this->app->user->get($openid);
    }
}
