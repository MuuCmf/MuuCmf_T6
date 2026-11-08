<?php

namespace app\common\queue;

use think\queue\Job;
use app\common\service\message\Dispatcher;

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
                // 同一任务已有批次在跑：稍后重投，但不能无限重投堆积
                if ($job->attempts() < 3) {
                    $job->release(30);
                    return;
                }
                // 超过上限直接丢弃，由 MessageRetry 的补偿段重新投递
                $job->delete();
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
            // 手动 delete 会绕过框架的 failJob / failed() / failed_jobs，必须把异常交回 Worker
            throw $e;
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
            // 框架已把失败 job 写入 failed_jobs，这里只记业务日志。
            // 不能把任务置 failed 终态：resumeStaleTasks 只补偿非终态任务，
            // 否则游标停在中途时剩余用户将永远收不到消息。
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
