<?php

namespace app\api\controller;

use app\common\controller\Api;
use think\facade\Db;
use app\common\model\Member as CommonMember;
use app\common\model\MemberSync;
use think\exception\ValidateException;
use thans\jwt\facade\JWTAuth;
use app\common\model\Verify;
use app\common\model\Attachment;

/**
 * @title 用户接口类
 * @package app\api\controller
 */
class Member extends Api
{
    protected $CommonMember;

    protected $middleware = [
        'app\\common\\middleware\\CheckAuth'  => ['except' => [
            'register', 
            'login', 
            'mi', 
            'agreement', 
            'privacy', 
            'estate'
        ]], 
    ];

    function __construct()
    {
        parent::__construct();
        $this->CommonMember = new CommonMember();
    }

    /**
     * register  注册页面
     */
    public function register()
    {
        //提交注册
        if (request()->isPost()) {

            //获取参数
            $account = (string)input('post.account', '', 'text'); // 账号
            $password = (string)input('post.password', '', 'text'); // 密码
            $confirm_password = (string)input('post.confirm_password', '', 'text'); // 确认密码
            $verify = (string)input('post.verify', '', 'text'); // 邮件或手机验证码
            $captcha = input('post.captcha', '', 'text'); // 图形验证码
            $channel = input('post.channel', '', 'text'); // 注册渠道
            $agreement = input('post.agreement', 0, 'intval'); // 用户服务协议勾选状态
            $forward = input('forward', '/index/index/index', 'text'); // 来源页面

            //注册开关设置
            if (!config('system.USER_REG_SWITCH')) {
                return $this->error('注册功能临时关闭，请稍后访问！');
            }

            // 账号为空验证
            if (empty($account)) {
                return $this->error('账号不能为空');
            }

            // 行为限制验证
            // $ActionLimit = new ActionLimit();
            // $return = $ActionLimit->checkActionLimit('reg', 'member', 1, 1, true);
            // if ($return && !$return['code']) {
            //     return $this->error($return['msg'], $return['url']);
            // }

            //昵称注册开关
            if (config('system.USER_NICKNAME_SWITCH') == 0) {
                $nickname = rand_nickname(config('system.USER_NICKNAME_PREFIX'));
            } else {
                $nickname = input('post.nickname', '', 'text');
            }

            // 自动获取注册类型
            $type = check_account_type($account);
            // 判断注册类型是否启用
            if (check_reg_type($type) == false) {
                return $this->error('未启用的注册类型或输入格式错误！');
            }
            if ($type == 'email') {
                $email = $account;
                $username = '';
                $mobile = '';
            } elseif ($type == 'mobile') {
                $mobile = $account;
                $username = '';
                $email = '';
            } else {
                $username = $account;
                $mobile = '';
                $email = '';
            }
            
            // 验证
            try {
                validate(Member::class)->check([
                    'username'  => $username,
                    'email' => $email,
                    'mobile' => $mobile,
                    'password' => $password,
                    'confirm_password' => $confirm_password,
                ]);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            // 检测图形验证码
            if (check_verify_open('reg')) {
                if (!captcha_check($captcha)) {
                    return $this->error('图形验证码错误');
                }
            }

            // 验证验证码
            if (($type == 'mobile') || $type == 'email') {
                $verifyModel = new Verify();
                if (!$verifyModel->checkVerify($account, $type, $verify)) {
                    return $this->error('验证码错误');
                }
            }

            // 验证是否勾选了协议
            if (empty($agreement)) {
                return $this->error('请勾选用户服务协议');
            }

            /* 注册用户并写入数据 */
            $uid = $this->CommonMember->register($username, $nickname, $password, $email, $mobile, $channel);

            if (0 < $uid) {
                $token = JWTAuth::builder(['uid' => $uid]); //参数为用户认证的信息，请自行添加
                // 登录账号
                $this->CommonMember->login($this->shopid, $uid);
                // 返回成功
                return $this->success('恭喜您！注册成功。', $token, $forward);
            } else {
                //注册失败，显示错误信息
                return $this->error($this->CommonMember->getError());
            }
        }
    }

    /**
     *  登录 
     */
    public function login()
    {
        if (request()->isPost()) {
            //获取参数
            $account = input('post.account', '', 'text'); // 账号
            $password = input('post.password', '', 'text'); // 密码
            $verify = input('post.verify', '', 'text'); // 短信验证码
            $captcha = input('post.captcha', '', 'text'); // 图形验证码
            $login_type = input('post.login_type', 'password'); //登录类型
            $channel = input('post.channel', '', 'text'); //来源渠道
            $remember = (int)input('post.remember', 0, 'intval'); //是否记住登录状态

            if (empty($account)) return $this->error('账号不能为空');

            if ($login_type == 'password') {
                //密码登录
                if (empty($password)) return $this->error('密码不能为空');
                // 检测图形验证码
                if (check_verify_open('login')) {
                    if (!captcha_check($captcha)) {
                        return $this->error('图形验证码错误');
                    }
                }
                // 验证账号和密码
                $uid = $this->CommonMember->verifyUserPassword($account, $password);
                if ($uid == 0) return $this->error('用户被禁用，请联系管理员');
                if ($uid == -1) return $this->error('用户不存在，请联系管理员');
                if ($uid == -2) return $this->error('密码错误');
            } else {
                //验证码登录
                $uid = $this->CommonMember->verifyUserCaptcha($account, $verify);
                if ($uid == -2) return $this->error('验证码错误');
                //快捷登录，第一次登录的用户默认生成新用户
                if ($uid == -1) {
                    $type = check_account_type($account);
                    if ($type == 'email') {
                        $email = $account;
                        $mobile = '';
                    } else {
                        $email = '';
                        $mobile = $account;
                    }
                    $uid = $this->CommonMember->randMember('', '', '', $email, $mobile, $channel);
                }
            }
            
            //登录
            $res = $this->CommonMember->login($this->shopid, $uid, $remember);
            
            if ($res) {
                $token = JWTAuth::builder(['uid' => $uid]);
                $token = 'Bearer ' . $token;

                return $this->success('登录成功', ['token' => $token]);
            } else {
                return $this->error($this->CommonMember->getError());
            }
        }
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        $uid = is_login();
        if ($uid) {
            $commonMemberModel = new CommonMember;
            $commonMemberModel->logout($uid);
            // 使当前 JWT token 失效（加入黑名单），避免退出后旧 token 仍可被使用
            try {
                $token = JWTAuth::getToken();
                if ($token) {
                    JWTAuth::invalidate($token);
                }
            } catch (\Exception $e) {
                // token 已失效或不存在，忽略
            }
            return $this->success('退出成功', '', request()->domain());
        }

        return $this->error('发生错误');
    }

    /**
     * 用户密码找回
     */
    public function mi()
    {
        if (request()->isPost()) {
            $account = $username = input('post.account', '', 'text');
            $password = input('post.password', '', 'text');
            $confirm_password = input('post.confirm_password', '', 'text'); // 确认密码
            $verify = input('post.verify', 0, 'intval'); //验证码

            // 检查用户类型
            check_username($username, $email, $mobile, $type);
            // 验证
            try {
                validate(Member::class)->scene('mi')->check([
                    'email' => $email,
                    'mobile' => $mobile,
                    'password' => $password,
                    'confirm_password' => $confirm_password,
                ]);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            //检查验证码是否正确
            $verifyModel = new Verify();
            $ret = $verifyModel->checkVerify($account, $type, $verify, 0);
            if (!$ret) { //验证码错误
                return $this->error('验证码错误');
            }
            $resend_time =  config('extend.SMS_RESEND');
            if (time() > session('verify_time') + $resend_time) { //验证超时
                return $this->error('验证码超时');
            }

            //获取用户UID
            switch ($type) {
                case 'mobile':
                    $uid = Db::name('Member')->where(['mobile' => $account])->value('uid');
                    break;
                case 'email':
                    $uid = Db::name('Member')->where(['email' => $account])->value('uid');
                    break;
            }
            if (!$uid) {
                return $this->error('用户不存在，请确认输入的信息正确！');
            }
            //设置新密码
            $password = user_md5($password, config('auth.auth_key'));
            $data['uid'] = $uid;
            $data['password'] = $password;
            $ret = Db::name('Member')->update($data);
            if ($ret) {
                //返回数据
                return $this->success('操作成功，密码已重置');
            } else {
                return $this->error('操作失败');
            }
        }
    }

    /**
     * 用户注销功能
     * 通过用户ID执行注销操作，更新用户状态为-1
     *
     * @return mixed 返回操作结果，成功返回成功信息，失败返回错误信息
     */
    public function logoff()
    {
        $uid = get_uid();
        if (!$uid) {
            return $this->error('需要登录', 'login');
        }

        $this->CommonMember->logout($uid);

        $res = $this->CommonMember->where(['uid' => $uid])->update(['status' => -1]);
        if (!$res) {
            return $this->error('注销失败');
        }

        return $this->success('注销成功', '', request()->domain());
    }

    /**
     * 用户服务协议
     */
    public function agreement()
    {
        $agreement = config('system.USER_REG_AGREEMENT') ?? '管理员未设置，敬请期待';
        
        return $this->success('success', $agreement);
    }

    /**
     * 用户隐私条款
     */
    public function privacy()
    {
        $privacy = config('system.USER_PRIVACY') ?? '管理员未设置，敬请期待';
        
        return $this->success('success', $privacy);
    }

    /**
     * @title 获取用户信息
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function userInfo()
    {
        $uid = get_uid();
        //查询用户信息
        $user = query_user($uid, ['uid', 'nickname', 'avatar', 'email', 'mobile', 'realname', 'sex', 'qq', 'score', 'birthday', 'signature']);
        if (is_array($user) && !empty($user)) {
            //格式化生日
            $birthday = strtotime($user['birthday']);
            $birthday = $birthday > 0 ? $birthday : time();
            $user['birthday'] = date('Y年m月d日', $birthday);

            return $this->success('success', $user);
        }
        return $this->error('没有查询到用户数据');
    }

    /**
     * 绑定用户手机或邮箱
     */
    public function bind()
    {
        if (request()->isPost()) {
            $account = input('account', '', 'text'); //账号
            $type = input('type', '', 'text'); //账号类型
            $verify = input('verify', '', 'text');
            $type_str = $type == 'mobile' ? '手机号' : 'email';

            if (empty($account)) {
                return $this->error($type_str . '不能为空');
            }

            if (empty($verify)) {
                return $this->error('验证码不能为空');
            }

            if (!in_array($type, ['mobile', 'email'])) {
                return $this->error('账号类型错误');
            }

            // 验证手机号码唯一性
            $has_map = [
                ['shopid', '=', $this->shopid],
            ];
            if ($type == 'mobile') {
                $has_map[] = ['mobile', '=', $account];
            }
            if ($type == 'email') {
                $has_map[] = ['email', '=', $account];
            }
            $has_account = $this->CommonMember->where($has_map)->find();

            if ($has_account) {
                return $this->error($type_str . '已绑定其他用户');
            }

            // 验证验证码
            if (($type == 'mobile') || $type == 'email') {
                $verifyModel = new Verify();
                if (!$verifyModel->checkVerify($account, $type, $verify)) {
                    return $this->error('验证码错误');
                }
            }
            if ($type == 'mobile') {
                $data = [
                    'mobile' => $account,
                ];
            }
            if ($type == 'email') {
                $data = [
                    'email' => $account,
                ];
            }

            $res = Db::name('Member')->where(['uid' => get_uid()])->update($data);
            if ($res) {
                return $this->success('保存成功');
            } else {
                return $this->error('保存失败');
            }
        }
    }

    /**
     *@title 修改用户信息
     */
    public function edit()
    {
        if (request()->isPost()) {
            $uid = get_uid();
            $avatar = input('avatar', '', 'text');
            $nickname = input('nickname', '', 'text');
            $sex = input('sex', '', 'intval');
            $birthday = input('birthday', '', 'text');
            $signature = input('signature', '', 'text');

            $data['uid'] = $uid;

            if (!empty($avatar)) {
                $data['avatar'] = $avatar;
            }
            if (!empty($nickname)) {
                // 过滤掉emoji表情符号
                $nickname = filter_emoji($nickname);
                $data['nickname'] = $nickname;
            }
            
            if (isset($sex)) {
                $data['sex'] = $sex;
            }
            
            if (!empty($birthday)) {
                $birthday_format = date_parse_from_format('Y年m月d日', $birthday);
                $birthday = mktime(0, 0, 0, $birthday_format['month'], $birthday_format['day'], $birthday_format['year']);
                $birthday = date('Y-m-d', $birthday);
                $data['birthday'] = $birthday;
            }

            if (!empty($signature)) {
                $data['signature'] = $signature;
            }

            $result = $this->CommonMember->edit($data);
            if ($result) {
                return $this->success('修改成功');
            }
            return $this->error('网络异常，请稍后再试');
        }

        return $this->error('请求方式错误');
    }

    /**
     * 修改密码
     * @return [type] [description]
     */
    public function password()
    {
        if (request()->isPost()) {
            $old_password = input('post.old_password', '', 'text');
            $new_password = input('post.new_password', '', 'text');
            $confirm_password = input('post.confirm_password', '', 'text');
            //调用接口

            $resCode = $this->CommonMember->changePassword($old_password, $new_password, $confirm_password);

            if ($resCode > 0) {
                return $this->success('密码修改成功');
            } else {
                return $this->error($this->CommonMember->error);
            }
        }
    }

    /**
     * saveAvatar  保存头像
     */
    public function avatar()
    {
        if (request()->isPost()) {
            $crop = input('post.crop', '', 'text');
            $uid = is_login();
            $path = input('post.path', '', 'text');
            $avatar = input('post.avatar', '', 'text');
            
            if (!empty($avatar)) {
                $res = $this->CommonMember->edit([
                    'uid' => $uid,
                    'avatar' => $avatar
                ]);
            } else {
                if (empty($crop)) {
                    return $this->error('参数错误');
                }

                // 裁切图片
                $Attachment = new Attachment();
                $path = $Attachment->cropImage($path, $crop);

                //更新数据库数据
                $data = [
                    'avatar' => $path,
                ];
                $res = Db::name('Member')->where(['uid' => $uid])->update($data);
            }

            if ($res) {
                return $this->success('保存成功');
            } else {
                return $this->error('保存失败');
            }
        }
    }

    /**
     * 绑定微信账号
     */
    public function wechat()
    {
        if (request()->isPost()) {
            //绑定用户信息
            $params = input('param.');
            //是否绑定过其他账号
            $bind_map = [];
            $bind_map[] = ['openid', '=', $params['openid']];
            $bind_map[] = ['type', '=', 'weixin_h5'];
            // 查询是否已绑定
            $has_bind = boolval((new MemberSync())->where($bind_map)->count());
            if ($has_bind) return $this->error('当前微信已绑定了其他账号');
            $data = [
                'uid'     => get_uid(),
                'openid'  => $params['openid'],
                'unionid' => $params['unionid'] ?? '',
                'type'    => 'weixin_h5'
            ];
            $res = (new MemberSync())->edit($data);
            if ($res) {
                return $this->success('绑定成功');
            }
            return $this->error('绑定失败，请稍后再试！');
        }
    }

    /**
     * 解除微信用户绑定
     */
    public function unbind()
    {
        $map[] = ['uid', '=', get_uid()];
        $map[] = ['type', '=',  'weixin_h5'];
        $res = (new MemberSync())->where($map)->delete();

        if ($res) {
            return $this->success('解除绑定成功');
        }
        return $this->error('解除绑定失败，请稍后再试！');
    }
}