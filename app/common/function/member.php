<?php

use app\common\model\AuthRule;
use think\facade\Db;
use muucmf\Auth;
use app\common\model\Member;
use app\common\model\MemberSync;
use thans\jwt\exception\JWTException;
use thans\jwt\facade\JWTAuth;


if (!function_exists('is_login')) {
    /**
     * 检测用户是否登录
     * @return integer 0-未登录，大于0-当前登录用户ID
     * @author 大蒙 <59262424@qq.com>
     */
    function is_login()
    {
        $header = request()->header();
        $uid = 0;
        if (isset($header['authorization'])) {
            $token = JWTAuth::getToken();
            if (!empty($token)) {
                try {
                    $payload = JWTAuth::decode($token);
                    $uid = $payload['uid'];
                } catch (JWTException $exception) {
                    // 如果捕获到此异常，即代表 refresh 也过期了，用户无法刷新令牌，需要重新登录。
                    $uid = 0;
                }
            }
        }

        if(!empty($uid)){
            return $uid;
        }

        $user = session('user_auth');
        if (empty($user)) {
            return 0;
        } else {
            return session('user_auth_sign') == data_auth_sign($user) ? $user['uid'] : 0;
        }
    }
}

if (!function_exists('get_uid')) {
    /**
     * 获取当前登录用户的 UID
     * 
     * @return int|bool 返回用户ID,未登录返回false
     */
    function get_uid()
    {
        return is_login();
    }
}

if (!function_exists('get_openid')) {
    /**
     * 获取用户第三方平台的openid
     * @param int $shopid 店铺ID
     * @param int $uid 用户ID
     * @param string $channel 第三方平台类型,默认为weixin_h5
     * @return string|null 返回openid,未找到返回null
     */
    function get_openid($shopid, $uid, $channel = 'weixin_h5')
    {
        $model = new MemberSync();
        $map = [
            ['shopid', '=', $shopid],
            ['uid', '=', $uid],
            ['type', '=', $channel]
        ];
        $openid = $model->where($map)->value('openid');
        return $openid;
    }
}

if (!function_exists('query_user')) {
    /**
     * 查询用户信息
     * @param int $uid 用户ID，默认为0
     * @param array $field_arr 需要查询的字段数组，为空时查询所有字段
     * @return array 返回用户信息数组
     */
    function query_user($uid = 0, $field_arr = [])
    {
        // 请求级内存缓存：同一请求内相同 uid+字段组合只查询一次，避免 N+1（如问答列表逐条查询用户信息）
        static $user_cache = [];

        if (empty($field_arr)) {
            $field = "*";
        }
        if (is_array($field_arr)) {
            $field = implode(',', $field_arr);
        }
        $cache_key = $uid . '_' . $field;
        if (array_key_exists($cache_key, $user_cache)) {
            return $user_cache[$cache_key];
        }

        // 获取用户数据
        $memberModel = new Member;
        $auth_user = $memberModel->info($uid, $field);
        $user_cache[$cache_key] = $auth_user;

        return $auth_user;
    }
}

if (!function_exists('get_username')) {
    /**
     * 根据用户ID获取用户名
     * 
     * @param int $uid 用户ID，默认为0
     * @return string 返回用户名
     * 
     * @author MuuCmf
     * @since T6
     */
    function get_username($uid = 0)
    {
        $member = new Member();
        return $member->getUsername($uid);
    }
}

if (!function_exists('get_nickname')) {
    /**
     * 获取用户昵称
     * @param int $uid 用户ID，默认为0
     * @return string 返回用户昵称
     */
    function get_nickname($uid = 0)
    {
        $member = new Member();
        return $member->getNickname($uid);
    }
}

if (!function_exists('get_auth_user')) {
    /**
     * 获取指定权限规则下的所有用户ID
     * 
     * @param string $rule 权限规则名称
     * @return array 返回用户ID数组
     * 
     * 该函数通过权限规则名称查找对应的规则ID，然后遍历所有权限组，
     * 找出包含该规则的权限组，获取这些权限组中的所有用户ID。
     * 最后合并ID为1的超级管理员，并去重返回。
     */
    function get_auth_user($rule = '')
    {
        $rule = Db::name('AuthRule')->where('name', $rule)->find();
        $groups = Db::name('AuthGroup')->select();
        $uids = array();
        foreach ($groups as $v) {
            $auth_rule = explode(',', $v['rules']);
            if (in_array($rule['id'], $auth_rule)) {
                $gid = $v['id'];
                $temp_uids = (array) Db::name('AuthGroupAccess')->where(['group_id' => $gid])->column('uid');
                if ($temp_uids !== null) {
                    $uids = array_merge($uids, $temp_uids);
                }
            }
        }
        $uids = array_merge($uids, 1);
        $uids = array_unique($uids);

        return $uids;
    }
}

if (!function_exists('check_account_type')) {
    /**
     * 检测账号类型
     * @param  [type] $account [description]
     * @return [type]          [description]
     */
    function check_account_type($account = '')
    {
        $check_email = preg_match("/[a-z0-9_\-\.]+@([a-z0-9_\-]+?\.)+[a-z]{2,3}/i", $account);
        // 这个宽松验证可以覆盖全球绝大多数国家的手机号格式
        $check_mobile = preg_match("/^\d{6,15}$/", $account);
        if ($check_email) {
            $type = 'email';
        } elseif ($check_mobile) {
            $type = 'mobile';
        } else {
            $type = 'username';
        }

        return $type;
    }
}

if (!function_exists('check_username')) {
    /**
     * check_username  根据type或用户名来判断注册使用的是用户名、邮箱或者手机
     * @param $username
     * @param $email
     * @param $mobile
     * @param int $type
     * @return bool
     */
    function check_username(&$username, &$email, &$mobile, &$type = 'username')
    {
        if ($type) {
            switch ($type) {
                case 'email':
                    $email = $username;
                    $username = '';
                    $mobile = '';
                    $type = 'email';
                    break;
                case 'mobile':
                    $mobile = $username;
                    $username = '';
                    $email = '';
                    $type = 'mobile';
                    break;
                default:
                    $mobile = '';
                    $email = '';
                    $username = $username;
                    $type = 'username';
                    break;
            }
        } else {
            $check_email = preg_match("/[a-z0-9_\-\.]+@([a-z0-9_\-]+?\.)+[a-z]{2,3}/i", $username);
            // 这个宽松验证可以覆盖全球绝大多数国家的手机号格式
            $check_mobile = preg_match("/^\d{6,15}$/", $username);
            if ($check_email) {
                $email = $username;
                $username = '';
                $mobile = '';
                $type = 'email';
            } elseif ($check_mobile) {
                $mobile = $username;
                $username = '';
                $email = '';
                $type = 'mobile';
            } else {
                $mobile = '';
                $email = '';
                $type = 'username';
            }
        }
        return true;
    }
}

if (!function_exists('check_reg_type')) {
    /**
     * 验证注册格式是否开启
     * @param $type
     * @return bool
     */
    function check_reg_type($type)
    {
        $t[1] = $t['username'] = 'username';
        $t[2] = $t['email'] = 'email';
        $t[3] = $t['mobile'] = 'mobile';

        $switch = config('system.USER_REG_SWITCH');
        if ($switch) {
            $switch = explode(',', $switch);
            if (in_array($t[$type], $switch)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('check_login_type')) {
    /**
     * 检查登录类型是否允许使用
     * @param int|string $type 登录类型(1/username:用户名, 2/email:邮箱, 3/mobile:手机, 4/qrcode:二维码)
     * @return bool 如果该登录类型被允许则返回true,否则返回false
     * 
     * 通过系统配置的USER_LOGIN_SWITCH判断指定的登录类型是否启用
     */
    function check_login_type($type)
    {
        $t[1] = $t['username'] = 'username';
        $t[2] = $t['email'] = 'email';
        $t[3] = $t['mobile'] = 'mobile';
        $t[4] = $t['qrcode'] = 'qrcode';

        $switch = config('system.USER_LOGIN_SWITCH');
        if ($switch) {
            $switch = explode(',', $switch);
            if (in_array($t[$type], $switch)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('user_md5')) {
    /**
     * 用户密码加密函数
     * 
     * 对用户密码进行双重加密,先使用 sha1 加密,再与密钥组合后进行 md5 加密
     * 
     * @param string $str 需要加密的字符串
     * @param string $key 加密密钥
     * @return string 返回加密后的字符串,如果输入为空则返回空字符串
     */
    function user_md5($str, $key = '')
    {
        return '' === $str ? '' : md5(sha1($str) . $key);
    }
}

if (!function_exists('check_verify_open')) {
    /**
     * 检查验证码开启状态
     * @param string $open 验证码场景
     * @return bool 是否开启验证码
     * 
     * 根据系统配置检查指定场景是否需要开启验证码
     * 配置格式为逗号分隔的验证码场景列表
     */
    function check_verify_open($open)
    {
        $config = config('system.VERIFY_OPEN');

        if ($config) {
            $config = explode(',', $config);
            if (in_array($open, $config)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('rand_username')) {
    /**
     * 生成随机用户名
     * @param string $prefix 用户名前缀,可选
     * @return string 返回生成的随机用户名
     * 
     * 如果提供前缀,生成格式为"前缀_随机字符串"
     * 如果未提供前缀,直接生成随机字符串作为用户名
     * 会检查生成的用户名是否已存在,如存在则递归重新生成
     */
    function rand_username($prefix)
    {
        if (empty($prefix)) {
            $username = create_rand(10);
        } else {
            $username = $prefix . '_' . create_rand(10);
        }

        if (Db::name('member')->where(['username' => $username])->find()) {
            rand_username($prefix);
        } else {
            return $username;
        }
    }
}

if (!function_exists('rand_nickname')) {
    /**
     * 生成随机昵称
     * @param string $prefix 昵称前缀
     * @return string 返回生成的随机昵称
     * 
     * 如果提供前缀,生成格式为"前缀_随机字符串"
     * 如果未提供前缀,直接生成随机字符串
     * 会检查生成的昵称是否已存在,如存在则递归重新生成
     */
    function rand_nickname($prefix)
    {
        if (empty($prefix)) {
            $nickname = create_rand(8);
        } else {
            $nickname = $prefix . '_' . create_rand(8);
        }

        if (Db::name('member')->where(['nickname' => $nickname])->find()) {
            rand_nickname($prefix);
        } else {
            return $nickname;
        }
    }
}

if (!function_exists('rand_email')) {
    /**
     * 生成随机邮箱地址
     * 生成一个10位随机字符串加上@muucmf.cn的邮箱地址
     * 如果生成的邮箱已存在于数据库中,则递归重新生成
     * 
     * @return string 返回生成的随机邮箱地址
     */
    function rand_email()
    {
        $email = create_rand(10) . '@muucmf.cn';
        if (Db::name('member')->where('email', $email)->count() > 0) {
            return rand_email();
        } else {
            return $email;
        }
    }
}

if (!function_exists('check_auth')) {
    /**
     * 检查用户权限
     * @param string $rule 检查的规则，为空时以当前URL为准
     * @param int|string $except_uid 不受限的用户ID，支持多个用逗号分隔
     * @param int $type 权限规则类型
     * @return bool 是否有权限
     * 
     * 权限检查函数:
     * 1. 超级管理员拥有所有权限
     * 2. 支持设置不受限用户
     * 3. 根据规则名称检查权限
     * 4. 使用Auth类进行权限认证
     */
    function check_auth($rule = '', $except_uid = -1, $type = AuthRule::RULE_URL)
    {
        if (is_login() == 1) {
            return true; //管理员允许访问任何页面
        }
        if ($except_uid != -1) {
            if (!is_array($except_uid)) {
                $except_uid = explode(',', $except_uid);
            }
            if (in_array(is_login(), $except_uid)) {
                return true;
            }
        }
        $rule = empty($rule) ? strtolower(app('http')->getName()) . '/' . strtolower(request()->controller()) . '/' . strtolower(request()->action()) : $rule;
        // 检测是否有该权限
        if (!Db::name('auth_rule')->where(['name' => $rule, 'status' => 1])->find()) {
            return false;
        }

        static $Auth = null;
        if (!$Auth) {
            $Auth = new Auth();
        }

        if (!$Auth->check($rule, is_login(), $type)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('get_my_score')) {
    /**
     * 获取当前登录用户的指定积分值
     * @param string $score_name 积分类型名称，默认为'score1'
     * @return int 返回用户的积分值
     */
    function get_my_score($score_name = 'score1')
    {
        $user = query_user(is_login(), array($score_name));
        $score = $user[$score_name];

        return $score;
    }
}
