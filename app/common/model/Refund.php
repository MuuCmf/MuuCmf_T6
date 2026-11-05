<?php

namespace app\common\model;

use think\facade\Db;

/**
 * 售后单
 *
 * 独立于 orders 的退款记录：支持幂等重试、崩溃恢复与后续部分退款。
 * 状态语义见 app\common\service\aftersale\RefundStatus。
 */
class Refund extends Base
{
    // 自动写入创建/更新时间（int 时间戳）
    protected $autoWriteTimestamp = true;

    /**
     * 预建/重置退款单为「打款中」
     *
     * 在调用外部网关前落库，保证打款成功后进程崩溃也能按单号对账并幂等重试。
     * 同一 refund_no 重试时复用原记录，不重复插入。
     *
     * @param string      $refundNo    业务退款单号（幂等键）
     * @param array       $order       订单数据
     * @param array       $refund      退款参数
     * @param int         $amount      退款金额
     * @param int         $refundTo    退款去向
     * @param string|null $outRefundNo 渠道退款单号（余额/积分/线下为 null）
     * @return array 退款单数据
     */
    public function createPending(string $refundNo, array $order, array $refund, int $amount, int $refundTo, ?string $outRefundNo = null): array
    {
        $now = time();
        $exist = $this->where('refund_no', $refundNo)->find();
        if ($exist) {
            $exist = $exist->toArray();
            if (intval($exist['status']) === \app\common\service\aftersale\RefundStatus::COMPLETED) {
                return $exist;
            }
            $from = intval($exist['status']);
            $this->where('id', intval($exist['id']))->update([
                'status'         => \app\common\service\aftersale\RefundStatus::PROCESSING,
                'refund_fee'     => $amount,
                'refund_to'      => $refundTo,
                'out_refund_no'  => $outRefundNo ?: null,
                'gateway_result' => null,
                'complete_time'  => 0,
                'update_time'    => $now,
            ]);
            RefundLog::write($refundNo, (string)($exist['order_no'] ?? ''), $from, \app\common\service\aftersale\RefundStatus::PROCESSING, '重新发起打款', '');
            $exist['status'] = \app\common\service\aftersale\RefundStatus::PROCESSING;
            $exist['refund_fee'] = $amount;
            $exist['refund_to'] = $refundTo;
            return $exist;
        }

        $data = [
            'refund_no'     => $refundNo,
            'order_no'      => (string)($order['order_no'] ?? ''),
            'order_id'      => intval($order['id'] ?? 0),
            'app'           => (string)($order['app'] ?? ''),
            'shopid'        => intval($order['shopid'] ?? 0),
            'uid'           => intval($order['uid'] ?? 0),
            'type'          => 1,
            'status'        => \app\common\service\aftersale\RefundStatus::PROCESSING,
            'order_fee'     => intval($order['paid_fee'] ?? 0),
            'refund_fee'    => $amount,
            'refund_to'     => $refundTo,
            'reason'        => (string)($refund['remark'] ?? ''),
            'source'        => intval($refund['source'] ?? 2),
            'out_refund_no' => $outRefundNo ?: null,
            'create_time'   => $now,
            'update_time'   => $now,
        ];
        $id = (int)$this->edit($data);
        RefundLog::write($refundNo, (string)$data['order_no'], 0, \app\common\service\aftersale\RefundStatus::PROCESSING, '预建退款单', (string)($refund['remark'] ?? ''));

        $data['id'] = $id;
        return $data;
    }

    /**
     * 标记退款完成
     *
     * @param string      $refundNo    退款单号
     * @param array       $result      渠道返回结果
     * @param int         $amount      实退金额
     * @param string|null $outRefundNo 渠道退款单号
     */
    public function markCompleted(string $refundNo, array $result, int $amount, ?string $outRefundNo = null): void
    {
        $now = time();
        $row = $this->where('refund_no', $refundNo)->find();
        $from = $row ? intval($row['status']) : 0;
        $this->where('refund_no', $refundNo)->update([
            'status'         => \app\common\service\aftersale\RefundStatus::COMPLETED,
            'refund_fee'     => $amount,
            'gateway_result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'complete_time'  => $now,
            'update_time'    => $now,
        ]);
        if ($outRefundNo !== null) {
            $this->where('refund_no', $refundNo)->update(['out_refund_no' => $outRefundNo]);
        }
        RefundLog::write($refundNo, (string)($row['order_no'] ?? ''), $from, \app\common\service\aftersale\RefundStatus::COMPLETED, '退款完成', '');
    }

    /**
     * 标记退款关闭（打款失败或人工关闭）
     */
    public function markClosed(string $refundNo, string $reason): void
    {
        $now = time();
        $row = $this->where('refund_no', $refundNo)->find();
        $from = $row ? intval($row['status']) : 0;
        $this->where('refund_no', $refundNo)->update([
            'status'         => \app\common\service\aftersale\RefundStatus::CLOSED,
            'gateway_result' => json_encode(['error' => $reason], JSON_UNESCAPED_UNICODE),
            'complete_time'  => $now,
            'update_time'    => $now,
        ]);
        RefundLog::write($refundNo, (string)($row['order_no'] ?? ''), $from, \app\common\service\aftersale\RefundStatus::CLOSED, '退款关闭', $reason);
    }

    /**
     * 按已存在的完成态流水补记退款单（旧链路已打款但未收敛订单）
     */
    public function recordCompleted(string $refundNo, array $order, array $refund, int $amount, int $refundTo, array $result): void
    {
        $exist = $this->where('refund_no', $refundNo)->find();
        if ($exist && intval($exist['status']) === \app\common\service\aftersale\RefundStatus::COMPLETED) {
            return;
        }
        if ($exist) {
            $this->markCompleted($refundNo, $result, $amount);
            return;
        }
        $now = time();
        $data = [
            'refund_no'     => $refundNo,
            'order_no'      => (string)($order['order_no'] ?? ''),
            'order_id'      => intval($order['id'] ?? 0),
            'app'           => (string)($order['app'] ?? ''),
            'shopid'        => intval($order['shopid'] ?? 0),
            'uid'           => intval($order['uid'] ?? 0),
            'type'          => 1,
            'status'        => \app\common\service\aftersale\RefundStatus::COMPLETED,
            'order_fee'     => intval($order['paid_fee'] ?? 0),
            'refund_fee'    => $amount,
            'refund_to'     => $refundTo,
            'reason'        => (string)($refund['remark'] ?? ''),
            'source'        => intval($refund['source'] ?? 2),
            'out_refund_no' => null,
            'gateway_result' => json_encode($result, JSON_UNESCAPED_UNICODE),
            'complete_time' => $now,
            'create_time'   => $now,
            'update_time'   => $now,
        ];
        $this->edit($data);
        RefundLog::write($refundNo, (string)$data['order_no'], 0, \app\common\service\aftersale\RefundStatus::COMPLETED, '按已完成流水补记', '');
    }
}
