<?php

namespace app\common\service\contribution;

/**
 * 投稿能力解析器
 *
 * 沿用 PolicyRegistry 的「约定命名空间 + class_exists + instanceof」惯例：
 * 无中心登记表、无 DB 声明字段，新增应用只需在自己的目录放 Provider 类；
 * 应用卸载后由 enabled() 的 ModuleGuard 判定为不可用。
 *
 * 不做请求级缓存，保证安装/卸载即时生效，也避免常驻进程缓存过期问题。
 */
class ContributionRegistry
{
    /** 约定实现类名 */
    public static function providerClass(string $app): string
    {
        return 'app\\' . $app . '\\service\\contribution\\Provider';
    }

    /**
     * 解析应用的投稿能力；未注册、类名非法或实现不合规时返回 null
     */
    public static function resolve(string $app): ?ContributionProviderInterface
    {
        $app = strtolower(trim($app));
        // 应用名会拼进类名，仅接受合法标识符，避免任意类加载
        if ($app === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $app)) {
            return null;
        }

        $class = self::providerClass($app);
        if (!class_exists($class)) {
            return null;
        }

        try {
            $instance = new $class();
        } catch (\Throwable $e) {
            return null;
        }

        return $instance instanceof ContributionProviderInterface ? $instance : null;
    }

    /**
     * 发现所有已声明投稿能力的应用
     *
     * 用文件约定扫描而非查 DB：路由注册阶段数据库可能尚未就绪（如模块安装中），
     * 而 routes() 纯静态声明不依赖 DB；安装态与平台配置由 enabled() 在运行时判定。
     * 新增应用只要在自身目录放 Provider 类即可被发现，宿主无需改动。
     *
     * @return ContributionProviderInterface[] 以应用标识为键
     */
    public static function all(): array
    {
        $providers = [];
        $appPath = str_replace('\\', '/', dirname(__DIR__, 3));
        $files = glob($appPath . '/*/service/contribution/Provider.php') ?: [];
        foreach ($files as $file) {
            // 路径形如 app/{app}/service/contribution/Provider.php
            $normalized = str_replace('\\', '/', $file);
            $app = basename(dirname($normalized, 3));
            $provider = self::resolve($app);
            if ($provider !== null) {
                $providers[$provider->app()] = $provider;
            }
        }
        ksort($providers);
        return $providers;
    }
}
