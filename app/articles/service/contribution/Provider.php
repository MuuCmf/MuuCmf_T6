<?php

namespace app\articles\service\contribution;

use app\common\service\contribution\BaseContributionProvider;

/**
 * 文章投稿能力声明
 *
 * 工作台壳页路由与菜单由本 Provider 声明，creator 侧只保留通用注册循环。
 * 双门准入沿用基类：模块已安装 + 平台「支持的应用」清单里包含 articles。
 */
class Provider extends BaseContributionProvider
{
    public function app(): string
    {
        return 'articles';
    }

    public function supportsCommission(): bool
    {
        return false;
    }

    /** 需要回退到 SPA 壳页的工作台 URL */
    public function routes(): array
    {
        return [
            ['path' => '/user_admin/articles/lists', 'spa' => true],
            ['path' => '/user_admin/articles/edit', 'spa' => true],
        ];
    }

    public function menus(array $context): array
    {
        if (!$this->enabled($context)) {
            return [];
        }
        $apps = (array)($context['apps_arr'] ?? []);
        if (!in_array('articles', $apps, true)) {
            return [];
        }

        return [
            'articles' => [
                'app' => 'articles',
                'title' => '文章管理',
                'list' => [
                    ['key' => 'article', 'title' => '文章列表', 'url' => '/user_admin/articles/lists'],
                ],
            ],
        ];
    }
}
