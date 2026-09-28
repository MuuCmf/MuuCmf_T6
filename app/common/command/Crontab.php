<?php

namespace app\common\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Workerman\Lib\Timer;
use Workerman\Worker;
use app\common\model\Crontab as CrontabModel;
use app\common\model\CrontabLog;

class Crontab extends Command
{
    protected $interval;
    protected $CrontabModel; //计划任务模型

    /** 任务执行类最小间隔（秒），防止 0/负值导致 Timer 高频风暴 */
    const MIN_INTERVAL = 60;

    public function __construct()
    {
        parent::__construct();
        $this->CrontabModel = new CrontabModel();
    }

    protected function configure()
    {
        // 指令配置 php think crontab start --d 守护进程启动
        $this->setName('crontab')
            ->addArgument('action', Argument::OPTIONAL, 'start/stop/reload/status/connections', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the crontab server in daemon mode.')
            ->setDescription('开启/关闭/重启 定时任务');
    }
    protected function init(Input $input, Output $output)
    {
        $action = $input->getArgument('action');
        global $argv;
        array_shift($argv);
        array_shift($argv);
        array_unshift($argv, 'muucmf', $action);

        Worker::$pidFile = app()->getRootPath() . 'Crontab.pid';

        // 开启守护进程模式
        if ($this->input->hasOption('daemon')) {
            Worker::$daemonize = true;
        }
    }

    protected function execute(Input $input, Output $output)
    {
        $this->init($input, $output);
        //创建定时器任务
        $task = new Worker();
        $task->name = 'Crontab';
        $task->count = 1;
        $task->onWorkerStart = [$this, 'start'];
        $task->runAll();
    }

    /**
     * 校验任务执行类是否合法
     * 仅允许 app\{模块}\crontab\{类名} 命名空间下真实存在的类，防止数据库 execute 字段被污染导致任意类实例化
     *
     * @param string $execute
     * @return bool
     */
    protected function isValidTaskClass($execute)
    {
        $execute = (string)$execute;
        if (!preg_match('/^app\\\\[a-zA-Z0-9_]+\\\\crontab\\\\[A-Za-z0-9_]+$/', $execute)) {
            return false;
        }
        return class_exists($execute);
    }

    /**
     * 安全执行任务：异常不中断调度循环，结果记入任务日志
     *
     * @param array $task
     * @return void
     */
    protected function runTask(array $task)
    {
        $shopid = intval($task['shopid'] ?? 0);
        $task_id = intval($task['id'] ?? 0);
        $execute = (string)($task['execute'] ?? '');

        // 执行类校验（命名空间白名单 + 类存在性）
        if (!$this->isValidTaskClass($execute)) {
            CrontabLog::addLog([
                'shopid' => $shopid,
                'cid'    => $task_id,
                'description' => '任务执行类不合法或不存在: ' . substr($execute, 0, 120),
                'status' => 0,
            ]);
            return;
        }

        try {
            $handler = new $execute();
            if (!method_exists($handler, 'handle')) {
                CrontabLog::addLog([
                    'shopid' => $shopid,
                    'cid'    => $task_id,
                    'description' => '任务类缺少 handle 方法: ' . substr($execute, 0, 120),
                    'status' => 0,
                ]);
                return;
            }
            $handler->handle($shopid, $task_id);
        } catch (\Throwable $e) {
            // 任务异常不允许拖垮整个调度循环
            CrontabLog::addLog([
                'shopid' => $shopid,
                'cid'    => $task_id,
                'description' => '任务执行异常: ' . mb_substr($e->getMessage(), 0, 200),
                'status' => 0,
            ]);
        }
    }

    /**
     * @title 开启定时任务
     */
    public function start()
    {
        //查询任务队列
        $map = [
            ['status', '=', 1]
        ];
        $task_list = $this->CrontabModel->where($map)->field('id,shopid,execute,cycle,day,hour,minute')->select()->toArray();
        foreach ($task_list as $index => $task) {
            //任务执行类合法性前置校验：不合法直接跳过，避免 fatal error 拖垮调度器
            if (!$this->isValidTaskClass($task['execute'])) {
                CrontabLog::addLog([
                    'shopid' => intval($task['shopid']),
                    'cid'    => intval($task['id']),
                    'description' => '任务执行类不合法或不存在，已跳过: ' . substr((string)$task['execute'], 0, 120),
                    'status' => 0,
                ]);
                continue;
            }
            //格式化天
            $d = max(0, intval($task['day']));
            //格式化小时
            $h = max(0, min(23, intval($task['hour'])));
            //格式化分钟
            $i = max(0, min(59, intval($task['minute'])));

            switch ($task['cycle']) {
                case 'month': //每月执行
                    $d = max(1, min(31, $d));
                    $rule = "{$i} {$h} {$d} * *";
                    break;
                case 'day': //每天执行
                    $rule = "{$i} {$h} * * *";
                    break;
                case 'hour': //每小时执行
                    $rule = "{$i} * * * *";
                    break;
                default:
                    //N段时间执行
                    switch ($task['cycle']) {
                        case 'day-n': //n天执行
                            $time_interval = ($d * 24 * 60 * 60) + ($h * 60 * 60) + (60 * $i);
                            break;
                        case 'hour-n': //n小时执行
                            $time_interval = ($h * 60 * 60) + (60 * $i);
                            break;
                        case 'minute-n': //n分钟执行
                            $time_interval = $i * 60;
                            break;
                        default:
                            $time_interval = self::MIN_INTERVAL;
                            break;
                    }
                    //间隔下限保护：防止 0/负间隔导致 Timer 高频死循环
                    $time_interval = max(self::MIN_INTERVAL, $time_interval);
                    Timer::add($time_interval, function () use ($task) {
                        $this->runTask($task); //处理任务（带校验与异常容错）
                    });
                    $rule = false;
                    break;
            }
            //完整日期任务
            if ($rule) {
                //加载任务
                new \Workerman\Crontab\Crontab($rule, function () use ($task) {
                    $this->runTask($task); //处理任务（带校验与异常容错）
                });
            }
        }
    }

    public function stop()
    {
        //手动暂停定时器
        Worker::stopAll();
    }
}
