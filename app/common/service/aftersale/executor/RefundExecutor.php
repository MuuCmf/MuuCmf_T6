<?php

namespace app\common\service\aftersale\executor;

use think\facade\Db;
use think\facade\Log;
use app\common\model\Refund;
use app\common\service\aftersale\Lifecycle;
use app\common\service\aftersale\RefundStatus;
use app\common\service\aftersale\policy\PolicyRegistry;
use app\common\service\aftersale\channel\RefundChannelInterface;
use app\common\service\aftersale\channel\OriginalChannel;
use app\common\service\aftersale\channel\BalanceChannel;
use app\common\service\aftersale\channel\OfflineChannel;
use app\common\service\aftersale\channel\ScoreChannel;
use app\common\service\message\MessageCenter;
use app\common\service\trade\TradeEventDispatcher;

/**
 * 退款执行器（唯一实现）
 *
 * 流程：订单级锁 → 持锁重读 → 幂等检查 → 策略校验 → 金额复核
 *      → 事务外打款 → 事务内落库与权益收回 → 事务外生命周期回滚
 *
 * 各应用差异全部下沉到 RefundPolicyInterface，执行器本身不感知业务规则。
 */
class RefundExecutor
{
    /**
     * 订单级退款锁名（执行器与后台退款入口共用，保证同一订单退款串行）
     */
    public static function orderLockName(string $orderNo): string
    {
        return 'aftersale:' . substr(md5($orderNo), 0, 40);
    }

    /**
     * 获取订单级退款锁，抢不到直接抛异常（不等待，避免后台请求长时间阻塞）
     * @return string 锁名，供 releaseOrderLock 释放
     * @throws \Exception
     */
    public static function acquireOrderLock(string $orderNo): string
    {
        $lockName = self::orderLockName($orderNo);
        $lockResult = Db::query('SELECT GET_LOCK(?, 0) AS locked', [$lockName]);
        if (empty($lockResult[0]['locked'])) {
            throw new \Exception('该订单正在退款处理中，请稍后重试');
        }
        return $lockName;
    }

    /**
     * 释放订单级退款锁（失败仅记录日志，不影响业务结果）
     */
    public static function releaseOrderLock(string $lockName): void
    {
        try {
            Db::query('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (\Throwable $e) {
            Log::warning('[售后] 释放退款锁失败：' . $e->getMessage());
        }
    }

    /**
     * 执行退款
     * @param array $order 订单数据（需含 id 或 order_no）
     * @param array $refund 退款参数：refund_to / refund_fee / remark / source
     * @return array refund_to / refund_no / refund_result
     * @throws \Exception
     */
    public function execute(array $order, array $refund): array
    {
        $orderId = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 && $orderNo === '') {
            throw new \Exception('订单参数错误');
        }

        // 订单级互斥：同一订单不同退款单号也只能串行处理，避免重复打款
        $lockName = self::orderLockName($orderNo !== '' ? $orderNo : (string)$orderId);
        $lockResult = Db::query('SELECT GET_LOCK(?, 0) AS locked', [$lockName]);
        if (empty($lockResult[0]['locked'])) {
            throw new \Exception('该订单正在退款处理中，请稍后重试');
        }

        try {
            // 持锁重读，杜绝旧快照
            $fresh = $orderId > 0
                ? Db::name('orders')->where('id', $orderId)->find()
                : Db::name('orders')->where('order_no', $orderNo)->find();
            if (empty($fresh)) {
                throw new \Exception('订单不存在');
            }
            $orderNo = (string)$fresh['order_no'];

            // 幂等：已退款直接返回既有结果
            if (intval($fresh['refund'] ?? 0) === 4) {
                return [
                    'refund_to'     => intval($fresh['refund_to'] ?? 0),
                    'refund_no'     => (string)($fresh['refund_no'] ?? ''),
                    'refund_result' => (string)($fresh['refund_result'] ?? ''),
                ];
            }

            $app = (string)($fresh['app'] ?? '');

            // 调用方期望的订单退款状态（如退货打款必须确认订单仍为「退货中」），
            // 持锁重读后校验，避免同意退货/关闭等并发操作改变订单意图后仍按原口径打款
            if (isset($refund['require_order_refund'])
                && intval($fresh['refund'] ?? 0) !== intval($refund['require_order_refund'])) {
                throw new \Exception('订单退款状态已变更，请刷新后重试');
            }

            // 已完成退款流水兜底：旧链路已打款但订单未收敛时直接收尾，避免换新单号再次调用网关
            $completedFlow = $this->findCompletedFlow($fresh, $app);
            if (!empty($completedFlow)) {
                return $this->convergeCompleted($fresh, $completedFlow, $refund);
            }

            $policy = PolicyRegistry::resolve($app);
            $policy->validate($fresh, $refund);

            $refundTo = intval($refund['refund_to'] ?? 0);
            if (!in_array($refundTo, $policy->allowedTargets($fresh), true)) {
                throw new \Exception('该订单不支持此退款方式');
            }
            $amount = $policy->refundableAmount($fresh, (array)($refund['items'] ?? []));
            if ($amount < 0) {
                throw new \Exception('退款金额异常');
            }
            // 原路/余额退款必须有金额；线下与积分渠道允许 0 金额（仅回滚业务）
            if ($amount === 0 && in_array($refundTo, [0, 1], true)) {
                throw new \Exception('订单实付金额为 0，无需退款');
            }
            // 金额复核：调用方传入与应退金额不一致时直接拒绝，避免静默改金额造成账实不符
            if (isset($refund['refund_fee']) && intval($refund['refund_fee']) !== $amount) {
                throw new \Exception('退款金额与应退金额不一致，请刷新后重试');
            }

            $refund['refund_no'] = self::buildRefundNo($app, $orderNo);
            // 存在待审核/退货中的售后单时禁止直接打款：必须走 RefundCenter::approve 完成审核，
            // 否则后台「直接退款」会绕过审核把待审核单据直接置为打款中（自动退款显式传 force 放行）
            if (!(bool)($refund['force'] ?? false)) {
                $existDoc = Db::name('refund')->where('refund_no', $refund['refund_no'])->find();
                if (!empty($existDoc)
                    && in_array(intval($existDoc['status']), [RefundStatus::WAIT_AUDIT, RefundStatus::RETURNING], true)) {
                    throw new \Exception('该订单存在待处理的售后申请，请先完成审核');
                }
            }
            $channel = $this->resolveChannel($refundTo);
            // 打款前预建退款单：先落库再调用渠道，崩溃后可按单号对账并幂等重试
            $outRefundNo = $refundTo === 1 ? (string)$refund['refund_no'] : null;
            (new Refund())->createPending((string)$refund['refund_no'], $fresh, $refund, $amount, $refundTo, $outRefundNo);

            // 事务外：外部网关调用，避免长事务占用数据库连接
            try {
                $transferResult = $channel->transfer($fresh, $refund, $amount);
            } catch (RefundResultUnknownException $e) {
                // 结果未知时保留「打款中」状态：此时若按失败关闭，调用方可能改走余额造成重复打款
                Log::warning('[售后] 原路退回结果未知，退款单保留打款中，order_no=' . $orderNo . '：' . $e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                (new Refund())->markClosed((string)$refund['refund_no'], $e->getMessage());
                throw $e;
            }

            // 事务内：本地落账 + 订单状态 + 权益收回
            $finalRefundNo = (string)$refund['refund_no'];
            Db::startTrans();
            try {
                $settleResult = $channel->settle($fresh, $refund, $amount, $transferResult);
                $refundResultJson = json_encode($settleResult, JSON_UNESCAPED_UNICODE);
                // 余额退款沿用资金流水号作为退款单号，保持与旧链路对账口径一致
                if ($refundTo === 0 && !empty($settleResult['flow_no'])) {
                    $finalRefundNo = (string)$settleResult['flow_no'];
                }
                $affected = Db::name('orders')
                    ->where('id', intval($fresh['id']))
                    ->where('paid', 1)
                    ->where('refund', '<>', 4)
                    ->update([
                        'refund'        => 4,
                        'refund_to'     => $refundTo,
                        'refund_no'     => $finalRefundNo,
                        'refund_result' => $refundResultJson,
                        'refund_fee'    => Db::raw('refund_fee + ' . $amount),
                        'refund_status' => 3,
                        'paid'          => 0,
                        'update_time'   => time(),
                    ]);
                if (intval($affected) !== 1) {
                    throw new \Exception('订单状态已变更，请刷新后重试');
                }
                // 售后单与订单同事务收敛，避免「流水已完成但订单未更新」
                (new Refund())->markCompleted((string)$refund['refund_no'], $settleResult, $amount, $refundTo === 1 ? $finalRefundNo : null);
                $policy->onRefundedInTx($fresh, $refund);
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                throw $e;
            }

            // 事务外：统一营销占用回滚与分销佣金冲正，失败仅记录日志，不阻断已完成的退款
            try {
                Lifecycle::rollback($orderNo, intval($fresh['shopid'] ?? 0), Lifecycle::MODE_REFUND, true);
            } catch (\Throwable $e) {
                Log::warning('[售后] 生命周期回滚失败，order_no=' . $orderNo . '：' . $e->getMessage());
            }
            try {
                $policy->onRefundedAfterTx($fresh, $refund);
            } catch (\Throwable $e) {
                Log::warning('[售后] 事务后钩子失败，order_no=' . $orderNo . '：' . $e->getMessage());
            }

            // 交易领域事件：退款完成后统一广播，由 SCRM 等业务应用订阅
            try {
                TradeEventDispatcher::refunded($fresh, [
                    'refund_no'   => (string)($refund['refund_no'] ?? ''),
                    'refund_fee'  => $amount,
                    'order_no'    => $orderNo,
                    'uid'         => (int)($fresh['uid'] ?? 0),
                    'refund_time' => time(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('[售后] 退款完成事件广播失败，order_no=' . $orderNo . '：' . $e->getMessage());
            }

            // 退款到账通知：售后中心是唯一触发点，biz_key 保证同一次退款只通知一次
            $this->notifyRefundSuccess($fresh, $amount, (string)$refund['refund_no']);

            Log::info('[售后] 退款完成，order_no=' . $orderNo . '，refund_to=' . $refundTo . '，refund_no=' . $finalRefundNo);

            return [
                'refund_to'     => $refundTo,
                'refund_no'     => $finalRefundNo,
                'refund_result' => $refundResultJson,
            ];
        } finally {
            self::releaseOrderLock($lockName);
        }
    }

    /**
     * 固定派生退款单号：重试复用同一 out_refund_no，支付网关幂等去重
     *
     * 过渡期按应用沿用旧实现的派生规则，避免灰度切换后与在途退款生成不同单号而重复打款。
     */
    public static function buildRefundNo(string $app, string $orderNo): string
    {
        switch ($app) {
            case 'classroom':
                return 'cr' . substr(md5('classroom_refund_' . $orderNo), 0, 30);
            case 'exam':
                return 'er' . substr(md5('exam_refund_' . $orderNo), 0, 30);
            case 'material':
                return 'mr' . substr(md5('material_refund_' . $orderNo), 0, 30);
            case 'live':
                return 'lr' . substr(md5('live_refund_' . $orderNo), 0, 30);
            case 'appstore':
                return 'as' . substr(md5('appstore_refund_' . $orderNo), 0, 30);
            case 'reservation':
                return 'RF' . substr(md5('reservation|' . $orderNo), 0, 30);
            case 'activity':
                return 'RF' . substr(md5('activity|' . $orderNo), 0, 30);
            default:
                return 'RF' . substr(md5($app . '|' . $orderNo), 0, 30);
        }
    }

    /**
     * 查询同一订单已完成的外部/余额退款流水（旧链路可能已打款但订单状态未收敛）
     *
     * 必须排除业务分账/佣金类收入流水：预约等应用的分账流水同为
     * app + type=2 + status=1，仅凭订单号匹配会把「服务人员收入」误判为「已退款」，
     * 导致退款被静默收敛（不真实打款）。退款流水 uid 恒为订单用户，分账流水 uid 为服务人员。
     */
    private function findCompletedFlow(array $order, string $app): array
    {
        return (array)(Db::name('capital_flow')
            ->where('order_no', (string)($order['order_no'] ?? ''))
            ->where('shopid', intval($order['shopid'] ?? 0))
            ->where('app', $app)
            ->where('uid', intval($order['uid'] ?? 0))
            ->where('type', 2)
            ->where('status', 1)
            ->where('remark', 'not like', '%的收入%')
            ->where('remark', 'not like', '%佣金%')
            ->find() ?: []);
    }

    /**
     * 按已完成流水收尾：不重复打款，只收敛订单状态与权益
     */
    private function convergeCompleted(array $order, array $completed, array $refund): array
    {
        $orderNo = (string)($order['order_no'] ?? '');
        $flowNo = (string)($completed['flow_no'] ?? '');
        $refundTo = (string)($completed['channel'] ?? '') === 'balance' ? 0 : 1;
        $refundResultJson = json_encode([
            'channel' => (string)($completed['channel'] ?? ''),
            'flow_no' => $flowNo,
        ], JSON_UNESCAPED_UNICODE);

        $policy = PolicyRegistry::resolve((string)($order['app'] ?? ''));
        $amount = intval($completed['price'] ?? ($order['paid_fee'] ?? 0));
        $businessRefundNo = self::buildRefundNo((string)($order['app'] ?? ''), $orderNo);
        // 仅当本次确实把订单收敛为「已退款」时才发到账通知，避免 paid=0 等异常状态误发
        $converged = false;
        Db::startTrans();
        try {
            $affected = Db::name('orders')
                ->where('id', intval($order['id'] ?? 0))
                ->where('paid', 1)
                ->where('refund', '<>', 4)
                ->update([
                    'refund'        => 4,
                    'refund_to'     => $refundTo,
                    'refund_no'     => $flowNo,
                    'refund_result' => $refundResultJson,
                    'refund_fee'    => Db::raw('refund_fee + ' . $amount),
                    'refund_status' => 3,
                    'paid'          => 0,
                    'update_time'   => time(),
                ]);
            if (intval($affected) === 1) {
                $converged = true;
                (new Refund())->recordCompleted($businessRefundNo, $order, $refund, $amount, $refundTo, [
                    'channel' => (string)($completed['channel'] ?? ''),
                    'flow_no' => $flowNo,
                ]);
                $policy->onRefundedInTx($order, $refund);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        try {
            Lifecycle::rollback($orderNo, intval($order['shopid'] ?? 0), Lifecycle::MODE_REFUND, true);
        } catch (\Throwable $e) {
            Log::warning('[售后] 已完成流水收尾时生命周期回滚失败，order_no=' . $orderNo . '：' . $e->getMessage());
        }
        try {
            $policy->onRefundedAfterTx($order, $refund);
        } catch (\Throwable $e) {
            Log::warning('[售后] 已完成流水收尾时事务后钩子失败，order_no=' . $orderNo . '：' . $e->getMessage());
        }

        // 按已完成流水收尾同样视为退款完成，统一广播，避免旧链路退款漏发业务事件
        try {
            TradeEventDispatcher::refunded($order, [
                'refund_no'   => $flowNo !== '' ? $flowNo : $businessRefundNo,
                'refund_fee'  => $amount,
                'order_no'    => $orderNo,
                'uid'         => (int)($order['uid'] ?? 0),
                'refund_time' => time(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[售后] 已完成流水收尾事件广播失败，order_no=' . $orderNo . '：' . $e->getMessage());
        }

        // 旧链路已打款但订单未收敛时同样补发到账通知；与 execute() 复用同一发送实现和 biz_key，
        // 因此同一次退款无论由哪条路径收尾，消息中心都只会产生一条到账记录
        if ($converged) {
            $this->notifyRefundSuccess($order, $amount, $businessRefundNo, '（已完成流水收尾）');
        }

        Log::info('[售后] 按已完成流水收尾，order_no=' . $orderNo . '，refund_no=' . $flowNo);
        return [
            'refund_to'     => $refundTo,
            'refund_no'     => $flowNo,
            'refund_result' => $refundResultJson,
        ];
    }

    /**
     * 退款到账通知（售后中心唯一发送实现）
     *
     * execute() 与 convergeCompleted() 均调用本方法。$refundNo 统一取
     * buildRefundNo(app, order_no) 派生的业务退款单号，保证两条路径的 biz_key 一致；
     * 渠道不显式指定，由 order.refund_success 模板与消息中心决定；
     * 异常只记日志，绝不影响已完成的退款。
     */
    private function notifyRefundSuccess(array $order, int $amount, string $refundNo, string $scene = ''): void
    {
        $uid = (int)($order['uid'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($uid <= 0 || $orderNo === '' || $refundNo === '') {
            return;
        }
        try {
            MessageCenter::send('order.refund_success', [
                'type'  => 'uids',
                'value' => [$uid],
            ], [
                'order_no' => $orderNo,
                'amount'   => sprintf('%.2f', $amount / 100),
            ], [
                'shopid'  => (int)($order['shopid'] ?? 0),
                'biz_key' => 'refund_' . $refundNo,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[售后] 退款到账通知发送失败' . $scene . '，order_no=' . $orderNo . '：' . $e->getMessage());
        }
    }

    /**
     * 退款去向 → 渠道实现
     */
    private function resolveChannel(int $refundTo): RefundChannelInterface
    {
        switch ($refundTo) {
            case 1:
                return new OriginalChannel();
            case 0:
                return new BalanceChannel();
            case 2:
                return new OfflineChannel();
            case 3:
                return new ScoreChannel();
            default:
                throw new \Exception('不支持的退款去向：' . $refundTo);
        }
    }
}
