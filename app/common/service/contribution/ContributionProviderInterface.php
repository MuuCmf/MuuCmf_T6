<?php

namespace app\common\service\contribution;

/**
 * 创作者投稿能力契约
 *
 * 由框架定义、内容应用实现，保证依赖方向为「模块 → 框架」：
 * creator 与各内容应用都只依赖本契约，彼此不得互相引用具体类。
 * 约定实现类名：app\{app}\service\contribution\Provider
 *
 * 阶段 0 只用 supportsCommission() 判定分佣资格；
 * resources()/routes()/menus() 在阶段 2-3 迁移投稿控制器时由各应用逐步填充，
 * 未迁移的应用通过 BaseContributionProvider 返回空实现，不影响现有链路。
 */
interface ContributionProviderInterface
{
    /** 应用标识，与 muucmf_module.name / orders.app 一致 */
    public function app(): string;

    /** 工作台投稿资源清单（阶段 2-3 填充） */
    public function resources(): array;

    /** 工作台路由声明（阶段 2-3 填充，保留现有 URL 形状） */
    public function routes(): array;

    /** 工作台菜单片段（阶段 4 填充，替代 CreatorConfig::getMenus() 的硬编码分支） */
    public function menus(array $context): array;

    /** 应用级启用判定；$context 预留给平台 apps_arr 等运行时配置 */
    public function enabled(array $context): bool;

    /** 该应用的订单是否参与创作者分佣，未实现即不参与（白名单语义） */
    public function supportsCommission(): bool;
}
