<?php

namespace app\common\service\contribution;

use app\common\service\aftersale\ModuleGuard;

/**
 * 投稿能力默认实现
 *
 * 所有方法给安全默认值：未迁移投稿能力的应用返回空清单，
 * 未显式声明分佣资格的应用一律不参与分佣，避免新增应用误发资金。
 */
abstract class BaseContributionProvider implements ContributionProviderInterface
{
    public function resources(): array
    {
        return [];
    }

    public function routes(): array
    {
        return [];
    }

    public function menus(array $context): array
    {
        return [];
    }

    /**
     * 双门准入：模块安装态 + 平台「支持的应用」清单。
     *
     * apps_arr 由调用方（creator 侧的投稿应用准入服务）通过 context 注入，
     * 契约层保持不依赖任何业务模块的配置模型。默认配置 apps='' 会 explode 出 ['']，
     * 需过滤空串；未提供 apps_arr 时保守拒绝，避免平台关闭应用后仍放行分账与页面。
     */
    public function enabled(array $context): bool
    {
        if (!ModuleGuard::installed($this->app())) {
            return false;
        }

        // apps 是后台人工填写的逗号串，可能带空格，比较前统一 trim
        $apps = array_map(
            static fn ($name): string => trim((string)$name),
            (array)($context['apps_arr'] ?? [])
        );
        $apps = array_filter($apps, static fn (string $name): bool => $name !== '');

        return in_array($this->app(), $apps, true);
    }

    public function supportsCommission(): bool
    {
        return false;
    }
}
