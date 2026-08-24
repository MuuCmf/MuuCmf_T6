<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Member;
use app\common\model\MemberSync;
use thans\jwt\facade\JWTAuth;
use app\common\facade\wechat\MiniProgram as MiniProgramServer;
use app\common\model\Tominiprogram as TominiprogramModel;
use app\common\logic\Tominiprogram as TominiprogramLogic;
use \app\common\model\WechatMpConfig as WechatMpConfigModel;

/**
 * 微信小程序
 * Class MiniProgram
 */
class WechatMiniProgram extends Api
{
    protected MemberSync $MemberSyncModel;
    protected Member $MemberModel;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['only' => ['bindMobile']],
    ];
    function __construct()
    {
        parent::__construct();
        //初始化用户平台标识模型
        $this->MemberSyncModel = new MemberSync();
        //初始化用户模型
        $this->MemberModel = new Member();
    }

    /**
     * 获取微信小程序配置信息
     * 
     * 根据当前店铺ID获取微信小程序配置，并过滤敏感信息后返回
     * 
     * @return \think\Response 返回处理结果，成功时包含配置信息，失败时返回错误提示
     * @throws \think\Exception 当未配置微信小程序时抛出异常
     */
    public function config()
    {
        // 获取配置
        $mp_config = (new WechatMpConfigModel())->getWechatMpConfigByShopId($this->shopid);
        if (empty($mp_config)) {
            return $this->error('没有配置微信小程序');
        }

        $mp_config = $mp_config->toArray();

        unset($mp_config['secret']);
        return $this->success('success', $mp_config);
    }

    /**
     * code 换取用户信息
     * @param $code
     */
    public function code()
    {
        $code = input('code', '', 'trim');
        if (empty($code)) {
            return $this->error('code不能为空');
        }

        $result = MiniProgramServer::user($code);
        if (!isset($result['openid'])) {
            return $this->error($result['errmsg']);
        }
        //查询是否注册过
        $map = [];
        $map[] = ['openid', '=', $result['openid']];
        $map[] = ['type', '=', 'weixin_mp'];
        $user = $this->MemberSyncModel->getDataByMap($map);
        if (!empty($user)) {
            $user = query_user($user['uid'], ['uid', 'nickname', 'avatar', 'email', 'mobile', 'realname', 'sex', 'score']);
            if (is_array($user)) {
                $this->MemberModel->updateLogin($user['uid']);
                $token = JWTAuth::builder(['uid' => $user['uid']]);
                $token = 'Bearer ' . $token;
                $res = [
                    'token'     => $token
                ];
                return $this->success('success', $res);
            } else {
                return $this->error('error', '用户已禁用或删除');
            }
        } else {
            return $this->error('error', '没有查询到用户信息');
        }
    }

    /**
     * 小程序授权登录
     */
    public function login()
    {
        $params = input('param.');
        $oauth = MiniProgramServer::user($params['code']);
        if (!isset($oauth['openid'])) {
            return $this->error($oauth['errmsg']);
        }
        //查询是否注册过
        $map[] = ['openid', '=', $oauth['openid']];
        $map[] = ['type', '=', 'weixin_mp'];
        $user = $this->MemberSyncModel->getDataByMap($map);
        // 已登录过
        if (!empty($user)) {
            $user = query_user($user['uid'], ['uid', 'nickname', 'avatar', 'email', 'mobile', 'realname', 'sex', 'score']);
            $this->MemberModel->updateLogin($user['uid']);
        } else {
            // 未登录过，创建用户
            $result = MiniProgramServer::decryptData($oauth['session_key'], $params['iv'], $params['encrypted_data']);
            $nickname = $result['nickName'];
            if ($nickname == '微信用户') {
                $nickname = rand_nickname(config('system.USER_NICKNAME_PREFIX'));
            }
            $data = [
                'unionid'   => $oauth['unionid'] ?? '',
                'openid'    => $oauth['openid'],
                'nickname'  => $nickname,
                'avatar'    => $result['avatarUrl'],
                'sex'       => $result['gender'],
                'shopid'    => $params['shopid'],
                'oauth_type' => 'weixin_mp'
            ];
            $user = $this->MemberModel->oauth($this->shopid, $data);
        }

        if ($user) {
            $this->MemberModel->updateLogin($user['uid']);
            $token = JWTAuth::builder(['uid' => $user['uid']]);
            $token = 'Bearer ' . $token;
            return $this->success('success', ['token' => $token]);
        }

        return $this->error('需要登录', 'login');
    }

    /**
     * 获取小程序码：适用于需要的码数量极多，或仅临时使用的业务场景
     * @return mixed
     */
    public function unlimitQrcode()
    {
        //小程序路径
        $path = input('param.path');
        //二维码url参数
        $scene = input('param.scene', '');
        $width = input('param.width', '500');
        $option = [
            'page' => $path,
            'width' => $width
        ];
        $result = MiniProgramServer::unlimitQrcode($scene, $option);
        // 发生错误时返回数组
        if (is_array($result)) {
            return $this->error('error', $result);
        }
        Header("Content-type: image/jpeg"); //直接输出显示jpg格式图片
        echo $result;
    }

    /**
     * 绑定手机号
     */
    public function bindMobile()
    {
        $uid = request()->uid;
        $code = input('code');
        $iv = input('iv');
        $encrypted = input('encrypted');
        $code_decode = MiniProgramServer::user($code);
        $session_key = $code_decode['session_key'];
        $data = MiniProgramServer::decryptData($session_key, $iv, $encrypted);
        //保存手机号
        $res = $this->MemberModel->edit([
            'uid' => $uid,
            'mobile' => $data['phoneNumber']
        ]);

        if ($res) {
            return $this->success('绑定手机号成功');
        }
        return $this->error('绑定手机号失败');
    }

    /**
     * 跳转小程序列表
     */
    public function toMiniProgramLists()
    {
        $TominiprogramModel = new TominiprogramModel();
        $TominiprogramLogic = new TominiprogramLogic();
        $rows = input('rows', 10, 'intval');
        $map = [
            ['shopid', '=', $this->shopid],
            ['type', '=', 'weixin_app']
        ];
        // 获取列表
        $lists = $TominiprogramModel->getListByPage($map, 'id DESC', '*', $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $TominiprogramLogic->formatData($val);
        }
        unset($val);

        return $this->success('SUCCESS', $lists);
    }

    /**
     * 跳转小程序数据详情
     */
    public function toMiniProgramDetail()
    {
        $id = input('id', 0, 'intval');
        if (!empty($id)) {
            $TominiprogramModel = new TominiprogramModel();
            $TominiprogramLogic = new TominiprogramLogic();
            $data = $TominiprogramModel->getDataById($id);
            $data = $TominiprogramLogic->formatData($data);

            return $this->success('SUCCESS', $data);
        }

        return $this->error('参数错误');
    }
}
