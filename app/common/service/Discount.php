<?php
namespace app\common\service;

use app\common\model\Module;

/**
 * 限时折扣门面（common 侧）
 *
 * 结算与价格展示代码统一通过本门面调用折扣引擎，与 business 模块解耦：
 * business 未启用或引擎缺失时返回 null，全链路安全降级为原价。
 */
class Discount
{
    /**
     * 引擎类名缓存：命中返回 'app\business\service\Discount'，未就绪为空字符串
     *
     * @var string|null
     */
    private static $engineClass = null;

    /**
     * 获取可用的折扣引擎类名
     *
     * 需同时满足「引擎类存在」且「business 模块已安装（数据表就绪）」，任一不满足即返回 null，
     * 由调用方降级为原价，避免模块未安装时查询缺失数据表导致 C 端页面报错。
     *
     * @return string|null 引擎类名，不可用时返回 null
     */
    private static function engine(): ?string
    {
        if (self::$engineClass === null) {
            $engine = 'app\\business\\service\\Discount';
            $ready = false;
            if (class_exists($engine)) {
                try {
                    $ready = (bool)(new Module())->checkInstalled('business');
                } catch (\Throwable $e) {
                    // 安装状态查询异常时按未就绪处理，确保 C 端安全降级
                    $ready = false;
                }
            }
            self::$engineClass = $ready ? $engine : '';
        }

        return self::$engineClass !== '' ? self::$engineClass : null;
    }

    /**
     * 解析某内容命中的最优限时折扣
     *
     * @param int    $shopid   平台/店铺 ID
     * @param string $app      所属模块
     * @param int    $uid      用户 ID
     * @param int    $infoId   内容 ID
     * @param string $infoType 内容类型
     * @param int    $price    内容原价（分）
     * @param string $sku      SKU 标识（预留）
     * @return array|null 命中返回折扣信息数组，未命中或 business 缺失返回 null
     */
    public static function resolve(int $shopid, string $app, int $uid, int $infoId, string $infoType, int $price, string $sku = ''): ?array
    {
        $engine = self::engine();
        if ($engine === null) {
            return null;
        }

        return (new $engine())->resolve($shopid, $app, $uid, $infoId, $infoType, $price, $sku);
    }

    /**
     * 批量解析多个内容命中的最优限时折扣（列表页使用，避免逐条 N+1 查询）
     *
     * @param int    $shopid   平台/店铺 ID
     * @param string $app      所属模块
     * @param string $infoType 内容类型
     * @param int    $uid      用户 ID
     * @param array  $contents [infoId => 原价（分）]
     * @return array [infoId => 折扣信息数组]，仅返回命中的内容
     */
    public static function resolveBatch(int $shopid, string $app, string $infoType, int $uid, array $contents): array
    {
        $engine = self::engine();
        if ($engine === null) {
            return [];
        }

        return (new $engine())->resolveBatch($shopid, $app, $infoType, $uid, $contents);
    }

    /**
     * 占用折扣额度（限购复查 + 活动库存占用 + 写使用记录）
     *
     * @param int    $shopid   平台/店铺 ID
     * @param string $app      所属模块
     * @param int    $uid      用户 ID
     * @param string $orderNo  订单号
     * @param int    $infoId   下单内容 ID
     * @param string $infoType 下单内容类型
     * @param int    $quantity 购买数量
     * @param array  $discount resolve 命中的折扣信息
     * @return bool 占用成功返回 true，business 缺失或失败返回 false
     */
    public static function consume(int $shopid, string $app, int $uid, string $orderNo, int $infoId, string $infoType, int $quantity, array $discount): bool
    {
        $engine = self::engine();
        if ($engine === null) {
            return false;
        }

        return (new $engine())->consume($shopid, $app, $uid, $orderNo, $infoId, $infoType, $quantity, $discount);
    }

    /**
     * 结算折扣：使用记录置为已支付
     *
     * @param string $orderNo 订单号
     * @return bool 处理成功返回 true，business 缺失返回 false
     */
    public static function settle(string $orderNo): bool
    {
        $engine = self::engine();
        if ($engine === null) {
            return false;
        }

        return (new $engine())->settle($orderNo);
    }

    /**
     * 回滚折扣：订单取消/退款时回退活动销量与限购计数
     *
     * @param string $orderNo 订单号
     * @return bool 处理成功返回 true，business 缺失返回 false
     */
    public static function rollback(string $orderNo): bool
    {
        $engine = self::engine();
        if ($engine === null) {
            return false;
        }

        return (new $engine())->rollback($orderNo);
    }
}
