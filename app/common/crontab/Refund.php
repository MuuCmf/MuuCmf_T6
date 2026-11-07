<?php

namespace app\common\crontab;

use think\facade\Log;
use app\common\model\CrontabLog;
use app\common\service\aftersale\RefundCenter;

/**
 * @title 售后退款审核超时关闭、滞留单对账与遗留订单巡检
 *
 * 前三个动作会写库（超时关闭 / 滞留回置 / 退货超时关闭），第四个动作是只读巡检：
 * 调用 RefundCenter::reconcileLegacy(dry_run=true) 对账 orders.refund 与 muucmf_refund，
 * 发现不一致只告警，不自动补建、不触发打款；补建需人工确认后执行
 * php think refund:reconcile --apply（见 app/common/command/RefundReconcile.php）。
 */
class Refund
{
    /**
     * 审核超时小时数（固定 12 小时）
     */
    protected const AUDIT_EXPIRE_HOURS = 12;

    /**
     * 打款滞留分钟数（固定 30 分钟，超时未收敛即回置待重试）
     */
    protected const RECONCILE_MINUTES = 30;

    /**
     * 退货超时天数（固定 7 天，与订单自动收货周期一致）
     */
    protected const RETURN_EXPIRE_DAYS = 7;

    /**
     * 遗留订单巡检单批上限（只读 dry-run，不写库）
     */
    protected const LEGACY_SCAN_LIMIT = 200;

    /**
     * @title 业务处理
     * @param int $shopid
     * @param int $task_id
     * @return bool
     */
    public function handle(int $shopid, int $task_id)
    {
        try {
            $hours = self::AUDIT_EXPIRE_HOURS;
            $closed = RefundCenter::closeExpired($hours, 200, $shopid);
            // 打款成功但落账失败的单据会停在「打款中」，定期回置为「已同意」恢复后台重试入口
            $minutes = self::RECONCILE_MINUTES;
            $reverted = RefundCenter::reconcileStuck($minutes, 200, $shopid);
            // 退货流程兜底：同意退货后长期未收到商品，关闭售后单并释放订单退货状态
            $days = self::RETURN_EXPIRE_DAYS;
            $returnClosed = RefundCenter::closeReturnTimeout($days, 200, $shopid);

            // 遗留订单巡检：只读 dry-run 对账，发现「订单退款状态与售后单不一致」时告警，
            // 不自动补建、不触发打款；补建由人工确认后执行 php think refund:reconcile --apply
            $legacyScanned = 0;
            $legacyFixable = 0;
            $legacyManual = 0;
            try {
                $legacy = RefundCenter::reconcileLegacy([
                    'shopid'  => $shopid,
                    'limit'   => self::LEGACY_SCAN_LIMIT,
                    'dry_run' => true,
                ]);
                $summary = $legacy['summary'] ?? [];
                $legacyScanned = intval($summary['scanned'] ?? 0);
                $legacyFixable = intval($summary['pending_doc'] ?? 0) + intval($summary['completed_doc'] ?? 0);
                $legacyManual = intval($summary['manual'] ?? 0);
                if ($legacyFixable > 0 || $legacyManual > 0) {
                    $samples = [];
                    foreach (($legacy['rows'] ?? []) as $row) {
                        if (!in_array(($row['action'] ?? ''), ['manual', 'pending_doc', 'completed_doc'], true)) {
                            continue;
                        }
                        $samples[] = $row['order_no'] . '(' . $row['action'] . ')';
                        if (count($samples) >= 5) {
                            break;
                        }
                    }
                    Log::warning('[售后巡检] 遗留订单待人工 ' . $legacyManual . ' 单、可补建 ' . $legacyFixable
                        . ' 单，示例：' . implode('、', $samples)
                        . '；确认后执行 php think refund:reconcile --apply');
                }
            } catch (\Throwable $e) {
                // 巡检失败不影响已完成的三个治理动作（例如未执行升级脚本、售后单表缺失）
                Log::warning('[售后巡检] 遗留订单巡检跳过：' . $e->getMessage());
            }

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => 'success，关闭 ' . $closed . ' 单，回置 ' . $reverted . ' 单，关闭退货超时 ' . $returnClosed . ' 单，超时阈值 '
                    . $hours . ' 小时/' . $minutes . ' 分钟/' . $days . ' 天；遗留订单巡检 ' . $legacyScanned
                    . ' 单（可补建 ' . $legacyFixable . ' / 待人工 ' . $legacyManual . '）',
            ]);
            return true;
        } catch (\Throwable $e) {
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => $e->getMessage(),
                'status'      => 0,
            ]);
            return false;
        }
    }
}
