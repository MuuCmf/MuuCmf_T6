<?php

namespace app\common\controller;

use think\facade\View;
use app\common\model\Author as AuthorModel;
use app\common\logic\Author as AuthorLogic;

/**
 * 创作者投稿控制器基类（框架层）
 *
 * 只依赖 common，不引用 creator 或任何可卸载内容应用的具体类；
 * 各应用投稿控制器继承本类后，creator 无需再编排它们的 model / logic。
 *
 * 定位：应用侧控制器是「资源处理器」，不是独立 Web 入口——
 * 身份校验（CheckAuth / CheckIsAuthor）与平台配置、菜单注入由宿主路由层统一提供，
 * 阶段 2 的 creator 通用控制器负责挂载，本基类不挂 creator 中间件。
 */
class ContributionBase extends Common
{
    /** 当前登录用户绑定的创作者数据 */
    public $author_data;

    protected $AuthorModel;
    protected $AuthorLogic;

    public function __construct()
    {
        parent::__construct();
        $this->AuthorModel = new AuthorModel();
        $this->AuthorLogic = new AuthorLogic();
        $this->loadAuthorContext();
    }

    /**
     * 作者上下文：与原 creator 的 pc/Base::getAuthorData() 口径一致
     * （shopid + uid + status in [0,1]，未登录或非创作者时不注入）
     */
    protected function loadAuthorContext()
    {
        $uid = get_uid();
        if ($uid == 0) {
            return;
        }
        $author_data = $this->AuthorModel->getDataByMap([
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', 'in', [0, 1]]
        ]);
        if (!empty($author_data)) {
            $author_data = $this->AuthorLogic->formatData($author_data);
            $this->author_data = $author_data;
            View::assign('author_data', $author_data);
        }
    }

    /**
     * 宿主注入的投稿上下文（平台配置 / 工作台菜单等）
     * 默认空，阶段 2-4 由 creator 的通用控制器覆盖，避免本基类反向依赖 CreatorConfig。
     */
    protected function contributionContext(): array
    {
        return [];
    }

    /** 记录列表页地址，供编辑成功后回跳 */
    protected function rememberForward(): void
    {
        cookie('__forward__', (string)request()->server('REQUEST_URI'));
    }

    /** 读取回跳地址 */
    protected function forwardUrl(): string
    {
        return (string)cookie('__forward__');
    }
}
