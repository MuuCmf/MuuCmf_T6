<?php

namespace app\common\service\message;

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
     * HTTP 请求内：请求参数带了 shopid（>0）时，只允许操作该店铺；
     * 请求未带 shopid（0）时，允许业务代码按订单/会员自身的 shopid 发送（平台默认店铺场景）。
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

        $requested = self::currentShopid();
        if ($requested > 0 && $shopid !== $requested) {
            throw new MessageException('shopid_forbidden', '无权操作该店铺的消息数据', [
                'requested' => $requested,
                'target'    => $shopid,
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
