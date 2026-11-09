<?php

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\model\Orders as OrdersModel;
use app\common\service\order\CancelService;
use think\facade\Db;
use think\facade\Log;

/**
 * @title 自动取消超时未支付订单
 *
 * 与 app\api\controller\Crontab::ordersCancel() 共用 CancelService，两条入口口径一致。
 *
 * 并发与正确性：
 * - 任务级 GET_LOCK 仅用于减少并发竞争；正确性由 CancelService 的逐单 CAS 保证。
 *   锁不可用（非 MySQL / 连接异常）时降级为无锁执行，不会造成重复回补库存。
 *   锁按任务行的 shopid 区分：默认全局任务与 URL 入口均为 shopid=0，可正常互斥；
 *   若额外配置了按店铺的任务行，它与全局任务锁名不同，但 CAS 仍保证只有一方执行回滚。
 * - 逐单事务 + 单笔失败隔离：任一订单异常只回滚该单并记录，不影响同批其余订单，
 *   也不会像旧版「100 单共用一个事务」那样被一颗毒丸订单整批卡死。
 */
class Orders
{
    /** 默认超时时间（秒）：24 小时 */
    protected const DEFAULT_TIMEOUT = 86400;

    /** 单轮处理上限 */
    protected const DEFAULT_LIMIT = 100;

    /** 任务锁等待时间（秒） */
    protected const LOCK_TIMEOUT = 5;

    protected $OrdersModel;

    public function __construct()
    {
        $this->OrdersModel = new OrdersModel();
    }

    /**
     * @title 业务处理
     * @param int $shopid 定时任务行的店铺 ID；0 表示全局任务（处理所有店铺）
     * @param int $task_id
     * @return bool
     */
    public function handle(int $shopid, int $task_id)
    {
        // true=拿到锁，false=锁被占用，null=锁不可用（降级为无锁执行）
        $lock = $this->lock($shopid);
        if ($lock === false) {
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => '同店铺订单取消任务正在执行，本轮跳过',
            ]);
            return true;
        }

        try {
            $stats    = ['scanned' => 0, 'cancelled' => 0, 'skipped' => 0, 'failed' => 0];
            $failures = [];
            $lists    = $this->fetchExpired($shopid);
            $service  = new CancelService();

            foreach ($lists as $order) {
                $stats['scanned']++;
                try {
                    $service->cancelUnpaid($order)
                        ? $stats['cancelled']++
                        : $stats['skipped']++;
                } catch (\Throwable $e) {
                    // 单笔失败隔离：其余订单继续处理，本单保持待付款由下一轮重试
                    $stats['failed']++;
                    $orderNo = (string)($order['order_no'] ?? $order['id'] ?? '');
                    if (count($failures) < 5) {
                        $failures[] = $orderNo . '(' . mb_substr($e->getMessage(), 0, 60) . ')';
                    }
                    Log::error('[订单自动取消] 单笔失败，order_no=' . $orderNo . '：' . $e->getMessage());
                }
            }

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => '扫描 ' . $stats['scanned'] . ' 单，取消 ' . $stats['cancelled']
                    . ' 单，并发跳过 ' . $stats['skipped'] . ' 单，失败 ' . $stats['failed'] . ' 单'
                    . ($failures ? '；失败示例：' . implode('、', $failures) : ''),
                // 有失败单即标记异常，便于后台发现；其余订单仍已正常处理
                'status'      => $stats['failed'] > 0 ? 0 : 1,
            ]);

            return $stats['failed'] === 0;
        } catch (\Throwable $e) {
            // 必须兜住 TypeError / Error，否则常驻 Worker 会留下未结束的事务
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => '任务异常：' . mb_substr($e->getMessage(), 0, 300),
                'status'      => 0,
            ]);
            return false;
        } finally {
            if ($lock === true) {
                $this->unlock($shopid);
            }
        }
    }

    /**
     * 拉取本轮待取消订单
     *
     * @param int $shopid
     * @return array
     */
    protected function fetchExpired(int $shopid): array
    {
        $timeout = max(60, intval(config('order.unpaid_cancel_timeout', self::DEFAULT_TIMEOUT)));
        $limit   = max(1, min(500, intval(config('order.unpaid_cancel_limit', self::DEFAULT_LIMIT))));

        $query = $this->OrdersModel
            ->field('id,order_no,uid,shopid,app,order_info_id,order_info_type,products,status,paid')
            ->where('status', CancelService::STATUS_UNPAID)
            ->where('paid', CancelService::PAID_NO)
            ->where('create_time', '<=', time() - $timeout);

        // 任务行 shopid=0 是全局任务（install.sql 默认注册），此时不过滤店铺以免多店漏单；
        // 显式指定店铺的任务行仍只处理该店铺
        if ($shopid > 0) {
            $query->where('shopid', $shopid);
        }

        return $query->order('id', 'asc')->limit($limit)->select()->toArray();
    }

    /**
     * 任务级互斥锁
     *
     * @param int $shopid
     * @return bool|null true=拿到锁；false=被占用；null=锁机制不可用
     */
    protected function lock(int $shopid): ?bool
    {
        try {
            $result = Db::query('SELECT GET_LOCK(?, ' . self::LOCK_TIMEOUT . ') AS locked', [
                'muucmf_order_cancel_' . $shopid,
            ]);
            return !empty($result[0]['locked']) ? true : false;
        } catch (\Throwable $e) {
            // 锁不可用时降级为无锁执行，由 CancelService 的 CAS 保证安全
            Log::warning('[订单自动取消] 获取任务锁失败，降级为无锁执行：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 释放任务级互斥锁
     *
     * @param int $shopid
     * @return void
     */
    protected function unlock(int $shopid): void
    {
        try {
            Db::query('SELECT RELEASE_LOCK(?)', ['muucmf_order_cancel_' . $shopid]);
        } catch (\Throwable $e) {
            Log::warning('[订单自动取消] 释放任务锁失败：' . $e->getMessage());
        }
    }
}
