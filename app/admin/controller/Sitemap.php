<?php

namespace app\admin\controller;

use app\common\service\Sitemap as SitemapService;

/**
 * Sitemap 生成（框架核心页面 + 已安装应用页面）
 */
class Sitemap extends Admin
{
    /**
     * 当前 sitemap 状态（站点域名、文件信息、已安装应用是否提供 URL）
     */
    public function index()
    {
        return $this->success(lang('操作成功'), (new SitemapService())->status());
    }

    /**
     * 手动生成/更新 sitemap（权限：admin/sitemap/generate）
     */
    public function generate()
    {
        $result = (new SitemapService())->publish();
        if (!empty($result['ok'])) {
            return $this->success($result['msg'], $result);
        }
        return $this->error($result['msg'], $result);
    }
}