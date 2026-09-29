<?php

declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;

/**
 * Sitemap 生成服务
 *
 * 汇总「框架核心页面」（config/sitemap.php 的 core 清单）与「已安装应用页面」
 * （app/{模块}/service/Sitemap.php 的 urls() 方法），生成标准 sitemap.xml 落盘 public 目录。
 *
 * 设计要点：
 *   - 静态落盘：产物为 public 下的普通文件，由 Web 服务器直接命中，不占用动态路由；
 *   - 自动发现：按 muucmf_module.is_setup=1 遍历已安装应用，应用实现约定类即自动被收录；
 *   - 域名兜底：HTTP 上下文取 request()->domain()，CLI/定时任务下依次取
 *     config('system.WEB_SITE_URL') 与数据库 muucmf_config.WEB_SITE_URL，取不到则拒绝生成；
 *   - 超限拆分：单文件超过 file_limit 时拆分为 sitemap-{分组}.xml，主文件写 sitemapindex。
 *
 * @package app\common\service
 */
class Sitemap
{
    /**
     * 框架核心模块名：不参与「已安装应用」页面贡献（管理端/接口/默认应用等）
     */
    protected const CORE_MODULES = ['admin', 'api', 'common', 'index', 'ucenter'];

    /**
     * 单文件条数上限兜底值（config/sitemap.php 未配置时使用）
     */
    protected const DEFAULT_FILE_LIMIT = 50000;

    /**
     * changefreq 合法取值（sitemaps.org 协议）
     */
    protected const CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    /**
     * 解析后的站点域名（含协议，无结尾斜杠）
     */
    protected string $siteUrl = '';

    /**
     * 是否已解析过站点域名（避免域名缺失时反复查库）
     */
    protected bool $siteUrlResolved = false;

    /**
     * 生成 sitemap 并落盘
     *
     * @return array [
     *   ok: bool, msg: string, total: int, url: string, robots: bool,
     *   files: string[], groups: array<string,int>
     * ]
     */
    public function publish(): array
    {
        $siteUrl = $this->siteUrl();
        if ($siteUrl === '') {
            return $this->result(false, '未配置站点域名：请在后端「系统配置」中设置 WEB_SITE_URL（需含 http:// 或 https://），或在 HTTP 请求下触发生成', 0, [], []);
        }

        $groups = [];
        foreach ($this->collect() as $name => $items) {
            $normalized = $this->normalize($items, $siteUrl);
            if ($normalized) {
                $groups[$name] = $normalized;
            }
        }

        $total = 0;
        $stat = [];
        foreach ($groups as $name => $items) {
            $total += count($items);
            $stat[$name] = count($items);
        }
        if ($total === 0) {
            return $this->result(false, '未采集到任何可索引页面（请检查 config/sitemap.php 与各应用的 Sitemap 提供者）', 0, [], []);
        }

        $limit = (int)config('sitemap.file_limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_FILE_LIMIT;
        }
        $indexFile = (string)config('sitemap.index_file');
        if ($indexFile === '') {
            $indexFile = 'sitemap.xml';
        }

        // 按分组切块：单块直接写主文件，多块写主文件为 sitemapindex
        $chunks = [];
        foreach ($groups as $name => $items) {
            $parts = array_chunk($items, $limit);
            $partCount = count($parts);
            foreach ($parts as $i => $part) {
                $chunks[] = [
                    'file'  => 'sitemap-' . $name . ($partCount > 1 ? '-' . ($i + 1) : '') . '.xml',
                    'items' => $part,
                ];
            }
        }

        $root = public_path();
        $files = [];
        try {
            if (count($chunks) === 1) {
                $this->writeFile($root . $indexFile, $this->toUrlset($chunks[0]['items']));
                $files[] = $indexFile;
            } else {
                $names = [];
                foreach ($chunks as $chunk) {
                    $this->writeFile($root . $chunk['file'], $this->toUrlset($chunk['items']));
                    $files[] = $chunk['file'];
                    $names[] = $chunk['file'];
                }
                $this->writeFile($root . $indexFile, $this->toIndex($names, $siteUrl));
                $files[] = $indexFile;
            }
        } catch (\Throwable $e) {
            return $this->result(false, '生成失败：' . $e->getMessage(), $total, [], $stat);
        }

        $robots = $this->updateRobots($siteUrl, $indexFile);

        return $this->result(
            true,
            sprintf('已生成 %d 条 URL，输出 %d 个文件（站点域名 %s）', $total, count($files), $siteUrl),
            $total,
            $files,
            $stat,
            $siteUrl . '/' . $indexFile,
            $robots
        );
    }

    /**
     * 当前 sitemap 状态（供后台展示，不触发生成）
     *
     * @return array [
     *   site_url: string, url: string, exists: bool, size: int, updated_at: string,
     *   apps: array<int,array{name:string,has_provider:bool}>, ...
     * ]
     */
    public function status(): array
    {
        $siteUrl = $this->siteUrl();
        $indexFile = (string)config('sitemap.index_file') ?: 'sitemap.xml';
        $path = public_path() . $indexFile;

        $apps = [];
        foreach ($this->installedApps() as $app) {
            $apps[] = ['name' => $app, 'has_provider' => class_exists(self::providerClass($app))];
        }

        return [
            'site_url'   => $siteUrl,
            'url'        => $siteUrl !== '' ? $siteUrl . '/' . $indexFile : '',
            'file'       => $indexFile,
            'exists'     => is_file($path),
            'size'       => is_file($path) ? (int)filesize($path) : 0,
            'updated_at' => is_file($path) ? date('Y-m-d H:i:s', (int)filemtime($path)) : '',
            'core'       => array_column((array)config('sitemap.core'), 'loc'),
            'apps'       => $apps,
        ];
    }

    /**
     * 汇总各来源的原始条目
     *
     * @return array<string,array> ['core' => [...], '{应用名}' => [...]]
     */
    protected function collect(): array
    {
        $groups = [];

        $core = config('sitemap.core');
        if (is_array($core) && $core) {
            $groups['core'] = $core;
        }

        foreach ($this->installedApps() as $app) {
            $items = $this->appUrls($app);
            if ($items) {
                $groups[$app] = $items;
            }
        }

        return $groups;
    }

    /**
     * 已安装应用名列表（排除框架核心模块，按名称排序，保证输出稳定）
     *
     * @return string[]
     */
    protected function installedApps(): array
    {
        try {
            $rows = Db::name('module')->where('is_setup', 1)->field('name')->select()->toArray();
        } catch (\Throwable $e) {
            trace('Sitemap 读取已安装应用失败：' . $e->getMessage(), 'error');
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string)($row['name'] ?? '')));
            if ($name === '' || in_array($name, self::CORE_MODULES, true)) {
                continue;
            }
            // 模块名会拼进类名与文件名，仅接受合法标识符，避免异常数据导致路径穿越
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                continue;
            }
            $names[] = $name;
        }
        sort($names);

        return $names;
    }

    /**
     * 调用某个应用的 Sitemap 提供者取 URL 清单
     *
     * @param string $app 应用名（即 php_server/app 下的模块目录名）
     * @return array
     */
    protected function appUrls(string $app): array
    {
        $class = self::providerClass($app);
        if (!class_exists($class) || !method_exists($class, 'urls')) {
            return [];
        }

        try {
            $items = (new $class())->urls();
        } catch (\Throwable $e) {
            // 单个应用异常不影响其它应用与框架页面的生成
            trace('Sitemap 应用 ' . $app . ' 取 URL 异常：' . $e->getMessage(), 'error');
            return [];
        }

        return is_array($items) ? $items : [];
    }

    /**
     * 应用 Sitemap 提供者类名
     */
    protected static function providerClass(string $app): string
    {
        return 'app\\' . $app . '\\service\\Sitemap';
    }

    /**
     * 站点域名：HTTP 取请求域名，CLI/定时任务取系统配置，均无则返回空串
     */
    protected function siteUrl(): string
    {
        if ($this->siteUrlResolved) {
            return $this->siteUrl;
        }
        $this->siteUrlResolved = true;

        $url = $this->normalizeSiteUrl((string)request()->domain());
        if ($url === '') {
            $url = $this->normalizeSiteUrl((string)config('system.WEB_SITE_URL'));
        }
        if ($url === '') {
            // CLI/定时任务下不执行 HTTP 中间件，config('system') 为空，直接读库兜底
            try {
                $url = $this->normalizeSiteUrl((string)Db::name('config')->where('name', 'WEB_SITE_URL')->where('status', 1)->value('value'));
            } catch (\Throwable $e) {
                $url = '';
            }
        }

        $this->siteUrl = $url;
        return $this->siteUrl;
    }

    /**
     * 站点域名归一化
     *
     * 仅接受「scheme://host[:port]」或「host[:port]（含点或为 localhost）」两种形式，
     * 其余一律视为无效：CLI 下 request()->domain() 会返回 'http:' 这类残缺值，
     * 若只判断非空会把 'https://http:' 写进 sitemap。
     *
     * @param string $url 待归一化的域名
     * @return string 归一化后的域名（含协议、无结尾斜杠），无效返回空串
     */
    protected function normalizeSiteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $host = '(?:(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}|\d{1,3}(?:\.\d{1,3}){3}|localhost)';

        if (preg_match('#^' . $host . '(?::\d{1,5})?$#i', $url)) {
            return 'https://' . $url;
        }
        if (preg_match('#^(https?)://(' . $host . ')(:\d{1,5})?$#i', $url, $m)) {
            return strtolower($m[1]) . '://' . strtolower($m[2]) . ($m[3] ?? '');
        }

        return '';
    }

    /**
     * 归一化条目：补全为绝对地址、校验取值、按 loc 去重并排序
     *
     * 同一 loc 只保留首个出现的条目（清单靠前者优先，避免后面的粗条目覆盖前面的精细条目）。
     *
     * @param array $items 原始条目
     * @param string $siteUrl 站点域名
     * @return array
     */
    protected function normalize(array $items, string $siteUrl): array
    {
        $map = [];
        foreach ($items as $item) {
            $entry = $this->normalizeItem($item, $siteUrl);
            if ($entry !== null && !isset($map[$entry['loc']])) {
                $map[$entry['loc']] = $entry;
            }
        }
        ksort($map);

        return array_values($map);
    }

    /**
     * 归一化单条条目
     *
     * @param mixed $item 字符串或含 loc/lastmod/changefreq/priority 的数组
     * @param string $siteUrl 站点域名
     * @return array|null 非法条目返回 null
     */
    protected function normalizeItem($item, string $siteUrl): ?array
    {
        if (is_string($item)) {
            $item = ['loc' => $item];
        }
        if (!is_array($item)) {
            return null;
        }

        $loc = trim((string)($item['loc'] ?? ''));
        if ($loc === '') {
            return null;
        }
        $loc = $this->absoluteUrl($loc, $siteUrl);
        if ($loc === '') {
            return null;
        }

        $entry = ['loc' => $loc];

        if (!empty($item['lastmod'])) {
            $ts = strtotime((string)$item['lastmod']);
            if ($ts) {
                $entry['lastmod'] = date('Y-m-d', $ts);
            }
        }

        if (!empty($item['changefreq'])) {
            $freq = strtolower(trim((string)$item['changefreq']));
            if (in_array($freq, self::CHANGEFREQ, true)) {
                $entry['changefreq'] = $freq;
            }
        }

        if (isset($item['priority'])) {
            $priority = (float)$item['priority'];
            $entry['priority'] = number_format(max(0.0, min(1.0, $priority)), 1, '.', '');
        }

        return $entry;
    }

    /**
     * 相对路径补全为绝对地址，并做 XML 转义
     */
    protected function absoluteUrl(string $loc, string $siteUrl): string
    {
        if (!preg_match('#^https?://#i', $loc)) {
            $loc = $siteUrl . '/' . ltrim($loc, '/');
        }

        // 中文等非 ASCII 字符与空格需转义为百分号编码，sitemap 只接受可打印 ASCII
        $loc = str_replace(' ', '%20', $loc);
        $loc = (string)preg_replace_callback('/[^\x20-\x7E]/', function (array $m): string {
            return rawurlencode($m[0]);
        }, $loc);

        return htmlspecialchars($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * 生成 urlset 文档
     */
    protected function toUrlset(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($items as $item) {
            $xml .= "  <url>\n    <loc>{$item['loc']}</loc>\n";
            if (isset($item['lastmod'])) {
                $xml .= "    <lastmod>{$item['lastmod']}</lastmod>\n";
            }
            if (isset($item['changefreq'])) {
                $xml .= "    <changefreq>{$item['changefreq']}</changefreq>\n";
            }
            if (isset($item['priority'])) {
                $xml .= "    <priority>{$item['priority']}</priority>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>' . "\n";

        return $xml;
    }

    /**
     * 生成 sitemapindex 文档
     *
     * @param string[] $files 分片文件名
     * @param string $siteUrl 站点域名
     */
    protected function toIndex(array $files, string $siteUrl): string
    {
        $lastmod = date('Y-m-d');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($files as $file) {
            $loc = $this->absoluteUrl('/' . $file, $siteUrl);
            $xml .= "  <sitemap>\n    <loc>{$loc}</loc>\n    <lastmod>{$lastmod}</lastmod>\n  </sitemap>\n";
        }
        $xml .= '</sitemapindex>' . "\n";

        return $xml;
    }

    /**
     * 写文件（目录不存在则创建）
     *
     * @throws \RuntimeException 目录或文件不可写
     */
    protected function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('目录不可写：' . $dir);
        }
        if (@file_put_contents($path, $content) === false) {
            throw new \RuntimeException('文件写入失败：' . basename($path) . '（请检查 public 目录写权限）');
        }
    }

    /**
     * 更新 robots.txt 的 Sitemap 声明（保留既有规则，仅替换 Sitemap 行）
     *
     * @return bool 是否写入成功
     */
    protected function updateRobots(string $siteUrl, string $indexFile): bool
    {
        $path = public_path() . 'robots.txt';
        $content = is_file($path) ? (string)@file_get_contents($path) : "User-agent: *\nDisallow:\n";

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $lines = array_values(array_filter($lines, function (string $line): bool {
            return stripos(trim($line), 'sitemap:') !== 0;
        }));
        while ($lines && trim((string)end($lines)) === '') {
            array_pop($lines);
        }
        $lines[] = 'Sitemap: ' . $siteUrl . '/' . $indexFile;

        return @file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

    /**
     * 统一结果结构
     */
    protected function result(bool $ok, string $msg, int $total, array $files, array $groups, string $url = '', bool $robots = false): array
    {
        return [
            'ok'     => $ok,
            'msg'    => $msg,
            'total'  => $total,
            'url'    => $url,
            'files'  => $files,
            'groups' => $groups,
            'robots' => $robots,
        ];
    }
}