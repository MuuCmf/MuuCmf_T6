<?php

namespace app\common\service\order;

use app\common\model\Orders as OrdersModel;
use app\common\service\aftersale\Lifecycle;
use think\facade\Db;

/**
 * 未支付订单取消（统一入口）
 *
 * 供「24 小时超时自动取消」定时任务与用户主动取消接口共用，保证两条路径的
 * 状态流转、营销占用回滚、库存回补口径完全一致。
 *
 * 正确性依赖一次条件更新（CAS）：只有把 status 从「待付款」改成「已取消」的
 * 唯一调用方才允许执行后续回滚。这样即便定时任务与支付回调 / 用户取消 / 另一个
 * 进程并发，也不会出现「已付款订单被改状态」或「库存被重复回补」。
 * 注意 stockRollback 本身不幂等（minishop / scoreshop 均为无条件 +quantity），
 * 因此 CAS 是库存正确性的关键，不能替换成无条件的批量 update。
 */
class CancelService
{
    /** 待付款 */
    public const STATUS_UNPAID = 1;

    /** 已取消 */
    public const STATUS_CANCELLED = 0;

    /** 未支付 */
    public const PAID_NO = 0;

    /**
     * 取消单笔未支付订单，自带事务边界
     *
     * @param array|\think\Model $order 订单数据（需含 id / order_no / shopid / app /
     *                                  order_info_id / order_info_type / products）
     * @return bool true=本次成功取消并完成回滚；false=订单已被并发改写，本方未做任何回滚
     * @throws \Throwable 单笔处理失败，事务已回滚，由调用方决定是否隔离并重试
     */
    public function cancelUnpaid($order): bool
    {
        $order   = $this->normalize($order);
        $id      = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($id <= 0) {
            throw new \RuntimeException('订单数据缺少主键');
        }

        Db::startTrans();
        try {
            // CAS：复查 status / paid，防止 select 之后的支付抢先完成仍被置为已取消
            $affected = (new OrdersModel())
                ->where('id', $id)
                ->where('status', self::STATUS_UNPAID)
                ->where('paid', self::PAID_NO)
                ->update([
                    'status'      => self::STATUS_CANCELLED,
                    'update_time' => time(),
                ]);

            // BaseQuery::update 返回影响行数，执行失败会抛异常；返回 0 只可能是
            // 已被支付回调 / 其他取消入口改写，整笔不做回滚，避免重复回补库存
            if (intval($affected) !== 1) {
                Db::rollback();
                return false;
            }

            // 营销占用统一收口（券 / 团 / 折扣回滚幂等）。引擎异常由门面记 Log 后降级，
            // 不阻断取消：否则少数异常订单会永远卡在待付款，且券占用另有 CouponExpire 兜底。
            // shopid 一律取订单自身值，不使用定时任务参数，避免全局任务传 0 时匹配不到记录。
            Lifecycle::rollback($orderNo, intval($order['shopid'] ?? 0), Lifecycle::MODE_CANCEL);

            $this->rollbackStock($order);

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            // 必须兜住 TypeError / Error，否则常驻 Worker 会留下未结束的事务
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 按订单归属模块派发库存回补（未实现 stockRollback 的模块安全跳过）
     *
     * @param array $order
     * @return void
     */
    protected function rollbackStock(array $order): void
    {
        $app = (string)($order['app'] ?? '');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $app)) {
            return;
        }
        $class = 'app\\' . $app . '\\service\\Orders';
        if (!class_exists($class) || !method_exists($class, 'stockRollback')) {
            return;
        }
        (new $class())->stockRollback($order);
    }

    /**
     * @param mixed $order
     * @return array
     */
    protected function normalize($order): array
    {
        if (is_object($order) && method_exists($order, 'toArray')) {
            return $order->toArray();
        }
        return is_array($order) ? $order : [];
    }
}
