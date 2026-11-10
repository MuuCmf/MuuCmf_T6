<?php

declare(strict_types=1);

namespace app\ucenter\controller;

use app\common\model\MemberSync;
use think\facade\Db;
use think\facade\View;
use think\exception\ValidateException;
use app\common\model\Attachment;
use app\common\model\Verify;
use app\common\model\Member;
use app\common\model\ScoreType;
use app\common\model\ScoreLog;
use app\common\model\MemberAuthentication;
use app\common\validate\Authentication as AuthenticationValidate;

class Config extends Base
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    public function __construct()
    {
        parent::__construct();

        $user = query_user(get_uid());
        View::assign('user', $user);
    }

    /**
     * 用户中心
     */
    public function index()
    {
        //调用基本信息
        $user = query_user(is_login(), ['username', 'nickname', 'signature', 'email', 'mobile', 'avatar', 'sex', 'birthday']);

        //显示页面
        View::assign('user', $user);
        // 当前方法赋值变量
        View::assign('tab', 'base');
        $this->setTitle('基本信息');

        return View::fetch();
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
        $aTag = input('tag', '', 'text');
        $aTag = $aTag == 'mobile' ? 'mobile' : 'email';
        View::assign('cName', $aTag == 'mobile' ? '手机号' : '邮箱');
        View::assign('type', $aTag);

        return View::fetch();
    }

    /**
     * 绑定用户手机或邮箱(即将废弃)
     */
    public function account()
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
            $has_account = Db::name('Member')->where($has_map)->find();

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
            $sex = input('sex', 0, 'intval');
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
            if (!empty($sex) && $sex != 0) {
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

            $result = (new Member)->edit($data);
            if ($result) {
                return $this->success('修改成功');
            }
            return $this->error('网络异常，请稍后再试');
        }
    }

    /**
     * 绑定手机号
     */
    public function mobile()
    {
        $uid = get_uid();
        $mobile = input('post.mobile');
        $code = input('post.code');

        if (empty($mobile)) {
            return $this->error('请输入手机号');
        }
        if (empty($code)) {
            return $this->error('请输入验证码');
        }
        $verifyModel = new Verify();
        if (!$verifyModel->checkVerify($mobile, 'mobile', $code)) {
            return $this->error('验证码错误');
        }

        $memberModel = new Member;
        $has_bind = $memberModel->where('mobile', $mobile)->count();
        if ($has_bind > 0) {
            return $this->error('当前手机号已被他人绑定');
        }
        $data = ['uid' => $uid, 'mobile' => $mobile];
        $res = $memberModel->edit($data);
        if ($res) {
            return $this->success('绑定成功');
        }
        return $this->error('绑定失败');
    }

    /**
     * 绑定邮件
     */
    public function email()
    {
        $uid = get_uid();
        $email = input('post.email');
        $code = input('post.code');

        if (empty($email)) {
            return $this->error('请输入邮箱');
        }
        if (empty($code)) {
            return $this->error('请输入验证码');
        }
        $verifyModel = new Verify();
        if (!$verifyModel->checkVerify($email, 'email', $code)) {
            return $this->error('验证码错误');
        }
        $memberModel = new Member;

        $has_bind = $memberModel->where('email', $email)->count();
        if ($has_bind > 0) {
            return $this->error('当前邮箱已被他人绑定');
        }
        $data = ['uid' => $uid, 'email' => $email];
        $res = $memberModel->edit($data);
        if ($res) {
            return $this->success('绑定成功');
        }
        return $this->error('绑定失败');
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
            $commonMemberModel = new Member;
            $resCode = $commonMemberModel->changePassword($old_password, $new_password, $confirm_password);

            if ($resCode > 0) {
                return $this->success('密码修改成功');
            } else {
                return $this->error($commonMemberModel->error);
            }
        } else {
            $this->setTitle('密码修改');
            View::assign('tab', 'password');
            return View::fetch();
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

            $memberModel = new Member();
            if (!empty($avatar)) {
                $res = $memberModel->edit([
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
     * 我的积分
     * @return [type] [description]
     */
    public function score()
    {
        $scoreTypeModel = new ScoreType();
        // 用户积分类型列表
        $scores = $scoreTypeModel->getTypeList(['status' => 1]);
        foreach ($scores as &$v) {
            $v['value'] = $scoreTypeModel->getUserScore(is_login(), $v['id']);
        }
        unset($v);
        View::assign('scores', $scores);

        // 获取积分日志列表
        $scoreLogModel = new ScoreLog();
        $lists = $scoreLogModel->getListByPage([
            ['uid', '=', get_uid()],
        ], 'create_time desc', '*', 10);
        $pager = $lists->render();
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$v) {
            $type = $scoreTypeModel->getType(['id' => $v['type']])->toArray();
            $v['type'] = $type;
            if (!empty($v['create_time'])) {
                $v['create_time_str'] = time_format($v['create_time']);
                $v['create_time_friendly_str'] = friendly_date($v['create_time']);
            }
        }
        unset($v);
        View::assign('pager', $pager);
        View::assign('lists', $lists);

        // 设置页面TITLE
        $this->setTitle('我的积分');
        View::assign('tab', 'score');
        // 输出模板
        return View::fetch();
    }

    //积分规则
    public function scorerule()
    {
        View::assign('tab', 'scorerule');
        $this->setTitle('积分规则');
        return View::fetch();
    }

    /**
     * 积分等级
     */
    public function score_estate()
    {
        $scoreModel = new ScoreType();

        $scores = $scoreModel->getTypeList(['status' => 1]);
        foreach ($scores as &$v) {
            $v['value'] = $scoreModel->getUserScore(is_login(), $v['id']);
        }
        unset($v);
        View::assign('scores', $scores);

        $level = config('system.USER_LEVEL');
        View::assign('level', $level);

        View::assign('tab', 'score_estate');
        // 设置页面title
        $this->setTitle('积分等级');
        // 输出模板
        return View::fetch();
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
            // 同一开放平台账号（unionid）已绑定到其他会员时拒绝，避免一个微信对应多个账号
            $unionid = trim((string)($params['unionid'] ?? ''));
            if ($unionid !== '' && (new MemberSync())->where([
                ['unionid', '=', $unionid],
                ['uid', '<>', get_uid()],
            ])->count()) {
                return $this->error('当前微信已绑定了其他账号');
            }
            $data = [
                'uid'     => get_uid(),
                'openid'  => $params['openid'],
                'unionid' => $unionid,
                'type'    => 'weixin_h5'
            ];
            $res = (new MemberSync())->edit($data);
            if ($res) {
                return $this->success('绑定成功');
            }
            return $this->error('绑定失败，请稍后再试！');
        }

        $uid = get_uid();
        $self = query_user($uid, ['mobile', 'nickname', 'avatar', 'score']);
        View::assign('user', $self);
        //是否绑定微信
        $bind_map = [];
        $bind_map[] = ['uid', '=',  $uid];
        $bind_map[] = ['type', '=',  'weixin_h5'];
        $has_bind = (new MemberSync())->where($bind_map)->find();

        View::assign('has_bind', $has_bind);
        View::assign('tab', 'wechat');
        $this->setTitle('绑定微信');

        return View::fetch();
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

    /**
     * 处理用户身份认证请求
     *
     * 此方法用于处理用户提交的身份认证信息，包括姓名、证件类型、证件号码及相关图片。
     * 如果请求为 POST 方法，将验证输入并尝试保存认证信息。
     * 成功时返回成功消息，失败时返回错误消息。
     *
     * @return \think\response\View|\think\response\Json
     */
    public function authentication()
    {
        $MemberAuthentication = new MemberAuthentication();
        $uid = get_uid();

        if (request()->isPost()) {
            $name = input('name', '', 'trim');
            $card_type = input('card_type', 0, 'intval');
            $card_no = input('card_no', '', 'trim');
            $front = input('front', '', 'trim');
            $back = input('back', '', 'trim');

            $data = [
                'shopid'  => $this->shopid,
                'uid'     => get_uid(),
                'name'    => $name,
                'card_type' => $card_type,
                'card_no' => $card_no,
                'front' => $front,
                'back' => $back,
                'status' => 1, // 默认未审核状态
            ];

            // 查询是否已存在
            $map = [
                ['shopid', '=', $this->shopid],
                ['uid', '=', $uid],
            ];
            $authentication = $MemberAuthentication->getDataByMap($map);
            if (!empty($authentication)) {
                $data['id'] = $authentication['id'];
            }

            // 数据验证
            try {
                validate(AuthenticationValidate::class)->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            $res = $MemberAuthentication->edit($data);
            if ($res) {
                return $this->success('提交成功', $res, url('authentication'));
            }
            return $this->error('提交失败，请稍后再试！');
        }

        // 允许编辑参数
        $force = input('force', 0, 'intval');
        View::assign('force', $force);

        // 查询用户认证信息
        $data = $MemberAuthentication->where(['shopid' => $this->shopid, 'uid' => $uid])->find();
        if (!empty($data)) {
            $data = $MemberAuthentication->handle($data);
        }
        if(empty($data)){
            $data = [
                'status' => 0
            ];
        }
        View::assign('data', $data);
        // 证件类型
        $card_type = $MemberAuthentication->_card_type;
        View::assign('card_type', $card_type);

        View::assign('tab', 'authentication');
        $this->setTitle('实名认证');

        return View::fetch();
    }
}
