<?php

namespace app\common\service\message;

use think\facade\Db;

/**
 * 店铺上下文与 shopid 归属校验
 *
 * 约定：
 * - shopid 一律取请求上下文并经本类校验，禁止把前端传的 shopid 直接拼进 where；
 * - shopid=0 表示平台级数据（平台模板/平台公告），不代表可以跨店；
 * - CLI（队列 worker / 定时任务）没有请求上下文，由调用方显式传入 shopid，不参与归属校验。
 */
class ShopContext
{
    /**
     * 当前请求上下文中的店铺 ID
     *
     * @return int
     */
    public static function currentShopid(): int
    {
        if (!self::inHttp()) {
            return 0;
        }
        try {
            return (int)request()->param('shopid', 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 当前登录用户 UID
     *
     * @return int
     */
    public static function currentUid(): int
    {
        if (!self::inHttp()) {
            return 0;
        }
        try {
            return (int)get_uid();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 是否平台超管
     *
     * @param int|null $uid
     * @return bool
     */
    public static function isPlatformAdmin(?int $uid = null): bool
    {
        $uid = $uid ?? self::currentUid();
        return $uid === 1;
    }

    /**
     * 校验 shopid 归属
     *
     * 后台请求用登录态可信的 uid 反查 member.shopid 做白名单校验，
     * 不再拿「请求参数 shopid」与自身比较（那等于没有校验）。
     * 业务链路（api / minishop 支付回调等）与 CLI 不参与，避免影响主流程。
     *
     * @param int $shopid
     * @return int
     * @throws MessageException
     */
    public static function assert(int $shopid): int
    {
        if ($shopid < 0) {
            throw new MessageException('shopid_forbidden', '店铺ID不合法');
        }

        if (!self::inHttp()) {
            return $shopid;
        }

        try {
            $app = strtolower((string)app('http')->getName());
        } catch (\Throwable $e) {
            return $shopid;
        }
        if ($app !== 'admin') {
            return $shopid;
        }

        $uid = self::currentUid();
        if ($uid <= 0 || self::isPlatformAdmin($uid)) {
            return $shopid;
        }

        try {
            $owned = (int)Db::name('member')->where('uid', $uid)->value('shopid');
        } catch (\Throwable $e) {
            // 会员表异常时不阻断发送，退化为 URL 权限校验
            return $shopid;
        }
        // owned=0 视为平台级账号；店铺账号只能操作平台级(0)或本店数据
        if ($owned > 0 && $shopid !== 0 && $shopid !== $owned) {
            throw new MessageException('shopid_forbidden', '无权操作该店铺的消息数据', [
                'owned'  => $owned,
                'target' => $shopid,
            ]);
        }

        return $shopid;
    }

    /**
     * 是否处于 HTTP 请求上下文
     *
     * @return bool
     */
    public static function inHttp(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        try {
            $request = request();
            return $request !== null && $request->param() !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
