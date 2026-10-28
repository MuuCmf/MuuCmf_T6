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
    /** 支持的操作 */
    const ACTIONS = ['start', 'stop', 'restart', 'reload', 'status', 'connections'];

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
            ->addArgument('action', Argument::OPTIONAL, 'start/stop/restart/reload/status/connections', 'start')
            ->addOption('daemon', 'd', Option::VALUE_NONE, 'Run the crontab server in daemon mode (Linux only).')
            ->setDescription('开启/关闭/重启 定时任务');
    }

    /**
     * 是否 Windows 环境
     * Workerman 在 Windows / Linux 下的启动方式、命令解析、信号机制完全不同
     *
     * @return bool
     */
    protected function isWindows()
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    protected function execute(Input $input, Output $output)
    {
        $action = (string)$input->getArgument('action');
        if (!in_array($action, self::ACTIONS, true)) {
            $output->error('未知操作: ' . $action . '，可选: ' . implode(' / ', self::ACTIONS));
            return;
        }

        Worker::$pidFile = app()->getRootPath() . 'Crontab.pid';

        // 真实存在的入口文件（Windows 下 Workerman 靠 argv 中的真实文件识别启动项）
        $startFile = realpath(app()->getRootPath() . 'think');
        if ($startFile === false) {
            $startFile = app()->getRootPath() . 'think';
        }

        if ($this->isWindows()) {
            // Windows：Workerman 3.x 的 parseCommand 直接 return（不识别命令/信号/守护进程），
            // 且 forkWorkersForWindows 只有在「argv 中恰好 1 个真实文件」时才在当前进程内运行 worker。
            // 所以 argv 固定为 [真实 think 入口, start]（is_file 命中 → 直接 run()）；
            // stop/restart/status 由本类基于 pid 文件实现，否则 stop 会被当成 start 再拉起一份调度器。
            global $argv;
            $argv = [$startFile, 'start'];

            if ($action !== 'start' && !$this->handleWindowsAction($action, $output)) {
                return;
            }

            if ((bool)$input->getOption('daemon')) {
                $output->writeln('[提示] Windows 不支持 -d 守护进程模式，定时任务将以前台进程运行（Ctrl+C 退出）');
            }

            $pid = $this->readPid();
            if ($pid && $this->isProcessAlive($pid)) {
                $output->writeln('定时任务已在运行 (PID: ' . $pid . ')，如需重启请执行 php think crontab restart');
                return;
            }
            @unlink(Worker::$pidFile);
        } else {
            // Linux：Workerman 依赖 argv[1] 识别 start/stop/restart/reload/status/connections，
            // argv[0] 保留真实入口文件（status/restart 会复用该路径）；守护进程由 Worker::$daemonize 控制
            global $argv;
            $argv = [$startFile, $action];
            if ((bool)$input->getOption('daemon')) {
                Worker::$daemonize = true;
            }
        }

        //创建定时器任务
        $task = new Worker();
        $task->name = 'Crontab';
        $task->count = 1;
        $task->onWorkerStart = [$this, 'start'];
        $task->runAll();
    }

    /**
     * Windows 下的非 start 操作
     *
     * @param string $action
     * @param Output $output
     * @return bool true=继续启动调度器（restart），false=已处理完成
     */
    protected function handleWindowsAction($action, Output $output)
    {
        switch ($action) {
            case 'stop':
                $this->stopWindows($output);
                return false;
            case 'restart':
                $this->stopWindows($output);
                return true;
            case 'status':
                $pid = $this->readPid();
                $output->writeln($pid && $this->isProcessAlive($pid)
                    ? '定时任务运行中 (PID: ' . $pid . ')'
                    : '定时任务未运行');
                return false;
            default: // reload / connections 依赖 Linux 信号机制
                $output->writeln('[提示] Windows 不支持 ' . $action . '，请使用 restart');
                return false;
        }
    }

    /**
     * Windows 下按 pid 文件结束调度器进程
     *
     * @param Output $output
     * @return void
     */
    protected function stopWindows(Output $output)
    {
        $pid = $this->readPid();
        if (!$pid || !$this->isProcessAlive($pid)) {
            @unlink(Worker::$pidFile);
            $output->writeln('定时任务未运行');
            return;
        }

        if (!function_exists('exec')) {
            $output->error('当前 PHP 禁用了 exec，无法结束进程，请手动结束 PID: ' . $pid);
            return;
        }

        $result = [];
        $code = 1;
        exec('taskkill /F /T /PID ' . $pid . ' 2>NUL', $result, $code);
        // 等待进程真正退出，避免 restart 后误判「已在运行」
        for ($i = 0; $i < 10 && $this->isProcessAlive($pid); $i++) {
            usleep(200000);
        }
        @unlink(Worker::$pidFile);

        if ($code === 0) {
            $output->writeln('定时任务已停止 (PID: ' . $pid . ')');
        } else {
            $output->error('停止失败，请手动结束进程 PID: ' . $pid);
        }
    }

    /**
     * 读取 pid 文件中的进程号
     *
     * @return int
     */
    protected function readPid()
    {
        $pidFile = Worker::$pidFile;
        if (!$pidFile || !is_file($pidFile)) {
            return 0;
        }
        $pid = intval(trim((string)@file_get_contents($pidFile)));
        return $pid > 0 ? $pid : 0;
    }

    /**
     * Windows 下判断进程是否存活
     *
     * @param int $pid
     * @return bool
     */
    protected function isProcessAlive($pid)
    {
        $pid = intval($pid);
        if ($pid <= 0) {
            return false;
        }
        if (!function_exists('exec')) {
            return true; // 无法探测，保守认为仍在运行
        }
        $result = [];
        exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $result);
        foreach ($result as $line) {
            if (preg_match('/\b' . $pid . '\b/', $line)) {
                return true;
            }
        }
        return false;
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
        // Windows 下 Workerman 不会写主进程 pid（saveMasterPid 仅 Linux 生效），这里自行记录，
        // 供 stop / restart / status 使用
        if ($this->isWindows()) {
            @file_put_contents(Worker::$pidFile, (string)getmypid());
        }

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
}
