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
     * 阶段 0 只判定模块安装态；平台 apps_arr 判定在阶段 2-3
     * 迁移投稿控制器后接入（默认配置 apps='' 会产生 ['']，不可直接 in_array）。
     */
    public function enabled(array $context): bool
    {
        return ModuleGuard::installed($this->app());
    }

    public function supportsCommission(): bool
    {
        return false;
    }
}
