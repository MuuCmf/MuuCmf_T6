<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\crontab\Orders as OrdersTask;
use app\common\crontab\Evaluate as EvaluateTask;
use app\minishop\crontab\Receive as ReceiveTask;
use app\common\model\CrontabLog;
use think\Exception;

/**
 * 订单自动取消/自动评价/自动确认收货
 * 
 * 1.自动取消超时未支付订单
 * 2.自动评价超过7天未评价的已完成订单
 * 3.自动确认收货（minishop 订单超过7天未确认）
 * 通过URL方式调用，需携带 system.CRON_SECRET 配置的密钥
 * 
 * 实现统一委托给 crontab 任务类（与 CLI 调度器共用同一逻辑与日志）
 */
class Crontab extends Api
{
    /**
     * 定时任务密钥校验
     * 仅允许携带正确 secret 的调用方（如系统定时任务/内网调度）执行批量写操作
     *
     * @return bool
     */
    protected function checkCronSecret()
    {
        // 密钥来自系统配置 system.CRON_SECRET（后端配置界面可设置）
        $secret = (string)config('system.CRON_SECRET', '');
        $input = (string)input('secret', '', 'trim');
        // 未配置密钥时直接拒绝，防止接口裸奔
        if ($secret === '' || $input === '') {
            return false;
        }
        return hash_equals($secret, $input);
    }

    /**
     * 任务标识映射（用于 URL 调用时写入日志的 cid）
     * 与 crontab 表默认任务 id 保持一致；也可通过参数 cid 覆盖
     *
     * @param string $default
     * @return int
     */
    protected function taskId($default)
    {
        return intval(input('cid', $default, 'intval'));
    }

    /**
     * 自动取消超时未支付订单
     * 查询24小时前创建且未支付的订单,将其状态更新为已取消
     * 每次处理最多100条订单记录,使用事务确保数据一致性
     *
     * @return \think\Response
     */
    public function ordersCancel()
    {
        if (!$this->checkCronSecret()) {
            return json([
                'code' => 0,
                'msg' => '定时任务密钥校验失败',
            ]);
        }
        try {
            $result = (new OrdersTask())->handle(intval($this->shopid), $this->taskId(5));
            return json([
                'code' => $result ? 200 : 0,
                'msg' => $result ? 'success' : '处理失败',
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 订单自动评价
     * 处理超过7天未评价的已完成订单,自动添加默认好评
     *
     * @return \think\Response
     */
    public function ordersEvaluate()
    {
        if (!$this->checkCronSecret()) {
            return json([
                'code' => 0,
                'msg' => '定时任务密钥校验失败',
            ]);
        }
        try {
            $result = (new EvaluateTask())->handle(intval($this->shopid), $this->taskId(3));
            return json([
                'code' => $result ? 200 : 0,
                'msg' => $result ? 'success' : '处理失败',
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 订单自动确认收货
     * 处理 minishop 超过7天未确认收货的订单,自动完成收货进入待评价
     *
     * @return \think\Response
     */
    public function ordersReceive()
    {
        if (!$this->checkCronSecret()) {
            return json([
                'code' => 0,
                'msg' => '定时任务密钥校验失败',
            ]);
        }
        try {
            $result = (new ReceiveTask())->handle(intval($this->shopid), $this->taskId(1));
            return json([
                'code' => $result ? 200 : 0,
                'msg' => $result ? 'success' : '处理失败',
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 日志轮转：清理指定天数前的任务执行日志
     * 建议配置为每日/每周调用一次，例如 /api/crontab/clearLogs?secret=xxx&days=180
     *
     * @return \think\Response
     */
    public function clearLogs()
    {
        if (!$this->checkCronSecret()) {
            return json([
                'code' => 0,
                'msg' => '定时任务密钥校验失败',
            ]);
        }
        $days = intval(input('days', 180));
        try {
            $deleted = CrontabLog::rotateLogs($days);
            return json([
                'code' => 200,
                'msg' => 'success',
                'data' => ['deleted' => $deleted],
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 0,
                'msg' => $e->getMessage(),
            ]);
        }
    }
}
