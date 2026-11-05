<?php

namespace app\common\service\aftersale;

use think\facade\Db;
use think\facade\Log;
use app\common\model\Refund;
use app\common\model\RefundLog;
use app\common\service\aftersale\executor\RefundExecutor;
use app\common\service\aftersale\policy\PolicyRegistry;

/**
 * 售后中心门面
 * 已接入策略的应用走公共执行器；未接入或开关关闭时回退到旧链路，保证过渡期可用
 */
class RefundCenter
{
    /**
     * 指定应用是否已接入售后策略
     */
    public static function supports(string $app): bool
    {
        return PolicyRegistry::supports($app);
    }

    /**
     * 执行退款，未接入策略时回退旧链路
     * @param array $order 订单数据
     * @param array $refund 退款参数：refund_to / refund_fee / remark / source
     * @param callable $legacy 旧链路回调，仅在回退时执行，需返回同样的结果结构
     * @return array
     * @throws \Exception
     */
    public static function execute(array $order, array $refund, callable $legacy): array
    {
        $app = (string)($refund['app'] ?? ($order['app'] ?? ''));
        if (!RefundExecutor::enabled() || !self::supports($app)) {
            return (array)$legacy();
        }
        return (new RefundExecutor())->execute($order, $refund);
    }

    /**
     * 自动退款（拼团失败、超时取消、支付后业务校验失败等场景）
     *
     * 与后台手动退款的差异：原路退回失败时自动回落用户余额，保证资金不挂账。
     * 已接入策略的应用走公共执行器；未接入或开关关闭时回退旧状态机。
     *
     * @param array $order 订单数据
     * @param array $opts  业务差异参数（app/skipPayChannels/restore_stock 供新链路策略读取；inTxHook/afterTxHook 仅旧链路回退时使用）
     * @return bool 是否退款成功或已处于已退款状态
     */
    public static function autoRefund(array $order, array $opts = []): bool
    {
        $app = (string)($opts['app'] ?? ($order['app'] ?? ''));
        if (!RefundExecutor::enabled() || !self::supports($app)) {
            return \app\common\service\OrderRefund::autoRefund($order, $opts);
        }

        $orderId = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 || $orderNo === '') {
            return false;
        }

        $fresh = Db::name('orders')->where('id', $orderId)->find();
        if (empty($fresh)) {
            return true;
        }
        if (intval($fresh['refund'] ?? 0) === 4) {
            // 已退款：幂等返回，并修复「已退款但仍为已支付」的脏状态
            if (intval($fresh['paid'] ?? 0) === 1) {
                Db::name('orders')->where('id', $orderId)->where('paid', 1)->update([
                    'paid'        => 0,
                    'update_time' => time(),
                ]);
            }
            return true;
        }
        if (intval($fresh['paid'] ?? 0) !== 1) {
            return false;
        }

        $payChannel = (string)($fresh['pay_channel'] ?? '');
        if (in_array($payChannel, ['score', 'password'], true)) {
            // 非资金渠道无真实资金可退
            return false;
        }

        $amount = max(0, intval($fresh['paid_fee'] ?? 0));
        $channel = (string)($fresh['channel'] ?? '');
        // 仅真实在线支付可原路退回；余额支付直接退余额
        $canOriginal = $amount > 0 && $channel !== '' && $channel !== 'balance'
            && $payChannel !== '' && $payChannel !== 'balance';

        $params = [
            'source'     => 3,
            'remark'     => (string)($opts['remark'] ?? '自动退款'),
            'refund_fee' => $amount,
        ];
        // 业务侧差异参数（如商城的库存回补开关），由策略按需读取
        if (array_key_exists('restore_stock', $opts)) {
            $params['restore_stock'] = (bool)$opts['restore_stock'];
        }

        if ($canOriginal) {
            try {
                (new RefundExecutor())->execute($fresh, $params + ['refund_to' => 1]);
                return true;
            } catch (\Throwable $e) {
                // 网关已受理但落库失败时退款单停在「打款中」，此时回落余额会造成重复打款，交给对账处理
                if (self::refundInFlight($app, $orderNo)) {
                    Log::error('[售后自动退款] 原路退回已受理但落库失败，跳过余额回落，order_no=' . $orderNo . '：' . $e->getMessage());
                    return false;
                }
                // 原路失败（渠道未配置/接口报错）→ 回落余额，保证退款不丢失
                Log::warning('[售后自动退款] 原路退回失败，回落余额，order_no=' . $orderNo . '：' . $e->getMessage());
            }
        }

        // 实付 0 的订单无资金可退，走线下登记以收回权益（与旧状态机口径一致）
        $target = $amount > 0 ? 0 : 2;
        try {
            (new RefundExecutor())->execute($fresh, $params + ['refund_to' => $target]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[售后自动退款] 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 用户申请售后（不直接打款，生成待审核退款单）
     *
     * 退款去向由订单支付能力自动判定，最终仍以后台审核结果为准。
     *
     * @param array $order  订单数据（需含 id 或 order_no）
     * @param array $params 申请参数：remark / items
     * @return array refund_no / status / refund_to / refund_fee
     * @throws \Exception
     */
    public static function apply(array $order, array $params = []): array
    {
        $orderId = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 && $orderNo === '') {
            throw new \Exception('订单参数错误');
        }

        $fresh = $orderId > 0
            ? Db::name('orders')->where('id', $orderId)->find()
            : Db::name('orders')->where('order_no', $orderNo)->find();
        if (empty($fresh)) {
            throw new \Exception('订单不存在');
        }
        $orderRefundFlag = intval($fresh['refund'] ?? 0);
        if ($orderRefundFlag === 4) {
            throw new \Exception('该订单已完成退款');
        }
        if (in_array($orderRefundFlag, [2, 3], true)) {
            throw new \Exception('订单退货处理中，暂不可申请退款');
        }

        $app = (string)($fresh['app'] ?? '');
        if (!RefundExecutor::enabled() || !self::supports($app)) {
            throw new \Exception('该应用暂未接入售后申请流程');
        }

        $policy = PolicyRegistry::resolve($app);
        $items = (array)($params['items'] ?? []);
        $amount = $policy->refundableAmount($fresh, $items);
        if ($amount < 0) {
            throw new \Exception('退款金额异常');
        }
        $target = self::decideTarget($fresh, $policy, $amount, $items);
        $remark = trim((string)($params['remark'] ?? ''));
        // 与执行器同一口径校验，避免审核时才暴露不可退问题
        $policy->validate($fresh, [
            'refund_to'  => $target,
            'refund_fee' => $amount,
            'remark'     => $remark,
            'source'     => 1,
        ]);

        $refundNo = self::buildRefundNo($app, (string)$fresh['order_no']);
        $now = time();
        $orderRefund = 0;

        Db::startTrans();
        try {
            // 同一订单固定退款单号，加锁重读保证并发申请只生成一条待审核单
            $exist = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (!empty($exist)) {
                $from = intval($exist['status']);
                if ($from === RefundStatus::COMPLETED) {
                    throw new \Exception('该订单已完成退款');
                }
                if ($from === RefundStatus::PROCESSING) {
                    throw new \Exception('退款正在处理中，请稍后查询');
                }
                if ($from === RefundStatus::WAIT_AUDIT) {
                    // 重复申请：幂等返回既有待审核单，避免反复提交
                    Db::commit();
                    return [
                        'refund_no'  => $refundNo,
                        'order_no'   => (string)$fresh['order_no'],
                        'status'     => RefundStatus::WAIT_AUDIT,
                        'refund_to'  => intval($exist['refund_to']),
                        'refund_fee' => intval($exist['refund_fee']),
                    ];
                }
                // 已同意/已拒绝/已关闭：允许用户重新提交，重置为待审核
                Db::name('refund')->where('id', intval($exist['id']))->update([
                    'status'          => RefundStatus::WAIT_AUDIT,
                    'refund_fee'      => $amount,
                    'refund_to'       => $target,
                    'reason'          => $remark,
                    'source'          => 1,
                    'out_refund_no'   => null,
                    'gateway_result'  => null,
                    'complete_time'   => 0,
                    // 重新申请须重置创建时间，否则超时关闭任务会按旧申请时间立即关闭新单
                    'create_time'     => $now,
                    'update_time'     => $now,
                ]);
                RefundLog::write($refundNo, (string)$fresh['order_no'], $from, RefundStatus::WAIT_AUDIT, '用户重新申请售后', $remark, 1, intval($fresh['uid'] ?? 0));
            } else {
                (new Refund())->edit([
                    'refund_no'    => $refundNo,
                    'order_no'     => (string)$fresh['order_no'],
                    'order_id'     => intval($fresh['id'] ?? 0),
                    'app'          => $app,
                    'shopid'       => intval($fresh['shopid'] ?? 0),
                    'uid'          => intval($fresh['uid'] ?? 0),
                    'type'         => 1,
                    'status'       => RefundStatus::WAIT_AUDIT,
                    'order_fee'    => intval($fresh['paid_fee'] ?? 0),
                    'refund_fee'   => $amount,
                    'refund_to'    => $target,
                    'reason'       => $remark,
                    'source'       => 1,
                    'complete_time' => 0,
                    'create_time'  => $now,
                    'update_time'  => $now,
                ]);
                RefundLog::write($refundNo, (string)$fresh['order_no'], 0, RefundStatus::WAIT_AUDIT, '用户申请售后', $remark, 1, intval($fresh['uid'] ?? 0));
            }

            // 订单侧仅标记「申请中」，权益与实际打款在审核通过后执行
            $affected = Db::name('orders')
                ->where('id', intval($fresh['id']))
                ->where('refund', '<>', 4)
                ->update(['refund' => 1, 'update_time' => $now]);
            if (intval($affected) !== 1) {
                $current = Db::name('orders')->where('id', intval($fresh['id']))->value('refund');
                if (intval($current) === 4) {
                    throw new \Exception('该订单已完成退款');
                }
            }
            $orderRefund = 1;

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Log::info('[售后申请] order_no=' . $fresh['order_no'] . '，refund_no=' . $refundNo . '，refund_to=' . $target);

        return [
            'refund_no'  => $refundNo,
            'order_no'   => (string)$fresh['order_no'],
            'status'     => RefundStatus::WAIT_AUDIT,
            'refund_to'  => $target,
            'refund_fee' => $amount,
            'order_refund' => $orderRefund,
        ];
    }

    /**
     * 审核通过：状态置「已同意」后走公共执行器打款
     *
     * 原路退回失败且网关未受理时自动回落余额，保证资金不挂账；其他失败保留「已同意」等待重试。
     *
     * @param string $refundNo 退款单号
     * @param array  $operator 操作人：id / name
     * @param string $remark   审核备注
     * @param array  $options  附加退款参数（如 minishop 的 restore_stock 库存回补开关）
     * @return array
     * @throws \Exception
     */
    public static function approve(string $refundNo, array $operator = [], string $remark = '', array $options = []): array
    {
        $row = Db::name('refund')->where('refund_no', $refundNo)->find();
        if (empty($row)) {
            throw new \Exception('退款单不存在');
        }
        $from = intval($row['status']);
        if ($from === RefundStatus::COMPLETED) {
            throw new \Exception('该退款单已完成，无需重复审核');
        }
        if ($from === RefundStatus::REJECTED) {
            throw new \Exception('该退款单已拒绝，无法再次审核');
        }
        if ($from === RefundStatus::PROCESSING) {
            throw new \Exception('退款正在处理中，请稍后查询');
        }

        $order = Db::name('orders')->where('id', intval($row['order_id']))->find();
        if (empty($order)) {
            $order = Db::name('orders')->where('order_no', (string)$row['order_no'])->find();
        }
        if (empty($order)) {
            throw new \Exception('订单不存在，无法退款');
        }
        if (intval($order['refund'] ?? 0) === 4) {
            throw new \Exception('该订单已完成退款，请刷新后查看');
        }

        $now = time();
        // 已同意单直接重试打款，无需再次变更状态（避免同秒重试时受影响行数为 0 被误判为状态冲突）
        if ($from !== RefundStatus::AGREED) {
            $affected = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', 'in', [RefundStatus::WAIT_AUDIT, RefundStatus::CLOSED])
                ->update(['status' => RefundStatus::AGREED, 'update_time' => $now]);
            if (intval($affected) !== 1) {
                throw new \Exception('退款单状态已变更，请刷新后重试');
            }
        }
        RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::AGREED, $from === RefundStatus::AGREED ? '重新打款' : '审核通过', $remark, 2, self::operatorId($operator));

        $params = $options + [
            'app'        => (string)$row['app'],
            'refund_to'  => intval($row['refund_to']),
            'refund_fee' => intval($row['refund_fee']),
            'remark'     => $remark !== '' ? $remark : (string)$row['reason'],
            'source'     => intval($row['source']) ?: 1,
        ];

        try {
            $result = (new RefundExecutor())->execute($order, $params);
        } catch (\Throwable $e) {
            // 原路退回失败：网关未受理时回落余额，避免资金挂账
            if ($params['refund_to'] === 1 && !self::refundInFlight((string)$row['app'], (string)$row['order_no'])) {
                $policy = PolicyRegistry::resolve((string)$row['app']);
                if (in_array(0, $policy->allowedTargets($order), true)) {
                    try {
                        Db::name('refund')->where('id', intval($row['id']))->where('status', RefundStatus::AGREED)->update([
                            'refund_to'   => 0,
                            'update_time' => time(),
                        ]);
                        RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::AGREED, RefundStatus::AGREED, '原路退回失败，回落余额', $e->getMessage(), 2, self::operatorId($operator));
                        $params['refund_to'] = 0;
                        $result = (new RefundExecutor())->execute($order, $params);
                        Log::warning('[售后审核] 原路退回失败已回落余额，refund_no=' . $refundNo . '：' . $e->getMessage());
                        return self::approveResult($refundNo, $order, $params, $result);
                    } catch (\Throwable $fallbackError) {
                        RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::AGREED, RefundStatus::CLOSED, '退款失败', $fallbackError->getMessage(), 2, self::operatorId($operator));
                        throw new \Exception('退款失败：' . $fallbackError->getMessage());
                    }
                }
            }
            RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::AGREED, RefundStatus::AGREED, '审核通过后打款失败', $e->getMessage(), 2, self::operatorId($operator));
            throw $e;
        }

        return self::approveResult($refundNo, $order, $params, $result);
    }

    /**
     * 审核拒绝：待审核单或已同意未打款单可拒绝，订单回到未申请状态
     *
     * 已同意单允许改判拒绝，避免渠道持续故障时订单长期卡在退款申请中。
     */
    public static function reject(string $refundNo, array $operator = [], string $remark = ''): void
    {
        $row = Db::name('refund')->where('refund_no', $refundNo)->find();
        if (empty($row)) {
            throw new \Exception('退款单不存在');
        }
        $from = intval($row['status']);
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED], true)) {
            throw new \Exception('该退款单已处理，无法拒绝');
        }
        $now = time();
        $affected = Db::name('refund')
            ->where('id', intval($row['id']))
            ->where('status', 'in', [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED])
            ->update(['status' => RefundStatus::REJECTED, 'complete_time' => $now, 'update_time' => $now]);
        if (intval($affected) !== 1) {
            throw new \Exception('退款单状态已变更，请刷新后重试');
        }
        self::updateOrderRefund($row, 0, [1, 2], $now);
        RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::REJECTED, '审核拒绝', $remark, 2, self::operatorId($operator));
    }

    /**
     * 同意退货（已发货订单）：售后单置「已同意」但不立即打款，订单转为「退货中」
     *
     * 买家退回商品后再由 approve() 执行打款并回补库存，避免未收到货即退款造成货款两失。
     *
     * @param string $refundNo 退款单号
     * @param array  $operator 操作人：id / name
     * @param string $remark   审核备注
     * @return void
     * @throws \Exception
     */
    public static function agreeReturn(string $refundNo, array $operator = [], string $remark = ''): void
    {
        $row = Db::name('refund')->where('refund_no', $refundNo)->find();
        if (empty($row)) {
            throw new \Exception('退款单不存在');
        }
        $from = intval($row['status']);
        if ($from === RefundStatus::COMPLETED) {
            throw new \Exception('该退款单已完成，无需重复审核');
        }
        if ($from === RefundStatus::REJECTED) {
            throw new \Exception('该退款单已拒绝，无法再次审核');
        }
        if ($from === RefundStatus::PROCESSING) {
            throw new \Exception('退款正在处理中，请稍后查询');
        }
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED], true)) {
            throw new \Exception('该退款单状态不支持同意退货');
        }
        $now = time();
        Db::startTrans();
        try {
            if ($from === RefundStatus::WAIT_AUDIT) {
                $affected = Db::name('refund')
                    ->where('id', intval($row['id']))
                    ->where('status', RefundStatus::WAIT_AUDIT)
                    ->update(['status' => RefundStatus::AGREED, 'update_time' => $now]);
                if (intval($affected) !== 1) {
                    throw new \Exception('退款单状态已变更，请刷新后重试');
                }
                RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::AGREED, '同意退货，待买家退回商品', $remark, 2, self::operatorId($operator));
            }
            self::updateOrderRefund($row, 2, [1, 2], $now);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 关闭退款单（人工关闭或超时自动关闭）
     *
     * 允许关闭待审核单与已同意未打款单（如退货流程停滞），订单回到未申请状态。
     */
    public static function close(string $refundNo, string $reason = '', array $operator = [], int $operatorType = 2): void
    {
        $row = Db::name('refund')->where('refund_no', $refundNo)->find();
        if (empty($row)) {
            throw new \Exception('退款单不存在');
        }
        $from = intval($row['status']);
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED], true)) {
            throw new \Exception('仅待审核或已同意未打款的退款单可关闭');
        }
        $now = time();
        $affected = Db::name('refund')
            ->where('id', intval($row['id']))
            ->where('status', 'in', [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED])
            ->update([
                'status'          => RefundStatus::CLOSED,
                'gateway_result'  => json_encode(['close_reason' => $reason], JSON_UNESCAPED_UNICODE),
                'complete_time'   => $now,
                'update_time'     => $now,
            ]);
        if (intval($affected) !== 1) {
            throw new \Exception('退款单状态已变更，请刷新后重试');
        }
        self::updateOrderRefund($row, 0, [1, 2], $now);
        RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::CLOSED, '关闭退款单', $reason, $operatorType, self::operatorId($operator));
    }

    /**
     * 按售后单定位订单并更新退款状态
     *
     * @param array $row    售后单数据
     * @param int   $to     目标退款状态
     * @param array $from   允许变更的当前状态
     * @param int   $now    操作时间
     * @return void
     */
    private static function updateOrderRefund(array $row, int $to, array $from, int $now): void
    {
        $orderId = intval($row['order_id'] ?? 0);
        $query = $orderId > 0
            ? Db::name('orders')->where('id', $orderId)
            : Db::name('orders')->where('order_no', (string)$row['order_no']);
        $query->where('refund', 'in', $from)->update(['refund' => $to, 'update_time' => $now]);
    }

    /**
     * 关闭超时未审核的退款单
     *
     * @param int $hours  超过多少小时未审核即关闭
     * @param int $limit  单次处理上限（避免定时任务长时间占用）
     * @param int $shopid 店铺ID，0 表示全平台
     * @return int 实际关闭数量
     */
    public static function closeExpired(int $hours, int $limit = 200, int $shopid = 0): int
    {
        if ($hours <= 0) {
            return 0;
        }
        $limit = max(1, min($limit, 500));
        $map = [
            ['status', '=', RefundStatus::WAIT_AUDIT],
            ['create_time', '<', time() - $hours * 3600],
        ];
        if ($shopid > 0) {
            $map[] = ['shopid', '=', $shopid];
        }
        $rows = Db::name('refund')->where($map)->order('id asc')->limit($limit)->select()->toArray();
        $closed = 0;
        foreach ($rows as $row) {
            try {
                self::close((string)$row['refund_no'], '超时未审核自动关闭', [], 0);
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('[售后超时关闭] refund_no=' . (string)$row['refund_no'] . '：' . $e->getMessage());
            }
        }
        return $closed;
    }

    /**
     * 退款去向自动判定：在策略允许的去向范围内按支付能力择优
     * @return int
     */
    private static function decideTarget(array $order, $policy, int $amount, array $items = []): int
    {
        $allowed = array_values(array_map('intval', (array)$policy->allowedTargets($order)));
        if (empty($allowed)) {
            throw new \Exception('该订单不支持退款');
        }

        $preferred = [];
        if ($amount <= 0) {
            // 无资金可退：走线下登记以收回权益
            $preferred = [2];
        } else {
            $payChannel = (string)($order['pay_channel'] ?? '');
            $channel = (string)($order['channel'] ?? '');
            if ($payChannel === 'score' || $channel === 'score') {
                $preferred = [3, 2];
            } elseif ($payChannel === 'balance' || $channel === 'balance') {
                $preferred = [0, 2];
            } elseif ($payChannel !== '' && $channel !== '') {
                $preferred = [1, 0, 2];
            } else {
                $preferred = [0, 2];
            }
        }

        foreach ($preferred as $target) {
            if (in_array($target, $allowed, true)) {
                return $target;
            }
        }
        return $allowed[0];
    }

    /**
     * 审核通过结果组装
     */
    private static function approveResult(string $refundNo, array $order, array $params, array $result): array
    {
        return [
            'refund_no'  => (string)($result['refund_no'] ?? $refundNo),
            'order_no'   => (string)($order['order_no'] ?? ''),
            'status'     => RefundStatus::COMPLETED,
            'refund_to'  => intval($result['refund_to'] ?? $params['refund_to']),
            'refund_fee' => intval($params['refund_fee']),
            'result'     => $result,
        ];
    }

    /**
     * 从操作人信息中解析操作人ID
     */
    private static function operatorId(array $operator): int
    {
        return intval($operator['id'] ?? ($operator['uid'] ?? 0));
    }

    /**
     * 退款单是否处于「打款中」：网关已受理但本地落库未完成，禁止再走余额回落以免重复打款
     * @param string $app     应用标识
     * @param string $orderNo 订单号
     * @return bool
     */
    private static function refundInFlight(string $app, string $orderNo): bool
    {
        $row = Db::name('refund')->where('refund_no', self::buildRefundNo($app, $orderNo))->find();
        return !empty($row) && intval($row['status'] ?? 0) === RefundStatus::PROCESSING;
    }

    /**
     * 幂等退款单号：同一订单固定派生，重试复用同一渠道单号
     */
    public static function buildRefundNo(string $app, string $orderNo): string
    {
        return RefundExecutor::buildRefundNo($app, $orderNo);
    }
}
