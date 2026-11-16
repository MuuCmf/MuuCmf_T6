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
        $post = [
            'domain'  =>  $domain,
            'ip' =>  $ip,
            'channel' => 'T6'
        ];
        // S1：已激活则叠加签名参数，避免关闭 legacy 后请求记录被拒
        try {
            $post = array_merge($post, CloudSignature::payload('request.record'));
        } catch (\Throwable $e) {
            trace('cloud signature payload exception: ' . $e->getMessage(), 'error');
        }
        $result = curl_request($url, $post);
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
     *
     * S0-C 宽限期状态机：云端可达时沿用原判定并清空宽限状态；云端不可达时按
     * cloud.grace_hours 放行，超过宽限期返回 end_auth（fail-closed）。
     * 缓存键追加激活态指纹，S1 激活后自动区分不同密钥实例。
     *
     * @param string $app_name 应用唯一标识
     * @return array|bool
     */
    public function needAuthorization($app_name)
    {
        $cacheKey = request()->host() . '_MUUCMF_CLOUD_AUTH_APP_' . $app_name
            . '_' . substr(hash('sha256', (string)$this->activationFingerprint()), 0, 12);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $url = $this->api . 'authorization/app';
        $raw = '';
        $post = [
            'app_name'  =>  $app_name,
            'auth_code' =>  self::authCode(),
            'frame' => 't6',
        ];
        // S1：已激活则叠加签名参数；未激活时签名参数为空，保持 legacy 单通道
        try {
            $post = array_merge($post, CloudSignature::payload('authorization.app', (string) $app_name));
        } catch (\Throwable $e) {
            trace('cloud signature payload exception: ' . $e->getMessage(), 'error');
        }
        try {
            $raw = curl_request($url, $post);
        } catch (ValidateException $e) {
            trace('cloud auth request validate exception: ' . $e->getMessage(), 'error');
        } catch (\Throwable $e) {
            trace('cloud auth request exception: ' . $e->getMessage(), 'error');
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $verdict = $this->classifyCloudResponse($decoded);

        // 云端不可达或非预期响应：走持久化三态宽限，不再静默 fail-open
        if ($verdict === 'unreachable') {
            return $this->graceFallback($cacheKey);
        }

        // 云端可达：清空断网计数；deny 立即拒绝，不受宽限保护
        $this->clearAuthState();
        if ($verdict === 'deny') {
            Cache::set($cacheKey, $decoded, 60);
            return $decoded;
        }
        Cache::set($cacheKey, true, 60);
        return true;
    }

    /**
     * 云端响应三分类（纯函数，供验收脚本直接断言）
     * @param mixed $decoded
     * @return string allow|deny|unreachable
     */
    public function classifyCloudResponse($decoded): string
    {
        if (!is_array($decoded) || !array_key_exists('code', $decoded)) {
            return 'unreachable';
        }
        return (int)$decoded['code'] === 0 ? 'deny' : 'allow';
    }

    /**
     * 宽限期三态判定（纯函数，不读写文件，便于验收脚本注入时间）
     *
     * @param array $state      上一次状态
     * @param int   $now        当前时间戳
     * @param int   $graceHours 宽限小时数，0 表示严格模式
     * @return array{allow:bool,state:array}
     */
    public function graceDecision(array $state, int $now, int $graceHours): array
    {
        $normalized = [
            'first_fail_at' => (int)($state['first_fail_at'] ?? 0),
            'last_fail_at'  => (int)($state['last_fail_at'] ?? 0),
            'fail_count'    => (int)($state['fail_count'] ?? 0),
        ];
        if ($normalized['first_fail_at'] <= 0 || $normalized['first_fail_at'] > $now) {
            $normalized['first_fail_at'] = $now;
        }
        $normalized['last_fail_at'] = $now;
        $normalized['fail_count']++;

        $grace = $graceHours * 3600;
        $allow = $grace > 0 && ($now - $normalized['first_fail_at']) <= $grace;
        return ['allow' => $allow, 'state' => $normalized];
    }

    /**
     * 云端不可达时的宽限处理：状态持久化到 data/cloud_auth_state.json
     * @param string $cacheKey
     * @return array|bool
     */
    private function graceFallback(string $cacheKey)
    {
        $decision = $this->graceDecision($this->authState(), time(), (int)config('cloud.grace_hours', 72));
        $this->saveAuthState($decision['state']);

        if ($decision['allow']) {
            trace('cloud auth unreachable, within grace: ' . json_encode($decision['state']), 'error');
            Cache::set($cacheKey, true, 60);
            return true;
        }

        $denied = [
            'code' => 0,
            'msg'  => '授权校验服务不可达，已超过宽限期',
            'data' => 'end_auth',
        ];
        trace('cloud auth unreachable, grace exceeded: ' . json_encode($decision['state']), 'error');
        Cache::set($cacheKey, $denied, 60);
        return $denied;
    }

    /**
     * 状态文件路径（与 public/ 同级，非 docroot）
     * 多节点部署需放共享卷，或改写为 Redis key cloud_auth_state_<host>。
     */
    protected function authStateFile(): string
    {
        return root_path() . 'data/cloud_auth_state.json';
    }

    /**
     * 读取状态文件：缺失、损坏时返回零值，下次写入自动修复
     */
    private function authState(): array
    {
        $file = $this->authStateFile();
        $raw = is_file($file) ? (string)@file_get_contents($file) : '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['first_fail_at' => 0, 'last_fail_at' => 0, 'fail_count' => 0];
        }
        return [
            'first_fail_at' => (int)($data['first_fail_at'] ?? 0),
            'last_fail_at'  => (int)($data['last_fail_at'] ?? 0),
            'fail_count'    => (int)($data['fail_count'] ?? 0),
        ];
    }

    /**
     * .tmp -> rename 原子落盘，避免读到半截 JSON；只读 0640
     */
    private function saveAuthState(array $state): void
    {
        $file = $this->authStateFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($state), LOCK_EX) === false) {
            trace('cloud auth state write failed: ' . $tmp, 'error');
            return;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            trace('cloud auth state rename failed: ' . $file, 'error');
        }
    }

    /**
     * 云端恢复可达时清零（仅文件已存在时写，减少无谓 IO）
     * 清 runtime 缓存不会重置该计数。
     */
    private function clearAuthState(): void
    {
        if (!is_file($this->authStateFile())) {
            return;
        }
        $this->saveAuthState(['first_fail_at' => 0, 'last_fail_at' => 0, 'fail_count' => 0]);
    }

    /**
     * 激活态指纹：激活页落盘密钥后返回密钥指纹，用于隔离激活前后的缓存判定
     */
    protected function activationFingerprint(): string
    {
        return CloudSignature::fingerprint();
    }

    /**
     * 上报应用安装/升级记录
     * 上报失败仅记录日志，不阻断安装/升级主流程
     * @param string $app_name 应用唯一标识
     * @param string $action install|upgrade
     * @param string $from_version 升级前版本，安装时为空
     * @param string $to_version 目标版本
     * @param string $release_type 发行类型 release|opensource
     * @return bool
     */
    public function reportAppRecord($app_name, $action, $from_version, $to_version, $release_type = 'release')
    {
        if (empty($app_name) || empty($to_version) || !in_array($action, ['install', 'upgrade'], true)) {
            return false;
        }

        try {
            $url = $this->api . 'app/report';
            $post = [
                'auth_code'    => self::authCode(),
                'app_name'     => $app_name,
                'frame'        => 't6',
                'action'       => $action,
                'from_version' => (string)$from_version,
                'to_version'   => (string)$to_version,
                'release_type' => $release_type,
            ];
            // S1：签名参数与 auth_code 双通道并存，滚动发版期间互不影响
            $post = array_merge($post, CloudSignature::payload('app.report', (string) $app_name));
            $result = curl_request($url, $post);
            $result = json_decode($result, true);
            if (is_array($result) && ($result['code'] ?? 0) == 200) {
                return true;
            }
            trace('cloud app record report failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'error');
        } catch (\Throwable $e) {
            trace('cloud app record report exception: ' . $e->getMessage(), 'error');
        }

        return false;
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
