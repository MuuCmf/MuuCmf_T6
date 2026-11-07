<?php

namespace app\common\service\message;

use think\facade\Cache;
use think\facade\Db;

/**
 * 渠道与触达配置聚合
 *
 * 统一读取 extend_config，按 shopid 缓存 5 分钟；后台保存后主动失效。
 * 各渠道只依赖本类，禁止自行读 extend_config / wechat_config。
 */
class ChannelConfigProvider
{
    /** 缓存有效期（秒） */
    protected const CACHE_TTL = 300;

    /** @var array|null CLI 环境扩展配置进程内缓存 */
    protected static $extendConfig = null;

    /** @var array|null CLI 环境系统配置进程内缓存 */
    protected static $systemConfig = null;

    /**
     * 获取店铺渠道配置
     *
     * @param int $shopid
     * @return array
     */
    public function get(int $shopid): array
    {
        $key = $this->cacheKey($shopid);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // 缓存不可用时直接读配置
        }

        $config = $this->build($shopid);
        try {
            Cache::set($key, $config, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // 忽略缓存写入失败
        }

        return $config;
    }

    /**
     * 失效缓存
     *
     * @param int $shopid
     * @return void
     */
    public function invalidate(int $shopid = 0): void
    {
        try {
            Cache::delete($this->cacheKey($shopid));
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 渠道是否已配置
     *
     * @param int    $shopid
     * @param string $channel
     * @return bool
     */
    public function configured(int $shopid, string $channel): bool
    {
        $config = $this->get($shopid);
        return !empty($config['channels'][$channel]);
    }

    /**
     * 渠道开关是否启用
     *
     * @param int    $shopid
     * @param string $channel
     * @return bool
     */
    public function enabled(int $shopid, string $channel): bool
    {
        $config = $this->get($shopid);
        return !empty($config['enabled'][$channel]);
    }

    /**
     * 构建配置
     *
     * @param int $shopid
     * @return array
     */
    protected function build(int $shopid): array
    {
        $driver = (string)$this->config('SMS_SEND_DRIVER', 'aliyun');

        $aliyun = [
            'access_key_id'     => (string)$this->config('SMS_ALIYUN_ACCESSKEYID', ''),
            'access_key_secret' => (string)$this->config('SMS_ALIYUN_ACCESSKEYSECRET', ''),
            'region'            => (string)$this->config('SMS_ALIYUN_REGION', 'cn-beijing'),
            'sign'              => (string)$this->config('SMS_ALIYUN_SIGN', ''),
        ];
        $tencent = [
            'secret_id'   => (string)$this->config('SMS_TENCENT_SECRETID', ''),
            'secret_key'  => (string)$this->config('SMS_TENCENT_SECRETKEY', ''),
            'sdk_app_id'  => (string)$this->config('SMS_TENCENT_APPID', ''),
            'sign'        => (string)$this->config('SMS_TENCENT_SIGN', ''),
            'region'      => (string)$this->config('SMS_TENCENT_REGION', 'ap-beijing'),
        ];

        $smsConfigured = $driver === 'tencent'
            ? ($tencent['secret_id'] !== '' && $tencent['secret_key'] !== '' && $tencent['sdk_app_id'] !== '')
            : ($aliyun['access_key_id'] !== '' && $aliyun['access_key_secret'] !== '');

        // 邮件连接信息属于系统配置（muucmf_config），与短信等扩展配置分属两张表
        $email = [
            'host' => (string)self::systemConfig('MAIL_SMTP_HOST', $this->config('MAIL_SMTP_HOST', '')),
            'port' => (string)self::systemConfig('MAIL_SMTP_PORT', $this->config('MAIL_SMTP_PORT', '')),
            'user' => (string)self::systemConfig('MAIL_SMTP_USER', $this->config('MAIL_SMTP_USER', '')),
            'pass' => (string)self::systemConfig('MAIL_SMTP_PASS', $this->config('MAIL_SMTP_PASS', '')),
        ];
        $emailConfigured = $email['host'] !== '' && $email['user'] !== '' && $email['pass'] !== '';

        $quietStart = $this->normalizeTime((string)$this->config('MESSAGE_QUIET_START', '21:00'));
        $quietEnd = $this->normalizeTime((string)$this->config('MESSAGE_QUIET_END', '09:00'));

        return [
            'shopid'     => $shopid,
            'sms'        => ['driver' => $driver, 'aliyun' => $aliyun, 'tencent' => $tencent],
            'email'      => $email,
            'channels'   => [
                'inbox' => true,
                'email' => $emailConfigured,
                'sms'   => $smsConfigured,
            ],
            'enabled'    => [
                'inbox' => true,
                'email' => (string)$this->config('MESSAGE_CHANNEL_EMAIL', '1') === '1' && $emailConfigured,
                'sms'   => (string)$this->config('MESSAGE_CHANNEL_SMS', '1') === '1' && $smsConfigured,
            ],
            'quiet'      => [
                'enabled' => (string)$this->config('MESSAGE_QUIET_ENABLED', '0') === '1',
                'start'   => $quietStart,
                'end'     => $quietEnd,
            ],
            'batch_size' => $this->clampInt((int)$this->config('MESSAGE_BATCH_SIZE', 500), 100, 2000, 500),
            // 重试次数允许配置为 0（不重试，首次失败直接走兜底链），因此不能套用 clampInt
            'retry_max'  => max(0, min(5, (int)$this->config('MESSAGE_RETRY_MAX', 3))),
            'frequency'  => [
                'max_times'    => $this->clampInt((int)$this->config('MESSAGE_FREQ_MAX', 3), 1, 100, 3),
                'window_hours' => $this->clampInt((int)$this->config('MESSAGE_FREQ_WINDOW', 24), 1, 8760, 24),
            ],
            'default_chain' => $this->normalizeChain((string)$this->config('MESSAGE_DEFAULT_CHAIN', 'sms')),
        ];
    }

    /**
     * 读取扩展配置（供定时任务等 CLI 场景复用同一口径）
     *
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    public static function configValue(string $name, $default = '')
    {
        return self::config($name, $default);
    }

    /**
     * 读取扩展配置
     *
     * 队列 worker / 定时任务是 CLI 环境，不经过 DbConfig 中间件，
     * 因此 config('extend.*') 为空时回落到数据库直读（进程内缓存一次）。
     *
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    protected static function config(string $name, $default = '')
    {
        $value = config('extend.' . $name);
        if ($value !== null && $value !== '') {
            return $value;
        }

        if (self::$extendConfig === null) {
            self::$extendConfig = [];
            try {
                $rows = Db::name('ExtendConfig')->where('status', 1)->field('name,value')->select()->toArray();
                foreach ($rows as $row) {
                    self::$extendConfig[$row['name']] = $row['value'];
                }
            } catch (\Throwable $e) {
                self::$extendConfig = [];
            }
        }

        $value = self::$extendConfig[$name] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * 读取系统配置
     *
     * 邮件 SMTP 等连接信息由系统设置写入 muucmf_config，
     * 与扩展配置分属不同数据源；CLI 环境下同样回落数据库直读。
     * 静态 + 进程内缓存，供 Mail 等外部服务复用，避免每次发信全表重读。
     *
     * @param string $name
     * @param mixed  $default
     * @return mixed
     */
    public static function systemConfig(string $name, $default = '')
    {
        $value = config('system.' . $name);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $map = self::systemConfigMap();
        $value = $map[$name] ?? null;
        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * 系统配置全量映射（muucmf_config 中 status=1 的记录）
     *
     * @return array
     */
    public static function systemConfigMap(): array
    {
        if (self::$systemConfig === null) {
            self::$systemConfig = [];
            try {
                $rows = Db::name('Config')->where('status', 1)->field('name,value')->select()->toArray();
                foreach ($rows as $row) {
                    self::$systemConfig[$row['name']] = $row['value'];
                }
            } catch (\Throwable $e) {
                self::$systemConfig = [];
            }
        }

        return self::$systemConfig;
    }

    /**
     * 规范化 HH:MM
     *
     * @param string $time
     * @return string
     */
    protected function normalizeTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{1,2})$/', trim($time), $m)) {
            return sprintf('%02d:%02d', min(23, (int)$m[1]), min(59, (int)$m[2]));
        }
        return '';
    }

    /**
     * 规范化渠道链
     *
     * @param string $chain
     * @return array
     */
    protected function normalizeChain(string $chain): array
    {
        $items = array_filter(array_map('trim', explode(',', $chain)));
        $items = array_values(array_unique(array_intersect($items, ['inbox', 'email', 'sms'])));
        return $items ?: ['sms'];
    }

    /**
     * 区间钳制
     *
     * @param int $value
     * @param int $min
     * @param int $max
     * @param int $default
     * @return int
     */
    protected function clampInt(int $value, int $min, int $max, int $default): int
    {
        if ($value <= 0) {
            return $default;
        }
        return max($min, min($max, $value));
    }

    /**
     * 缓存键
     *
     * @param int $shopid
     * @return string
     */
    protected function cacheKey(int $shopid): string
    {
        return 'msg:channel_config:' . $shopid;
    }
}
