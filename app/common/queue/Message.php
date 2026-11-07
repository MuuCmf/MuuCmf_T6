<?php

namespace app\common\queue;

use think\facade\Db;
use think\queue\Job;
use app\common\service\message\Dispatcher;
use app\common\model\MessageTask;

/**
 * 统一消息推送中心队列任务
 *
 * 粒度：一批（默认 500 用户）一个 job，批次之间用 Queue::later 串行推进；
 * job 级失败由 failed.type=database 记录 failed_jobs，业务失败落 message_record。
 */
class Message
{
    /**
     * @param Job   $job
     * @param array $data ['task_id' => int]
     * @return void
     */
    public function handle(Job $job, $data)
    {
        $taskId = (int)($data['task_id'] ?? 0);
        if ($taskId <= 0) {
            $job->delete();
            return;
        }

        try {
            $result = (new Dispatcher())->runBatch($taskId);
            if (($result['reason'] ?? '') === 'batch_locked') {
                // 同一任务已有批次在跑：稍后重投，不能直接丢弃，否则任务会停在中途
                $job->release(30);
                return;
            }
            trace('[message] dispatch task ' . $taskId . ' done: ' . json_encode($result, JSON_UNESCAPED_UNICODE), 'info');
        } catch (\Throwable $e) {
            trace('[message] dispatch task ' . $taskId . ' exception: ' . $e->getMessage(), 'error');
            // 交给队列重投（幂等键保证不会重复触达）
            if ($job->attempts() < 3) {
                $job->release(60);
                return;
            }
        }

        $job->delete();
    }

    /**
     * 队列级失败
     *
     * @param array $data
     * @return void
     */
    public function failed($data)
    {
        try {
            trace('[message] dispatch job failed: ' . json_encode($data, JSON_UNESCAPED_UNICODE), 'error');

            // 队列级失败后任务不会再被推进，直接置 failed，
            // 避免长期停在 running 让运营误以为还在发（补偿会重投非终态任务）
            $taskId = (int)($data['task_id'] ?? 0);
            if ($taskId > 0) {
                Db::name('message_task')
                    ->where('id', $taskId)
                    ->whereNotIn('status', MessageTask::TERMINAL_STATUS)
                    ->update([
                        'status'      => MessageTask::STATUS_FAILED,
                        'finish_time' => time(),
                        'update_time' => time(),
                    ]);
            }
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
