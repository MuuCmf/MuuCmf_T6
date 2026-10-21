<?php

namespace app\common\service\Concerns;

use app\common\model\Module;

/**
 * business 引擎门面公共逻辑（trait）
 *
 * 统一承载「读路径要求 business 已安装 / 写路径仅要求引擎类存在」的引擎解析逻辑，
 * 供 Discount / Coupon / Groupbuy 等门面复用，避免逐类复制 ~45 行模板代码。
 *
 * 调用方使用约定：
 *   1. 门面类 use BusinessEngineProxy;
 *   2. 每个门面类定义自己的「引擎类名常量」，如 ENGINECLASS = 'app\\business\\service\\Discount';
 *   3. 读路径调 self::engineForRead(self::ENGINECLASS)，写路径调 self::engineForWrite(self::ENGINECLASS)。
 */
trait BusinessEngineProxy
{
    /**
     * 引擎类名解析结果缓存：[引擎类名 => 解析结果]，空字符串表示已查过但不可用
     *
     * @var array<string, string|null>
     */
    private static $engineResolveCache = [];

    /**
     * 解析引擎类名是否可用
     *
     * @param string $engineClass      引擎类全限定名
     * @param bool   $requireInstalled true=读路径（要求 business 模块已安装）；false=写路径（仅要求引擎类存在）
     * @return string|null 可用返回引擎类名，不可用返回 null
     */
    private static function resolveEngineClass(string $engineClass, bool $requireInstalled): ?string
    {
        if (!class_exists($engineClass)) {
            return null;
        }

        // 写路径为订单生命周期内的数据补偿，只要引擎类存在即放行，
        // 保证 business 保留数据卸载（is_setup=0、数据表仍在）时回滚/结算仍可落库
        if (!$requireInstalled) {
            return $engineClass;
        }

        try {
            $ready = (bool)(new Module())->checkInstalled('business');
        } catch (\Throwable $e) {
            // 安装状态查询异常时按未就绪处理，确保 C 端安全降级
            $ready = false;
        }

        return $ready ? $engineClass : null;
    }

    /**
     * 获取读路径可用引擎类名（带进程内缓存）
     *
     * 需同时满足「引擎类存在」且「business 模块已安装」，任一不满足即返回 null，
     * 由调用方降级为原价/空列表，避免模块未安装时查询缺失数据表导致 C 端页面报错。
     *
     * @param string $engineClass 引擎类全限定名
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function engineForRead(string $engineClass): ?string
    {
        if (!array_key_exists($engineClass, self::$engineResolveCache)) {
            self::$engineResolveCache[$engineClass] = self::resolveEngineClass($engineClass, true) ?? '';
        }

        return self::$engineResolveCache[$engineClass] !== '' ? self::$engineResolveCache[$engineClass] : null;
    }

    /**
     * 获取写路径可用引擎类名（不缓存）
     *
     * 仅要求引擎类存在，供 consume/settle/rollback 等补偿操作使用。
     *
     * @param string $engineClass 引擎类全限定名
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function engineForWrite(string $engineClass): ?string
    {
        return self::resolveEngineClass($engineClass, false);
    }
}
