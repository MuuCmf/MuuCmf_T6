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
     * S0-C 宽限期状态机：云端可达时沿用原判定、清空宽限状态并保存云端下发的策略；
     * 云端不可达时按最近一次下发策略放行，超过宽限期返回 offline_expired（fail-closed）。
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

        // 云端可达：清空断网计数并保存云端下发策略；deny 立即拒绝，不受宽限保护
        $this->clearAuthState();
        $this->storeGracePolicy($decoded);
        if ($verdict === 'deny') {
            Cache::set($cacheKey, $decoded, 60);
            return $decoded;
        }
        Cache::set($cacheKey, true, 60);
        return true;
    }

    /**
     * 应用授权状态归一
     *
     * 把 needAuthorization() 的返回收敛成统一状态，供进入应用、安装、升级共用：
     * - ok                 授权有效，可使用、安装、升级
     * - unlicensed         未购买/盗版，禁止使用
     * - expired            已购但到期，允许使用，禁止安装与升级
     * - invalid_activation 未激活/密钥/签名失效，禁止使用
     * - offline_expired    云端不可达且超过宽限期，禁止使用
     *
     * @param string $app_name 应用唯一标识
     * @return array{state:string,msg:string,data:string}
     */
    public function appState($app_name)
    {
        // 请求级缓存：同一请求内多个控制器实例化时不重复请求云端
        static $memo = [];
        $key = strtolower((string) $app_name);
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $result = $this->needAuthorization($app_name);
        if ($result === true) {
            return $memo[$key] = ['state' => 'ok', 'msg' => '', 'data' => ''];
        }

        $msg = is_array($result) ? (string) ($result['msg'] ?? '') : '';
        $data = is_array($result) ? (string) ($result['data'] ?? '') : '';
        return $memo[$key] = [
            'state' => $this->classifyAuthFailure($data, $msg),
            'msg'   => $msg,
            'data'  => $data,
        ];
    }

    /**
     * 失败原因归类：优先使用云端显式 data 码，兼容旧云端按文案兜底
     */
    protected function classifyAuthFailure(string $data, string $msg): string
    {
        switch ($data) {
            case 'tort':
                return 'unlicensed';
            case 'end_auth':
                return 'expired';
            case 'offline_expired':
                return 'offline_expired';
            case 'unactivated':
            case 'sig_invalid':
            case 'auth_code_invalid':
                return 'invalid_activation';
        }

        // 旧云端只回文案时按关键字兜底，避免状态口径丢失
        if ($msg !== '') {
            if (strpos($msg, '到期') !== false || strpos($msg, '过期') !== false) {
                return 'expired';
            }
            if (strpos($msg, '激活') !== false || strpos($msg, '签名') !== false || strpos($msg, '授权码') !== false) {
                return 'invalid_activation';
            }
            if (strpos($msg, '授权') !== false) {
                return 'unlicensed';
            }
        }
        return 'invalid_activation';
    }

    /**
     * 授权状态提示文案
     *
     * @param string $state appState() 返回的 state
     * @param string $scene use / install / upgrade
     */
    public static function stateMessage(string $state, string $scene = 'use'): string
    {
        if ($state === 'expired') {
            if ($scene === 'install') {
                return '授权已到期，无法安装或重新安装，请续费后重试';
            }
            if ($scene === 'upgrade') {
                return '授权已到期，无法升级，请续费后重试';
            }
            return '授权已到期，可继续使用当前版本，但无法安装或升级，请及时续费';
        }
        if ($state === 'unlicensed') {
            return '未获取该应用授权，无法使用';
        }
        if ($state === 'offline_expired') {
            return '授权校验服务不可达且已超过宽限期，请检查网络后重新激活';
        }
        return '站点未激活或授权已失效，请重新激活后再使用';
    }

    /**
     * 批量应用授权查询
     *
     * status 用于让调用方区分失败类型，避免把「云端明确拒绝」和「云端不可达」混为一谈：
     * - ok：拿到授权状态
     * - denied：云端可达但凭证/参数被拒（如密钥失效），可按单应用口径复核
     * - unsupported：云端可达但返回非预期结构（如批量接口尚未部署），可按单应用口径复核
     * - unreachable：网络不可达，调用方不应逐应用重试，避免列表页被多次超时拖慢
     *
     * @param array $app_names 应用标识列表
     * @return array{status:string,apps:array}
     */
    public function needAuthorizationBatch(array $app_names)
    {
        $app_names = array_values(array_unique(array_filter(array_map('strval', $app_names))));
        if (empty($app_names)) {
            return ['status' => 'ok', 'apps' => []];
        }

        $url = $this->api . 'authorization/apps';
        $post = [
            'app_names' => json_encode($app_names, JSON_UNESCAPED_UNICODE),
            'auth_code' => self::authCode(),
            'frame' => 't6',
        ];
        try {
            $post = array_merge($post, CloudSignature::payload('authorization.apps', md5(implode(',', $app_names))));
        } catch (\Throwable $e) {
            trace('cloud batch auth signature exception: ' . $e->getMessage(), 'error');
        }

        try {
            $raw = curl_request($url, $post);
        } catch (ValidateException $e) {
            trace('cloud batch auth request validate exception: ' . $e->getMessage(), 'error');
            return ['status' => 'unreachable', 'apps' => []];
        } catch (\Throwable $e) {
            trace('cloud batch auth request exception: ' . $e->getMessage(), 'error');
            return ['status' => 'unreachable', 'apps' => []];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return ['status' => 'unreachable', 'apps' => []];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            // 有响应但不是 JSON：多为批量接口未部署（路由 404 返回 HTML），可按单应用接口复核
            $status = strpos($raw, '<') !== false ? 'unsupported' : 'unreachable';
            return ['status' => $status, 'apps' => []];
        }
        if ((int) ($decoded['code'] ?? 0) !== 200) {
            return ['status' => 'denied', 'apps' => []];
        }
        $apps = $decoded['data']['apps'] ?? null;
        if (!is_array($apps)) {
            return ['status' => 'unsupported', 'apps' => []];
        }

        // 云端可达：与单应用检测保持一致，清空断网宽限计数并保存云端下发策略
        $this->clearAuthState();
        $this->storeGracePolicy($decoded);

        $result = [];
        foreach ($apps as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $result[(string) $name] = [
                'authorized' => !empty($info['authorized']),
                'expired' => !empty($info['expired']),
                'auth_data' => (string) ($info['auth_data'] ?? ''),
            ];
        }

        return ['status' => 'ok', 'apps' => $result];
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
        $decision = $this->graceDecision($this->authState(), time(), $this->gracePolicyHours());
        $this->saveAuthState($decision['state']);

        if ($decision['allow']) {
            trace('cloud auth unreachable, within grace: ' . json_encode($decision['state']), 'error');
            Cache::set($cacheKey, true, 60);
            return true;
        }

        $denied = [
            'code' => 0,
            'msg'  => '授权校验服务不可达，已超过宽限期',
            'data' => 'offline_expired',
        ];
        trace('cloud auth unreachable, grace exceeded: ' . json_encode($decision['state']), 'error');
        Cache::set($cacheKey, $denied, 60);
        return $denied;
    }

    /**
     * 保存云端下发的宽限策略
     *
     * 租户端本地 config 会随代码交付给租户，可被直接修改；策略改由云端下发并持久化，
     * 租户无法通过改本地配置延长离线可用时间。
     *
     * @param mixed $decoded 云端原始响应
     */
    protected function storeGracePolicy($decoded): void
    {
        if (!is_array($decoded)) {
            return;
        }
        $data = $decoded['data'] ?? null;
        if (!is_array($data) || !isset($data['policy']) || !is_array($data['policy'])) {
            return;
        }
        $hours = $data['policy']['grace_hours'] ?? null;
        if (!is_numeric($hours)) {
            return;
        }
        try {
            // 保留 30 天，避免租户长期离线后策略丢失；期间每次云端可达都会刷新
            Cache::tag('cloud_policy')->set($this->gracePolicyKey(), self::normalizeGraceHours($hours), 30 * 86400);
        } catch (\Throwable $e) {
            trace('cloud policy cache write failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 最近一次云端下发的宽限小时数
     *
     * 未收到过策略时按 72 小时兜底；取值收敛到 0-168，0 表示严格模式（断网即拒）。
     */
    public function gracePolicyHours(): int
    {
        try {
            $hours = Cache::get($this->gracePolicyKey());
        } catch (\Throwable $e) {
            return 72;
        }
        if ($hours === null || $hours === false || $hours === '') {
            return 72;
        }
        return self::normalizeGraceHours($hours);
    }

    /**
     * 宽限小时数收敛：非法值按 0 处理，超过 168（7 天）按 168 处理
     *
     * @param mixed $hours
     */
    protected static function normalizeGraceHours($hours): int
    {
        return max(0, min(168, (int) $hours));
    }

    /**
     * 策略缓存键：按站点隔离，避免多站点共用同一 Redis 时互相覆盖
     */
    protected function gracePolicyKey(): string
    {
        return request()->host() . '_MUUCMF_CLOUD_POLICY_GRACE_HOURS';
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
