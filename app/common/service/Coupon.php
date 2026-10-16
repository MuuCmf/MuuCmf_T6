<?php
namespace app\common\service;

use app\common\model\Module;
use think\facade\Log;

/**
 * 优惠券门面（common 侧）
 *
 * 结算与 C 端领券代码统一通过本门面调用券引擎，与 business 模块解耦：
 * business 未启用或引擎缺失时读路径返回空（原价降级），写路径返回 false。
 *
 * 读路径（getAvailable/checkValid/getMyCoupons）要求 business 模块已安装，未就绪即降级为空；
 * 写路径（occupy/settle/release/refundRollback/grant/claim）为数据补偿操作，仅要求引擎类存在即可执行，
 * 以免 business「保留数据卸载」后回滚被静默吞掉，导致券状态虚高。
 */
class Coupon
{
    /**
     * 读路径引擎类名缓存：命中返回 'app\business\service\Coupon'，未就绪为空字符串
     *
     * @var string|null
     */
    private static $engineClass = null;

    /**
     * 解析券引擎类名
     *
     * @param bool $requireInstalled true=读路径（要求 business 模块已安装）；false=写路径（仅要求引擎类存在）
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function resolveEngine(bool $requireInstalled): ?string
    {
        $engine = 'app\\business\\service\\Coupon';
        if (!class_exists($engine)) {
            return null;
        }

        // 写路径为订单生命周期内的数据补偿，只要引擎类存在即放行，
        // 保证 business 保留数据卸载（is_setup=0、数据表仍在）时回滚/结算仍可落库
        if (!$requireInstalled) {
            return $engine;
        }

        try {
            $ready = (bool)(new Module())->checkInstalled('business');
        } catch (\Throwable $e) {
            // 安装状态查询异常时按未就绪处理，确保 C 端安全降级
            $ready = false;
        }

        return $ready ? $engine : null;
    }

    /**
     * 获取读路径可用引擎类名（带进程内缓存）
     *
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function engine(): ?string
    {
        if (self::$engineClass === null) {
            self::$engineClass = self::resolveEngine(true) ?? '';
        }

        return self::$engineClass !== '' ? self::$engineClass : null;
    }

    /**
     * 获取写路径可用引擎类名（不缓存）
     *
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function engineForWrite(): ?string
    {
        return self::resolveEngine(false);
    }

    /**
     * 获取用户在指定 app/内容下可用的券列表
     *
     * @param int         $shopid   平台/店铺 ID
     * @param int         $uid      用户 ID
     * @param string      $app      所属模块
     * @param string|null $infoType 内容类型
     * @param int         $priceCent 折后价（分）
     * @return array 可用券列表，business 缺失或异常返回空数组
     */
    public static function getAvailable(int $shopid, int $uid, string $app, ?string $infoType, int $priceCent): array
    {
        $engine = self::engine();
        if ($engine === null) {
            return [];
        }

        try {
            return (new $engine())->getAvailable($shopid, $uid, $app, $infoType, $priceCent);
        } catch (\Throwable $e) {
            Log::warning('[coupon] getAvailable 降级为空结果：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 校验单张券可用性并返回优惠计算结果
     *
     * @param int    $shopid       平台/店铺 ID
     * @param int    $uid          用户 ID
     * @param int    $couponUserId 用户持有券记录 ID
     * @param string $app          所属模块
     * @param string|null $infoType 内容类型
     * @param int    $priceCent    折后价（分）
     * @return array|null 可用返回优惠结果，不可用或 business 缺失返回 null
     */
    public static function checkValid(int $shopid, int $uid, int $couponUserId, string $app, ?string $infoType, int $priceCent): ?array
    {
        $engine = self::engine();
        if ($engine === null) {
            return null;
        }

        try {
            return (new $engine())->checkValid($shopid, $uid, $couponUserId, $app, $infoType, $priceCent);
        } catch (\Throwable $e) {
            Log::warning('[coupon] checkValid 降级为不可用：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 我的券列表
     *
     * @param int $shopid 平台/店铺 ID
     * @param int $uid    用户 ID
     * @param int $status 状态过滤：0 未使用 / 2 已使用 / 3 已过期（其他值返回全部）
     * @return array 券列表，business 缺失或异常返回空数组
     */
    public static function getMyCoupons(int $shopid, int $uid, int $status = -1): array
    {
        $engine = self::engine();
        if ($engine === null) {
            return [];
        }

        try {
            return (new $engine())->getMyCoupons($shopid, $uid, $status);
        } catch (\Throwable $e) {
            Log::warning('[coupon] getMyCoupons 降级为空结果：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 占用优惠券（下单锁定，须在调用方事务内）
     *
     * @param int    $shopid       平台/店铺 ID
     * @param int    $uid          用户 ID
     * @param int    $couponUserId 用户持有券记录 ID
     * @param string $orderNo      订单号
     * @param int    $priceCent    折后价（分）
     * @return bool 占用成功返回 true，business 缺失或失败返回 false
     */
    public static function occupy(int $shopid, int $uid, int $couponUserId, string $orderNo, int $priceCent): bool
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->occupy($shopid, $uid, $couponUserId, $orderNo, $priceCent);
        } catch (\Throwable $e) {
            Log::error('[coupon] occupy 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 结算优惠券（支付成功核销）
     *
     * @param int    $shopid 平台/店铺 ID
     * @param int    $uid    用户 ID
     * @param string $orderNo 订单号
     * @return bool 处理成功返回 true，business 缺失返回 false
     */
    public static function settle(int $shopid, int $uid, string $orderNo): bool
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->settle($shopid, $uid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[coupon] settle 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 释放优惠券（订单取消/超时）
     *
     * @param int    $shopid 平台/店铺 ID
     * @param string $orderNo 订单号
     * @return bool 处理成功返回 true，business 缺失返回 false
     */
    public static function release(int $shopid, string $orderNo): bool
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->release($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[coupon] release 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 退款处理优惠券（核销不退策略）
     *
     * @param int    $shopid 平台/店铺 ID
     * @param string $orderNo 订单号
     * @return bool 处理成功返回 true，business 缺失返回 false
     */
    public static function refundRollback(int $shopid, string $orderNo): bool
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->refundRollback($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[coupon] refundRollback 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 手动发放优惠券
     *
     * @param int   $shopid   平台/店铺 ID
     * @param int   $couponId 券模板 ID
     * @param array $uids     用户 ID 列表
     * @return array ['ok' => bool, 'granted' => int, 'skipped' => int]，business 缺失时 ok=false
     */
    public static function grant(int $shopid, int $couponId, array $uids): array
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return ['ok' => false, 'granted' => 0, 'skipped' => 0];
        }

        try {
            return (new $engine())->grant($shopid, $couponId, $uids);
        } catch (\Throwable $e) {
            Log::error('[coupon] grant 失败，coupon_id=' . $couponId . '：' . $e->getMessage());
            return ['ok' => false, 'granted' => 0, 'skipped' => 0];
        }
    }

    /**
     * PC 领券中心领取
     *
     * @param int $shopid   平台/店铺 ID
     * @param int $couponId 券模板 ID
     * @param int $uid      用户 ID
     * @return array ['ok' => bool, 'message' => string]，business 缺失时 ok=false
     */
    public static function claim(int $shopid, int $couponId, int $uid): array
    {
        $engine = self::engineForWrite();
        if ($engine === null) {
            return ['ok' => false, 'message' => '暂不可领取'];
        }

        try {
            return (new $engine())->claim($shopid, $couponId, $uid);
        } catch (\Throwable $e) {
            Log::error('[coupon] claim 失败，coupon_id=' . $couponId . '：' . $e->getMessage());
            return ['ok' => false, 'message' => '领取失败'];
        }
    }
}
