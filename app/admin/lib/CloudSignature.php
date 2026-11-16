<?php

namespace app\admin\lib;

/**
 * 租户端域名签名
 *
 * 与云端 app\cloud\service\cloud\DomainSignature 的 canonical() 必须逐字一致。
 * 激活文件只存本机，密钥不参与任何列表/详情回显。
 */
class CloudSignature
{
    /**
     * 规范化签名串（6 段，顺序不可调整）
     *
     * @param array $p 待签名参数：domain/app_name/frame/scope/ts/nonce
     * @return string 用于 HMAC-SHA256 的规范字符串
     */
    public static function canonical(array $p): string
    {
        return implode('|', [
            strtolower(trim((string) ($p['domain'] ?? ''))),
            trim((string) ($p['app_name'] ?? '')),
            trim((string) ($p['frame'] ?? '')),
            (string) ($p['scope'] ?? ''),
            (int) ($p['ts'] ?? 0),
            trim((string) ($p['nonce'] ?? '')),
        ]);
    }

    /**
     * 生成签名参数
     *
     * @param string $secret   明文密钥（64 位十六进制）
     * @param string $domain   激活时落盘的域名，不能用 request()->host() 反推
     * @param string $scope    接口标识
     * @param string $appName  应用唯一标识，无应用维度时为空串
     * @param int    $ts       时间戳，默认取当前时间
     * @param string $nonce    随机串，默认自动生成
     * @return array 可直接并入 POST/GET 的签名参数
     */
    public static function make(string $secret, string $domain, string $scope,
                                string $appName = '', int $ts = 0, string $nonce = ''): array
    {
        $p = [
            'domain'   => $domain,
            'app_name' => $appName,
            'frame'    => 't6',
            'scope'    => $scope,
            'ts'       => $ts > 0 ? $ts : time(),
            'nonce'    => $nonce !== '' ? $nonce : bin2hex(random_bytes(8)),
        ];
        $p['sig'] = hash_hmac('sha256', self::canonical($p), $secret);

        return $p;
    }

    /**
     * 生成某接口的签名参数；未激活时返回空数组，由调用方回落 legacy
     *
     * @param string $scope   接口标识
     * @param string $appName 应用唯一标识
     * @return array
     */
    public static function payload(string $scope, string $appName = ''): array
    {
        $activation = self::activation();
        if (empty($activation)) {
            return [];
        }

        return self::make((string) $activation['secret'], (string) $activation['domain'], $scope, $appName);
    }

    /**
     * 读取激活态
     *
     * @return array{secret:string,domain:string,key_id:string,issued_at:int}|array
     */
    public static function activation(): array
    {
        $file = self::activationFile();
        $raw = is_file($file) ? (string) @file_get_contents($file) : '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $secret = strtolower(trim((string) ($data['secret'] ?? '')));
        if (!preg_match('/^[0-9a-f]{64}$/', $secret)) {
            return [];
        }

        return [
            'secret'    => $secret,
            'domain'    => strtolower(trim((string) ($data['domain'] ?? ''))),
            'key_id'    => (string) ($data['key_id'] ?? ''),
            'issued_at' => (int) ($data['issued_at'] ?? 0),
        ];
    }

    /**
     * 激活态指纹（写缓存键，避免激活前后复用旧判定）
     *
     * @return string 未激活时为空串
     */
    public static function fingerprint(): string
    {
        $activation = self::activation();

        return empty($activation) ? '' : hash('sha256', (string) $activation['secret']);
    }

    /**
     * 激活文件路径
     *
     * @return string
     */
    public static function activationFile(): string
    {
        return root_path() . 'data/license/domain.secret';
    }
}
