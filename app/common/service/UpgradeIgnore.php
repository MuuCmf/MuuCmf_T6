<?php
// +----------------------------------------------------------------------
// | muucmf 升级忽略名单
// +----------------------------------------------------------------------
namespace app\common\service;

/**
 * 升级忽略名单：云端编译与租户端落盘共用同一份定义
 *
 * 历史问题：这份名单曾在租户端 app\admin\lib\Upgrade::checkIgnoreFile() 内硬编码，
 * 云端编译发行包时不认识它，于是把云端自身 .env、config/database.php、runtime 日志
 * 一起打进框架包下发给所有租户。名单收敛到这里之后，编译期直接剔除、租户端再兜一层。
 */
class UpgradeIgnore
{
    /**
     * 框架（system）包不参与升级的路径片段
     *
     * 说明：按片段做子串匹配是刻意保留的历史语义（防 config//queue.php 这类拼接绕过），
     * 因此这里列出的是片段而非精确路径。
     */
    const FRAMEWORK_PATHS = [
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

    /**
     * 名单片段
     * @return array
     */
    public static function paths()
    {
        return self::FRAMEWORK_PATHS;
    }

    /**
     * 路径归一化：统一分隔符并折叠重复斜杠
     * @param string $path
     * @return string
     */
    public static function normalize($path)
    {
        return preg_replace('#/+#', '/', str_replace('\\', '/', (string) $path));
    }

    /**
     * 是否属于框架包忽略文件（仅框架包有意义，应用包不受影响）
     * @param string $path
     * @return bool
     */
    public static function isIgnored($path)
    {
        $path = self::normalize($path);
        foreach (self::FRAMEWORK_PATHS as $item) {
            if (strpos($path, $item) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 过滤清单条目
     * @param array $manifest 形如 [['name' => 'app/x', 'md5' => '...'], ...]
     * @return array 重新索引后的清单
     */
    public static function filterManifest(array $manifest)
    {
        $result = [];
        foreach ($manifest as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (self::isIgnored((string) ($item['name'] ?? '')) === true) {
                continue;
            }
            $result[] = $item;
        }
        return $result;
    }
}
