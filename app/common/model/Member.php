<?php

namespace app\common\model;

use think\Exception;
use think\facade\Db;
use think\facade\Config;
use app\common\model\AuthGroup;
use app\common\model\ActionLog;
use app\common\model\ScoreType;
use app\common\logic\Channel;
use app\common\model\MemberAuthentication;

/**
 * 会员模型
 */
class Member extends Base
{
    protected $autoWriteTimestamp = true;

    //自动完成
    protected $insert = ['reg_ip'];
    protected $update = ['update_time'];
    public $_status  = [
        1  => '启用',
        0  => '禁用',
        -1 => '删除',
    ];
    public $_authentication = [
        -1 => '审核未通过',
        0  => '未认证',
        1  => '待审核',
        2  => '已认证',
    ];
    /**
     * 编辑/新增数据
     *
     * @param      <type>  $data   The data
     * @return     <type>  ( description_of_the_return_value )
     */
    public function edit($data)
    {
        if (!empty($data['uid'])) {
            $res = $this->update($data, ['uid' => $data['uid']]);
            return $data['uid'];
        } else {
            // 初始密码
            $data['password'] = user_md5('123456', Config::get('auth.auth_key'));
            $res = $this->save($data);
        }

        if (!empty($this->uid)) {
            return $this->uid;
        }

        return $res;
    }

    /**
     * 注册一个新用户
     * @param  string $username 用户名
     * @param  string $nickname 昵称
     * @param  string $password 用户密码
     * @param  string $email 用户邮箱
     * @param  string $mobile 用户手机号码
     * @return integer 注册成功-用户信息，注册失败-错误编号
     */
    public function register($username = '', $nickname = '', $password = '', $email = '', $mobile = '', $channel = '')
    {
        $data = [
            'username' => $username,
            'password' => user_md5($password, Config::get('auth.auth_key')),
            'email' => $email,
            'mobile' => $mobile,
            'nickname' => $nickname,
            'sex' => 0,
            'status' => 1,
            'reg_ip' => request()->ip(),
            'reg_channel' => $channel
        ];

        /* 添加用户 */
        if ($res = $this->save($data)) {
            if (!$res) {
                return false;
            } else {
                $uid = $this->id;
                //写行为日志
                $actionLog = new ActionLog();
                $actionLog->add('reg', 'member', 1, $uid);
                //将用户添加到用户组
                (new AuthGroup())->addToGroup($uid, 1);
                return $uid;
            }
        } else {
            return -1;
        }
    }

    /**
     * 验证账号和密码是否正确
     * @param  string  $account 账号
     * @param  string  $password 用户密码
     * @return integer           登录成功-用户ID，登录失败-错误编号
     */
    public function verifyUserPassword($account, $password)
    {
        $type = check_account_type($account);
        $map = [];
        switch ($type) {
            case 'username':
                $map['username'] = $account;
                break;
            case 'email':
                $map['email'] = $account;
                break;
            case 'mobile':
                $map['mobile'] = $account;
                break;
            case 'uid':
                $map['uid'] = $account;
                break;
            default:
                return 0; //参数错误
        }
        // 获取用户数据
        $user = $this->where($map)->find();
        if ($user) {
            if ($user['status'] == 1) {
                // 行为限制
                $actionLimit = new ActionLimit();
                $return = $actionLimit->checkActionLimit('input_password', 'member', $user['uid'], $user['uid']);
                if ($return && !$return['code']) {
                    return $return['msg'];
                }

                if ($user['uid'] && $user['status'] == 1) {
                    /* 验证用户密码 */
                    if (user_md5($password, Config::get('auth.auth_key')) === $user['password']) {
                        return $user['uid']; //返回用户ID
                    } else {
                        $actionLog = new ActionLog();
                        $actionLog->add('input_password', 'member', $user['uid'], $user['uid']);
                        return -2; //密码错误
                    }
                }
            }
            // 用户被禁用
            if ($user['status'] == 0) {
                return 0;
            }
            // 用户被删除
            if ($user['status'] == -1) {
                return -1;
            }
        }

        return -1; //用户不存在或被禁用
    }

    /**
     * 验证账号和验证码是否正确
     * @param  string  $account 账号
     * @param  string  $captcha 验证码
     * @return integer           登录成功-用户ID，登录失败-错误编号
     */
    public function verifyUserCaptcha($account, $captcha)
    {
        $type = check_account_type($account);
        $map = [];
        switch ($type) {
            case 'username':
                $map['username'] = $account;
                break;
            case 'email':
                $map['email'] = $account;
                break;
            case 'mobile':
                $map['mobile'] = $account;
                break;
            case 'uid':
                $map['uid'] = $account;
                break;
            default:
                return 0; //参数错误
        }
        // 获取用户数据
        $user = $this->where($map)->find();
        $verifyModel = new Verify();
        if ($user) {
            // 行为限制
            $actionLimit = new ActionLimit();
            $return = $actionLimit->checkActionLimit('input_password', 'member', $user['uid'], $user['uid']);

            if ($return && !$return['code']) {
                return $return['msg'];
            }

            if ($user['uid'] && $user['status']) {
                /* 验证用户验证码 */
                if (!$verifyModel->checkVerify($account, $type, $captcha)) {
                    return -2;
                } else {
                    return $user['uid']; //返回用户ID
                }
            }
        } else {
            if (!$verifyModel->checkVerify($account, $type, $captcha)) {
                return -2;
            }
        }
        return -1; //用户不存在或被禁用
    }

    /**
     * 登录指定用户
     * @param  integer $uid 用户ID
     * @return boolean      ture-登录成功，false-登录失败
     */
    public function login(int $shopid, int $uid, int $remember = 0)
    {
        $user = null;
        if ($uid) {
            /* 检测是否在当前应用注册 */
            $user = $this->where([
                ['shopid', '=', $shopid],
                ['uid', '=', $uid]
            ])->find();
        }

        if (!$user || $user['status'] !== 1) {
            $this->error = '用户不存在或已禁用';
            return false;
        }

        //更新登录信息
        $this->updateLogin($uid);

        /* 记录登录SESSION和COOKIES */
        $auth = [
            'uid' => $user['uid'],
            'username' => $user['username'],
            'last_login_time' => $user['last_login_time'],
        ];

        session('user_auth', $auth);
        session('user_auth_sign', data_auth_sign($auth));

        //记录行为
        action_log('user_login', 'member', $uid, $uid);

        //记住登录
        if ($remember == 1) {
            $token = Db::name('user_token')->where('uid', $uid)->find();

            if (empty($token)) {
                $data_token['uid'] = $uid;
                $token_unique = create_unique();
                $data_token['token'] = $token_unique;
                $data_token['create_time'] = time();
                $data_token['expire_time'] = time() + 3600 * 24 * 7;
                Db::name('user_token')->insert($data_token);
            } else {
                $token_unique = $token['token'];
                Db::name('user_token')->update([
                    'id' => $token['id'],
                    'create_time' => time(),
                    'expire_time' => time() + 3600 * 24 * 7,
                ]);
            }

            // 记录cookie
            if (!$this->getCookieUid()) {
                $expire = 3600 * 24 * 7;
                cookie('MUU_LOGGED_USER', think_encrypt("{$uid}.{$token_unique}", 'muucmf', $expire));
            }

            // 清除所有过期的token
            Db::name('user_token')->where('expire_time', '<', time())->delete();
        }

        return true;
    }

    /**
     * 获取cookie记录的用户ID
     */
    public function getCookieUid()
    {
        static $cookie_uid = null;
        if (isset($cookie_uid) && $cookie_uid !== null) {
            return $cookie_uid;
        } else {
            $cookie = cookie('MUU_LOGGED_USER');
            if (!empty($cookie)) {
                $cookie = explode(".", think_decrypt($cookie, 'muucmf'));
                if (!empty($cookie[0]) && !empty($cookie[1])) {
                    $map = [
                        ['uid', '=', $cookie[0]]
                    ];
                    $user = Db::name('user_token')->where($map)->find();
                    $cookie_uid = ($cookie[1] != $user['token']) ? null : $cookie[0];
                    $cookie_uid = time() > $user['expire_time'] ? null : $cookie_uid; //过期时间7天
                }
            }
        }

        return $cookie_uid;
    }

    /**
     * 记住登陆状态
     * @return [type] [description]
     */
    public function rembemberLogin(int $shopid = 0)
    {
        if (!is_login()) {
            //判断COOKIE
            $uid = $this->getCookieUid();
            if ($uid) {
                $this->login($shopid, $uid);
                return $uid;
            }
        }

        return false;
    }

    /**
     * 退出登录
     */
    public function logout(int $uid)
    {
        session(null);
        cookie('MUU_LOGGED_USER', NULL);

        return true;
    }

    /**
     * 获取用户信息
     * @param  string  $uid 用户ID或用户名
     * @param  boolean $is_username 是否使用用户名查询
     * @return array                用户信息
     */
    public function info($uid, $fields)
    {
        if (!empty($uid)) {

            if (!empty($fields) && $fields != '*') {
                if (!is_array($fields)) {
                    $fields_arr = explode(',', $fields);
                } else {
                    $fields_arr = $fields;
                }
                if (!in_array('uid', $fields_arr)) {
                    array_push($fields_arr, 'uid');
                }

                if (!in_array('status', $fields_arr)) {
                    array_push($fields_arr, 'status');
                }

                if (strpos($fields, 'score') !== false) {
                    // 用户积分
                    $field = (new ScoreType())->getTypeList([['status', '=', 1]]);
                    foreach ($field as $vf) {
                        array_push($fields_arr, 'score' . $vf['id']);
                    }
                }

                // 排除不存在的字段
                $prefix = config('database.connections.mysql.prefix');
                $columns = Db::query('show COLUMNS FROM ' . $prefix . 'member');
                $columns = array_column($columns, 'Field');
                foreach ($fields_arr as $k => $v) {
                    if (!in_array($v, $columns)) unset($fields_arr[$k]);
                }

                // 转回字符串
                $new_fields = implode(',', $fields_arr);
            } else {
                $fields = $new_fields = '*';
            }

            // 查询用户数组
            $map['uid'] = $uid;
            $member = $this->where($map)->field($new_fields)->find();
            if ($member) {
                $member = $member->toArray();
            }

            if (is_array($member)) {

                if ($fields == '*' || strpos($fields, 'avatar') !== false) {
                    // 头像
                    if (empty($member['avatar'])) {
                        $member['avatar'] = $member['avatar64'] = $member['avatar128'] = $member['avatar256'] = $member['avatar512'] = request()->domain() . '/static/common/images/default_avatar.jpg';
                    } else {
                        $member['avatar64'] = get_thumb_image($member['avatar'], 64, 64);
                        $member['avatar128'] = get_thumb_image($member['avatar'], 128, 128);
                        $member['avatar256'] = get_thumb_image($member['avatar'], 256, 256);
                        $member['avatar512'] = get_thumb_image($member['avatar'], 512, 512);
                    }
                }

                if ($fields == '*' || strpos($fields, 'sex') !== false) {
                    // 性别
                    if ($member['sex'] == 0) {
                        $member['sex_str'] = '保密';
                    }
                    if ($member['sex'] == 1) {
                        $member['sex_str'] = '男';
                    }
                    if ($member['sex'] == 2) {
                        $member['sex_str'] = '女';
                    }
                }

                if ($fields == '*' || strpos($fields, 'create_time') !== false) {
                    // 注册时间
                    $member['create_time_str'] = time_format($member['create_time']);
                    $member['create_time_friendly_str'] = friendly_date($member['create_time']);
                }

                if ($fields == '*' || strpos($fields, 'last_login_time') !== false) {
                    // 注册时间
                    $member['last_login_time_str'] = time_format($member['last_login_time']);
                    $member['last_login_time_friendly_str'] = friendly_date($member['last_login_time']);
                }

                if ($fields == '*' || strpos($fields, 'score') !== false) {
                    // 用户积分
                    $field = (new ScoreType())->getTypeList([['status', '=', 1]]);
                    $score_key = [];
                    foreach ($field as $vf) {
                        if (isset($member['score' . $vf['id']])) {
                            $vf['value'] = $member['score' . $vf['id']];
                            $score_key[] = $vf;
                        }
                    }
                    $member['score'] = $score_key;
                }

                if (($fields == '*' || strpos($fields, 'reg_channel') !== false) && isset($member['reg_channel'])) {
                    // 用户注册渠道
                    $member['reg_channel_str'] = Channel::$_channel[$member['reg_channel']];
                }

                //获取权限组
                $auth_g_id = Db::name('auth_group_access')->where(['uid' => $uid])->select()->toArray();
                foreach ($auth_g_id as $k => $val) {
                    $auth_group = Db::name('auth_group')->where(['id' => $val['group_id']])->value('title');
                    $member['auth_group'][$k]['id'] = $val['group_id'];
                    $member['auth_group'][$k]['title'] = $auth_group;
                }
                unset($val);

                //实名认证状态
                $member['authentication_text'] = '未认证';
                $member['authentication'] = 0;
                $authModel = new MemberAuthentication();
                $authInfo = $authModel->where('uid', $member['uid'])->find();

                if ($authInfo) {
                    $member['authentication'] = $authInfo['status'];
                    $member['authentication_text'] = $authModel->_status[$authInfo['status']] ?? '未认证';
                }

                //用户状态
                if (isset($member['status'])) {
                    $member['status_text'] = $this->_status[$member['status']];
                }

                // 扩展资料
                try {
                    $field_group = Db::name('field_group')->where('status', '=', 1)->select();
                    $fields_list = [];
                    if (!empty($field_group) && !empty($member)) {
                        $field_group = $field_group->toArray();
                        $field_group_ids = array_column($field_group, 'id');

                        $map_profile[] = ['group_id', 'in', $field_group_ids];
                        $map_profile[] = ['status', '=', 1];
                        $fields_list = Db::name('field_setting')->where($map_profile)->field('id,field_name,field_alias,sort,form_type')->select();
                        if (!empty($fields_list)) {
                            $fields_list = $fields_list->toArray();
                        }
                        $fields_list = array_combine(array_column($fields_list, 'field_name'), $fields_list);

                        $map_field['uid'] = $member['uid'];
                        // 初始化用户扩展字段
                        $expend = [];
                        foreach ($fields_list as $key => $val) {
                            $map_field['field_id'] = $val['id'];
                            $field_data = Db::name('field')->where($map_field)->value('field_data');
                            $temp_arr['name'] = $val['field_name'];
                            $temp_arr['alias'] = $val['field_alias'];
                            if (empty($field_data)) {
                                $expend[$key] = '';
                                $temp_arr['data'] = '';
                            } else {
                                $expend[$key] = $field_data;
                                $temp_arr['data'] = $field_data;
                            }
                            $expend[$key] = $temp_arr;
                        }
                        $member['expend'] = $expend;
                    }
                } catch (Exception $e) {
                    //todo:老版本由于表字段不完整，这里特殊处理下
                }

                // 获取钱包数据
                $wallet = (new MemberWallet())->getWallet($uid);
                $member['wallet'] = $wallet;

                //是否绑定微信
                $bind_map = [];
                $bind_map[] = ['uid', '=',  $uid];
                $bind_map[] = ['type', '=',  'weixin_h5'];
                $has_bind = (new MemberSync())->where($bind_map)->field('id,uid,openid,unionid,type')->find();
                $member['wechat'] = $has_bind;

                return $member;
            } else {
                return -1; //用户不存在或已删除
            }
        } else {
            return false;
        }
    }

    /**
     * 更新用户登录信息
     * @param  integer $uid 用户ID
     */
    public function updateLogin(int $uid)
    {
        $user = $this->where('uid', '=', $uid)->find()->toArray();

        $data = [
            'login' => $user['login'] + 1,
            'last_login_time' => time(),
            'last_login_ip' => request()->ip(),
        ];

        $res = $this->where('uid', $uid)->save($data);

        return $res;
    }

    /**修改密码
     * @param $old_password
     * @param $new_password
     * @return bool
     */
    public function changePassword($old_password, $new_password, $confirm_password)
    {
        //检查旧密码是否正确
        if (!$this->verifyUser(get_uid(), $old_password)) {
            //'旧密码错误';
            $this->error = '旧密码错误';
            return false;
        }

        $data = [
            'password' => $new_password,
            'confirm_password' => $confirm_password,
        ];
        //验证密码
        $validate = new \app\ucenter\validate\Member;
        $result = $validate->scene('password')->check($data);
        if (false === $result) {
            $this->error = $validate->getError();
            return false;
        }
        //移除数组中无用值
        unset($data['confirm_password']);

        //密码数据加密
        $password = user_md5($new_password, Config::get('auth.auth_key'));
        $data['password'] = $password;
        //更新用户信息
        $res = $this->where('uid', get_uid())->save($data);
        if ($res) {
            //返回成功信息
            return true;
        } else {
            $this->error = '密码修改失败';
            return false;
        }
    }

    /**
     * 获取用户名
     */
    public function getUsername(int $uid)
    {
        //调用接口获取用户信息
        $username = $this->where('uid', $uid)->value('username');

        return $username;
    }

    /**
     * 获取昵称
     */
    public function getNickname(int $uid)
    {
        //调用接口获取用户信息
        $nickname = $this->where('uid', $uid)->value('nickname');

        return $nickname;
    }

    /**
     * 验证昵称
     * @param $nickname
     */
    public function checkNickname($nickname, $uid)
    {
        $length = mb_strlen($nickname, 'utf8');
        try {
            if ($length == 0) {
                throw new Exception('请输入昵称');
            } else if ($length > Config::get('system.NICKNAME_MAX_LENGTH', 32)) {
                throw new Exception('昵称不能超过' . Config::get('system.NICKNAME_MAX_LENGTH', 32) . '个字');
            } else if ($length < Config::get('system.NICKNAME_MIN_LENGTH', 2)) {
                throw new Exception('昵称不能少于' . Config::get('system.NICKNAME_MIN_LENGTH', 2) . '个字');
            }

            $pattern = '/^(?!_)(?!.*?_$)[\x{4e00}-\x{9fa5}a-zA-Z0-9_-]+$/u';
            $match = preg_match($pattern, $nickname);
            if (!$match) {
                throw new Exception('昵称只允许中文、字母、数字、下划线和中横线');
            }
            //验证唯一性
            $map_nickname[] = ['nickname', '=', $nickname];
            $map_nickname[] = ['uid', '<>', $uid];
            $map_nickname[] = ['status', 'in', [0, 1]];
            $had_nickname = $this->where($map_nickname)->count();
            if ($had_nickname > 0) {
                throw new Exception('昵称已被人使用');
            }

            //保留昵称
            if ($uid != 1) {
                $denyName = Config::get('system.USER_NAME_BAOLIU');
                if (!empty($denyName)) {
                    $denyName = explode(',', $denyName);
                    foreach ($denyName as $val) {
                        if (!is_bool(strpos($nickname, $val))) {
                            throw new Exception('该昵称已被禁用');
                        }
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 验证用户名
     */
    public function checkUsername($username, $uid)
    {
        try {
            //如果用户名中有空格，不允许注册
            if (strpos($username, ' ') !== false) {
                throw new Exception('用户名格式错误');
            }
            //验证唯一性
            $map[] = ['username', '=', $username];
            $map[] = ['uid', '<>', $uid];
            $has = $this->where($map)->count();
            if ($has) {
                throw new Exception('用户名已被占用');
            }

            if ($uid != 1) {
                $denyName = Config::get('system.USER_NAME_BAOLIU');
                if ($denyName != '') {
                    $denyName = explode(',', $denyName);
                    foreach ($denyName as $val) {
                        if (!is_bool(strpos($username, $val))) {
                            throw new Exception('该用户名已被禁用');
                        }
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function checkEmail($email, $uid)
    {
        try {
            if (!empty($email)) {
                if (!preg_match("/^([0-9A-Za-z\\-_\\.]+)@([0-9a-z]+\\.[a-z]{2,3}(\\.[a-z]{2})?)$/i", $email)) {
                    throw new Exception('请正确填写邮箱！');
                }

                //验证唯一性
                $map[] = ['email', '=', $email];
                $map[] = ['uid', '<>', $uid];
                $has = $this->where($map)->count();
                if ($has) {
                    throw new Exception('邮箱已被占用');
                }
            }

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function checkMobile($mobile, $uid)
    {
        try {
            if (!empty($mobile)) {
                if (!preg_match("/^\d{11}$/", $mobile)) {
                    throw new Exception('请正确填写手机号！');
                }

                //验证唯一性
                $map[] = ['mobile', '=', $mobile];
                $map[] = ['uid', '<>', $uid];
                $has = $this->where($map)->count();
                if ($has) {
                    throw new Exception('手机号已被占用');
                }
            }

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * 验证用户密码
     * @param int    $uid 用户id
     * @param string $password_in 密码
     * @return true 验证成功，false 验证失败
     * @author huajie <banhuajie@163.com>
     */
    public function verifyUser($uid, $password_in)
    {
        $password = $this->where('uid', $uid)->value('password');
        if (user_md5($password_in, Config::get('auth.auth_key')) === $password) {
            return true;
        }
        return false;
    }

    /**
     * 第三方平台授权登录
     */
    public function oauth(int $shopid, array $data)
    {
        $syncModel = new MemberSync();
        //是否已有授权信息
        $sync = $syncModel->where([
            ['shopid', '=', $shopid],
            ['openid', '=', $data['openid']]
        ])->find();
        // 初始化授权记录id
        $sync_id = 0;
        if ($sync) {
            $sync_id = $sync['id'];
            $uid = $sync['uid'];
            $has_member = $this->where([
                ['uid', '=', $uid],
                ['status', 'in', [0, 1]]
            ])->find();

            if ($has_member) {
                return $has_member;
            }
        }

        //是否已有开放平台相同的账户
        if (!empty($data['unionid'])) {
            $has_union = $syncModel->where([
                ['shopid', '=', $shopid],
                ['unionid', '=', $data['unionid']],
            ])->find();
            if ($has_union) $has_union = $has_union->toArray();
        }
        //初始UID
        $uid = 0;

        // 过滤掉emoji表情符号
        $nickname = filter_emoji($data['nickname']);
        // 验证昵称
        $match = preg_match('/^(?!_|\s\')[A-Za-z0-9_|\x80-\xff\s\']+$/', $nickname);
        if (!$match) {
            $nickname = rand_nickname(Config::get('system.USER_NICKNAME_PREFIX'));
        }
        $member_data = [
            'shopid'    => $data['shopid'],
            'nickname'  => $nickname,
            'username'  => rand_username(''),
            'password'  => user_md5('123456', Config::get('auth.auth_key')),
            'avatar'    => $data['avatar'],
            'sex'       => $data['sex'],
            'status'    => 1,
            'reg_ip' => request()->ip(),
            'reg_channel' => $data['oauth_type']
        ];
        //写入会员表
        $result = $this->save($member_data);
        if (!$result) {
            throw new Exception('存入用户信息失败');
        }
        //将用户添加到用户组
        (new AuthGroup())->addToGroup($this->id, 1);
        $uid = $this->id;

        $sync_data = [
            'id'        => $sync_id,
            'uid'       => $uid,
            'openid'    => $data['openid'],
            'type'      => $data['oauth_type']
        ];
        if (!empty($data['unionid'])) {
            $sync_data['unionid'] = $data['unionid'];
        }

        //存入授权记录
        $sync_result = $syncModel->edit($sync_data);
        if (!$sync_result) {
            throw new Exception('存入用户授权记录失败');
        }
        $actionLog = new ActionLog();
        $actionLog->add('reg', 'member', 1, $uid);

        return $this->where('uid', $uid)->find();
    }

    /**
     * 更新用户余额 或积分
     * @param $uid
     * @param $field
     * @param $num
     * @param int $type
     * @return Member|bool
     */
    public static function updateAmount($uid, $field, $num, $type = 1)
    {
        $value = Member::where('uid', $uid)->value($field);
        //加法
        if ($type == 1) {
            $value = bcadd($value, $num, 2);
        } else {
            $value = bcsub($value, $num, 2);
        }
        $result = Member::where('uid', $uid)->update([
            $field => $value
        ]);
        if ($result !== false) {
            $result = true;
        }
        return $result;
    }

    /**
     * @title 生成一个用户
     * @param string $username
     * @param string $nickname
     * @param string $password
     * @param string $mobile
     * @param string $email
     * @return bool|int
     */
    public function randMember($username = '', $nickname = '', $password = '', $email = '', $mobile = '', $channel = '')
    {
        //昵称注册开关
        if (config('system.USER_NICKNAME_SWITCH') == 0 || empty($nickname)) {
            $nickname = rand_nickname(config('system.USER_NICKNAME_PREFIX'));
        }
        //用户名称
        $username = $username ?: rand_username('用户');
        $password = $password ?: 123456;
        $email = $email ?: '';
        return $this->register($username, $nickname, $password, $email, $mobile, $channel);
    }
}
