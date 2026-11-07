<?php

namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use app\common\service\aftersale\RefundCenter;

/**
 * 历史订单与售后单对账补建
 *
 * 默认 dry-run（只出报告不写库），确认报告后加 --apply 才会补建记录。
 * 只补售后单与订单脏状态，绝不触发打款；按 id 升序分批，可用 --after-id 续跑。
 *
 * 用法：
 *   php think refund:reconcile
 *   php think refund:reconcile --shopid 1 --app minishop --limit 500 --report runtime/refund_reconcile.csv
 *   php think refund:reconcile --apply
 */
class RefundReconcile extends Command
{
    protected function configure()
    {
        $this->setName('refund:reconcile')
            ->addOption('shopid', null, Option::VALUE_OPTIONAL, '站点ID，0 或不传表示全部')
            ->addOption('app', null, Option::VALUE_OPTIONAL, '只处理指定应用，如 minishop / creator / docs')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '单批处理上限（1-500，默认 200）')
            ->addOption('after-id', null, Option::VALUE_OPTIONAL, '从指定订单ID之后继续扫描')
            ->addOption('show', null, Option::VALUE_OPTIONAL, '控制台打印明细条数（默认 20）')
            ->addOption('report', null, Option::VALUE_OPTIONAL, 'CSV 报告输出路径')
            ->addOption('apply', null, Option::VALUE_NONE, '实际写库；不传则为 dry-run')
            ->setDescription('历史订单与售后单对账补建（默认 dry-run，只补记录不打款）');
    }

    protected function execute(Input $input, Output $output)
    {
        $dryRun = !(bool)$input->getOption('apply');
        $options = [
            'shopid'   => max(0, intval($input->getOption('shopid') ?? 0)),
            'app'      => trim((string)($input->getOption('app') ?? '')),
            'limit'    => max(1, min(intval($input->getOption('limit') ?? 200), 500)),
            'after_id' => max(0, intval($input->getOption('after-id') ?? 0)),
            'dry_run'  => $dryRun,
        ];
        $show = max(0, intval($input->getOption('show') ?? 20));
        $report = trim((string)($input->getOption('report') ?? ''));

        try {
            $result = RefundCenter::reconcileLegacy($options);
        } catch (\Throwable $e) {
            $output->error('对账失败（请确认已执行 data/upgrade.sql 建好 muucmf_refund 表）：' . $e->getMessage());
            return 1;
        }

        $summary = $result['summary'];
        $rows = $result['rows'];

        $output->writeln('模式：' . ($dryRun ? 'DRY-RUN（未写库，确认后加 --apply 执行）' : 'APPLY（已写库）'));
        $output->writeln(sprintf(
            '扫描 %d 单 | 补建在途单 %d | 按流水补记 %d | 修复 paid %d | 人工复核 %d | 跳过 %d | 失败 %d | last_id %d',
            $summary['scanned'],
            $summary['pending_doc'],
            $summary['completed_doc'],
            $summary['paid_fixed'],
            $summary['manual'],
            $summary['skipped'],
            $summary['failed'],
            $result['last_id']
        ));

        if ($show > 0 && !empty($rows)) {
            $output->writeln('明细（最多 ' . $show . ' 条）：');
            foreach (array_slice($rows, 0, $show) as $row) {
                $output->writeln(implode(' | ', [
                    'order_no=' . $row['order_no'],
                    'app=' . $row['app'],
                    'refund=' . $row['order_refund'],
                    'doc=' . ($row['doc_status'] === null ? '-' : $row['doc_status']),
                    'action=' . $row['action'],
                    $row['detail'],
                ]));
            }
        }

        if ($report !== '') {
            if (!$this->writeReport($report, $rows, $output)) {
                return 1;
            }
            $output->writeln('报告已写入：' . $report);
        }

        if ($dryRun) {
            $output->writeln('确认报告后重跑并加 --apply；未接入策略的应用需先补 service/aftersale/Policy.php。');
        }
        if ($summary['failed'] > 0) {
            $output->error('存在 ' . $summary['failed'] . ' 条处理失败，请查看明细后重试（脚本可重放）。');
            return 1;
        }
    }

    /**
     * 输出 CSV 报告（带 BOM，便于 Excel 直接打开中文）
     *
     * @param string $path
     * @param array  $rows
     * @param Output $output
     * @return bool
     */
    protected function writeReport(string $path, array $rows, Output $output): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            $output->error('报告目录不存在：' . $dir);
            return false;
        }
        $fp = @fopen($path, 'w');
        if ($fp === false) {
            $output->error('无法写入报告文件：' . $path);
            return false;
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['order_no', 'app', 'shopid', 'order_refund', 'doc_status', 'action', 'paid_fixed', 'detail']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                $row['order_no'],
                $row['app'],
                $row['shopid'],
                $row['order_refund'],
                $row['doc_status'] === null ? '' : $row['doc_status'],
                $row['action'],
                $row['paid_fixed'],
                $row['detail'],
            ]);
        }
        fclose($fp);
        return true;
    }
}
