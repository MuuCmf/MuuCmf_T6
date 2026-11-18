<?php

namespace app\admin\lib;

use app\common\model\Module;
use app\common\service\UpgradeIgnore;
use think\Exception;
use think\facade\Db;
use think\exception\ValidateException;

class Upgrade
{
    public $api;

    /**
     * 框架包忽略名单的兜底副本
     *
     * 与 app\common\service\UpgradeIgnore::paths() 必须逐项一致（tests/tenant_upgrade_local.php 有断言）。
     * 存在的意义：老租户升级到本版本时，替换是按批推进的，Upgrade.php 落盘后依赖类可能还没落盘，
     * 此时若直接 Class not found 会让整个升级流程中断且无法续做，必须能退回一份自带名单。
     */
    const IGNORE_FALLBACK = [
        '.env',
        'config/auth.php',
        'config/cache.php',
        'config/database.php',
        'config/jwt.php',
        'config/queue.php',
        'runtime/',
        '.idea',
        '.gitignore',
        'vendor/',
        '_src/',
        'data/version.ini',
        'public/favicon.ico',
    ];

    function __construct()
    {
        $this->api = config('cloud.api');
    }

    /**
     * @title 当前站点所用框架标识
     * 说明：历史实现把 t6 写死在请求参数里，t8 站点会拿 t6 的版本数据，
     * 查询与升级全部落空。这里统一走配置，可用 .env 的 CLOUD_FRAME 覆盖。
     * @return string
     */
    public function frame()
    {
        $frame = (string) config('cloud.frame', 't6');
        return in_array($frame, ['t6', 't8'], true) ? $frame : 't6';
    }

    /**
     * @title 获取应用根目录
     * @param string $app
     * @return string
     */
    public function getAppRootPath($app = 'system')
    {
        if ($app == 'system') {
            $path = root_path();
        } else {
            $path = root_path("app" . DIRECTORY_SEPARATOR . $app);
        }
        return $path;
    }

    /**
     * @title 获取应用当前版本号
     * @param string $app
     * @return false|string
     */
    public function version($app = 'system')
    {
        if ($app == 'system') {
            $path = $this->getAppRootPath($app) . 'data' . DIRECTORY_SEPARATOR . 'version.ini';
            // 文件缺失/不可读时不能直接 file_get_contents（会抛 warning 并返回 false，
            // 后续版本比较拿到空串，表现为「永远提示可升级」）
            if (!is_file($path) || !is_readable($path)) {
                return '';
            }
            $version = trim((string) @file_get_contents($path));
        } else {
            $version = Module::where([
                ['name', '=', $app],
                ['is_setup', '=', 1]
            ])->value('version');
        }
        return $version;
    }

    /**
     * @title 检查忽略文件
     * @param $path
     * @return bool
     */
    public function checkIgnoreFile($path, $app = 'system')
    {
        // 仅框架升级检测；名单与云端编译期共用一份定义（app\common\service\UpgradeIgnore），
        // 避免云端把 .env / config/database.php / runtime 日志打进包、租户端再各自维护一份
        if ($app == 'system') {
            // 先就地归一化（不能依赖尚未确认存在的类）
            $path = preg_replace('#/+#', '/', str_replace('\\', '/', (string) $path));
            if (!class_exists(UpgradeIgnore::class) && is_file(root_path() . 'app/common/service/UpgradeIgnore.php')) {
                // 升级窗口内依赖类可能尚未落盘，先按固定路径加载一次（自动加载只认命名空间映射）
                @include_once root_path() . 'app/common/service/UpgradeIgnore.php';
            }
            if (class_exists(UpgradeIgnore::class)) {
                return UpgradeIgnore::isIgnored($path);
            }
            // 仍未加载成功：退回自带名单，绝不让「类不存在」把升级流程打死
            foreach (self::IGNORE_FALLBACK as $item) {
                if (strpos($path, $item) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @title下载远端文件
     * @param array $params  参数
     * @param string $save_path 保存路径
     * @return string
     */
    public function downFile(array $params, $save_path = '')
    {
        if(empty($params['frame'])){
            $params['frame'] = $this->frame();
        }
        $source = $this->api . "upgrade/download?" . http_build_query($params);
        //地址追加授权域名
        $source .= "&auth_code=" . urlencode(Cloud::authCode());
        // S1：签名参数与 auth_code 双通道并存；GET 请求的签名参数走 query
        try {
            $sig = CloudSignature::payload('upgrade.download', (string) ($params['app_name'] ?? ''));
            if (!empty($sig)) {
                $source .= "&" . http_build_query($sig);
            }
        } catch (\Throwable $e) {
            trace('cloud signature payload exception: ' . $e->getMessage(), 'error');
        }
        $ch = curl_init(); //初始化一个cURL会话
        curl_setopt($ch, CURLOPT_URL, $source); //抓取url
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); //是否显示头信息
        //curl_setopt($ch, CURLOPT_SSLVERSION, 3);//传递一个包含SSL版本的长参数
        curl_setopt($ch, CURLINFO_HEADER_OUT, true); //TRUE 时追踪句柄的请求字符串，从 PHP 5.1.3 开始可用。这个很关键，就是允许你查看请求header
        // 强制校验证书链与主机名：升级通道传输的是可执行代码，关闭校验等于给中间人开门
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $data = curl_exec($ch); // 执行一个cURL会话
        $response = curl_getinfo($ch);
        $error = curl_error($ch); //返回一条最近一次cURL操作明确的文本的错误信息。
        curl_close($ch); //关闭一个cURL会话并且释放所有资源
        //处理返回的错误信息：curl 层错误（含 TLS 校验失败）必须先于内容类型判断抛出
        if ($error) {
            $this->log($params['app_name'] ?? 'system', $params['version'] ?? '', 'error', '下载文件失败：' . $error, [
                'file' => $save_path,
            ]);
            throw new Exception($error);
        }
        if ($response['content_type'] != 'application/octet-stream') {
            $error = json_decode($data, true);
            $this->log($params['app_name'] ?? 'system', $params['version'] ?? '', 'error', '云端返回异常', [
                'file' => $save_path,
                'msg'  => (string) ($error['msg'] ?? ''),
            ]);
            throw new Exception($error['msg'] ?? '云端返回异常', (int) ($error['code'] ?? 0));
        }

        // 写盘前复验内容 md5：清单下发的 md5 是唯一完整性锚点，不复验则换包无从发现
        $expect_md5 = strtolower((string) ($params['md5'] ?? ''));
        if ($expect_md5 !== '' && md5((string) $data) !== $expect_md5) {
            $this->log($params['app_name'] ?? 'system', $params['version'] ?? '', 'error', '下载文件 md5 校验失败', [
                'file' => $save_path,
            ]);
            throw new Exception('下载文件校验失败，已丢弃');
        }

        // 只写暂存区：下载阶段不触碰运行目录，备份与替换统一在 apply 阶段完成
        $this->ensureDir(dirname($save_path));
        // 并发下载同一文件时避免临时文件互相覆盖
        $tmp_path = $save_path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp_path, $data) !== strlen($data)) {
            @unlink($tmp_path);
            throw new Exception('写入暂存文件失败');
        }
        if (!@rename($tmp_path, $save_path)) {
            @unlink($tmp_path);
            throw new Exception('暂存文件落盘失败');
        }
        $this->log($params['app_name'] ?? 'system', $params['version'] ?? '', 'download', '文件已下载到暂存区', [
            'file' => $save_path,
            'md5'  => $expect_md5,
        ]);
        return $save_path;
    }

    /**
     * @title 备份（按目标版本隔离，首次备份为原始文件，重试不覆盖）
     * @param string $file
     * @param string $app
     * @param string|null $version 为空时取当前本地版本
     * @return string
     */
    public function backup($file, $app = 'system', $version = null)
    {
        $version = $version === null ? (string) $this->version($app) : (string) $version;
        // 统一分隔符后再剥离根目录，避免 Windows 下混合斜杠导致绝对路径被拼进备份区
        $root = str_replace('\\', '/', root_path());
        $normalized = str_replace('\\', '/', (string) $file);
        $relative = ltrim(str_replace($root, '', $normalized), '/');
        if ($relative === '' || strpos($relative, '..') !== false || preg_match('#^[a-zA-Z]:#', $relative)) {
            throw new Exception('备份文件路径不合法：' . $file);
        }
        $backup_path = $this->getBackupPath($app, $version, $relative);
        // 已存在说明是首次替换前保存的原始文件，绝不允许被后续重试覆盖
        if (is_file($backup_path)) {
            $this->log($app, $version, 'backup', '备份已存在，跳过覆盖', ['file' => $backup_path]);
            return $backup_path;
        }
        $this->ensureDir(dirname($backup_path));
        if (!@copy($file, $backup_path)) {
            $this->log($app, $version, 'error', '备份原文件失败', ['file' => $file]);
            throw new Exception('备份原文件失败：' . $file);
        }
        $this->log($app, $version, 'backup', '已备份原文件', ['file' => $backup_path]);
        return $backup_path;
    }

    /**
     * 备份根目录
     */
    public function getBackupRoot($app = 'system', $version = '')
    {
        $version = $this->safeVersion($version);
        if ($app == 'system') {
            return $this->getUpgradeDataRoot() . 'backup/system/' . $version . '/';
        }
        return root_path() . 'app/' . $this->safeAppName($app) . '/info/backup/' . $version . '/';
    }

    /**
     * 备份文件路径
     */
    public function getBackupPath($app, $version, $relative)
    {
        return $this->getBackupRoot($app, $version) . ltrim(str_replace('\\', '/', (string) $relative), '/');
    }

    /**
     * 规范化应用标识
     */
    public function safeAppName($app)
    {
        $app = trim((string) $app);
        if ($app === '' || !preg_match('/^[A-Za-z0-9_-]{1,50}$/', $app)) {
            throw new Exception('应用标识不合法');
        }
        return $app;
    }

    /**
     * 规范化版本号（用于目录与文件名，不改变版本比较逻辑）
     */
    public function safeVersion($version)
    {
        $version = preg_replace('/[^A-Za-z0-9._+\-]/', '_', trim((string) $version));
        if ($version === '') {
            throw new Exception('版本号不合法');
        }
        return $version;
    }

    /**
     * 创建目录
     */
    public function ensureDir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new Exception('创建目录失败：' . $dir);
        }
    }

    /**
     * 升级数据根目录
     */
    public function getUpgradeDataRoot()
    {
        return root_path() . 'data/upgrade/';
    }

    /**
     * 暂存区根目录
     */
    public function getStageRoot($app, $version)
    {
        return $this->getUpgradeDataRoot() . 'stage/' . $this->safeAppName($app) . '/' . $this->safeVersion($version) . '/';
    }

    /**
     * 暂存文件路径
     */
    public function getStagePath($app, $version, $relative)
    {
        return $this->getStageRoot($app, $version) . ltrim(str_replace('\\', '/', (string) $relative), '/');
    }

    /**
     * 升级状态文件路径
     */
    public function getStatePath($app, $version)
    {
        return $this->getUpgradeDataRoot() . 'state/' . $this->safeAppName($app) . '_' . $this->safeVersion($version) . '.json';
    }

    /**
     * 升级锁路径（按应用维度互斥）
     */
    public function getLockPath($app)
    {
        return $this->getUpgradeDataRoot() . 'locks/' . $this->safeAppName($app) . '.lock';
    }

    /**
     * 维护标志路径
     */
    public function getMaintenancePath()
    {
        return $this->getUpgradeDataRoot() . 'maintenance.lock';
    }

    /**
     * 升级日志路径（按应用 + 版本落盘，便于定位首次升级失败）
     */
    public function getLogPath($app, $version)
    {
        return $this->getUpgradeDataRoot() . 'logs/' . $this->safeAppName($app) . '_' . $this->safeVersion($version) . '.log';
    }

    /**
     * 追加升级日志
     *
     * 说明：日志失败绝不影响升级主流程；不记录 auth_code、完整请求地址等敏感信息。
     *
     * @param string $app
     * @param string $version
     * @param string $action download|backup|apply|remove|rollback|sql|finish|error
     * @param string $message
     * @param array  $context
     * @return void
     */
    public function log($app, $version, $action, $message, array $context = [])
    {
        try {
            $path = $this->getLogPath($app, $version);
            $this->ensureDir(dirname($path));
            $line = '[' . date('Y-m-d H:i:s') . '] [' . $action . '] ' . $message;
            if (!empty($context)) {
                $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // 日志写入失败不影响升级
        }
    }

    /**
     * 清单目录（记录目标版本全量文件与已安装版本全量文件）
     */
    public function getManifestRoot()
    {
        return $this->getUpgradeDataRoot() . 'manifest/';
    }

    /**
     * 指定版本的清单路径
     */
    public function getManifestPath($app, $version)
    {
        return $this->getManifestRoot() . $this->safeAppName($app) . '_' . $this->safeVersion($version) . '.json';
    }

    /**
     * 当前已安装版本的清单路径
     */
    public function getInstalledManifestPath($app)
    {
        return $this->getManifestRoot() . $this->safeAppName($app) . '_installed.json';
    }

    /**
     * 原子写 JSON 文件
     */
    private function writeJsonFile($path, array $data)
    {
        $this->ensureDir(dirname($path));
        $tmp = $path . '.tmp.' . getmypid();
        $raw = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (@file_put_contents($tmp, $raw) !== strlen($raw)) {
            @unlink($tmp);
            throw new Exception('写入升级清单失败：' . $path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new Exception('升级清单落盘失败：' . $path);
        }
    }

    /**
     * 保存目标版本全量清单（仅保留 name/md5，避免响应与文件膨胀）
     */
    public function saveManifest($app, $version, array $files)
    {
        $list = [];
        foreach ($files as $file) {
            $name = ltrim(str_replace('\\', '/', (string) ($file['name'] ?? '')), '/');
            if ($name === '') {
                continue;
            }
            $list[] = ['name' => $name, 'md5' => strtolower((string) ($file['md5'] ?? ''))];
        }
        $this->writeJsonFile($this->getManifestPath($app, $version), [
            'version' => (string) $version,
            'files'   => $list,
        ]);
    }

    /**
     * 读取指定版本清单
     */
    public function readManifest($app, $version)
    {
        $path = $this->getManifestPath($app, $version);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 读取已安装版本清单（上一次成功升级后的全量文件）
     */
    public function readInstalledManifest($app)
    {
        $path = $this->getInstalledManifestPath($app);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * 升级成功收尾时把目标版本清单提升为已安装清单
     */
    public function promoteManifest($app, $version)
    {
        $source = $this->getManifestPath($app, $version);
        if (!is_file($source)) {
            return;
        }
        $target = $this->getInstalledManifestPath($app);
        $this->ensureDir(dirname($target));
        $tmp = $target . '.tmp.' . getmypid();
        if (@copy($source, $tmp) && @rename($tmp, $target)) {
            return;
        }
        @unlink($tmp);
    }

    /**
     * 待回收文件列表路径
     */
    public function getRemovedListPath($app, $version)
    {
        return $this->getUpgradeDataRoot() . 'removed/' . $this->safeAppName($app) . '_' . $this->safeVersion($version) . '.json';
    }

    /**
     * 保存待回收文件列表
     */
    public function saveRemovedList($app, $version, array $names)
    {
        $this->writeJsonFile($this->getRemovedListPath($app, $version), [
            'version' => (string) $version,
            'files'   => array_values($names),
        ]);
    }

    /**
     * 读取待回收文件列表
     */
    public function readRemovedList($app, $version)
    {
        $path = $this->getRemovedListPath($app, $version);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data['files'] ?? null) ? $data['files'] : [];
    }

    /**
     * 受管目录白名单：只允许在这些目录内回收删除文件
     *
     * 严禁触碰配置、附件上传、运行时、data、.env 等用户数据。
     *
     * @param string $app
     * @param string $name
     * @return bool
     */
    public function isManagedPath($app, $name)
    {
        $name = ltrim(str_replace('\\', '/', trim((string) $name)), '/');
        if ($name === '' || strpos($name, '..') !== false || preg_match('#^[a-zA-Z]:#', $name)) {
            return false;
        }
        if ($this->checkIgnoreFile($name, $app) === true) {
            return false;
        }
        // 备份区、升级 SQL 等元数据永不回收
        if (strpos($name, 'info/backup/') !== false || substr($name, -10) === 'upgrade.sql') {
            return false;
        }

        if ($app == 'system') {
            $prefixes = ['app/', 'route/', 'public/static/', 'public/h5/'];
        } else {
            $prefixes = ['app/' . $this->safeAppName($app) . '/'];
        }
        foreach ($prefixes as $prefix) {
            if (strpos($name, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * 计算新版本删除的文件
     *
     * 以「已安装版本全量清单」为基线，加上云端下发的 removed[]，
     * 取差集中仍在受管目录内的文件，避免误删已安装模块与用户数据。
     *
     * @param string $app
     * @param string $version
     * @param array  $new_files     新版本全量清单
     * @param array  $cloud_removed 云端下发的 removed[]
     * @return array
     */
    public function computeRemoved($app, $version, array $new_files, array $cloud_removed = [])
    {
        $new = [];
        foreach ($new_files as $file) {
            $name = ltrim(str_replace('\\', '/', (string) ($file['name'] ?? '')), '/');
            if ($name !== '') {
                $new[$name] = true;
            }
        }

        $old = [];
        $installed = $this->readInstalledManifest($app);
        foreach (($installed['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = ltrim(str_replace('\\', '/', (string) ($file['name'] ?? '')), '/');
            if ($name !== '') {
                $old[$name] = true;
            }
        }
        foreach ($cloud_removed as $name) {
            if (!is_string($name)) {
                continue;
            }
            $name = ltrim(str_replace('\\', '/', $name), '/');
            if ($name !== '') {
                $old[$name] = true;
            }
        }

        $removed = [];
        foreach (array_keys($old) as $name) {
            if (isset($new[$name])) {
                continue;
            }
            if (!$this->isManagedPath($app, $name)) {
                continue;
            }
            $removed[] = $name;
        }
        sort($removed);

        return $removed;
    }

    /**
     * 回收新版本已删除的文件：移入备份区 __removed__，不做物理删除
     *
     * @param string $app
     * @param string $version
     * @param array  $names
     * @return array ['moved' => int, 'errors' => array]
     */
    public function applyRemoved($app, $version, array $names)
    {
        $moved = 0;
        $errors = [];
        foreach ($names as $name) {
            if (!is_string($name) || !$this->isManagedPath($app, $name)) {
                continue;
            }
            $target = root_path() . $name;
            if (!is_file($target)) {
                continue;
            }
            try {
                $backup = $this->getBackupPath($app, $version, '__removed__/' . $name);
                $this->ensureDir(dirname($backup));
                if (is_file($backup)) {
                    // 重复执行：备份已在，直接移除当前文件
                    if (!@unlink($target)) {
                        throw new Exception('移除残留文件失败：' . $name);
                    }
                } elseif (!@rename($target, $backup)) {
                    // 跨分区兜底
                    if (!@copy($target, $backup)) {
                        throw new Exception('回收文件到备份区失败：' . $name);
                    }
                    @unlink($target);
                }
                $moved++;
                $this->log($app, $version, 'remove', '回收新版本已删除的文件', ['name' => $name]);
            } catch (\Throwable $e) {
                $errors[] = ['name' => $name, 'reason' => $e->getMessage()];
                $this->log($app, $version, 'error', '回收删除文件失败：' . $e->getMessage(), ['name' => $name]);
            }
        }
        return ['moved' => $moved, 'errors' => $errors];
    }

    /**
     * 回滚时把 __removed__ 中回收的文件恢复到运行目录
     *
     * @param string $app
     * @param string $version
     * @return array ['restored' => int, 'errors' => array]
     */
    public function restoreRemoved($app, $version)
    {
        $root = $this->getBackupRoot($app, $version) . '__removed__' . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return ['restored' => 0, 'errors' => []];
        }

        $restored = 0;
        $errors = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
                if ($relative === '' || !$this->isManagedPath($app, $relative)) {
                    continue;
                }
                $target = root_path() . $relative;
                try {
                    $this->ensureDir(dirname($target));
                    if (!@rename($path, $target)) {
                        $tmp = $target . '.rollback_tmp';
                        if (!@copy($path, $tmp) || !@rename($tmp, $target)) {
                            @unlink($tmp);
                            throw new Exception('恢复回收文件失败：' . $relative);
                        }
                        @unlink($path);
                    }
                    $restored++;
                    $this->log($app, $version, 'rollback', '恢复新版本已删除的文件', ['name' => $relative]);
                } catch (\Throwable $e) {
                    $errors[] = ['name' => $relative, 'reason' => $e->getMessage()];
                }
            }
        } catch (\Throwable $e) {
            $errors[] = ['name' => '', 'reason' => $e->getMessage()];
        }

        return ['restored' => $restored, 'errors' => $errors];
    }

    /**
     * 升级前预检：不通过时直接拒绝开始，保证零写入
     *
     * @param string $app
     * @param array  $files 差异文件清单
     * @return array
     */
    public function preflight($app, array $files = [])
    {
        $app = $this->safeAppName($app);
        $blockers = [];
        $warnings = [];

        foreach (['zip', 'curl', 'pdo_mysql', 'mbstring', 'json', 'openssl'] as $ext) {
            if (!extension_loaded($ext)) {
                $blockers[] = '缺少 PHP 扩展：' . $ext;
            }
        }
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $blockers[] = 'PHP 版本过低（当前 ' . PHP_VERSION . '），至少需要 7.4';
        }

        $writable = [root_path(), root_path() . 'data/', root_path() . 'public/'];
        if ($app != 'system') {
            $writable[] = root_path() . 'app/' . $app . '/';
        }
        foreach ($writable as $dir) {
            if (is_dir($dir) && !is_writable($dir)) {
                $blockers[] = '目录不可写：' . $dir;
            }
        }

        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(root_path());
            if ($free !== false && $free < 200 * 1024 * 1024) {
                $blockers[] = '磁盘剩余空间不足 200MB，无法安全升级';
            }
        }

        $max_execution_time = (int) ini_get('max_execution_time');
        if ($max_execution_time > 0 && $max_execution_time < 30) {
            $warnings[] = 'max_execution_time=' . $max_execution_time . ' 偏小，升级可能中断';
        }

        // 依赖变化必须阻断：vendor 与 composer 文件不在在线替换范围内，静默跳过会导致类不存在
        $dependency = [];
        foreach ($files as $file) {
            $name = str_replace('\\', '/', (string) ($file['name'] ?? ''));
            if ($name === 'composer.json' || $name === 'composer.lock' || strpos($name, 'vendor/') === 0) {
                $dependency[$name] = $name;
            }
        }
        if (!empty($dependency)) {
            $blockers[] = '本次升级包含依赖变化（' . implode('、', array_slice(array_values($dependency), 0, 3))
                . ' 等 ' . count($dependency) . ' 项），请先重建部署镜像或执行 composer install 后重试';
        }

        return [
            'ok'       => empty($blockers),
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * 读取升级状态
     */
    public function readState($app, $version)
    {
        $path = $this->getStatePath($app, $version);
        if (!is_file($path)) {
            return [];
        }
        $fp = @fopen($path, 'r');
        if (!$fp) {
            return [];
        }
        flock($fp, LOCK_SH);
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 原子更新升级状态
     */
    public function updateState($app, $version, callable $callback)
    {
        $path = $this->getStatePath($app, $version);
        $this->ensureDir(dirname($path));
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            throw new Exception('升级状态文件不可写');
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $state = json_decode((string) $raw, true);
        if (!is_array($state)) {
            $state = [
                'app_name'      => $app,
                'version'       => $version,
                'stage'         => 'downloading',
                'files'         => [],
                'apply_cursor'  => 0,
                'apply_failed'  => [],
                'created_at'    => time(),
            ];
        }
        $state = $callback($state);
        if (!is_array($state)) {
            $state = [];
        }
        $state['updated_at'] = time();
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $state;
    }

    /**
     * 记录已下载到暂存区的文件（幂等）
     *
     * @param string   $app
     * @param string   $version
     * @param string   $name
     * @param string   $md5
     * @param int|null $phase 云端下发的替换顺序（0 普通 1 静态 2 入口），为空时按路径推断
     */
    public function addStateFile($app, $version, $name, $md5, $phase = null)
    {
        $name = ltrim(str_replace('\\', '/', (string) $name), '/');
        $md5 = strtolower((string) $md5);
        $phase = $phase === null ? null : (int) $phase;

        return $this->updateState($app, $version, function ($state) use ($name, $md5, $phase) {
            if (empty($state['files']) || !is_array($state['files'])) {
                $state['files'] = [];
            }
            foreach ($state['files'] as $item) {
                if (($item['name'] ?? '') === $name) {
                    return $state;
                }
            }
            $entry = ['name' => $name, 'md5' => $md5];
            if ($phase !== null) {
                $entry['phase'] = $phase;
            }
            $state['files'][] = $entry;
            if (empty($state['stage']) || $state['stage'] === 'idle') {
                $state['stage'] = 'downloading';
            }
            return $state;
        });
    }

    /**
     * 状态摘要（不含文件明细，避免响应过大）
     */
    public function getStateSummary($app, $version)
    {
        $state = $this->readState($app, $version);
        if (empty($state)) {
            return [];
        }
        return [
            'app_name'     => $state['app_name'] ?? $app,
            'version'      => $state['version'] ?? $version,
            'stage'        => $state['stage'] ?? 'downloading',
            'files_total'  => is_array($state['files'] ?? null) ? count($state['files']) : 0,
            'apply_cursor' => (int) ($state['apply_cursor'] ?? 0),
            'apply_failed' => $state['apply_failed'] ?? [],
            'updated_at'   => $state['updated_at'] ?? 0,
        ];
    }

    /**
     * 把单个暂存文件原子替换到运行目录
     *
     * @return string applied|skipped|ignored
     */
    public function applyFile($app, $version, array $item)
    {
        $name = str_replace('\\', '/', (string) ($item['name'] ?? ''));
        $expected = strtolower((string) ($item['md5'] ?? ''));

        // 防目录穿越：状态文件同样不可信任为合法路径
        if ($name === '' || strpos($name, '..') !== false
            || $name[0] === '/' || preg_match('#^[a-zA-Z]:#', $name)) {
            throw new Exception('文件路径不合法：' . $name);
        }
        if ($this->checkIgnoreFile($name, $app) === true) {
            return 'ignored';
        }

        $target = root_path() . $name;
        // 目标已是期望内容，视为已完成（重试幂等）
        if ($expected !== '' && is_file($target) && md5_file($target) === $expected) {
            return 'skipped';
        }

        $staged = $this->getStagePath($app, $version, $name);
        if (!is_file($staged)) {
            throw new Exception('暂存文件不存在，请重新下载：' . $name);
        }
        if ($expected !== '' && md5_file($staged) !== $expected) {
            throw new Exception('暂存文件校验失败：' . $name);
        }

        // 备份原始文件；同版本重试时已存在则跳过，保证回滚依据不被覆盖
        if (is_file($target)) {
            $this->backup($target, $app, $version);
        }

        $this->ensureDir(dirname($target));
        if (!@rename($staged, $target)) {
            // 跨分区兜底：先复制到目标目录，再原子替换
            $tmp = $target . '.upgrade_tmp';
            if (!@copy($staged, $tmp) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new Exception('替换文件失败：' . $name);
            }
            @unlink($staged);
        }
        if ($expected !== '' && md5_file($target) !== $expected) {
            $this->log($app, $version, 'error', '文件替换后校验失败', ['name' => $name]);
            throw new Exception('文件替换后校验失败：' . $name);
        }
        $this->log($app, $version, 'apply', '文件已原子替换', ['name' => $name, 'md5' => $expected]);

        return 'applied';
    }

    /**
     * 替换顺序：普通文件 → 静态资源 → 入口文件，入口最后切换
     *
     * 优先采用云端下发的 phase，未下发时按路径推断，保持旧协议兼容。
     */
    public function sortApplyFiles(array $files)
    {
        usort($files, function ($a, $b) {
            $pa = isset($a['phase']) ? (int) $a['phase'] : $this->applyPhase((string) ($a['name'] ?? ''));
            $pb = isset($b['phase']) ? (int) $b['phase'] : $this->applyPhase((string) ($b['name'] ?? ''));
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });
        return $files;
    }

    private function applyPhase($name)
    {
        $name = str_replace('\\', '/', (string) $name);
        if ($name === 'public/index.php' || $name === 'public/install.php' || strpos($name, 'route/') === 0) {
            return 2;
        }
        if (strpos($name, 'public/static/') === 0 || strpos($name, 'public/h5/') === 0 || substr($name, -5) === '.html') {
            return 1;
        }
        return 0;
    }

    /**
     * 校验状态中全部文件是否都已达到目标 md5
     *
     * @return array 不一致的文件
     */
    public function verifyStateFiles($app, $version, array $state)
    {
        $bad = [];
        foreach (($state['files'] ?? []) as $item) {
            $name = (string) ($item['name'] ?? '');
            $expected = strtolower((string) ($item['md5'] ?? ''));
            if ($name === '' || $expected === '') {
                continue;
            }
            $target = root_path() . $name;
            if (!is_file($target) || md5_file($target) !== $expected) {
                $bad[] = $name;
            }
        }
        return $bad;
    }

    /**
     * 开启维护标志：替换阶段租户端不对外服务，避免加载半新半旧代码
     */
    public function enableMaintenance($app, $version)
    {
        $path = $this->getMaintenancePath();
        $this->ensureDir(dirname($path));
        @file_put_contents($path, json_encode([
            'app_name'   => $app,
            'version'    => $version,
            'started_at' => time(),
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 关闭维护标志
     */
    public function disableMaintenance()
    {
        $path = $this->getMaintenancePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * 收尾：标记完成并清理暂存区（备份保留用于回滚）
     */
    public function finishState($app, $version)
    {
        $this->updateState($app, $version, function ($state) {
            $state['stage'] = 'finished';
            return $state;
        });
        $this->removeDir($this->getStageRoot($app, $version));
    }

    /**
     * 获取升级锁（同应用互斥）
     */
    public function acquireLock($app)
    {
        $path = $this->getLockPath($app);
        $this->ensureDir(dirname($path));
        $fp = @fopen($path, 'c');
        if (!$fp) {
            throw new Exception('升级锁文件不可写');
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            throw new Exception('已有升级任务正在进行，请稍后重试');
        }
        return $fp;
    }

    /**
     * 释放升级锁
     */
    public function releaseLock($lock)
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 递归删除目录
     */
    public function removeDir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @title 获取云端最新系统版本
     * @return [type] [description]
     */
    public function cloudVersion($params = [])
    {
        $api = $this->api . 'app/version';
        $domain = request()->host();
        $ip   = request()->ip();
        $params['domain'] = $domain;
        $params['ip'] = $ip;
        $params['frame'] = $this->frame();
        // 已激活站点叠加签名，云端据此判断测试版是否对当前域名可见
        $signed = $params;
        try {
            $signed = array_merge($params, CloudSignature::payload('app.version', (string) ($params['app_name'] ?? '')));
        } catch (\Throwable $e) {
            trace('cloud version signature exception: ' . $e->getMessage(), 'error');
        }
        $output = curl_request($api, $signed);
        // 初始化返回数据
        $result = [
            'code' => 0,
            'data' => [
                'version' => '服务器错误',
                'remark' => '服务器错误'
            ]
        ];
        if (is_json($output)) {
            $result = json_decode($output, true); //转换为数组格式
        }
        return $result;
    }

    /**
     * 批量获取云端应用最新版本
     *
     * @param array $appNames 应用标识列表
     * @return array 云端返回结构
     */
    public function cloudVersionBatch(array $appNames)
    {
        $appNames = $this->normalizeAppNames($appNames);
        if (empty($appNames)) {
            return ['code' => 0, 'msg' => '应用参数不正确', 'data' => ['apps' => []]];
        }

        $api = $this->api . 'app/versions';
        $params = [
            'app_names' => json_encode(array_values($appNames), JSON_UNESCAPED_UNICODE),
            'domain' => request()->host(),
            'ip' => request()->ip(),
            'frame' => $this->frame(),
        ];
        // 已激活站点叠加签名，app_name 使用应用清单 hash，便于云端校验清单完整性
        $signed = $params;
        try {
            $signed = array_merge($params, CloudSignature::payload('app.versions', md5(implode(',', $appNames))));
        } catch (\Throwable $e) {
            trace('cloud batch version signature exception: ' . $e->getMessage(), 'error');
        }

        $result = $this->requestCloudVersions($api, $signed);
        if ((int) ($result['code'] ?? 0) === 200) {
            return $result;
        }

        // 签名通道失败（激活密钥过期、云端重置密钥、服务器时钟超窗）时回退公开查询：
        // 版本号与单应用 app/version 同为公开数据，升级提示不允许被授权状态阻断，
        // 否则密钥失效的站点会静默丢失全部升级按钮。
        if ($signed !== $params) {
            trace(
                'cloud batch version signed request failed, fallback to public: '
                . json_encode($result, JSON_UNESCAPED_UNICODE),
                'error'
            );
            $result = $this->requestCloudVersions($api, $params);
        }

        return $result;
    }

    /**
     * 请求云端批量版本接口
     *
     * @param string $api    接口地址
     * @param array  $params 请求参数
     * @return array 云端返回结构，异常时返回 code=0
     */
    private function requestCloudVersions(string $api, array $params): array
    {
        try {
            $output = curl_request($api, $params);
        } catch (\Throwable $e) {
            trace('cloud batch version request exception: ' . $e->getMessage(), 'error');
            $output = '';
        }

        if (!is_json($output)) {
            return ['code' => 0, 'msg' => '服务器错误', 'data' => ['apps' => []]];
        }
        $result = json_decode($output, true);

        return is_array($result) ? $result : ['code' => 0, 'msg' => '服务器错误', 'data' => ['apps' => []]];
    }

    /**
     * 规范化批量应用标识
     *
     * @param array $appNames
     * @return array
     */
    private function normalizeAppNames(array $appNames): array
    {
        $result = [];
        foreach ($appNames as $name) {
            if (!is_string($name) && !is_numeric($name)) {
                continue;
            }
            $name = trim((string) $name);
            if ($name === '' || strlen($name) > 50 || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                continue;
            }
            $result[$name] = $name;
            if (count($result) >= 100) {
                break;
            }
        }

        return array_values($result);
    }

    public function cloudVersionList($params = [])
    {
        $api = $this->api . 'app/version_list';
        $domain = request()->host();
        $ip   = request()->ip();
        $params['domain'] = $domain;
        $params['ip'] = $ip;
        $params['frame'] = $this->frame();
        $output = curl_request($api, $params);
        // 初始化返回数据
        $result = [
            'code' => 0,
            'data' => [
                'version' => '服务器错误',
                'remark' => '服务器错误'
            ]
        ];
        if (is_json($output)) {
            $result = json_decode($output, true); //转换为数组格式
        }
        return $result;
    }

    /**
     * @title 执行升级sql
     * @param $sql_path
     * @return array ['executed' => int, 'errors' => array]
     */
    public function executeUpgradeSql($app = 'system', $version = '')
    {
        if ($app == 'system') {
            $sql_path = $this->getAppRootPath($app) . 'data' . DIRECTORY_SEPARATOR . 'upgrade.sql';
        } else {
            $sql_path = $this->getAppRootPath($app) . 'info' . DIRECTORY_SEPARATOR . 'upgrade.sql';
        }
        // 有版本号时写入该版本日志；否则退回独立 SQL 日志，避免日志落空
        $log_version = $version !== '' ? $version : 'upgrade_sql';

        $executed = 0;
        $skipped = 0;
        $errors = [];
        if (file_exists($sql_path)) {
            $content = file_get_contents($sql_path);
            if (!empty($content)) {
                $sql = (new SqlFile())->getSqlFromFile($sql_path, false, ['muucmf_' => config('database.connections.mysql.prefix')]);
                if ($sql) {
                    foreach ($sql as $s) {
                        // 按 ";\n" 切分后，文件末尾会多出一条空语句；空 SQL 在 PHP 8 下会抛 ValueError，需直接跳过
                        $s = trim($s);
                        if ($s === '') {
                            continue;
                        }
                        // 跨版本累积 SQL：结构已存在/已不存在时跳过，减少无意义的报错
                        $skip_reason = $this->sqlSkipReason($s);
                        if ($skip_reason !== null) {
                            $skipped++;
                            $this->log($app, $log_version, 'sql', '跳过 SQL：' . $skip_reason, [
                                'sql' => mb_substr($s, 0, 300),
                            ]);
                            continue;
                        }
                        try {
                            @Db::query($s);
                            $executed++;
                        } catch (\Throwable $e) {
                            // 保持“单条失败继续执行”的既有语义，但把失败语句暴露给前端与日志
                            $errors[] = ['sql' => $s, 'msg' => $e->getMessage()];
                            trace('upgrade sql failed: ' . $e->getMessage() . ' | ' . $s, 'error');
                            $this->log($app, $log_version, 'error', 'SQL 执行失败：' . $e->getMessage(), [
                                'sql' => mb_substr($s, 0, 300),
                            ]);
                        }
                    }
                    $this->log($app, $log_version, 'sql', '升级 SQL 执行完成', [
                        'executed' => $executed,
                        'skipped'  => $skipped,
                        'failed'   => count($errors),
                    ]);
                }
            }
        }

        // 模块专属模板定义已下沉到各模块 info/message_templates.sql，升级后重放补齐
        try {
            \app\common\service\message\ModuleTemplateSeeder::replayAll();
        } catch (\Throwable $e) {
            // 模板种子失败不影响升级主流程
        }

        return ['executed' => $executed, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * 判断 SQL 是否已无执行必要
     *
     * 仅对「单动作 ALTER / CREATE TABLE / DROP TABLE / CREATE INDEX / DROP INDEX」做结构判断；
     * 多动作 ALTER、含 IF EXISTS 的语句、无法解析的语句一律返回 null 交给数据库执行，
     * 避免误跳过必要变更。
     *
     * @param string $sql
     * @return string|null 跳过原因；null 表示需要执行
     */
    private function sqlSkipReason($sql)
    {
        $sql = trim((string) $sql);
        if ($sql === '') {
            return '空语句';
        }
        if (strpos($sql, '--') === 0 || strpos($sql, '#') === 0 || strpos($sql, '/*') === 0) {
            return null;
        }
        $flat = preg_replace('/\s+/', ' ', $sql);

        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([`\w.]+)/i', $flat, $m)) {
            return $this->tableExists($m[1]) === true ? '数据表已存在' : null;
        }
        if (preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?([`\w.]+)/i', $flat, $m)) {
            return $this->tableExists($m[1]) === false ? '数据表不存在' : null;
        }
        if (preg_match('/^CREATE\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?INDEX\s+([`\w]+)\s+ON\s+([`\w.]+)/i', $flat, $m)) {
            return $this->indexExists($m[2], $m[1]) === true ? '索引已存在' : null;
        }
        if (preg_match('/^DROP\s+INDEX\s+([`\w]+)\s+ON\s+([`\w.]+)/i', $flat, $m)) {
            return $this->indexExists($m[2], $m[1]) === false ? '索引不存在' : null;
        }
        if (!preg_match('/^ALTER\s+TABLE\s+([`\w.]+)\s+(.+)$/is', $sql, $m)) {
            return null;
        }

        $table = $m[1];
        $body = trim($m[2]);
        if (stripos($body, 'IF EXISTS') !== false || stripos($body, 'IF NOT EXISTS') !== false) {
            return null;
        }
        $actions = $this->splitSqlActions($body);
        if (count($actions) !== 1) {
            return null;
        }
        $action = trim($actions[0]);

        if (preg_match('/^ADD\s+PRIMARY\s+KEY/i', $action)) {
            return $this->primaryKeyExists($table) === true ? '主键已存在' : null;
        }
        if (preg_match('/^ADD\s+(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:INDEX|KEY)\s+([`\w]+)/i', $action, $mm)) {
            return $this->indexExists($table, $mm[1]) === true ? '索引已存在' : null;
        }
        if (preg_match('/^ADD\s+CONSTRAINT\s+([`\w]+)/i', $action, $mm)) {
            return $this->constraintExists($table, $mm[1]) === true ? '约束已存在' : null;
        }
        if (preg_match('/^ADD\s+(?:COLUMN\s+)?([`\w]+)/i', $action, $mm)) {
            return $this->columnExists($table, $mm[1]) === true ? '字段已存在' : null;
        }
        if (preg_match('/^DROP\s+PRIMARY\s+KEY/i', $action)) {
            return $this->primaryKeyExists($table) === false ? '主键不存在' : null;
        }
        if (preg_match('/^DROP\s+(?:INDEX|KEY)\s+([`\w]+)/i', $action, $mm)) {
            return $this->indexExists($table, $mm[1]) === false ? '索引不存在' : null;
        }
        if (preg_match('/^DROP\s+(?:FOREIGN\s+KEY|CONSTRAINT)\s+([`\w]+)/i', $action, $mm)) {
            return $this->constraintExists($table, $mm[1]) === false ? '约束不存在' : null;
        }
        if (preg_match('/^DROP\s+(?:COLUMN\s+)?([`\w]+)/i', $action, $mm)) {
            return $this->columnExists($table, $mm[1]) === false ? '字段不存在' : null;
        }
        if (preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?([`\w]+)/i', $action, $mm)) {
            return $this->columnExists($table, $mm[1]) === false ? '字段不存在' : null;
        }
        if (preg_match('/^RENAME\s+(?:TO\s+|AS\s+)?([`\w.]+)/i', $action, $mm)) {
            if ($this->tableExists($table) === false) {
                return '数据表不存在';
            }
            return $this->tableExists($mm[1]) === true ? '目标数据表已存在' : null;
        }

        return null;
    }

    /**
     * 按顶层逗号拆分 ALTER 动作（忽略括号内的逗号）
     */
    private function splitSqlActions($body)
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $length = strlen($body);
        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $parts[] = trim($current);
        }
        return $parts;
    }

    /**
     * 解析表名与库名（兼容 `db`.`table` 写法与表前缀）
     *
     * @return array [database, [候选表名...]]
     */
    private function resolveTableLookup($table)
    {
        $table = str_replace(['`', ' '], '', (string) $table);
        $database = (string) config('database.connections.mysql.database');
        if (strpos($table, '.') !== false) {
            [$database, $table] = explode('.', $table, 2);
        }
        $prefix = (string) config('database.connections.mysql.prefix');
        $names = [$table];
        if ($prefix !== '' && strpos($table, $prefix) !== 0) {
            $names[] = $prefix . $table;
        }
        return [$database, $names];
    }

    /**
     * 数据表是否存在；null 表示无法判断（交回数据库执行）
     */
    private function tableExists($table)
    {
        try {
            [$database, $names] = $this->resolveTableLookup($table);
            foreach ($names as $name) {
                $rows = Db::query(
                    'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                    [$database, $name]
                );
                if (!empty($rows[0]['c'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 字段是否存在；null 表示无法判断
     */
    private function columnExists($table, $column)
    {
        try {
            [$database, $names] = $this->resolveTableLookup($table);
            $column = str_replace('`', '', (string) $column);
            foreach ($names as $name) {
                $rows = Db::query(
                    'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                    [$database, $name, $column]
                );
                if (!empty($rows[0]['c'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 索引是否存在；null 表示无法判断
     */
    private function indexExists($table, $index)
    {
        try {
            [$database, $names] = $this->resolveTableLookup($table);
            $index = str_replace('`', '', (string) $index);
            foreach ($names as $name) {
                $rows = Db::query(
                    'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
                    [$database, $name, $index]
                );
                if (!empty($rows[0]['c'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 约束是否存在；null 表示无法判断
     */
    private function constraintExists($table, $constraint)
    {
        try {
            [$database, $names] = $this->resolveTableLookup($table);
            $constraint = str_replace('`', '', (string) $constraint);
            foreach ($names as $name) {
                $rows = Db::query(
                    'SELECT COUNT(*) AS c FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ?',
                    [$database, $name, $constraint]
                );
                if (!empty($rows[0]['c'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 主键是否存在；null 表示无法判断
     */
    private function primaryKeyExists($table)
    {
        try {
            [$database, $names] = $this->resolveTableLookup($table);
            foreach ($names as $name) {
                $rows = Db::query(
                    "SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = 'PRIMARY'",
                    [$database, $name]
                );
                if (!empty($rows[0]['c'])) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 生成本地升级json包
     *
     * @param string $scene upgrade|setup：安装场景不做删除文件回收，避免误删本地已存在内容
     */
    public function buildJson($app_name, $version, $auth_code, $scene = 'upgrade')
    {
        $url = $this->api;
        if ($app_name == 'system') {
            $url = $url . "upgrade/system/version";
        } else {
            $url = $url . "upgrade/app/version";
        }
        $post = [
            'app_name'  =>  $app_name,
            'frame' => $this->frame(),
            'version' =>  $version,
            'auth_code' => $auth_code
        ];
        // S1：已激活则叠加签名参数，scope 固定为 upgrade.version
        try {
            $post = array_merge($post, CloudSignature::payload('upgrade.version', (string) $app_name));
        } catch (\Throwable $e) {
            trace('cloud signature payload exception: ' . $e->getMessage(), 'error');
        }
        $result = curl_request($url, $post);

        try {
            $result = json_decode($result, true);
            if (is_array($result) && $result['code'] == 200) {
                $files = $result['data']['data'];
                $files_allowed = [];
                if (!empty($files)) {
                    // 先落一份目标版本全量清单，用于下次升级计算「新版本删除了哪些文件」
                    try {
                        $this->saveManifest($app_name, $version, is_array($files) ? $files : []);
                    } catch (\Throwable $e) {
                        trace('upgrade manifest save failed: ' . $e->getMessage(), 'error');
                    }

                    // 回收清单：已安装清单 + 云端下发 removed[] 与新清单的差集（受管目录内）
                    $cloud_removed = is_array($result['data']['removed'] ?? null) ? $result['data']['removed'] : [];
                    $removed = $scene === 'setup'
                        ? []
                        : $this->computeRemoved($app_name, $version, $files, $cloud_removed);
                    try {
                        $this->saveRemovedList($app_name, $version, $removed);
                    } catch (\Throwable $e) {
                        trace('upgrade removed list save failed: ' . $e->getMessage(), 'error');
                    }
                    $result['data']['removed'] = $removed;

                    foreach ($files as $file) {
                        $name = str_replace('\\', '/', (string) ($file['name'] ?? ''));
                        $expected = strtolower((string) ($file['md5'] ?? ''));
                        $entry = root_path() . $name;
                        // md5 必须严格比较：'0e...' 这类字符串用 != 会互相相等，
                        // 会把「内容已变化」的文件误判为已达标而跳过，升级后文件停留在旧版本
                        if (!is_file($entry) || $expected === '' || md5_file($entry) !== $expected) {
                            $files_allowed[] = $file;
                        }
                    }
                    $result['data']['data'] = $files_allowed;
                    $result['data']['total'] = count($files_allowed);
                } else {
                    $result['data']['data'] = is_array($files) ? $files : [];
                    $result['data']['total'] = is_array($files) ? count($files) : 0;
                    $result['data']['removed'] = [];
                    // 云端清单为空时清掉同版本遗留的待回收列表，避免误删
                    try {
                        $this->saveRemovedList($app_name, $version, []);
                    } catch (\Throwable $e) {
                        trace('upgrade removed list reset failed: ' . $e->getMessage(), 'error');
                    }
                }

                return $result;
            } else {
                return $result;
            }
        } catch (ValidateException $e) {
            return false;
        }

        return false;
    }
}
