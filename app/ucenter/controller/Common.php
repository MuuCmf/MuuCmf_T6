<?php

namespace app\ucenter\controller;

use think\facade\Db;
use think\facade\View;
use think\facade\Request;
use app\common\model\Member as CommonMember;
use app\ucenter\validate\Member;
use think\exception\ValidateException;
use thans\jwt\facade\JWTAuth;
use app\common\model\Verify;

/**
 * 用户登录及注册
 */
class Common extends Base
{
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        //框架版本号
        View::assign('version', $this->version());
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
            $commonMemberModel = new CommonMember;
            $uid = $commonMemberModel->register($username, $nickname, $password, $email, $mobile, $channel);

            if (0 < $uid) {
                $token = JWTAuth::builder(['uid' => $uid]); //参数为用户认证的信息，请自行添加
                // 登录账号
                $commonMemberModel->login($this->shopid, $uid);
                // 返回成功
                return $this->success('恭喜您！注册成功。', $token, $forward);
            } else {
                //注册失败，显示错误信息
                return $this->error($commonMemberModel->getError());
            }
        } else {
            // 注册类型开关
            $regSwitch = config('system.USER_REG_SWITCH');
            if (!empty($regSwitch)) {
                $regSwitch = explode(',', $regSwitch);
            }
            View::assign('regSwitch', $regSwitch);
            // 昵称开关
            $nicknameSwitch = config('system.USER_NICKNAME_SWITCH');
            View::assign('nicknameSwitch', $nicknameSwitch);

            $this->setTitle('注册');
            return View::fetch();
        }
    }

    /**
     *  登录 
     */
    public function login()
    {
        // 获取返回页面路径
        $last_url = session('login_http_referer');
        if (empty($last_url) || $last_url == Request::url(true)) {
            $last_url = Request::domain(true);
        }

        if (request()->isPost()) {
            //获取参数
            $account = input('post.account', '', 'text'); // 账号
            $password = input('post.password', '', 'text'); // 密码
            $verify = input('post.verify', '', 'text'); // 短信验证码
            $captcha = input('post.captcha', '', 'text'); // 图形验证码
            $login_type = input('post.login_type', 'password'); //登录类型
            $channel = input('post.channel', '', 'text'); //来源渠道
            $remember = (int)input('post.remember', 0, 'intval');

            if (empty($account)) return $this->error('账号不能为空');
            $commonMemberModel = new CommonMember;

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
                $uid = $commonMemberModel->verifyUserPassword($account, $password);
                if (is_string($uid)) return $this->error($uid); // 行为限制等错误消息
                if ($uid == 0) return $this->error('用户被禁用，请联系管理员');
                if ($uid == -1) return $this->error('用户不存在，请联系管理员');
                if ($uid == -2) return $this->error('密码错误');
                if ($uid == -3) return $this->error('该账号已被删除，请重新注册');
            } else {
                //验证码登录
                $uid = $commonMemberModel->verifyUserCaptcha($account, $verify);
                if (is_string($uid)) return $this->error($uid); // 行为限制等错误消息
                if ($uid == 0) return $this->error('用户被禁用，请联系管理员');
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
                    $uid = $commonMemberModel->randMember('', '', '', $email, $mobile, $channel);
                }
            }
            
            //登录
            $res = $commonMemberModel->login($this->shopid, $uid, $remember);
            
            if ($res) {
                $token = JWTAuth::builder(['uid' => $uid]);
                $token = 'Bearer ' . $token;

                return $this->success('登录成功', ['token' => $token], $last_url);
            } else {
                return $this->error($commonMemberModel->getError());
            }
        } else {
            if(is_login()){
                return redirect($last_url);
            }
            // 允许的登录类型
            $ph_account = [];
            check_login_type('username') && $ph_account[] = '用户名';
            check_login_type('email') && $ph_account[] = '邮箱';
            check_login_type('mobile') && $ph_account[] = '手机';
            View::assign([
                'ph_account' => implode('/', $ph_account)
            ]);

            return View::fetch();
        }
    }

    /**
     * 快捷登陆
     */
    public function quickLogin()
    {
        // 允许的登录类型
        $ph_account = [];
        check_login_type('username') && $ph_account[] = '用户名';
        check_login_type('email') && $ph_account[] = '邮箱';
        check_login_type('mobile') && $ph_account[] = '手机';
        View::assign([
            'ph_account' => implode('/', $ph_account)
        ]);

        // 输出页面
        return View::fetch();
    }

    /**
     * 退出登录
     */
    public function logout()
    {
        if (is_login()) {
            $commonMemberModel = new CommonMember;
            $commonMemberModel->logout(is_login());
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
                return $this->success('操作成功，密码已重置', '', url('ucenter/Common/login'));
            } else {
                return $this->error('操作失败');
            }
        } else {
            $this->setTitle('重置密码');
            return View::fetch();
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
        $commonMemberModel = new CommonMember;
        $commonMemberModel->logout($uid);

        $res = $commonMemberModel->where(['uid' => $uid])->update(['status' => -1]);
        if (!$res) {
            return $this->error('注销失败');
        }

        return $this->success('注销成功', '', request()->domain());
    }

    /**
     * 验证昵称是否符合要求
     */
    public function checkNickname()
    {
        $aNickname = input('post.nickname', '', 'text');

        if (empty($aNickname)) {
            return $this->error('昵称不能为空！');
        }

        $length = mb_strlen($aNickname, 'utf-8'); // 当前数据长度
        if ($length < config('system.USER_NICKNAME_MIN_LENGTH') || $length > config('system.USER_NICKNAME_MAX_LENGTH')) {
            return $this->error('昵称长度在' . config('system.USER_NICKNAME_MIN_LENGTH') . '-' . config('system.USER_NICKNAME_MAX_LENGTH') . '之间');
        }

        $memberModel = new CommonMember;
        $uid = $memberModel->where(['nickname' => $aNickname])->value('uid');
        if ($uid) {
            return $this->error('该昵称已经存在');
        }
        preg_match('/^(?!_|\s\')[A-Za-z0-9_\x80-\xff\s\']+$/', $aNickname, $result);

        if (!$result) {
            return $this->error('只允许中文、字母和数字和下划线');
        }

        return $this->success('验证成功');
    }

    /**
     * 用户服务协议显示页
     */
    public function agreement()
    {
        $agreement = config('system.USER_REG_AGREEMENT') ?? '管理员未设置，敬请期待';
        View::assign('agreement', $agreement);
        return View::fetch();
    }

    /**
     * 用户隐私条款显示页
     */
    public function privacy()
    {
        $privacy = config('system.USER_PRIVACY') ?? '管理员未设置，敬请期待';
        View::assign('privacy', $privacy);
        return View::fetch();
    }

    // protected function setTitle($title)
    // {
    //     View::assign('title', $title);
    // }
}
