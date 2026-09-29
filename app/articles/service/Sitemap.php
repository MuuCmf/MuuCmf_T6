<?php

declare(strict_types=1);

namespace app\articles\service;

use think\facade\Db;

/**
 * 文章系统 Sitemap URL 提供者
 *
 * 约定：已安装应用在 app/{模块}/service/Sitemap.php 中提供 urls(): array，
 * 由 app\common\service\Sitemap 按 muucmf_module.is_setup=1 自动发现并汇总，
 * 无需注册；未提供该类的应用不贡献任何 URL。
 *
 * 条目只需给相对路径（以 / 开头），站点域名由核心服务统一补全；
 * URL 形式与 PC 端路由（app/articles/route/pc.php）及 service\Link::linkToUrl()
 * 保持一致（url_html_suffix = html）。
 * 仅收录已发布文章（status=1）与已启用分类（status=1），未审核/禁用内容不进入 sitemap。
 *
 * @package app\articles\service
 */
class Sitemap
{
    /**
     * 本应用可索引的公开页面
     *
     * @return array 条目列表（loc / lastmod / changefreq / priority）
     */
    public function urls(): array
    {
        $list = [
            ['loc' => '/articles/lists.html', 'changefreq' => 'daily', 'priority' => 0.8],
        ];

        // 分类落地页（文章列表按分类筛选，PC 端列表页支持 category_id 参数）
        $categories = Db::name('articles_category')
            ->where('status', 1)
            ->field('id,update_time')
            ->order('id asc')
            ->select()
            ->toArray();

        foreach ($categories as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $item = [
                'loc'        => '/articles/lists.html?category_id=' . $id,
                'changefreq' => 'weekly',
                'priority'   => 0.6,
            ];
            if (!empty($row['update_time'])) {
                $item['lastmod'] = date('Y-m-d', (int)$row['update_time']);
            }
            $list[] = $item;
        }

        // 文章详情页
        $articles = Db::name('articles_articles')
            ->where('status', 1)
            ->field('id,update_time')
            ->order('id desc')
            ->select()
            ->toArray();

        foreach ($articles as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $item = [
                'loc'        => '/articles/detail.html?id=' . $id,
                'changefreq' => 'weekly',
                'priority'   => 0.7,
            ];
            if (!empty($row['update_time'])) {
                $item['lastmod'] = date('Y-m-d', (int)$row['update_time']);
            }
            $list[] = $item;
        }

        return $list;
    }
}