<?php

namespace app\common\service\aftersale;

use think\facade\Db;
use think\facade\Log;
use app\common\model\Refund;
use app\common\model\RefundLog;
use app\common\service\aftersale\executor\RefundExecutor;
use app\common\service\aftersale\executor\RefundResultUnknownException;
use app\common\service\aftersale\policy\PolicyRegistry;

/**
 * 售后中心门面
 * 已接入策略的应用统一走公共执行器；未接入策略的应用明确拒绝，不再回退旧链路
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
     * 执行退款（后台手动退款统一入口）
     * @param array $order 订单数据
     * @param array $refund 退款参数：refund_to / refund_fee / remark / source
     * @return array
     * @throws \Exception
     */
    public static function execute(array $order, array $refund): array
    {
        $app = (string)($refund['app'] ?? ($order['app'] ?? ''));
        if (!self::supports($app)) {
            throw new \Exception('该应用暂未接入售后流程');
        }
        return (new RefundExecutor())->execute($order, $refund);
    }

    /**
     * 自动退款（拼团失败、超时取消、支付后业务校验失败等场景）
     *
     * 与后台手动退款的差异：原路退回失败时自动回落用户余额，保证资金不挂账。
     * 未接入策略的应用不做自动退款，交由后台人工处理。
     *
     * @param array $order 订单数据
     * @param array $opts  业务差异参数（app/restore_stock/remark 由策略读取）
     * @return bool 是否退款成功或已处于已退款状态
     */
    public static function autoRefund(array $order, array $opts = []): bool
    {
        $app = (string)($opts['app'] ?? ($order['app'] ?? ''));
        if (!self::supports($app)) {
            Log::warning('[售后自动退款] 该应用未接入售后策略，跳过，app=' . $app . '，order_no=' . (string)($order['order_no'] ?? ''));
            return false;
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
        if ($payChannel === 'password') {
            // 口令支付无真实资金可退，也没有积分可返
            return false;
        }
        $channel = (string)($fresh['channel'] ?? '');
        // 积分支付订单退回积分，不参与原路退回
        $isScore = $payChannel === 'score' || $channel === 'score';

        // 自动退款金额与各应用策略保持一致（如商城扣除不退的运费），避免与执行器金额复核冲突
        $amount = PolicyRegistry::resolve($app)->refundableAmount($fresh);
        // 仅真实在线支付可原路退回；余额/积分支付直接退回对应账户
        $canOriginal = !$isScore && $amount > 0 && $channel !== '' && $channel !== 'balance'
            && $payChannel !== '' && $payChannel !== 'balance';

        $params = [
            'source'     => 3,
            'remark'     => (string)($opts['remark'] ?? '自动退款'),
            'refund_fee' => $amount,
            // 自动退款不经过审核，显式放行待审核单据校验（订单取消/过期时用户申请可能仍在途）
            'force'      => true,
        ];
        // 业务侧差异参数（如商城的库存回补开关），由策略按需读取
        if (array_key_exists('restore_stock', $opts)) {
            $params['restore_stock'] = (bool)$opts['restore_stock'];
        }

        if ($canOriginal) {
            try {
                (new RefundExecutor())->execute($fresh, $params + ['refund_to' => 1]);
                return true;
            } catch (RefundResultUnknownException $e) {
                // 网关结果未知（可能已打款）：严禁回落余额，否则会重复打款；退款单留在「打款中」等对账处理
                Log::error('[售后自动退款] 原路退回结果未知，跳过余额回落，order_no=' . $orderNo . '：' . $e->getMessage());
                return false;
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

        // 积分订单退回积分；实付 0 的订单无资金可退，走线下登记以收回权益（与旧状态机口径一致）
        $target = $isScore ? 3 : ($amount > 0 ? 0 : 2);
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
        // 用户主动申请统一拦截已删除订单：status=-1 的订单仍可能收到支付回调，
        // 但那条链路走 autoRefund / enqueueManual，不经过 apply。
        if (intval($fresh['status'] ?? 0) === -1) {
            throw new \Exception('该订单已删除，无法申请退款');
        }
        $orderRefundFlag = intval($fresh['refund'] ?? 0);
        if ($orderRefundFlag === 4) {
            throw new \Exception('该订单已完成退款');
        }
        if (in_array($orderRefundFlag, [2, 3], true)) {
            throw new \Exception('订单退货处理中，暂不可申请退款');
        }

        $app = (string)($fresh['app'] ?? '');
        if (!self::supports($app)) {
            throw new \Exception('该应用暂未接入售后申请流程');
        }

        $policy = PolicyRegistry::resolve($app);
        // 可选策略能力：虚拟商品等业务可关闭用户端自助申请，仅保留后台人工退款。
        // 使用 method_exists 兼容未实现该方法的策略，且不改 RefundPolicyInterface。
        if (method_exists($policy, 'allowUserApply') && !$policy->allowUserApply()) {
            throw new \Exception('该订单不支持在线申请退款，请联系商家处理');
        }
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
                if ($from === RefundStatus::RETURNING) {
                    // 退货进行中不可重新申请，否则会覆盖退货单并回退订单状态
                    throw new \Exception('该订单正在退货处理中，暂不可重复申请');
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
            // 并发双击：另一请求已按唯一键插入同一退款单，按幂等返回既有待审核单
            if (self::isDuplicateKey($e)) {
                $exist = Db::name('refund')->where('refund_no', $refundNo)->find();
                if (!empty($exist) && intval($exist['status']) === RefundStatus::WAIT_AUDIT) {
                    return [
                        'refund_no'  => $refundNo,
                        'order_no'   => (string)$fresh['order_no'],
                        'status'     => RefundStatus::WAIT_AUDIT,
                        'refund_to'  => intval($exist['refund_to']),
                        'refund_fee' => intval($exist['refund_fee']),
                    ];
                }
            }
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
     * 惰性补建售后单（历史数据迁移）
     *
     * 接入统一售后前，退款申请只写订单 refund=1/2，没有对应售后单，后台既看不到申请记录，
     * 也无法走统一审核打款。后台处理这类订单时按订单当前退款状态补建售后单
     * （申请中→待审核，退货中→退货中）；已存在售后单时原样返回，不做任何改动。
     *
     * @param array $order 订单数据（需含 id 或 order_no）
     * @return string|null 售后单号，无需补建时返回 null
     * @throws \Exception
     */
    public static function ensureDoc(array $order): ?string
    {
        $orderId = intval($order['id'] ?? 0);
        $orderNo = (string)($order['order_no'] ?? '');
        if ($orderId <= 0 && $orderNo === '') {
            return null;
        }

        $fresh = $orderId > 0
            ? Db::name('orders')->where('id', $orderId)->find()
            : Db::name('orders')->where('order_no', $orderNo)->find();
        if (empty($fresh)) {
            return null;
        }
        $app = (string)($fresh['app'] ?? '');
        if (!self::supports($app)) {
            return null;
        }
        // 仅申请中/退货中的历史订单需要补建，其他状态说明没有在途售后
        $orderRefund = intval($fresh['refund'] ?? 0);
        if (!in_array($orderRefund, [1, 2], true)) {
            return null;
        }

        $refundNo = self::buildRefundNo($app, (string)$fresh['order_no']);
        Db::startTrans();
        try {
            $exist = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (!empty($exist)) {
                Db::commit();
                return (string)$exist['refund_no'];
            }

            $policy = PolicyRegistry::resolve($app);
            $amount = $policy->refundableAmount($fresh);
            $target = self::decideTarget($fresh, $policy, $amount);
            // 订单已处于退货中时补建为退货中，后续可直接「确认收货并打款」
            $status = $orderRefund === 2 ? RefundStatus::RETURNING : RefundStatus::WAIT_AUDIT;
            $now = time();
            (new Refund())->edit([
                'refund_no'     => $refundNo,
                'order_no'      => (string)$fresh['order_no'],
                'order_id'      => intval($fresh['id'] ?? 0),
                'app'           => $app,
                'shopid'        => intval($fresh['shopid'] ?? 0),
                'uid'           => intval($fresh['uid'] ?? 0),
                'type'          => 1,
                'status'        => $status,
                'order_fee'     => intval($fresh['paid_fee'] ?? 0),
                'refund_fee'    => $amount,
                'refund_to'     => $target,
                'reason'        => '历史退款申请补建',
                'source'        => 2,
                'complete_time' => 0,
                'create_time'   => $now,
                'update_time'   => $now,
            ]);
            RefundLog::write($refundNo, (string)$fresh['order_no'], 0, $status, '历史退款申请补建售后单', '', 2, 0);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            // 并发补建：唯一键冲突说明另一方已补建成功，按幂等返回
            if (self::isDuplicateKey($e)) {
                return $refundNo;
            }
            throw $e;
        }

        Log::info('[售后] 历史退款申请补建售后单，order_no=' . $fresh['order_no'] . '，refund_no=' . $refundNo);
        return $refundNo;
    }

    /**
     * 自动退款失败 / 定时补偿转人工：事务内补建待审核售后单并标记订单申请中
     *
     * 取代调用方直接写 orders.refund=1，保证「订单状态」与「售后单」同时产生，
     * 后台可见、可审核；同一订单按固定单号幂等，重复触发不产生第二张单。
     * 本方法只建单，绝不触发打款。
     *
     * @param array  $order  订单数据（需含 id 或 order_no）
     * @param string $reason 转入队列原因（自动退款失败原因 / 业务说明）
     * @param int    $source 来源：1用户申请 2后台手动 3自动退款
     * @return array refund_no / status / created / order_refund
     * @throws \Exception
     */
    public static function enqueueManual(array $order, string $reason = '', int $source = 3): array
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

        $current = intval($fresh['refund'] ?? 0);
        if ($current === 4) {
            throw new \Exception('该订单已完成退款');
        }

        $app = (string)($fresh['app'] ?? '');
        $orderNo = (string)$fresh['order_no'];
        $refundNo = self::buildRefundNo($app, $orderNo);
        $now = time();
        $remark = mb_substr($reason !== '' ? $reason : '自动退款失败转人工处理', 0, 255);

        Db::startTrans();
        try {
            $exist = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (!empty($exist)) {
                $from = intval($exist['status']);
                if (in_array($from, [RefundStatus::COMPLETED, RefundStatus::PROCESSING, RefundStatus::RETURNING], true)) {
                    throw new \Exception('该订单退款正在处理中或已完成，请勿重复转入人工队列');
                }
                // 已有可审核单据：不覆盖审核结论，按幂等返回
                Db::commit();
                return [
                    'refund_no'    => $refundNo,
                    'status'       => $from,
                    'created'      => false,
                    'order_refund' => $current,
                ];
            }

            $amount = 0;
            $target = 0;
            if (self::supports($app)) {
                $policy = PolicyRegistry::resolve($app);
                $amount = $policy->refundableAmount($fresh);
                $target = self::decideTarget($fresh, $policy, $amount);
            } else {
                // 未接入策略时仍补建单据保证后台可见，后续审核由执行器按策略解析结果决定
                Log::warning('[售后] 应用未接入售后策略，仅补建人工待审单，order_no=' . $orderNo . '，app=' . $app);
            }

            (new Refund())->edit([
                'refund_no'     => $refundNo,
                'order_no'      => $orderNo,
                'order_id'      => intval($fresh['id'] ?? 0),
                'app'           => $app,
                'shopid'        => intval($fresh['shopid'] ?? 0),
                'uid'           => intval($fresh['uid'] ?? 0),
                'type'          => 1,
                'status'        => RefundStatus::WAIT_AUDIT,
                'order_fee'     => intval($fresh['paid_fee'] ?? 0),
                'refund_fee'    => $amount,
                'refund_to'     => $target,
                'reason'        => $remark,
                'source'        => in_array($source, [1, 2, 3], true) ? $source : 3,
                'complete_time' => 0,
                'create_time'   => $now,
                'update_time'   => $now,
            ]);
            RefundLog::write($refundNo, $orderNo, 0, RefundStatus::WAIT_AUDIT, '转入后台退款队列', $remark, 0, 0);

            // 仅在「未申请/已拒绝」时置申请中，避免覆盖退货中/已退货等在途状态
            $affected = Db::name('orders')
                ->where('id', intval($fresh['id']))
                ->where('refund', 'in', [0, -1])
                ->update(['refund' => 1, 'update_time' => $now]);
            $orderRefund = intval($affected) === 1 ? 1 : $current;

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            // 并发补建：唯一键冲突说明另一方已建单，按幂等返回
            if (self::isDuplicateKey($e)) {
                $exist = Db::name('refund')->where('refund_no', $refundNo)->find();
                if (!empty($exist)) {
                    return [
                        'refund_no'    => $refundNo,
                        'status'       => intval($exist['status']),
                        'created'      => false,
                        'order_refund' => $current,
                    ];
                }
            }
            throw $e;
        }

        Log::info('[售后] 转入后台退款队列，order_no=' . $orderNo . '，refund_no=' . $refundNo . '，reason=' . $remark);

        return [
            'refund_no'    => $refundNo,
            'status'       => RefundStatus::WAIT_AUDIT,
            'created'      => true,
            'order_refund' => $orderRefund,
        ];
    }

    /**
     * 重新处理已结束的售后单（后台人工重开）
     *
     * 拒绝/打款失败关闭的售后单在订单侧已恢复为未申请状态，后台没有入口再次处理，
     * 只能等用户重新申请。由管理员显式重开后回到「待审核」，订单同步回到「申请中」，
     * 之后可正常走审核打款流程。
     *
     * @param string $refundNo 退款单号
     * @param array  $operator 操作人信息（id）
     * @param string $remark   重开原因
     * @return array refund_no / status / refund_to / refund_fee
     * @throws \Exception
     */
    public static function reopen(string $refundNo, array $operator = [], string $remark = ''): array
    {
        if ($refundNo === '') {
            throw new \Exception('退款单号不能为空');
        }

        Db::startTrans();
        try {
            $doc = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (empty($doc)) {
                throw new \Exception('售后单不存在');
            }
            $from = intval($doc['status']);
            if (!in_array($from, [RefundStatus::REJECTED, RefundStatus::CLOSED], true)) {
                throw new \Exception('该售后单当前状态不支持重新处理');
            }
            $order = Db::name('orders')->where('order_no', (string)$doc['order_no'])->find();
            if (empty($order)) {
                throw new \Exception('订单不存在');
            }
            if (intval($order['refund'] ?? 0) === 4) {
                throw new \Exception('该订单已完成退款，无需重新处理');
            }

            // 按当前订单重新计算应退金额与去向，避免历史单据金额与最新口径不一致导致执行器拒绝
            $policy = PolicyRegistry::resolve((string)$doc['app']);
            $amount = $policy->refundableAmount($order);
            $target = intval($doc['refund_to']);
            if (!in_array($target, $policy->allowedTargets($order), true)) {
                $target = self::decideTarget($order, $policy, $amount);
            }

            $now = time();
            Db::name('refund')->where('id', intval($doc['id']))->update([
                'status'        => RefundStatus::WAIT_AUDIT,
                'refund_fee'    => $amount,
                'refund_to'     => $target,
                'complete_time' => 0,
                // 重置创建时间，避免超时关闭任务按旧申请时间立即关闭重开的单据
                'create_time'   => $now,
                'update_time'   => $now,
            ]);
            $affected = Db::name('orders')
                ->where('id', intval($order['id']))
                ->where('refund', '<>', 4)
                ->update(['refund' => 1, 'update_time' => $now]);
            if (intval($affected) !== 1) {
                throw new \Exception('订单状态已变更，请刷新后重试');
            }
            RefundLog::write($refundNo, (string)$doc['order_no'], $from, RefundStatus::WAIT_AUDIT, '后台重新处理售后', $remark, 2, self::operatorId($operator));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        Log::info('[售后] 后台重新处理售后单，refund_no=' . $refundNo);
        return [
            'refund_no'  => $refundNo,
            'status'     => RefundStatus::WAIT_AUDIT,
            'refund_to'  => intval($target),
            'refund_fee' => intval($amount),
        ];
    }

    /**
     * 用户查询售后详情与处理进度
     *
     * @param string $orderNo 订单号
     * @param int    $uid     当前用户ID
     * @return array refund（无售后单时为 null）/ logs
     * @throws \Exception
     */
    public static function userDetail(string $orderNo, int $uid): array
    {
        if ($orderNo === '' || $uid <= 0) {
            throw new \Exception('订单参数错误');
        }
        $order = Db::name('orders')->where('order_no', $orderNo)->find();
        if (empty($order) || intval($order['uid'] ?? 0) !== $uid) {
            throw new \Exception('订单不存在');
        }
        $refund = RefundQuery::latestByOrderNo($orderNo);
        if (empty($refund)) {
            return ['refund' => null, 'logs' => []];
        }
        $logs = Db::name('refund_log')
            ->where('refund_no', (string)$refund['refund_no'])
            ->order('id desc')
            ->select()
            ->toArray();
        $logs = array_map(function (array $log) {
            return [
                'action'        => (string)($log['action'] ?? ''),
                'remark'        => (string)($log['remark'] ?? ''),
                'operator_type' => intval($log['operator_type'] ?? 0),
                'create_time'   => !empty($log['create_time']) ? date('Y-m-d H:i:s', intval($log['create_time'])) : '',
            ];
        }, $logs);
        return ['refund' => $refund, 'logs' => $logs];
    }

    /**
     * 用户撤销售后申请（仅待审核）
     *
     * @param string $refundNo 退款单号
     * @param int    $uid      当前用户ID
     * @param string $remark   撤销原因
     * @return void
     * @throws \Exception
     */
    public static function cancelByUser(string $refundNo, int $uid, string $remark = ''): void
    {
        if ($refundNo === '' || $uid <= 0) {
            throw new \Exception('售后单参数错误');
        }
        Db::startTrans();
        try {
            $row = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (empty($row) || intval($row['uid'] ?? 0) !== $uid) {
                throw new \Exception('售后单不存在');
            }
            $from = intval($row['status']);
            if ($from !== RefundStatus::WAIT_AUDIT) {
                throw new \Exception('当前售后状态不可撤销，请联系客服');
            }
            $now = time();
            $affected = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', RefundStatus::WAIT_AUDIT)
                ->update([
                    'status'        => RefundStatus::CLOSED,
                    'complete_time' => $now,
                    'update_time'   => $now,
                ]);
            if ($affected !== 1) {
                throw new \Exception('售后单状态已变更，请刷新后重试');
            }
            self::updateOrderRefund($row, 0, [1], $now);
            RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::CLOSED, '用户撤销售后申请', $remark, 1, $uid);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        Log::info('[售后] 用户撤销售后申请，refund_no=' . $refundNo);
    }

    /**
     * 用户填写退货物流（退货中单据）
     *
     * @param string $refundNo  退款单号
     * @param int    $uid       当前用户ID
     * @param string $company   物流公司
     * @param string $logisticNo 物流单号
     * @return array 最新售后单信息
     * @throws \Exception
     */
    public static function submitReturnLogistics(string $refundNo, int $uid, string $company, string $logisticNo): array
    {
        $company = trim($company);
        $logisticNo = trim($logisticNo);
        if ($refundNo === '' || $uid <= 0) {
            throw new \Exception('售后单参数错误');
        }
        if ($company === '' || $logisticNo === '') {
            throw new \Exception('请填写退货物流公司与单号');
        }
        Db::startTrans();
        try {
            $row = Db::name('refund')->where('refund_no', $refundNo)->lock(true)->find();
            if (empty($row) || intval($row['uid'] ?? 0) !== $uid) {
                throw new \Exception('售后单不存在');
            }
            if (intval($row['status']) !== RefundStatus::RETURNING) {
                throw new \Exception('当前售后状态无需填写退货物流');
            }
            $now = time();
            Db::name('refund')->where('id', intval($row['id']))->update([
                'return_company' => $company,
                'return_no'      => $logisticNo,
                'return_time'    => $now,
                'update_time'    => $now,
            ]);
            RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::RETURNING, RefundStatus::RETURNING, '买家填写退货物流', $company . ' ' . $logisticNo, 1, $uid);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return (array)RefundQuery::latestByOrderNo((string)$row['order_no']);
    }

    /**
     * 审核通过：状态置「已同意」后走公共执行器打款
     *
     * 原路退回失败且网关未受理时自动回落余额，保证资金不挂账；其他失败保留「已同意」等待重试。
     *
     * @param string $refundNo 退款单号
     * @param array  $operator 操作人：id / name
     * @param string $remark   审核备注
     * @param array  $options  附加退款参数（如 minishop 的 restore_stock 库存回补开关；
     *                          goods_received=true 表示已收到买家退回商品，用于放行「退货中」单据打款；
     *                          fallback_to_balance=false 表示原路失败时不自动回落余额，交由管理员改选；
     *                          其余键如 refund_to / force / source / items 透传给执行器）
     * @return array
     * @throws \Exception
     */
    public static function approve(string $refundNo, array $operator = [], string $remark = '', array $options = []): array
    {
        // 打款意图显式传入，避免并发「同意退货」后按退款口径直接打款
        $goodsReceived = (bool)($options['goods_received'] ?? false);
        unset($options['goods_received']);
        // 原路退回失败是否自动回落余额：默认回落（与原有行为一致），
        // 后台审核可显式传 false，把失败交回管理员改选退款方式，避免静默降级。
        $allowFallback = (bool)($options['fallback_to_balance'] ?? true);
        unset($options['fallback_to_balance']);

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
        // 退货中（含历史遗留「已同意」但订单仍为退货中）必须先收到商品再打款
        $waitingReturn = $from === RefundStatus::RETURNING || intval($order['refund'] ?? 0) === 2;
        if ($waitingReturn && !$goodsReceived) {
            throw new \Exception('买家尚未退回商品，请收到商品后再确认退款');
        }

        $now = time();
        // 已同意单直接重试打款，无需再次变更状态（避免同秒重试时受影响行数为 0 被误判为状态冲突）
        if ($from !== RefundStatus::AGREED) {
            // 退货中单据在确认收到商品后同样收敛为「已同意」，与退款单并行审批互斥
            $fromStatuses = $from === RefundStatus::RETURNING
                ? [RefundStatus::RETURNING]
                : [RefundStatus::WAIT_AUDIT, RefundStatus::CLOSED];
            $affected = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', 'in', $fromStatuses)
                ->update(['status' => RefundStatus::AGREED, 'update_time' => $now]);
            if (intval($affected) !== 1) {
                throw new \Exception('退款单状态已变更，请刷新后重试');
            }
        }
        RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::AGREED, $from === RefundStatus::AGREED ? '重新打款' : '审核通过', $remark, 2, self::operatorId($operator));

        // 审核时可改判退款去向（如积分订单退积分、线下人工处理），未指定则沿用申请时的去向
        if (array_key_exists('refund_to', $options)) {
            $targetOverride = intval($options['refund_to']);
            $policy = PolicyRegistry::resolve((string)$row['app']);
            if (!in_array($targetOverride, $policy->allowedTargets($order), true)) {
                throw new \Exception('该订单不支持此退款方式');
            }
            Db::name('refund')->where('id', intval($row['id']))->update([
                'refund_to'   => $targetOverride,
                'update_time' => $now,
            ]);
            $row['refund_to'] = $targetOverride;
        }

        $params = $options + [
            'app'        => (string)$row['app'],
            'refund_to'  => intval($row['refund_to']),
            'refund_fee' => intval($row['refund_fee']),
            'remark'     => $remark !== '' ? $remark : (string)$row['reason'],
            'source'     => intval($row['source']) ?: 1,
            // 打款前由执行器复核单据仍为「已同意」，避免并发拒绝/关闭后继续打款
            'require_doc_status' => RefundStatus::AGREED,
            // 透传给策略：确认收到退回商品时允许回补库存（策略会结合持锁后的订单状态复核）
            'goods_received'     => $goodsReceived,
        ];
        // 退货打款须在持锁重读后确认订单仍为「退货中」，防止并发的关单/取消改变订单状态后仍打款
        if ($waitingReturn) {
            $params['require_order_refund'] = 2;
        }

        try {
            $result = (new RefundExecutor())->execute($order, $params);
        } catch (RefundResultUnknownException $e) {
            // 网关结果未知（可能已打款）：退款单保留「打款中」等待对账，
            // 既不能回落余额（重复打款），也不能回置「已同意」（会诱导再次打款）
            Log::error('[售后审核] 原路退回结果未知，保留打款中等待对账，refund_no=' . $refundNo . '：' . $e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            // 原路退回失败：网关未受理时回落余额，避免资金挂账
            if ($params['refund_to'] === 1 && $allowFallback
                && !self::refundInFlight((string)$row['app'], (string)$row['order_no'])) {
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
                        // 落账/渠道失败后执行器可能留下「处理中」或「已关闭」，回置为「已同意」保留重试入口
                        self::restoreAgreedAfterFailure($refundNo, $row, $fallbackError->getMessage());
                        RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::AGREED, RefundStatus::AGREED, '退款失败，待重试', $fallbackError->getMessage(), 2, self::operatorId($operator));
                        throw new \Exception('退款失败：' . $fallbackError->getMessage());
                    }
                }
            }
            // 失败后回置为「已同意」，避免单据残留「处理中/已关闭」导致后台无法再次发起退款
            self::restoreAgreedAfterFailure($refundNo, $row, $e->getMessage());
            RefundLog::write($refundNo, (string)$row['order_no'], RefundStatus::AGREED, RefundStatus::AGREED, '审核通过后打款失败', $e->getMessage(), 2, self::operatorId($operator));
            throw $e;
        }

        return self::approveResult($refundNo, $order, $params, $result);
    }

    /**
     * 打款失败后将售后单回置为「已同意」，保留后台重试入口
     *
     * 执行器落账/渠道失败时会把单据置为「关闭」或停留在「处理中」，
     * 若不复位，后台会把单据当成已终结并误走旧退款链路。
     *
     * @param string $refundNo 退款单号
     * @param array  $row      审核时的售后单快照
     * @param string $reason   失败原因
     * @return void
     */
    private static function restoreAgreedAfterFailure(string $refundNo, array $row, string $reason): void
    {
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $from = intval(Db::name('refund')->where('id', $id)->value('status'));
        if (!in_array($from, [RefundStatus::PROCESSING, RefundStatus::CLOSED], true)) {
            return;
        }
        // 订单仍处于退货中时回置为「退货中」，避免单据显示为已同意却被再次直接打款
        $orderId = intval($row['order_id'] ?? 0);
        $orderRefund = $orderId > 0
            ? intval(Db::name('orders')->where('id', $orderId)->value('refund'))
            : intval(Db::name('orders')->where('order_no', (string)$row['order_no'])->value('refund'));
        $to = $orderRefund === 2 ? RefundStatus::RETURNING : RefundStatus::AGREED;
        $affected = Db::name('refund')
            ->where('id', $id)
            ->where('status', 'in', [RefundStatus::PROCESSING, RefundStatus::CLOSED])
            ->update([
                'status'         => $to,
                'gateway_result' => json_encode(['last_error' => $reason], JSON_UNESCAPED_UNICODE),
                'complete_time'  => 0,
                'update_time'    => time(),
            ]);
        if (intval($affected) === 1) {
            RefundLog::write($refundNo, (string)$row['order_no'], $from, $to, '打款失败，回置待重试', $reason, 2, 0);
        }
    }

    /**
     * 审核拒绝：待审核、退货中或已同意未打款单可拒绝，订单回到未申请状态
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
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED, RefundStatus::RETURNING], true)) {
            throw new \Exception('该退款单已处理，无法拒绝');
        }
        $now = time();
        Db::startTrans();
        try {
            $affected = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', 'in', [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED, RefundStatus::RETURNING])
                ->update(['status' => RefundStatus::REJECTED, 'complete_time' => $now, 'update_time' => $now]);
            if (intval($affected) !== 1) {
                throw new \Exception('退款单状态已变更，请刷新后重试');
            }
            self::updateOrderRefund($row, 0, [1, 2], $now);
            RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::REJECTED, '审核拒绝', $remark, 2, self::operatorId($operator));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 同意退货（已发货订单）：售后单置「退货中」但不立即打款，订单转为「退货中」
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
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED, RefundStatus::RETURNING, RefundStatus::CLOSED], true)) {
            throw new \Exception('该退款单状态不支持同意退货');
        }
        $now = time();
        Db::startTrans();
        try {
            // 转为独立的「退货中」状态：即使单据此前已是「已同意」，也必须让并发的 approve 复核失败，
            // 否则直接打款会把「等待退货」的意图覆盖掉
            if ($from !== RefundStatus::RETURNING) {
                $affected = Db::name('refund')
                    ->where('id', intval($row['id']))
                    ->where('status', 'in', [RefundStatus::WAIT_AUDIT, RefundStatus::AGREED, RefundStatus::CLOSED])
                    ->update(['status' => RefundStatus::RETURNING, 'complete_time' => 0, 'update_time' => $now]);
                if (intval($affected) !== 1) {
                    throw new \Exception('退款单状态已变更，请刷新后重试');
                }
                RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::RETURNING, '同意退货，待买家退回商品', $remark, 2, self::operatorId($operator));
            }
            // 已关闭单的订单侧已回退为「未申请」，重开退货时一并置为「退货中」
            self::updateOrderRefund($row, 2, $from === RefundStatus::CLOSED ? [0, 1, 2] : [1, 2], $now);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 关闭退款单（人工关闭或超时自动关闭）
     *
     * 允许关闭待审核单、退货中单与已同意未打款单（如退货流程停滞），订单回到未申请状态。
     *
     * @param string $refundNo        退款单号
     * @param string $reason          关闭原因
     * @param array  $operator        操作人：id / name
     * @param int    $operatorType    操作人类型（0系统 1用户 2管理员）
     * @param array  $expect          期望快照（供定时任务使用）：status / create_time，
     *                                 候选单被选出后若被用户重新申请或审核改写，条件更新失败即放弃关闭
     * @return void
     * @throws \Exception
     */
    public static function close(string $refundNo, string $reason = '', array $operator = [], int $operatorType = 2, array $expect = []): void
    {
        $row = Db::name('refund')->where('refund_no', $refundNo)->find();
        if (empty($row)) {
            throw new \Exception('退款单不存在');
        }
        $from = intval($row['status']);
        if (!in_array($from, [RefundStatus::WAIT_AUDIT, RefundStatus::RETURNING, RefundStatus::AGREED], true)) {
            throw new \Exception('仅待审核、退货中或已同意未打款的退款单可关闭');
        }
        $now = time();
        $expectCreateTime = intval($expect['create_time'] ?? 0);
        // WAIT_AUDIT 为 0，需按「是否传入」判断而非按值判断
        $statuses = array_key_exists('status', $expect)
            ? [intval($expect['status'])]
            : [RefundStatus::WAIT_AUDIT, RefundStatus::RETURNING, RefundStatus::AGREED];
        Db::startTrans();
        try {
            $query = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', 'in', $statuses);
            if ($expectCreateTime > 0) {
                // 申请时间已刷新说明用户已重新提交，本次关闭失效
                $query->where('create_time', $expectCreateTime);
            }
            $affected = $query->update([
                'status'          => RefundStatus::CLOSED,
                'gateway_result'  => json_encode(['close_reason' => $reason], JSON_UNESCAPED_UNICODE),
                'complete_time'   => $now,
                'update_time'     => $now,
            ]);
            if (intval($affected) !== 1) {
                throw new \Exception('退款单状态已变更，请刷新后重试');
            }
            // 与申请流程串行：单据行锁持有期间订单回退，避免用户重新申请写入的订单状态被旧任务覆盖
            self::updateOrderRefund($row, 0, [1, 2], $now);
            RefundLog::write($refundNo, (string)$row['order_no'], $from, RefundStatus::CLOSED, '关闭退款单', $reason, $operatorType, self::operatorId($operator));
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
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
                // 带上候选快照：被选出后用户重新申请会刷新 create_time，此时不得再关闭新申请
                self::close((string)$row['refund_no'], '超时未审核自动关闭', [], 0, [
                    'status'      => RefundStatus::WAIT_AUDIT,
                    'create_time' => intval($row['create_time']),
                ]);
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('[售后超时关闭] refund_no=' . (string)$row['refund_no'] . '：' . $e->getMessage());
            }
        }
        return $closed;
    }

    /**
     * 关闭超时未收到退回商品的售后单
     *
     * 已发货订单同意退货后，订单停在「退货中」等待买家寄回商品，
     * 若长期无人处理会永久卡住订单。仅关闭订单仍为「退货中」的已同意单，
     * 避免误关「已同意未打款」等待人工重试的单据。
     *
     * @param int $days   超过多少天未更新即视为退货超时
     * @param int $limit  单次处理上限（避免定时任务长时间占用）
     * @param int $shopid 店铺ID，0 表示全平台
     * @return int 实际关闭数量
     */
    public static function closeReturnTimeout(int $days = 7, int $limit = 200, int $shopid = 0): int
    {
        if ($days <= 0) {
            return 0;
        }
        $limit = max(1, min($limit, 500));
        $map = [
            ['status', 'in', [RefundStatus::RETURNING, RefundStatus::AGREED]],
            ['update_time', '<', time() - $days * 86400],
        ];
        if ($shopid > 0) {
            $map[] = ['shopid', '=', $shopid];
        }
        $rows = Db::name('refund')->where($map)->order('id asc')->limit($limit)->select()->toArray();
        $closed = 0;
        foreach ($rows as $row) {
            $orderId = intval($row['order_id'] ?? 0);
            $order = $orderId > 0
                ? Db::name('orders')->where('id', $orderId)->find()
                : Db::name('orders')->where('order_no', (string)$row['order_no'])->find();
            // 仅退货中的单参与退货超时关闭，已同意未打款单保留重试入口
            if (empty($order) || intval($order['refund'] ?? 0) !== 2) {
                continue;
            }
            try {
                // 带上候选快照：被选出后若单据被审核/驳回改写，条件更新失败即放弃关闭
                self::close((string)$row['refund_no'], '退货超时未收到商品，自动关闭', [], 0, [
                    'status'      => intval($row['status']),
                    'create_time' => intval($row['create_time']),
                ]);
                $closed++;
            } catch (\Throwable $e) {
                Log::warning('[售后退货超时] refund_no=' . (string)$row['refund_no'] . '：' . $e->getMessage());
            }
        }
        return $closed;
    }

    /**
     * 对账回置：把超时滞留在「打款中 / 已关闭」的退款单恢复为「已同意」或「退货中」
     *
     * 执行器在渠道打款成功但本地落账失败时会把单据停在「打款中」，
     * 该状态下用户无法重新申请、后台也无法再次审核，资金与订单会长期卡住。
     * 仅回置订单仍处于退款流程（申请中/退货中）的单据，并按订单侧回置为对应状态；
     * 订单已终结的不处理，避免把人工关闭或已完成收尾的单据重新激活。
     *
     * @param int $minutes 单据超过多少分钟未更新即视为滞留
     * @param int $limit   单次处理上限（避免定时任务长时间占用）
     * @param int $shopid  店铺ID，0 表示全平台
     * @return int 实际回置数量
     */
    public static function reconcileStuck(int $minutes = 30, int $limit = 200, int $shopid = 0): int
    {
        if ($minutes <= 0) {
            return 0;
        }
        $limit = max(1, min($limit, 500));
        $map = [
            ['status', 'in', [RefundStatus::PROCESSING, RefundStatus::CLOSED]],
            ['update_time', '<', time() - $minutes * 60],
        ];
        if ($shopid > 0) {
            $map[] = ['shopid', '=', $shopid];
        }
        $rows = Db::name('refund')->where($map)->order('id asc')->limit($limit)->select()->toArray();
        $reverted = 0;
        foreach ($rows as $row) {
            $orderId = intval($row['order_id'] ?? 0);
            $order = $orderId > 0
                ? Db::name('orders')->where('id', $orderId)->find()
                : Db::name('orders')->where('order_no', (string)$row['order_no'])->find();
            $orderRefund = intval($order['refund'] ?? 0);
            if (empty($order) || !in_array($orderRefund, [1, 2], true)) {
                continue;
            }
            // 按订单侧意图回置：仍在等待退货的单据必须回到「退货中」，否则会被 approve 当作可直接打款
            $to = $orderRefund === 2 ? RefundStatus::RETURNING : RefundStatus::AGREED;
            $now = time();
            $affected = Db::name('refund')
                ->where('id', intval($row['id']))
                ->where('status', 'in', [RefundStatus::PROCESSING, RefundStatus::CLOSED])
                ->update([
                    'status'        => $to,
                    'complete_time' => 0,
                    'update_time'   => $now,
                ]);
            if (intval($affected) !== 1) {
                continue;
            }
            $reverted++;
            RefundLog::write(
                (string)$row['refund_no'],
                (string)$row['order_no'],
                intval($row['status']),
                $to,
                '对账回置，待重试',
                '处理超过 ' . $minutes . ' 分钟未收敛',
                3,
                0
            );
        }
        return $reverted;
    }

    /**
     * 历史订单与售后单对账补建（只补记录，不触发打款）
     *
     * 处理口径：
     *   - orders.refund IN (1,2) 且无售后单 → ensureDoc() 补建待审核/退货中单
     *   - orders.refund=4 且无售后单：有已完成退款流水时按流水补记完成单；
     *     无流水（线下/积分/口令或更早数据）时只出人工复核清单，不伪造完成态
     *   - orders.refund=4 且 paid=1 的脏状态 → 修复为 paid=0
     *   - 售后单在途/已完成但订单状态不符 → 只出人工复核清单，不自动收敛
     *
     * 默认 dry_run=true，不写任何数据；按 id 升序分批，after_id 可续跑。
     *
     * @param array $options shopid / app / limit / after_id / dry_run
     * @return array{summary:array, rows:array, last_id:int}
     */
    public static function reconcileLegacy(array $options = []): array
    {
        $shopid = max(0, intval($options['shopid'] ?? 0));
        $app = trim((string)($options['app'] ?? ''));
        $limit = max(1, min(intval($options['limit'] ?? 200), 500));
        $afterId = max(0, intval($options['after_id'] ?? 0));
        $dryRun = (bool)($options['dry_run'] ?? true);

        $summary = [
            'dry_run'       => $dryRun,
            'scanned'       => 0,
            'pending_doc'   => 0,
            'completed_doc' => 0,
            'paid_fixed'    => 0,
            'manual'        => 0,
            'skipped'       => 0,
            'failed'        => 0,
        ];
        $rows = [];
        $lastId = $afterId;

        // 只扫描非「未申请」状态；refund=0 的反向异常需另行 SQL 巡检
        $query = Db::name('orders')
            ->where('id', '>', $afterId)
            ->where('refund', 'in', [1, 2, 3, 4])
            ->order('id asc')
            ->limit($limit);
        if ($shopid > 0) {
            $query->where('shopid', $shopid);
        }
        if ($app !== '') {
            $query->where('app', $app);
        }
        $orders = $query->select()->toArray();

        foreach ($orders as $o) {
            $lastId = intval($o['id']);
            $summary['scanned']++;

            $orderRefund = intval($o['refund'] ?? 0);
            $orderNo = (string)($o['order_no'] ?? '');
            $appName = (string)($o['app'] ?? '');
            $doc = Db::name('refund')->where('order_no', $orderNo)->order('id desc')->find();
            $docStatus = empty($doc) ? null : intval($doc['status']);

            $action = 'skipped';
            $detail = '';
            $paidFixed = false;

            if ($docStatus === RefundStatus::COMPLETED && $orderRefund !== 4) {
                $action = 'manual';
                $detail = '售后单已完成但 orders.refund=' . $orderRefund . '，需人工核实';
            } elseif (in_array($orderRefund, [1, 2], true)) {
                if (empty($doc)) {
                    if (!self::supports($appName)) {
                        $action = 'manual';
                        $detail = '应用未接入售后策略，需先补 Policy 再补建';
                    } else {
                        $action = 'pending_doc';
                        $detail = '补建' . ($orderRefund === 2 ? '退货中' : '待审核') . '售后单';
                        if (!$dryRun) {
                            try {
                                self::ensureDoc($o);
                            } catch (\Throwable $e) {
                                $action = 'failed';
                                $detail = '补建失败：' . $e->getMessage();
                            }
                        }
                    }
                }
            } elseif ($orderRefund === 3 && empty($doc)) {
                $action = 'manual';
                $detail = '订单已退货但无售后单，需人工核实';
            } elseif ($orderRefund === 4) {
                // 已退款仍为已支付：与 autoRefund 的脏状态修复同口径
                if (intval($o['paid'] ?? 0) === 1) {
                    $paidFixed = true;
                    $detail = '修复已退款但仍为已支付（paid=0）';
                    if (!$dryRun) {
                        Db::name('orders')->where('id', intval($o['id']))->where('paid', 1)
                            ->update(['paid' => 0, 'update_time' => time()]);
                    }
                }

                if ($docStatus !== RefundStatus::COMPLETED) {
                    if (!empty($doc)) {
                        $action = 'manual';
                        $detail = ($detail !== '' ? $detail . '；' : '')
                            . '已有售后单但非完成态(status=' . $docStatus . ')，结果未知不可自动收敛';
                    } else {
                        // 与 RefundExecutor::findCompletedFlow 同口径：排除分账/佣金收入流水，
                        // 避免把「服务人员收入」误判为已退款而静默收敛订单
                        $flow = (array)(Db::name('capital_flow')
                            ->where('order_no', $orderNo)
                            ->where('shopid', intval($o['shopid'] ?? 0))
                            ->where('app', $appName)
                            ->where('uid', intval($o['uid'] ?? 0))
                            ->where('type', 2)
                            ->where('status', 1)
                            ->where('remark', 'not like', '%的收入%')
                            ->where('remark', 'not like', '%佣金%')
                            ->find() ?: []);

                        if (!empty($flow)) {
                            $action = 'completed_doc';
                            $detail = ($detail !== '' ? $detail . '；' : '') . '按已完成流水补记完成态售后单（不重新打款）';
                            if (!$dryRun) {
                                try {
                                    (new Refund())->recordCompleted(
                                        self::buildRefundNo($appName, $orderNo),
                                        $o,
                                        ['remark' => '历史数据补记', 'source' => 2],
                                        intval($flow['price'] ?? 0),
                                        (string)($flow['channel'] ?? '') === 'balance' ? 0 : 1,
                                        [
                                            'channel' => (string)($flow['channel'] ?? ''),
                                            'flow_no' => (string)($flow['flow_no'] ?? ''),
                                            'legacy'  => true,
                                        ]
                                    );
                                } catch (\Throwable $e) {
                                    $action = 'failed';
                                    $detail = '补记失败：' . $e->getMessage();
                                }
                            }
                        } else {
                            $action = 'manual';
                            $detail = ($detail !== '' ? $detail . '；' : '')
                                . '无已完成退款流水（线下/积分/口令或更早数据），需人工确认是否已打款';
                        }
                    }
                }
            }

            if ($paidFixed && $action === 'skipped') {
                $action = 'paid_fixed';
            }
            // paid_fixed 是附加统计维度，避免同一行被 action 与 paid_fixed 重复计数
            if ($action !== 'paid_fixed' && isset($summary[$action])) {
                $summary[$action]++;
            }
            if ($paidFixed) {
                $summary['paid_fixed']++;
            }

            $rows[] = [
                'order_no'     => $orderNo,
                'app'          => $appName,
                'shopid'       => intval($o['shopid'] ?? 0),
                'order_refund' => $orderRefund,
                'doc_status'   => $docStatus,
                'action'       => $action,
                'paid_fixed'   => $paidFixed ? 1 : 0,
                'detail'       => $detail,
            ];
        }

        return ['summary' => $summary, 'rows' => $rows, 'last_id' => $lastId];
    }

    /**
     * 退款去向自动判定：在策略允许的去向范围内按支付能力择优
     *
     * 供后台直退等未走售后单的入口复用，保证「去向判定」只有一份实现。
     *
     * @param array $order  订单数据
     * @param mixed $policy 已解析的退款策略（实现 RefundPolicyInterface）
     * @param int   $amount 应退金额（分）
     * @param array $items  退款明细
     * @return int 退款去向：0余额 1原路 2线下 3积分
     */
    public static function decideTarget(array $order, $policy, int $amount, array $items = []): int
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
     * 是否为唯一键冲突（并发重复提交时由数据库唯一索引抛出）
     */
    private static function isDuplicateKey(\Throwable $e): bool
    {
        $message = $e->getMessage();
        return strpos($message, 'Duplicate entry') !== false
            || strpos($message, '1062') !== false
            || strpos($message, 'uk_refund_no') !== false;
    }

    /**
     * 幂等退款单号：同一订单固定派生，重试复用同一渠道单号
     */
    public static function buildRefundNo(string $app, string $orderNo): string
    {
        return RefundExecutor::buildRefundNo($app, $orderNo);
    }
}
