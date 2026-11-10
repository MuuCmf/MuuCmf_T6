<?php

namespace app\common\service\security;

/**
 * 敏感凭据加解密
 *
 * 与 app\geo\service\ApiKeyCrypt 保持同一密文格式：enc: + base64(iv + ciphertext)，
 * 便于全站统一识别；无 enc: 前缀的值视为历史明文，原样返回，实现平滑迁移。
 *
 * 密钥来源：env('JWT.SECRET')，不新增环境变量；密钥变更会导致已存密文无法解密，
 * 需在同一变更窗口重新保存对应配置。
 */
class SecretCrypt
{
    /** 密文标识前缀 */
    private const PREFIX = 'enc:';

    /** 加密算法 */
    private const CIPHER = 'AES-256-CBC';

    /** IV 长度（AES-256-CBC 固定 16 字节） */
    private const IV_LENGTH = 16;

    /**
     * 加密
     *
     * @param string $plain 明文
     * @return string 密文（空值原样返回）
     * @throws \RuntimeException 加密失败时抛出
     */
    public static function encrypt(string $plain): string
    {
        if ($plain === '' || str_starts_with($plain, self::PREFIX)) {
            return $plain;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $cipherText = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv);
        if ($cipherText === false) {
            throw new \RuntimeException('凭据加密失败');
        }

        return self::PREFIX . base64_encode($iv . $cipherText);
    }

    /**
     * 解密
     *
     * @param string $value 密文或历史明文
     * @return string 明文（解密失败返回空字符串）
     */
    public static function decrypt(string $value): string
    {
        if ($value === '' || !str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_LENGTH) {
            return '';
        }

        $plain = openssl_decrypt(
            substr($raw, self::IV_LENGTH),
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_LENGTH)
        );

        return $plain === false ? '' : $plain;
    }

    /**
     * 是否为密文
     *
     * @param string $value 待判断值
     * @return bool
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * 派生 32 字节加密密钥
     *
     * @return string 原始二进制密钥
     */
    private static function key(): string
    {
        return hash('sha256', (string)env('JWT.SECRET', 'muucmf_scrm'), true);
    }
}
