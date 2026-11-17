<?php

namespace app\admin\controller;

use app\admin\lib\Cloud;
use app\admin\lib\Upgrade as UpgradeServer;
use think\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;
use think\Response;

/**
 * 升级包制作规则
 * 压缩方式：zip
 */
class Update extends Admin
{
    private $UpgradeServer;
    public $app_name; //应用标识

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->app_name = input('app_name', 'system') ?: 'system';
        $this->UpgradeServer = new UpgradeServer();
    }

    /**
     * 系统升级首页
     * @return [type] [description]
     */
    public function index()
    {
        // 设置页面title
        $this->setTitle('在线更新');
        // 读取本地版本号
        $local_version = $this->UpgradeServer->version($this->app_name);
        // 读取云端最新版本号
        $cloud_version = $this->UpgradeServer->cloudVersion([
            'app_name' => $this->app_name,
        ]);
        if (is_array($cloud_version) && !empty($cloud_version)) {
            $cloud_version = $cloud_version['data'];
        }
        // 是否可升级
        $upgrade = false;
        if (!empty($cloud_version['version'])) {
            $upgrade = get_upgrade_status($local_version, $cloud_version['version']);
        }

        //备份地址
        $backup_path = $this->app_name == 'system' ? '网站根目录->data->upgrade' : '网站根目录->app->' . $this->app_name . '->info->backup';

        // ajax请求返回
        if (request()->isAjax()) {
            $result = [
                'local_version' => $local_version,
                'cloud_version' => $cloud_version,
                'upgrade' => $upgrade,
                'backup_path' => $backup_path,
                'app_name' => $this->app_name,
                'scene' => 'upgrade',
                'frame' => 't6',
            ];
            return $this->success('success', $result);
        }

        View::assign('localVersion', $local_version);
        View::assign('cloudVersion', $cloud_version);
        View::assign('upgrade', $upgrade);
        View::assign('appName', $this->app_name);
        View::assign('backupPath', $backup_path);

        return View::fetch();
    }

    /*开始在线更新数据*/
    public function start()
    {
        $version = input('version', '', 'text');
        $app_name = input('app_name', '', 'text');
        $scene = input('scene', 'upgrade', 'text');
        $frame = input('frame', 't6', 'text');
        $this->setTitle('在线更新');

        View::assign([
            'appName' => $app_name,
            'frame' => $frame,
            'localVersion' => $this->UpgradeServer->version(),
            'upgradeVersion' => $version,
            'scene' => $scene,
            'authCode' => Cloud::authCode(),
            'cloud' => config('cloud.api')
        ]);

        return View::fetch();
    }

    /**
     * 获取升级包
     */
    public function package()
    {
        $version = input('version');
        $app_name = input('app_name');
        $scene = input('scene', 'upgrade', 'text');
        // 到期/未授权/未激活的应用禁止获取安装、升级包（升级按钮仍展示，拦截在执行环节）
        if (!empty($app_name) && $app_name !== 'system') {
            $state = (new Cloud())->appState($app_name);
            if ($state['state'] !== 'ok') {
                // 操作级拦截：仅返回提示文案，不携带授权失败标识 data，
                // 避免前端全局拦截器跳转授权页，让用户在升级/安装弹窗内看到失败原因
                return json([
                    'code' => 0,
                    'msg'  => Cloud::stateMessage($state['state'], $scene === 'setup' ? 'install' : 'upgrade'),
                    'data' => '',
                ]);
            }
        }
        $auth_code = Cloud::authCode();
        // 生成请求json（安装场景不做删除文件回收）
        $result = $this->UpgradeServer->buildJson($app_name, $version, $auth_code, $scene);

        // 预检：依赖变化、目录权限、磁盘等硬性条件不满足时直接拒绝开始，保证零写入
        if (is_array($result) && (int) ($result['code'] ?? 0) === 200) {
            $files = $result['data']['data'] ?? [];
            $result['data']['preflight'] = $this->UpgradeServer->preflight(
                $app_name ?: 'system',
                is_array($files) ? $files : []
            );
            if (empty($result['data']['preflight']['ok'])) {
                $this->UpgradeServer->log($app_name ?: 'system', (string) $version, 'error', '升级预检未通过', [
                    'blockers' => $result['data']['preflight']['blockers'] ?? [],
                ]);
            }
        }

        return json($result);
    }

    /**
     * @title 更新
     * @return Response|void
     */
    public function upgrade()
    {
        if (request()->isAjax()) {
            $params = request()->param();
            $path = str_replace('\\', '/', (string) ($params['file'] ?? '')); //文件路径
            $md5 = strtolower((string) ($params['md5'] ?? '')); //文件md5
            $app_name = $this->app_name; //应用标识
            $version = (string) ($params['version'] ?? ''); //目标版本
            // 写入路径必须落在根目录内：拒绝绝对路径与目录穿越，防云端字节写到 webroot 之外
            if ($path === '' || strpos($path, '..') !== false
                || $path[0] === '/' || preg_match('#^[a-zA-Z]:#', $path)) {
                return $this->error('文件路径不合法');
            }

            $local_version = $this->UpgradeServer->version($app_name); //本地版本
            if (!get_upgrade_status($local_version, $version)) {
                return $this->success('已经是最新版本！', 'same_version');
            }

            try {
                //检查忽略文件
                if ($this->UpgradeServer->checkIgnoreFile($path) === true) {
                    return $this->success('success', '忽略的文件');
                }

                // 兼容旧版前端（含浏览器缓存的旧 JS）：未上报 total 时仍按原来的直接落盘流程执行，
                // 仅在写盘方式上退化为「临时文件 + rename」的原子写，避免半截文件。
                if (!isset($params['total'])) {
                    $local_path = root_path() . $path;
                    if (is_file($local_path) && md5_file($local_path) === $md5) {
                        return $this->success('success', false);
                    }
                    if (is_file($local_path)) {
                        $this->UpgradeServer->backup($local_path, $app_name);
                    }
                    $this->UpgradeServer->downFile([
                        'md5'      => $md5,
                        'app_name' => $app_name,
                        'version'  => $version,
                    ], $local_path);

                    return $this->success('success', true);
                }

                // 阶段 A：只下载到暂存区，运行目录保持不变
                $phase = isset($params['phase']) ? (int) $params['phase'] : null;
                $staged_path = $this->UpgradeServer->getStagePath($app_name, $version, $path);
                if (is_file($staged_path) && md5_file($staged_path) === $md5) {
                    $this->UpgradeServer->addStateFile($app_name, $version, $path, $md5, $phase);
                    return $this->success('success', false);
                }

                $this->UpgradeServer->downFile([
                    'md5'      => $md5,
                    'app_name' => $app_name,
                    'version'  => $version,
                ], $staged_path);
                $this->UpgradeServer->addStateFile($app_name, $version, $path, $md5, $phase);

                return $this->success('success', true);
            } catch (Exception $e) {
                $this->UpgradeServer->log($app_name, (string) ($version ?? ''), 'error', '下载升级文件失败：' . $e->getMessage(), [
                    'file' => $path ?? '',
                ]);
                return $this->result($e->getCode(), $e->getMessage());
            }
        }
    }

    /**
     * @title 分批应用暂存文件（原子替换）
     * @return Response|void
     */
    public function apply()
    {
        if (!request()->isAjax()) {
            return;
        }
        $app_name = $this->app_name;
        $version = (string) input('version', '', 'text');
        $limit = max(1, min(500, (int) input('limit', 100)));
        $reset = (int) input('reset', 0) === 1;

        try {
            $lock = $this->UpgradeServer->acquireLock($app_name);
            try {
                $state = $this->UpgradeServer->readState($app_name, $version);
                if (empty($state['files']) || !is_array($state['files'])) {
                    throw new Exception('没有待应用的升级文件，请先获取升级包');
                }

                $files = $this->UpgradeServer->sortApplyFiles($state['files']);
                $total = count($files);
                $cursor = $reset ? 0 : max(0, (int) ($state['apply_cursor'] ?? 0));
                $processed = 0;
                $errors = [];
                $consecutive_failures = 0;

                // 进入替换阶段：开启维护标志，租户端不会看到半新半旧的页面
                $this->UpgradeServer->updateState($app_name, $version, function ($s) use ($reset) {
                    if (($s['stage'] ?? '') !== 'applied') {
                        $s['stage'] = 'applying';
                    }
                    if ($reset) {
                        $s['apply_cursor'] = 0;
                        $s['apply_failed'] = [];
                    }
                    return $s;
                });
                $this->UpgradeServer->enableMaintenance($app_name, $version);

                while ($cursor < $total && $processed < $limit) {
                    $item = $files[$cursor];
                    try {
                        $this->UpgradeServer->applyFile($app_name, $version, $item);
                        $consecutive_failures = 0;
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'name'   => (string) ($item['name'] ?? ''),
                            'reason' => $e->getMessage(),
                        ];
                        $consecutive_failures++;
                    }
                    $cursor++;
                    $processed++;
                    // 连续失败通常意味着目录只读等系统性问题，停止放大
                    if ($consecutive_failures >= 10) {
                        break;
                    }
                }

                $done = $cursor >= $total;
                // 全部批次结束后以磁盘实际 md5 为准做一次全量校验，避免前几批的失败被本批成功掩盖
                if ($done) {
                    $bad = $this->UpgradeServer->verifyStateFiles($app_name, $version, ['files' => $files]);
                    $errors = [];
                    foreach ($bad as $name) {
                        $errors[] = ['name' => $name, 'reason' => '文件校验不一致'];
                    }
                }

                // 新版本已删除的文件：全部文件替换成功后才回收，移入备份区 __removed__ 可随回滚恢复
                $removed_moved = 0;
                $removed_errors = [];
                if ($done && empty($errors)) {
                    $removed_names = $this->UpgradeServer->readRemovedList($app_name, $version);
                    if (!empty($removed_names)) {
                        $removed_result = $this->UpgradeServer->applyRemoved($app_name, $version, $removed_names);
                        $removed_moved = (int) ($removed_result['moved'] ?? 0);
                        $removed_errors = $removed_result['errors'] ?? [];
                        $this->UpgradeServer->log($app_name, $version, 'remove', '删除文件回收完成', [
                            'moved'  => $removed_moved,
                            'failed' => count($removed_errors),
                        ]);
                    }
                }

                $state = $this->UpgradeServer->updateState($app_name, $version, function ($s) use ($cursor, $done, $errors) {
                    $s['apply_cursor'] = $cursor;
                    $s['apply_failed'] = $errors;
                    if ($done) {
                        $s['stage'] = empty($errors) ? 'applied' : 'failed';
                    } else {
                        $s['stage'] = 'applying';
                    }
                    return $s;
                });

                return $this->success('success', [
                    'stage'          => $state['stage'] ?? 'applying',
                    'cursor'         => $cursor,
                    'total'          => $total,
                    'done'           => $done,
                    'errors'         => $errors,
                    'removed'        => $removed_moved,
                    'removed_errors' => $removed_errors,
                ]);
            } finally {
                $this->UpgradeServer->releaseLock($lock);
            }
        } catch (Exception $e) {
            $this->UpgradeServer->log($app_name, $version, 'error', '应用升级文件失败：' . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }

    /**
     * @title 查询升级状态（刷新页面后续传）
     * @return Response|void
     */
    public function state()
    {
        if (!request()->isAjax()) {
            return;
        }
        $version = (string) input('version', '', 'text');
        if ($version === '') {
            return $this->success('success', []);
        }
        return $this->success('success', $this->UpgradeServer->getStateSummary($this->app_name, $version));
    }

    /**
     * @title 回滚到升级前（按备份区整版恢复）
     * @return Response|void
     */
    public function rollback()
    {
        if (!request()->isAjax()) {
            return;
        }
        $app_name = $this->app_name;
        $version = (string) input('version', '', 'text');

        try {
            $lock = $this->UpgradeServer->acquireLock($app_name);
            try {
                $state = $this->UpgradeServer->readState($app_name, $version);
                if (empty($state['files']) || !is_array($state['files'])) {
                    throw new Exception('没有可回滚的升级记录');
                }

                $restored = 0;
                $removed = 0;
                foreach (array_reverse($state['files']) as $item) {
                    $name = str_replace('\\', '/', (string) ($item['name'] ?? ''));
                    if ($name === '' || strpos($name, '..') !== false || $name[0] === '/') {
                        continue;
                    }
                    $target = root_path() . $name;
                    $backup = $this->UpgradeServer->getBackupPath($app_name, $version, $name);
                    if (is_file($backup)) {
                        $this->UpgradeServer->ensureDir(dirname($target));
                        $tmp = $target . '.rollback_tmp';
                        if (!@copy($backup, $tmp) || !@rename($tmp, $target)) {
                            @unlink($tmp);
                            throw new Exception('回滚文件失败：' . $name);
                        }
                        $restored++;
                    } elseif (is_file($target)) {
                        // 升级前不存在的新文件，回滚时移除
                        @unlink($target);
                        $removed++;
                    }
                }

                $this->UpgradeServer->disableMaintenance();
                // 恢复被回收的删除文件（__removed__），保证回滚后与升级前完全一致
                $removed_result = $this->UpgradeServer->restoreRemoved($app_name, $version);
                $this->UpgradeServer->updateState($app_name, $version, function ($s) {
                    $s['stage'] = 'rolledback';
                    $s['apply_cursor'] = 0;
                    $s['apply_failed'] = [];
                    return $s;
                });
                $this->UpgradeServer->log($app_name, $version, 'rollback', '回滚完成', [
                    'restored'         => $restored,
                    'removed'          => $removed,
                    'removed_restored' => (int) ($removed_result['restored'] ?? 0),
                    'failed'           => count($removed_result['errors'] ?? []),
                ]);

                return $this->success('回滚完成', [
                    'restored'         => $restored,
                    'removed'          => $removed,
                    'removed_restored' => (int) ($removed_result['restored'] ?? 0),
                ]);
            } finally {
                $this->UpgradeServer->releaseLock($lock);
            }
        } catch (Exception $e) {
            $this->UpgradeServer->log($app_name, $version, 'error', '回滚失败：' . $e->getMessage());
            return $this->error($e->getMessage());
        }
    }

    /**
     * @title 更新完成
     * @return Response|void
     */
    public function finish()
    {
        if (request()->isAjax()) {
            $params = request()->post();
            $version = (string) ($params['version'] ?? '');
            $skip = (int) ($params['skip'] ?? 0);

            try {
                $lock = $this->UpgradeServer->acquireLock($this->app_name);
                try {
                    // 状态校验：仍有文件未替换或校验不一致时，不允许写版本号
                    $state = $this->UpgradeServer->readState($this->app_name, $version);
                    if (!empty($state['files']) && ($state['stage'] ?? '') !== 'finished') {
                        $files_total = count($state['files']);
                        $cursor = (int) ($state['apply_cursor'] ?? 0);
                        if ($cursor < $files_total) {
                            throw new Exception('仍有 ' . ($files_total - $cursor) . ' 个文件未完成替换，请继续升级');
                        }
                        $bad = $this->UpgradeServer->verifyStateFiles($this->app_name, $version, $state);
                        if (!empty($bad)) {
                            throw new Exception('有 ' . count($bad) . ' 个文件校验不一致，请重试或回滚：'
                                . implode('、', array_slice($bad, 0, 3)));
                        }
                    }

                    //执行升级sql（单条失败继续执行，失败语句随结果返回）
                    $sql_result = $this->UpgradeServer->executeUpgradeSql($this->app_name, $version);

                    // 升级前的本地版本号，用于上报升级记录
                    $local_version = $skip === 0 ? $this->UpgradeServer->version($this->app_name) : '';

                    // 更新版本号：以云端当前发布版本为准，不信任客户端提交值
                    $target_version = '';
                    if ($skip === 0) {

                        // 向云端确认当前发布版本；获取失败则中止 finish，避免本地标新到错误版本
                        $cloud = $this->UpgradeServer->cloudVersion(['app_name' => $this->app_name]);
                        $target_version = trim((string) ($cloud['data']['version'] ?? ''));
                        if ($target_version === '' || $target_version === '服务器错误') {
                            throw new Exception('无法获取云端发布版本，请稍后重试完成升级');
                        }

                        // 系统更新
                        if ($this->app_name == 'system') {
                            // 更改root_path() . 'data/version.ini'文件内容为版本号
                            file_put_contents(root_path() . 'data/version.ini', $target_version);
                        } else {
                            //更新应用版本号
                            Db::name('module')->where('name', '=', $this->app_name)->update(['version' => $target_version]);
                        }
                    }

                    // 上报应用安装/升级记录（失败不影响升级结果）
                    if (!empty($local_version) && $target_version !== '' && $local_version != $target_version) {
                        (new Cloud())->reportAppRecord($this->app_name, 'upgrade', $local_version, $target_version);
                    }

                    //队列 worker 与 crontab 调度器都是常驻进程，升级后需重新加载代码，
                    //否则新模块投递的队列任务仍会走旧代码；此处写入重启信号（等同 php think queue:restart）
                    Cache::set('think:queue:restart', time());

                    //入口域名/IP 变更后旧授权码仍会缓存 30 分钟，提前失效避免后续检查更新误报未授权
                    Cache::delete(request()->host() . '_MUUCMF_CLOUD_AUTH');

                    //应用升级完成后清理应用管理列表的云端状态缓存
                    Cache::tag('module_cloud')->clear();

                    // 让 PHP 立即载入新文件，避免 validate_timestamps=0 时继续执行旧字节码
                    if (function_exists('opcache_reset')) {
                        @opcache_reset();
                    }

                    // 收尾：关闭维护标志、清理暂存区；备份保留用于回滚
                    $this->UpgradeServer->disableMaintenance();
                    if (!empty($state['files'])) {
                        $this->UpgradeServer->finishState($this->app_name, $version);
                    }
                    // 目标版本清单提升为「已安装清单」，供下次升级计算删除文件
                    try {
                        $this->UpgradeServer->promoteManifest($this->app_name, $version);
                    } catch (\Throwable $e) {
                        // 清单提升失败不影响升级结果，下次升级最多退化为不回收本地删除文件
                        $this->UpgradeServer->log($this->app_name, $version, 'error', '升级清单提升失败：' . $e->getMessage());
                    }
                    $this->UpgradeServer->log($this->app_name, $version, 'finish', '升级完成', [
                        'target_version'   => $target_version,
                        'sql_executed'     => $sql_result['executed'] ?? 0,
                        'sql_skipped'      => $sql_result['skipped'] ?? 0,
                        'sql_failed'       => count($sql_result['errors'] ?? []),
                    ]);

                    //返回
                    return $this->success('更新完成', ['sql_errors' => $sql_result['errors'] ?? []]);
                } finally {
                    $this->UpgradeServer->releaseLock($lock);
                }
            } catch (Exception $e) {
                $this->UpgradeServer->log($this->app_name, (string) ($version ?? ''), 'error', '完成升级失败：' . $e->getMessage());
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 检查是否可升级
     */
    public function check()
    {
        // 读取本地版本号
        $local_version = $this->UpgradeServer->version($this->app_name);
        // 读取云端最新版本号
        $cloud_version = $this->UpgradeServer->cloudVersion([
            'app_name' => $this->app_name,
        ]);
        if (is_array($cloud_version) && !empty($cloud_version)) {
            $cloud_version = $cloud_version['data'];
        }
        // 是否可升级
        $upgrade = false;
        if (!empty($cloud_version['version'])) {
            $upgrade = get_upgrade_status($local_version, $cloud_version['version']);
        }

        if ($upgrade) {
            return $this->success('云端有新版版可更新', $cloud_version['version']);
        }
        return $this->error('已经是最新版本', $local_version);
    }
}
