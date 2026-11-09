<?php
declare(strict_types = 1);

namespace app\common\crontab;

use think\facade\Db;
use think\facade\Log;
use app\common\model\CrontabLog;
use app\common\service\message\MessageCenter;

/**
 * 会员到期提醒（每天一次，随框架发布）
 *
 * 扫描 muucmf_vip 中到期前 7/3/1 天的有效会员，按店铺批量调用 MessageCenter
 * 发送 svip.expire_remind。会员表是框架核心表，故任务类放在 common，
 * 不依赖可卸载的 svip 模块（模块未安装时只是无会员数据，不会 fatal）。
 *
 * 口径说明：
 * 1. end_time = 0 为永久会员，不提醒；status = 1 才提醒；uid <= 0 跳过。
 * 2. expire_time 传到期「日期」而非精确时刻：同一提醒节点的会员都在当天到期，
 *    nickname 属于用户级变量，由 Dispatcher::userVarsMap 逐用户注入，
 *    这里绝不传共享昵称，避免跨用户信息泄露。
 * 3. 渠道完全交给模板配置（fallback = ["inbox"]）与用户订阅偏好决定，
 *    本任务不传 channels，不绕过免打扰 / 退订 / 黑名单 / 频控。
 * 4. 模板默认频控为 7 天内最多 3 次（install.sql / upgrade.sql / MessageSeed
 *    已对齐），与 7/3/1 三个节点一一对应。后台若调低该值，靠后的节点会被
 *    拦为 blocked，属预期治理行为，任务侧不在代码里绕过频控。
 * 5. biz_key = svip_expire_{节点天数}_{目标到期日}，同一会员同一提醒周期
 *    只触达一次；消息中心的「用户级成功判定」保证降级/重试不重复发送。
 */
class VipExpireRemind
{
    /** 提醒节点：到期前 7/3/1 天 */
    public const REMIND_DAYS = [7, 3, 1];

    /** 模板编码 */
    public const TEMPLATE_CODE = 'svip.expire_remind';

    /** 单页扫描人数：查询次数与用户数解耦，每 500 人一次查询 */
    protected const PAGE_SIZE = 500;

    /** 单节点最多扫描页数（500 × 40 = 2 万人），防止异常数据量拖垮调度进程 */
    protected const MAX_PAGES = 40;

    /** 全局互斥锁：本任务一次扫描全部店铺，不按 shopid 分锁 */
    protected const LOCK_NAME = 'muucmf_vip_expire_remind';

    /**
     * 定时任务入口
     *
     * 框架调度器按 handle(int $shopid, int $task_id) 反射调用，缺少该方法
     * 只会写一条「任务类缺少 handle 方法」日志而永不执行。
     *
     * @param int $shopid  定时任务行的店铺 ID（本任务为全局任务，固定 0）
     * @param int $task_id 定时任务 ID
     * @return bool
     */
    public function handle(int $shopid = 0, int $task_id = 0): bool
    {
        if (!$this->lock()) {
            return true;
        }

        $scanned  = 0;
        $submitted = 0;
        $batches  = 0;
        $failed   = 0;
        $skipped  = 0;

        try {
            foreach (self::REMIND_DAYS as $days) {
                [$dayStart, $dayEnd] = $this->dayWindow($days);
                $expireDate = date('Y-m-d', $dayStart);
                $bizKey = 'svip_expire_' . $days . '_' . date('Ymd', $dayStart);

                // 先按店铺聚合全量 uid，再每个店铺只提交一个发送任务：
                // 由消息中心内部按 500 一批切批，调用方无需自己分片，
                // 也避免同一节点拆成多个任务后并发触发重复触达。
                $uidsByShop = [];
                $lastId = 0;

                for ($page = 0; $page < self::MAX_PAGES; $page++) {
                    $rows = Db::name('vip')
                        ->where('id', '>', $lastId)
                        ->where('status', 1)
                        ->where('end_time', 'between', [$dayStart, $dayEnd])
                        ->where('uid', '>', 0)
                        ->where('shopid', '>=', 0)
                        ->order('id', 'asc')
                        ->limit(self::PAGE_SIZE)
                        ->field('id, shopid, uid, end_time')
                        ->select()
                        ->toArray();

                    if (empty($rows)) {
                        break;
                    }

                    $lastId = (int)$rows[count($rows) - 1]['id'];
                    $scanned += count($rows);

                    foreach ($rows as $row) {
                        $uid = (int)($row['uid'] ?? 0);
                        $sid = (int)($row['shopid'] ?? 0);
                        if ($uid <= 0 || $sid < 0) {
                            $skipped++;
                            continue;
                        }
                        $uidsByShop[$sid][] = $uid;
                    }

                    if (count($rows) < self::PAGE_SIZE) {
                        break;
                    }
                }

                foreach ($uidsByShop as $sid => $uids) {
                    $uids = array_values(array_unique(array_map('intval', $uids)));
                    if (empty($uids)) {
                        continue;
                    }

                    try {
                        MessageCenter::send(self::TEMPLATE_CODE, [
                            'type'  => 'uids',
                            'value' => $uids,
                        ], [
                            'expire_time' => $expireDate,
                        ], [
                            'shopid'  => (int)$sid,
                            'biz_key' => $bizKey,
                        ]);
                        $batches++;
                        $submitted += count($uids);
                    } catch (\Throwable $e) {
                        // 模板被停用 / 表不存在 / 参数异常都不影响其他店铺与主流程
                        $failed += count($uids);
                        Log::warning('[svip] 到期提醒提交失败 days=' . $days . ' shopid=' . $sid
                            . '：' . $e->getMessage());
                    }
                }
            }

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => 'success(扫描' . $scanned . ' 提交' . $submitted
                    . ' 批次' . $batches . ' 跳过' . $skipped . ' 失败' . $failed . ') 会员到期提醒',
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('[svip] 会员到期提醒任务异常：' . $e->getMessage());
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => mb_substr($e->getMessage(), 0, 200),
                'status'      => 0,
            ]);

            return false;
        } finally {
            $this->unlock();
        }
    }

    /**
     * 计算目标日的时间窗（本地时区，含当天 00:00:00 ~ 23:59:59）
     *
     * @param int $days 到期前天数
     * @return array [起始时间戳, 结束时间戳]
     */
    protected function dayWindow(int $days): array
    {
        $start = strtotime(date('Y-m-d', strtotime('+' . $days . ' day')) . ' 00:00:00');
        return [$start, $start + 86399];
    }

    /**
     * 获取全局互斥锁
     *
     * @return bool
     */
    protected function lock(): bool
    {
        try {
            $result = Db::query('SELECT GET_LOCK(?, 5) AS locked', [self::LOCK_NAME]);
            return !empty($result[0]['locked']);
        } catch (\Throwable $e) {
            Log::warning('[svip] lock failed, skip remind: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 释放全局互斥锁
     *
     * @return void
     */
    protected function unlock(): void
    {
        try {
            Db::query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
