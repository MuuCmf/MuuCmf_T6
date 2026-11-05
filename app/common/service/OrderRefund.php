<?php

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 拼团失败/取消场景的自动退款核心状态机（minishop / material 共用）
 *
 * 收敛原先在 minishop 与 material 各写一份的退款流程：
 *   1. GET_LOCK 订单级互斥（防并发重复退款）
 *   2. 持锁重读订单，复核 refund 状态（幂等）
 *   3. 原路退回（支付网关），失败回落用户余额
 *   4. 事务内：余额回落 + 订单标记已退款 + 模块自定义 inTxHook（如库存回补）
 *   5. 事务外：营销占用回滚（Discount/Groupbuy/Coupon）+ 模块自定义 afterTxHook（如 ERP 同步）
 *
 * 幂等：订单 refund 已为 4 直接返回 true；原路退回使用「由订单号派生的固定退款单号」，
 * 重试复用同一 out_refund_no 时支付网关幂等去重，不会重复打款。
 *
 * 各模块差异点通过 $opts 注入：
 *   - app: capitalFlow 的 app 字段（'minishop' / 'material'）
 *   - skipPayChannels: 原路退回排除的 pay_channel 数组（material 排除 'score'/'password'）
 *   - inTxHook: 事务内钩子 callable($order, $refundPriceCent)（minishop 回补库存）
 *   - afterTxHook: 事务后钩子 callable($order, $refundPriceCent)（minishop ERP 同步）
 *
 * @deprecated 过渡期回退实现：已接入策略的应用统一走 RefundCenter::autoRefund，新代码勿直接调用本类
 */
class OrderRefund
{
    /**
     * 自动退款入口（含并发互斥 + 持锁重读）
     *
     * @param array $order 订单数据（含 id、order_no、paid_fee、uid、shopid、channel、pay_channel）
     * @param array $opts  差异点参数（见类注释）
     * @return bool 是否退款成功或已处于已退款状态
     */
    public static function autoRefund(array $order, array $opts = []): bool
    {
        $orderId = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 || $orderNo === '') {
            return false;
        }

        // 并发互斥：定时任务、C 端惰性触发、后台可能同时退款同一订单。
        // 原路退回靠固定 out_refund_no 由支付网关幂等，但「回落余额」分支不幂等，必须串行化后再复核订单状态。
        $lockName = 'gb_refund_' . substr(md5($orderNo), 0, 24);
        $got = Db::query('SELECT GET_LOCK(?, 0) AS locked', [$lockName]);
        if (empty($got[0]['locked'])) {
            Log::warning('[拼团自动退款] 订单正在退款中，跳过本轮，order_no=' . $orderNo);
            return false;
        }

        try {
            // 持锁后重新读取订单：避免两个并发调用都基于「未退款」的旧快照执行打款
            $fresh = Db::name('orders')->where('id', $orderId)->find();
            if (empty($fresh)) {
                return true;
            }
            if (intval($fresh['refund'] ?? 0) === 4) {
                // 已退款：幂等返回；顺带修复历史数据中「已退款但仍为已支付」的脏状态，避免权益残留
                if (intval($fresh['paid'] ?? 0) === 1) {
                    Db::name('orders')->where('id', $orderId)->where('paid', 1)->update([
                        'paid'        => 0,
                        'update_time' => time(),
                    ]);
                }
                return true;
            }

            return self::doRefund($fresh, $opts);
        } finally {
            try {
                Db::query('SELECT RELEASE_LOCK(?)', [$lockName]);
            } catch (\Throwable $e) {
                Log::warning('[拼团自动退款] 释放退款锁失败，order_no=' . $orderNo . '：' . $e->getMessage());
            }
        }
    }

    /**
     * 实际退款执行（调用方需持有订单级互斥锁）
     *
     * @param array $order 订单数据
     * @param array $opts  差异点参数
     * @return bool
     */
    protected static function doRefund(array $order, array $opts): bool
    {
        $orderId = intval($order['id'] ?? 0);
        $shopid  = intval($order['shopid'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 || $orderNo === '') {
            return false;
        }
        if (intval($order['paid'] ?? 0) !== 1) {
            return false; // 仅已支付订单需要退款
        }
        if (intval($order['refund'] ?? 0) === 4) {
            return true; // 已退款，幂等
        }

        $refundPrice = max(0, intval($order['paid_fee'] ?? 0));
        $app = (string)($opts['app'] ?? 'common');
        $skipPayChannels = (array)($opts['skipPayChannels'] ?? []);
        $inTxHook = $opts['inTxHook'] ?? null;
        $afterTxHook = $opts['afterTxHook'] ?? null;

        // 退款去向：0 余额 / 1 原路
        $refundTo = 0;
        $refundNo = '';
        $refundResultJson = '';

        // 1. 原路退回（事务外调用外部退款接口，避免长事务持有连接）
        $channel = (string)($order['channel'] ?? '');
        $payChannel = (string)($order['pay_channel'] ?? '');
        $canRefundOriginal = ($refundPrice > 0 && $channel !== '' && $channel !== 'balance'
            && $payChannel !== '' && !in_array($payChannel, $skipPayChannels, true));
        if ($canRefundOriginal) {
            // 由订单号派生的固定退款单号：重试复用同一 out_refund_no，支付网关幂等去重，避免重复打款
            $refundNo = 'gb' . substr(md5('groupbuy_refund_' . $orderNo), 0, 30);
            try {
                $config = \app\common\facade\channel\Channel::config($channel, $shopid);
                if (empty($config['appid'])) {
                    throw new \Exception('支付渠道配置缺失');
                }
                $payService = \app\common\facade\channel\Pay::init($config['appid'], $payChannel);
                $refundResult = $payService->server->refund([
                    'order_no'   => $orderNo,
                    'refund_no'  => $refundNo,
                    'total_fee'  => intval($order['paid_fee'] ?? 0),
                    'refund_fee' => $refundPrice,
                    'title'      => $orderNo,
                ]);
                if (!empty($refundResult['return_code']) && $refundResult['return_code'] === 'SUCCESS'
                    && !empty($refundResult['result_code']) && $refundResult['result_code'] === 'SUCCESS') {
                    $refundTo = 1;
                    $refundResultJson = json_encode($refundResult, JSON_UNESCAPED_UNICODE);
                } else {
                    Log::warning('[拼团自动退款] 原路退回未成功，回落余额，order_no=' . $orderNo . '：' . json_encode($refundResult, JSON_UNESCAPED_UNICODE));
                }
            } catch (\Throwable $e) {
                // 原路退回异常（渠道未配置/接口报错）→ 回落余额，保证退款不丢失
                Log::warning('[拼团自动退款] 原路退回异常，回落余额，order_no=' . $orderNo . '：' . $e->getMessage());
            }
        }

        // 2. 本地落库：余额回落、订单标记、模块 inTxHook 在同一事务，失败整体回滚（幂等安全）
        Db::startTrans();
        try {
            if ($refundTo === 0 && $refundPrice > 0) {
                // 退回用户余额
                (new \app\common\model\MemberWallet())->income($shopid, intval($order['uid'] ?? 0), $refundPrice, true);
                $capitalFlow = new \app\common\model\CapitalFlow();
                $flowNo = $capitalFlow->build_flow_no();
                $result = $capitalFlow->createFlow([
                    'shopid'   => $shopid,
                    'app'      => $app,
                    'uid'      => intval($order['uid'] ?? 0),
                    'flow_no'  => $flowNo,
                    'order_no' => $orderNo,
                    'channel'  => 'balance',
                    'type'     => 2,
                    'price'    => $refundPrice,
                    'remark'   => '拼团未成团自动退款',
                    'status'   => 1,
                ]);
                if ($result === false) {
                    throw new \Exception('写入收支明细失败');
                }
                $refundNo = $flowNo;
                $refundResultJson = json_encode(['channel' => 'balance', 'flow_no' => $flowNo], JSON_UNESCAPED_UNICODE);
            }

            // 模块自定义事务内钩子（如 minishop 回补商品库存）
            if (is_callable($inTxHook)) {
                $inTxHook($order, $refundPrice);
            }

            // 订单标记已退款（refund_to：0 余额 / 1 原路），并置 paid=0 收回权益，防止退款后仍可使用
            Db::name('orders')->where('id', $orderId)->update([
                'refund'        => 4,
                'refund_to'     => $refundTo,
                'refund_no'      => $refundNo,
                'refund_result'  => $refundResultJson,
                'paid'          => 0,
                'update_time'   => time(),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('[拼团自动退款] 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }

        // 分销：退款完成后冲正已发放的推广佣金（幂等；未产生分销时为空操作）
        // 模块卸载后类文件可能仍残留，需同时校验安装状态，避免调用失效模块的写库逻辑
        $distribution_class = 'app\\distribution\\service\\Orders';
        if (class_exists($distribution_class) && (new \app\common\model\Module())->checkInstalled('distribution')) {
            $distribution_class::revert($shopid, $orderNo);
        }

        // 事务外：营销占用回滚（幂等）。跨模块调用返回 false（business 模块停用/表缺失）时记录告警，便于对账补偿
        if (!Discount::rollback($orderNo)) {
            Log::warning('[拼团自动退款] 限时折扣占用回滚未生效，order_no=' . $orderNo);
        }
        if (!Groupbuy::refundRollback($shopid, $orderNo)) {
            Log::warning('[拼团自动退款] 拼团成员状态回滚未生效，order_no=' . $orderNo);
        }
        if (!Coupon::refundRollback($shopid, $orderNo)) {
            Log::warning('[拼团自动退款] 优惠券占用回滚未生效，order_no=' . $orderNo);
        }

        // 模块自定义事务后钩子（如 minishop ERP 同步）
        if (is_callable($afterTxHook)) {
            try {
                $afterTxHook($order, $refundPrice);
            } catch (\Throwable $e) {
                Log::warning('[拼团自动退款] 事务后钩子失败，order_no=' . $orderNo . '：' . $e->getMessage());
            }
        }

        return true;
    }
}
