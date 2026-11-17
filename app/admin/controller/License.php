<?php

namespace app\admin\controller;

use app\admin\lib\CloudSignature;

/**
 * 域名授权激活
 *
 * 云端下发的一次性密钥在此落盘到 data/license/domain.secret（非 docroot）。
 * 本控制器任何响应都不得回显完整密钥，列表页只展示激活状态。
 */
class License extends Admin
{
    /**
     * 激活状态
     *
     * @return \think\Response
     */
    public function index()
    {
        $activation = CloudSignature::activation();
        if (empty($activation)) {
            return $this->success('', [
                'activated' => false,
                'domain'    => (string) request()->host(),
                'key_id'    => '',
                'issued_at' => '',
            ]);
        }

        return $this->success('', [
            'activated' => true,
            'domain'    => (string) $activation['domain'],
            'key_id'    => (string) $activation['key_id'],
            'issued_at' => $activation['issued_at'] > 0 ? date('Y-m-d H:i:s', (int) $activation['issued_at']) : '',
        ]);
    }

    /**
     * 用本地激活态校验云端状态
     *
     * 重新签一次 authorization.allapp 探测云端是否仍接受当前密钥
     * （云端管理员重置密钥后本地副本即过期，此接口可主动发现该状态）。
     * 只返回校验结论与本地激活元信息，不回显密钥。
     *
     * @return \think\Response
     */
    public function check()
    {
        $activation = CloudSignature::activation();
        if (empty($activation)) {
            // 未激活作为结构化结果返回，便于应用管理页据此展示引导横幅而非报错
            return $this->success('', [
                'valid'         => false,
                'not_activated' => true,
                'msg'           => '本机未激活，请先完成激活',
            ]);
        }

        $sig = CloudSignature::payload('authorization.allapp');
        try {
            $raw = curl_request(
                config('cloud.api') . 'authorization/allapp',
                array_merge(['domain' => (string) $activation['domain']], $sig)
            );
            $res = json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            trace('cloud license check exception: ' . $e->getMessage(), 'error');
            return $this->error('云端不可达，校验失败，请稍后重试');
        }
        if (!is_array($res)) {
            return $this->error('云端响应异常，校验失败，请稍后重试');
        }

        $valid = (int) ($res['code'] ?? 0) === 200;

        return $this->success('', [
            'valid'     => $valid,
            'msg'       => $valid
                ? '云端校验通过，签名通道可用'
                : (string) ($res['msg'] ?? '云端拒绝了当前签名'),
            'domain'    => (string) $activation['domain'],
            'key_id'    => (string) $activation['key_id'],
            'issued_at' => $activation['issued_at'] > 0 ? date('Y-m-d H:i:s', (int) $activation['issued_at']) : '',
        ]);
    }

    /**
     * 导入密钥并落盘
     *
     * 落盘前用待激活密钥签名调用一次云端 allapp，确认域名可用；
     * 密钥只在页面输入与本次请求内存在，不入日志。
     *
     * @return \think\Response
     */
    public function import()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $secret = strtolower(trim((string) input('post.secret', '', 'text')));
        if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
            return $this->error('密钥格式不正确，应为 64 位十六进制字符');
        }

        $domain = strtolower(trim((string) request()->host()));
        if ($domain === '') {
            return $this->error('无法识别当前域名，激活失败');
        }

        // 写前预校验：域名未授权或云端不可达时拒绝落盘
        try {
            $sig = CloudSignature::make($secret, $domain, 'authorization.allapp');
            $raw = curl_request(config('cloud.api') . 'authorization/allapp', array_merge(['domain' => $domain], $sig));
            $res = json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            trace('cloud license import exception: ' . $e->getMessage(), 'error');
            return $this->error('云端不可达，激活失败，请稍后重试');
        }
        if (!is_array($res)) {
            return $this->error('云端响应异常，激活失败，请稍后重试');
        }
        if ((int) ($res['code'] ?? 0) !== 200) {
            return $this->error('激活失败：' . (string) ($res['msg'] ?? '域名未授权'));
        }

        // 密钥正确性校验：取该域名任一已授权应用走 authorization/app，
        // 该接口对签名失败一律拒绝，可发现复制不完整/密钥错误
        $appName = (string) ($res['data'][0]['app']['name'] ?? '');
        if ($appName !== '') {
            try {
                $appSig = CloudSignature::make($secret, $domain, 'authorization.app', $appName);
                $appRaw = curl_request(
                    config('cloud.api') . 'authorization/app',
                    array_merge(['app_name' => $appName, 'frame' => 't6'], $appSig)
                );
                $appRes = json_decode((string) $appRaw, true);
            } catch (\Throwable $e) {
                trace('cloud license verify exception: ' . $e->getMessage(), 'error');
                return $this->error('云端不可达，激活失败，请稍后重试');
            }
            if (!is_array($appRes)) {
                return $this->error('云端响应异常，激活失败，请稍后重试');
            }
            if ((int) ($appRes['code'] ?? 0) !== 200) {
                return $this->error('密钥校验失败：' . (string) ($appRes['msg'] ?? '请确认密钥复制完整'));
            }
        }

        $writeError = $this->writeActivation($secret, $domain, 'k' . date('Ym'), time());
        if ($writeError !== '') {
            return $this->error($writeError);
        }
        $warning = $this->selfCheck();

        return $this->activationSuccess($warning);
    }

    /**
     * 自助激活：用授权码向云端换取密钥后落盘
     *
     * 授权码取云端授权列表中的随机 code（不是本地生成的 auth_code），
     * 云端校验「授权码对应域名 = 当前域名」且授权已启用；
     * 重复激活不会轮换云端密钥，避免把其他实例刚拉到的密钥顶掉。
     *
     * @return \think\Response
     */
    public function activate()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $code = trim((string) input('post.code', '', 'text'));
        if ($code === '') {
            return $this->error('请输入授权码');
        }

        $domain = strtolower(trim((string) request()->host()));
        if ($domain === '') {
            return $this->error('无法识别当前域名，激活失败');
        }

        try {
            $raw = curl_request(config('cloud.api') . 'authorization/activate', [
                'code'   => $code,
                'domain' => $domain,
            ]);
            $res = json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            trace('cloud license activate exception: ' . $e->getMessage(), 'error');
            return $this->error('云端不可达，激活失败，请稍后重试');
        }
        if (!is_array($res)) {
            return $this->error('云端响应异常，激活失败，请稍后重试');
        }
        if ((int) ($res['code'] ?? 0) !== 200) {
            return $this->error('激活失败：' . (string) ($res['msg'] ?? '授权码无效或授权未启用'));
        }

        $data = is_array($res['data'] ?? null) ? $res['data'] : [];
        $secret = strtolower(trim((string) ($data['secret'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
            return $this->error('云端返回密钥格式异常，请稍后重试或在云端重置密钥');
        }
        $returnedDomain = strtolower(trim((string) ($data['domain'] ?? '')));
        if ($returnedDomain !== '' && $returnedDomain !== $domain) {
            return $this->error('云端返回域名与当前站点不一致，激活失败');
        }

        $writeError = $this->writeActivation(
            $secret,
            $domain,
            (string) ($data['key_id'] ?? ('k' . date('Ym'))),
            (int) ($data['issued_at'] ?? time())
        );
        if ($writeError !== '') {
            return $this->error($writeError);
        }
        $warning = $this->selfCheck();

        return $this->activationSuccess($warning);
    }

    /**
     * 自动获取授权码：域名归属验证通过后由云端下发
     *
     * 流程：向云端申请一次性验证串 → 写入站点 public 目录 → 云端回连校验 →
     * 通过后返回授权码。验证文件在本次请求内写入并立即删除，不在站点留存。
     * 需要云端能够访问本站（内网/断网站点不适用），失败时仍可手动粘贴授权码。
     *
     * @return \think\Response
     */
    public function fetchCode()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $domain = strtolower(trim((string) request()->host()));
        if ($domain === '') {
            return $this->error('无法识别当前域名，获取授权码失败');
        }

        try {
            $raw = curl_request(config('cloud.api') . 'authorization/challenge', ['domain' => $domain]);
            $res = json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            trace('cloud license challenge exception: ' . $e->getMessage(), 'error');
            return $this->error('云端不可达，暂时无法自动获取，请手动粘贴授权码');
        }
        if (!is_array($res)) {
            return $this->error('云端响应异常，请手动粘贴授权码');
        }
        if ((int) ($res['code'] ?? 0) !== 200) {
            return $this->error((string) ($res['msg'] ?? '无法获取授权码，请手动粘贴'));
        }

        $data     = is_array($res['data'] ?? null) ? $res['data'] : [];
        $token    = strtolower(trim((string) ($data['token'] ?? '')));
        $filename = (string) ($data['filename'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $token) || !preg_match('/^[a-z0-9._-]{1,64}$/', $filename)) {
            return $this->error('云端返回数据异常，请手动粘贴授权码');
        }

        $file = public_path() . $filename;
        if (@file_put_contents($file, $token, LOCK_EX) === false) {
            return $this->error('站点 public 目录不可写，无法自动获取，请手动粘贴授权码');
        }

        try {
            $raw = curl_request(config('cloud.api') . 'authorization/claim', [
                'domain' => $domain,
                'token'  => $token,
            ]);
            $res = json_decode((string) $raw, true);
        } catch (\Throwable $e) {
            @unlink($file);
            trace('cloud license claim exception: ' . $e->getMessage(), 'error');
            return $this->error('云端不可达，暂时无法自动获取，请手动粘贴授权码');
        }
        @unlink($file);

        if (!is_array($res)) {
            return $this->error('云端响应异常，请手动粘贴授权码');
        }
        if ((int) ($res['code'] ?? 0) !== 200) {
            return $this->error((string) ($res['msg'] ?? '自动获取失败，请手动粘贴授权码'));
        }

        $code = trim((string) ($res['data']['code'] ?? ''));
        if (!preg_match('/^[0-9a-f]{32}$/i', $code)) {
            return $this->error('云端返回授权码异常，请手动粘贴');
        }

        return $this->success('已获取授权码', ['code' => $code]);
    }

    /**
     * 密钥原子落盘
     *
     * @param string $secret  64 位十六进制密钥
     * @param string $domain  授权域名
     * @param string $keyId   云端密钥标识
     * @param int    $issuedAt 签发时间
     * @return string 失败原因，空串表示成功
     */
    protected function writeActivation(string $secret, string $domain, string $keyId, int $issuedAt): string
    {
        $file = CloudSignature::activationFile();
        $dir  = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return '激活目录创建失败，请检查 data 目录写入权限';
        }

        $payload = json_encode([
            'secret'    => $secret,
            'domain'    => $domain,
            'key_id'    => $keyId,
            'issued_at' => $issuedAt,
        ]);
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return '密钥写入失败，请检查 data 目录写入权限';
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return '密钥落盘失败，请检查 data 目录写入权限';
        }

        return '';
    }

    /**
     * 落盘后用激活态再签一次做自检，确认租户端签名链路可用
     *
     * 自检失败不回滚激活（密钥已通过写前预校验），只把结果作为告警文案返回。
     *
     * @return string 告警文案，空串表示自检通过
     */
    protected function selfCheck(): string
    {
        $selfSig = CloudSignature::payload('authorization.allapp');
        if (empty($selfSig)) {
            return '激活已保存，但自检未读取到激活密钥，请刷新页面确认激活状态';
        }
        try {
            $raw = curl_request(
                config('cloud.api') . 'authorization/allapp',
                array_merge(['domain' => (string) $selfSig['domain']], $selfSig)
            );
            $res = json_decode((string) $raw, true);
            if (!is_array($res) || (int) ($res['code'] ?? 0) !== 200) {
                $message = is_array($res) ? (string) ($res['msg'] ?? '响应异常') : '响应异常';
                return '激活已保存，但自检调用云端失败：' . $message;
            }
        } catch (\Throwable $e) {
            trace('cloud license self-check exception: ' . $e->getMessage(), 'error');
            return '激活已保存，但自检时云端不可达，请稍后在授权列表中确认签名请求正常';
        }

        return '';
    }

    /**
     * 统一的激活成功响应
     *
     * @param string $warning 自检告警，空串表示无告警
     * @return \think\Response
     */
    protected function activationSuccess(string $warning)
    {
        if ($warning !== '') {
            return $this->success($warning, ['warning' => $warning]);
        }

        return $this->success('激活成功，后续请求将自动携带域名签名');
    }
}
