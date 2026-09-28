<?php

namespace app\api\controller;

use think\Exception;
use app\common\controller\Api;
use app\common\model\WechatWorkConfig;
use app\common\model\Member;
use app\common\facade\wechat\Work;
use thans\jwt\facade\JWTAuth;

/**
 * 企业微信服务
 */
class WechatWork extends Api
{
    function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取公众号配置
     */
    public function config()
    {
        //获取公众号配置
        $config = (new WechatWorkConfig())->where('shopid', $this->params['shopid'])->field('shopid,title,description,agent_id,corp_id')->find();

        return $this->success('success', $config);
    }

    /**
     * 微信回调
     */
    public function callback()
    {
        //实例化公众号
        $app = Work::getApp();
        //获取消息类型
        $message = $app->server->getMessage();
        if (isset($message['MsgType'])) {
            $map = [
                ['status', '=', 1],
            ];
            switch ($message['MsgType']) {
                case 'event': //事件
                    $this->doEvent($message);
                    break;
                default:
                    //自动回复消息
                    $map[] = ['type', '=', 2];
                    $map[] = ['keyword', '=', $message['Content']];
                    $map[] = ['status', '=', 1];
                    $this->doMessage($message, $map);
                    break;
            }
            $app->server->serve();
        }
        //token 回调
        Work::serverOAath();
    }

    /**
     * 网页授权
     */
    public function oauth()
    {
        $target_url = input('param.target_url', request()->domain());
        // 协议白名单：仅允许 http/https，防止 javascript:/data: 等协议注入
        if (!preg_match('#^https?://#i', (string)$target_url)) {
            $target_url = request()->domain();
        }
        $target_url = explode('#', $target_url);
        $oauth_data = [
            'target_url' => urlencode($target_url[0])
        ];
        //uniapp hash路由带有# ,防止跳转授权时丢失
        if (isset($target_url[1])) {
            $oauth_data['spa_param'] = urlencode($target_url[1]);
        }
        $url = Work::oauth($oauth_data);

        return redirect($url);
    }

    /**
     * 网页授权回调
     */
    public function oauthCallback()
    {
        $code = request()->param('code');
        $app = Work::getApp();
        // 获取 OAuth 授权结果用户信息
        $user = $app->oauth->userFromCode($code);

        $user_id = $user->getId();
        $user_raw = $user->getRaw();

        $user = $app->user->get($user_id);
        $openid = $app->user->userIdToOpenid($user_id);
        $user['openid'] = $openid['openid'];
        $user['nickname'] = $user['realname'] = $user['name'];
        if(empty($user['avatar'])){
            $user['avatar'] = '';
        }
        if(empty($user['sex'])){
            $user['sex'] = '';
        }

        try {
            //处理用户数据
            $MemberModel = new Member();
            $user['oauth_type'] = 'weixin_work';
            $user['shopid'] = $this->shopid;

            $user = $MemberModel->oauth($this->shopid, $user);
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
        $MemberModel->updateLogin($user['uid']);
        //生成token
        $token = JWTAuth::builder(['uid' => $user['uid']]);
        $token = 'Bearer ' . $token;
        //跳回原网页
        $target_url = input('param.target_url', '');
        $spa_param = input('param.spa_param', '');
        // 协议白名单：仅允许 http/https，其余回落本站首页
        if ($target_url !== '' && !preg_match('#^https?://#i', (string)$target_url)) {
            $target_url = request()->domain();
        }
        // JS 字符串转义（单引号/反斜杠），防止反射型 XSS 注入 script 上下文
        $target_url = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$target_url);
        $spa_param = str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$spa_param);

        $script = "window.location.href='{$target_url}#{$spa_param}'";
        echo save_local_storage('user_token', $token, $script);
        exit();
    }

    /**
     * 生成微信SDK
     * @return \think\Response|void
     */
    public function jssdk()
    {
        if (request()->isPost()) {
            $app = Work::getApp();
            $apis = input('post.apis');
            $url = input('post.url');
            if (empty($apis)) {
                $apis = [
                    'chooseWXPay',
                    'checkJsApi',
                    'scanQRCode',
                    'onMenuShareTimeline',
                    'onMenuShareAppMessage'
                ];
            }
            $app->jssdk->setUrl($url);
            $jssdk = $app->jssdk->buildConfig($apis);

            return $this->success('success', json_decode($jssdk, true));
        }
    }
}
