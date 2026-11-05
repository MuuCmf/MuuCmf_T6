<?php

namespace app\common\service\aftersale;

use app\common\service\Coupon;
use app\common\service\Discount;
use app\common\service\Groupbuy;
use think\facade\Log;

/**
 * 订单生命周期
 * 统一收口订单退款、取消、关闭时的营销占用回滚与分销佣金冲正，避免多入口重复实现
 */
class Lifecycle
{
    /** 退款模式：按退款语义回滚（优惠券占用自动释放、已核销不退） */
    const MODE_REFUND = 'refund';

    /** 取消模式：取消/关闭，释放营销占用 */
    const MODE_CANCEL = 'cancel';

    /**
     * 回滚订单占用的营销资源，并按需冲正分销佣金
     * @param string $orderNo 订单号
     * @param int $shopid 店铺ID
     * @param string $mode 回滚模式：refund-退款；cancel-取消/关闭
     * @param bool $revertDistribution 是否冲正已发放的分销佣金
     * @return void
     */
    public static function rollback(string $orderNo, int $shopid, string $mode = self::MODE_CANCEL, bool $revertDistribution = false): void
    {
        if ($orderNo === '') {
            return;
        }
        // 限时折扣：回滚活动销量与限购计数（无折扣记录时为安全空操作）
        Discount::rollback($orderNo);
        if ($mode === self::MODE_REFUND) {
            // 拼团：回滚成员状态（非拼团订单为空操作）
            Groupbuy::refundRollback($shopid, $orderNo);
            // 优惠券：占用自动释放、已核销不退（无券为空操作）
            Coupon::refundRollback($shopid, $orderNo);
        } else {
            // 优惠券：释放占用中的券（无券为空操作）
            Coupon::release($shopid, $orderNo);
            // 拼团：取消成员记录并回退人数（非拼团订单为空操作）
            Groupbuy::release($shopid, $orderNo);
        }
        if ($revertDistribution) {
            self::revertDistribution($shopid, $orderNo);
        }
    }

    /**
     * 冲正已发放的分销佣金（幂等；模块未安装或异常时仅记录日志，不阻断主流程）
     * @param int $shopid 店铺ID
     * @param string $orderNo 订单号
     * @return void
     */
    public static function revertDistribution(int $shopid, string $orderNo): void
    {
        if ($orderNo === '' || !ModuleGuard::installed('distribution')) {
            return;
        }
        $class = 'app\\distribution\\service\\Orders';
        if (!class_exists($class) || !method_exists($class, 'revert')) {
            return;
        }
        try {
            $class::revert($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[分销] 佣金冲正失败，order_no=' . $orderNo . '：' . $e->getMessage());
        }
    }
}
