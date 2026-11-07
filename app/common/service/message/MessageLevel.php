<?php

namespace app\common\service\message;

/**
 * 消息分级常量
 *
 * 三级互斥：transaction 事务 / marketing 营销 / security 安全。
 * security 等同事务消息，只多一条「豁免黑名单拦截」。
 */
class MessageLevel
{
    public const TRANSACTION = 'transaction';
    public const MARKETING   = 'marketing';
    public const SECURITY    = 'security';

    /**
     * 可选级别
     *
     * @return array
     */
    public static function all(): array
    {
        return [self::TRANSACTION, self::MARKETING, self::SECURITY];
    }

    /**
     * 级别文案
     *
     * @return array
     */
    public static function labels(): array
    {
        return [
            self::TRANSACTION => '事务消息',
            self::MARKETING   => '营销消息',
            self::SECURITY    => '安全消息',
        ];
    }

    /**
     * 是否合法级别
     *
     * @param string $level
     * @return bool
     */
    public static function isValid(string $level): bool
    {
        return in_array($level, self::all(), true);
    }

    /**
     * 是否营销级别（参与退订/免打扰/频控）
     *
     * @param string $level
     * @return bool
     */
    public static function isMarketing(string $level): bool
    {
        return $level === self::MARKETING;
    }

    /**
     * 是否必须传 biz_key
     *
     * @param string $level
     * @return bool
     */
    public static function requiresBizKey(string $level): bool
    {
        return in_array($level, [self::TRANSACTION, self::SECURITY], true);
    }

    /**
     * 是否豁免黑名单
     *
     * @param string $level
     * @return bool
     */
    public static function exemptBlacklist(string $level): bool
    {
        return $level === self::SECURITY;
    }

    /**
     * 用户级变量白名单
     *
     * 内容里出现这些变量时必须逐用户渲染 message_content，禁止按批次复用，
     * 否则会造成跨用户信息泄露。
     *
     * @return array
     */
    public static function userScopedVars(): array
    {
        return ['nickname', 'mobile', 'email', 'balance', 'avatar', 'uid', 'username'];
    }
}
