<?php

namespace app\admin\lib;

use think\facade\Cache;
use think\exception\ValidateException;

class Cloud
{
    public $api;
    function __construct()
    {
        $this->api = config('cloud.api');
    }

    /**
     * 授权请求
     */
    public function recordRequest()
    {
        $domain = request()->host();
        $ip   = request()->ip();
        $url = $this->api . 'request/record';
        $result = curl_request($url, [
            'domain'  =>  $domain,
            'ip' =>  $ip,
            'channel' => 'T6'
        ]);
        try {
            $result = json_decode($result, true);
            if (is_array($result) && $result['code'] == 0) {
                return $result;
            }
        } catch (ValidateException $e) {
            return false;
        }

        return true;
    }

    /**
     * 查询应用授权
     * app_name 应用唯一标识
     */
    public function needAuthorization($app_name)
    {
        $cacheKey = request()->host() . '_MUUCMF_CLOUD_AUTH_APP_' . $app_name;
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = $this->api . 'authorization/app';
        $result = curl_request($url, [
            'app_name'  =>  $app_name,
            'auth_code' =>  self::authCode(),
            'frame' => 't6',
        ]);
        try {
            $result = json_decode($result, true);
            if (is_array($result) && $result['code'] == 0) {
                // 未授权：缓存 5 分钟（期间持续拦截）
                Cache::set($cacheKey, $result, 300);
                return $result;
            }
        } catch (ValidateException $e) {
            // 云端异常：fail-closed 降级，短缓存 1 分钟避免雪崩
            Cache::set($cacheKey, false, 60);
            return false;
        }

        // 已授权 / 云端返回非预期（fail-open 放行）：短缓存 1 分钟避免频繁打云端
        Cache::set($cacheKey, true, 60);
        return true;
    }

    /**
     * @title 生成授权码
     * @return string
     */
    public static function authCode()
    {
        $web_domain = request()->host();
        $web_host   = request()->ip();
        $lock_str   = $web_domain . '|' . $web_host;
        $code = Cache::get(request()->host() . '_MUUCMF_CLOUD_AUTH');
        if (!$code || $code['time'] < time()) {
            $code = self::encrypt_code($lock_str, 6000, 'muu');
            Cache::set(request()->host() . '_MUUCMF_CLOUD_AUTH', ['time' => time() + 60 * 30, 'code' => $code]);
        } else {
            $code = $code['code'];
        }
        return $code;
    }

    /**
     * 加密
     * @param string $string     要加密或解密的字符串
     * @param string $key        密钥，加密解密时保持一致
     * @param int    $expiry 有效时长，单位：秒
     * @return string
     */
    protected static function encrypt_code($string, $expiry = 0, $key = '1234567890')
    {
        $ckey_length = 1;
        $key = md5($key ? $key : 'MUUCMF'); //加密解密时这个是不变的
        $keya = md5(substr($key, 0, 16)); //加密解密时这个是不变的
        $keyb = md5(substr($key, 16, 16)); //加密解密时这个是不变的
        $keyc = $ckey_length ?  substr(md5(microtime()), -$ckey_length) : '';
        $cryptkey = $keya . md5($keya . $keyc); //64
        $key_length = strlen($cryptkey); //64

        $string = sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
        $string_length = strlen($string);

        $result = '';
        $box = range(0, 255);

        $rndkey = array();
        for ($i = 0; $i <= 255; $i++) { //字母表 64位后重复 数列 范围为48~122
            $rndkey[$i] = ord($cryptkey[$i % $key_length]);
        }

        for ($j = $i = 0; $i < 256; $i++) { //这里是一个打乱算法
            $j = ($j + $box[$i] + $rndkey[$i]) % 256;
            $tmp = $box[$i];
            $box[$i] = $box[$j];
            $box[$j] = $tmp;
        }
        for ($a = $j = $i = 0; $i < $string_length; $i++) {
            $result .= chr(ord($string[$i]) ^ ($box[$i]));
        }
        $str =  $keyc . str_replace('=', '', base64_encode($result));
        //  $str =htmlentities($str, ENT_QUOTES, "UTF-8"); // curl 访问出错
        return $str;
    }
}
