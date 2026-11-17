<?php

namespace app\common\service;

use app\admin\lib\CloudSignature;
use app\admin\lib\Upgrade;
use think\facade\Cache;
use think\facade\Db;

/**
 * 云端应用来源判定（全局口径）
 *
 * 口径：云端「已发布」即视为云端应用（app/versions 返回 exists=true），
 * 与库内 source 标记无关。判定命中后强制把 module.source 纠正为 cloud，
 * 避免 allapp 同步失败、历史数据或本地目录重建留下的 local 标记，
 * 让列表、详情、版本检测、安装拦截、运行时授权共用同一份判定。
 *
 * 标识唯一：本地应用禁止占用云端已发布标识，重名由 Module::getLocalApp()
 * 在登记阶段直接拒绝，因此这里不做「同名但属于本地」的区分。
 */class CloudAppSource
{
    /** 批量版本结果缓存有效期（秒），与原列表页缓存一致 */
    const CACHE_TTL = 300;
    /** 云端不可达时的负缓存（秒），避免后台每个请求都重复重试 */
    const FAIL_CACHE_TTL = 60;
    /** 应用框架标识 */
    const FRAME = 't6';
    /** 单次批量上限，与云端接口约定一致 */
    const MAX_BATCH = 100;
    /** 系统内置应用，不参与云端判定 */
    const SYSTEM_APP = ['admin', 'common', 'channel', 'ucenter', 'api', 'index', 'cloud', 'appstore'];

    /** @var array 请求内版本结果 name => ['exists' => bool, 'version' => string] */
    protected static array $versionMap = [];

    /** @var array 请求内来源判定结果 name => bool */
    protected static array $resolved = [];

    /**
     * 判断应用是否为云端应用，命中后回写来源标记
     *
     * @param string $name 应用标识
     * @return bool
     */
    public static function isCloud($name): bool
    {
        $name = trim((string) $name);
        if ($name === '' || in_array($name, self::SYSTEM_APP, true)) {
            return false;
        }
        if (array_key_exists($name, self::$resolved)) {
            return self::$resolved[$name];
        }

        $result = self::versions([$name]);
        $exists = !empty($result[$name]['exists']);
        if ($exists) {
            self::heal([$name]);
        }

        return self::$resolved[$name] = $exists;
    }

    /**
     * 是否需要对应用做云端授权门禁
     *
     * 来源为 cloud 且已安装才需要门禁；框架内置应用一律放行，
     * 避免授权页、应用商店等自救入口被「未购买」判定锁死。
     *
     * @param array $module 应用模块信息
     * @return bool
     */
    public static function requiresLicense(array $module): bool
    {
        if (($module['source'] ?? '') !== 'cloud') {
            return false;
        }
        if ((int) ($module['is_setup'] ?? 0) !== 1) {
            return false;
        }

        $name = (string) ($module['name'] ?? '');

        return !self::isInfraApp($name);
    }

    /**
     * 是否为框架内置应用
     *
     * 历史商业模块标记已废弃，所有非内置应用一律按商业应用处理，
     * 门禁强度不因应用自身声明而降低。
     *
     * @param string $name 应用标识
     * @return bool
     */
    public static function isInfraApp($name): bool
    {
        $name = trim((string) $name);
        if ($name === '') {
            return true;
        }

        return in_array($name, self::SYSTEM_APP, true);
    }

    /**
     * 批量获取云端应用发布状态
     *
     * @param array $names 应用标识列表
     * @return array name => ['exists' => bool, 'version' => string]
     */
    public static function versions(array $names): array
    {
        $names = self::normalize($names);
        if (empty($names)) {
            return [];
        }
        sort($names);

        // 请求内命中
        $hit = [];
        foreach ($names as $name) {
            if (array_key_exists($name, self::$versionMap)) {
                $hit[$name] = self::$versionMap[$name];
            }
        }
        if (count($hit) === count($names)) {
            return $hit;
        }

        // CLI / 计划任务入口不发起云端判定，避免无宿主域名时阻塞任务
        if (PHP_SAPI === 'cli') {
            return $hit;
        }

        $cacheKey = self::cacheKey($names);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            foreach ($cached as $name => $info) {
                if (is_array($info)) {
                    self::$versionMap[(string) $name] = [
                        'exists' => !empty($info['exists']),
                        'version' => (string) ($info['version'] ?? ''),
                        'status' => (int) ($info['status'] ?? 1),
                    ];
                }
            }
            return self::pick($names);
        }

        // 云端不可达的负缓存期内按未知处理，命中项仍走高置信缓存
        if (Cache::get($cacheKey . '_fail')) {
            return $hit;
        }

        try {
            $result = (new Upgrade())->cloudVersionBatch($names);
        } catch (\Throwable $e) {
            trace('cloud app source batch exception: ' . $e->getMessage(), 'error');
            Cache::tag('module_cloud')->set($cacheKey . '_fail', 1, self::FAIL_CACHE_TTL);
            return $hit;
        }

        if (!is_array($result) || (int) ($result['code'] ?? 0) !== 200) {
            Cache::tag('module_cloud')->set($cacheKey . '_fail', 1, self::FAIL_CACHE_TTL);
            return $hit;
        }

        $apps = $result['data']['apps'] ?? [];
        if (!is_array($apps)) {
            Cache::tag('module_cloud')->set($cacheKey . '_fail', 1, self::FAIL_CACHE_TTL);
            return $hit;
        }

        $map = [];
        foreach ($apps as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $map[(string) $name] = [
                'exists' => !empty($info['exists']),
                'version' => (string) ($info['version'] ?? ''),
                'status' => (int) ($info['status'] ?? 1),
            ];
        }
        // 云端未返回的应用补空，避免上层因缺项继续按旧标记处理
        foreach ($names as $name) {
            if (!isset($map[$name])) {
                $map[$name] = ['exists' => false, 'version' => '', 'status' => 1];
            }
        }

        Cache::tag('module_cloud')->set($cacheKey, $map, self::CACHE_TTL);
        foreach ($map as $name => $info) {
            self::$versionMap[(string) $name] = $info;
        }

        return self::pick($names);
    }

    /**
     * 批量把库内来源标记纠正为 cloud
     *
     * 单向纠正，只允许 local → cloud，不做反向回退。
     *
     * @param array $names 应用标识列表
     * @return void
     */
    public static function heal(array $names): void
    {
        $names = self::normalize($names);
        if (empty($names)) {
            return;
        }

        try {
            $affected = Db::name('module')->where('name', 'in', $names)
                ->where('source', '<>', 'cloud')
                ->update(['source' => 'cloud']);
            if ($affected) {
                // 留痕：被强制纠正的应用可在日志中审计，便于快速识别误判的本地应用
                trace('cloud app source forced: ' . implode(',', $names) . ' (' . $affected . ')', 'info');
            }
        } catch (\Throwable $e) {
            // 回写失败不影响本次请求的判定结果，下次请求会重新纠正
            trace('cloud app source heal failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 从请求内结果集取指定应用
     *
     * @param array $names 已规范化的应用标识
     * @return array
     */
    private static function pick(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            if (isset(self::$versionMap[$name])) {
                $result[$name] = self::$versionMap[$name];
            }
        }
        return $result;
    }

    /**
     * 规范化应用标识
     *
     * @param array $names
     * @return array
     */
    private static function normalize(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            if (!is_string($name) && !is_numeric($name)) {
                continue;
            }
            $name = trim((string) $name);
            if ($name === '' || strlen($name) > 50 || !preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                continue;
            }
            if (in_array($name, self::SYSTEM_APP, true)) {
                continue;
            }
            $result[$name] = $name;
            if (count($result) >= self::MAX_BATCH) {
                break;
            }
        }
        return array_values($result);
    }

    /**
     * 批量结果缓存键
     *
     * 与列表页历史缓存键保持一致（host + frame + 激活指纹 + 应用清单），
     * 保证全局判定与列表页复用同一份结果。
     *
     * @param array $names 已排序的应用标识
     * @return string
     */
    private static function cacheKey(array $names): string
    {
        $host = '';
        try {
            $host = (string) request()->host();
        } catch (\Throwable $e) {
            $host = '';
        }
        $fingerprint = '';
        try {
            $fingerprint = CloudSignature::fingerprint();
        } catch (\Throwable $e) {
            $fingerprint = '';
        }

        return 'module_cloud_versions_' . md5(
            $host . '|' . self::FRAME . '|' . $fingerprint . '|' . implode(',', $names)
        );
    }
}
