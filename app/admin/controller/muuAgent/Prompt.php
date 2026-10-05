<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 提示词模板管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理提示词模板
 */
class Prompt extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取提示词模板列表
     *
     * @return mixed 返回模板列表数据
     */
    public function list()
    {
        $page     = (int)input('get.page', 1, 'intval');        // 页码
        $pageSize = (int)input('get.page_size', 10, 'intval');  // 每页条数
        // 钳制到中台 DTO 允许范围（QueryPromptTemplateDto 校验 pageSize 最大 100）
        $pageSize = max(1, min($pageSize, 100));
        $category = (string)input('get.category', '', 'text');  // 分类筛选
        $status   = (string)input('get.status', '', 'text');    // 状态筛选
        $keyword  = (string)input('get.keyword', '', 'text');   // 搜索关键词

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($category)) {
            $data['category'] = $category;
        }
        if (!empty($status)) {
            $data['status'] = $status;
        }
        if (!empty($keyword)) {
            $data['keyword'] = $keyword;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/prompt-template', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取提示词模板详情
     *
     * @return mixed 返回模板详情数据
     */
    public function detail()
    {
        $id = (string)input('get.id', '', 'text');  // 提示词模板 ID

        if (empty($id)) {
            return $this->error('提示词模板 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建提示词模板
     *
     * @return mixed 返回创建结果
     */
    public function create()
    {
        $name        = (string)input('post.name', '', 'text');        // 模板名称
        $code        = (string)input('post.code', '', 'text');        // 模板代码
        $category    = (string)input('post.category', '', 'text');    // 模板分类
        $content     = (string)input('post.content', '', 'text');     // 模板内容（提示词文本）
        $variables   = request()->post('variables');                  // 模板变量（JSON 字符串/数组，原始读取避免 text 过滤转义引号）
        $isDefault   = $this->parseBool(input('post.isDefault'), false); // 是否默认模板
        $status      = $this->parseBool(input('post.status'), true); // 状态
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $tags        = (string)input('post.tags', '', 'text');        // 标签（JSON 字符串）
        $metadata    = (string)input('post.metadata', '', 'text');    // 元数据（JSON 字符串）
        $createdBy   = (string)input('post.createdBy', '', 'text');  // 创建者

        if (empty($name)) {
            return $this->error('模板名称不能为空');
        }
        if (empty($code)) {
            return $this->error('模板代码不能为空');
        }
        if (empty($category)) {
            return $this->error('模板分类不能为空');
        }
        if (empty($content)) {
            return $this->error('模板内容不能为空');
        }

        $data = [
            'name'     => $name,
            'code'     => $code,
            'category' => $category,
            'content'  => $content,
            'appCode'  => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        if (!empty($variables)) {
            $parsed = is_string($variables) ? json_decode($variables, true) : $variables;
            if (is_array($parsed)) {
                $data['variables'] = $parsed;
            }
        }
        if ($isDefault) {
            $data['isDefault'] = $isDefault;
        }
        if (isset($status)) {
            $data['status'] = $status;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($tags)) {
            $data['tags'] = is_string($tags) ? json_decode($tags, true) : $tags;
        }
        if (!empty($metadata)) {
            $data['metadata'] = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        }
        if (!empty($createdBy)) {
            $data['createdBy'] = $createdBy;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/prompt-template', $data);
            return $this->success('创建成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑提示词模板
     *
     * @return mixed 返回编辑结果
     */
    public function edit()
    {
        $code        = (string)input('post.code', '', 'text');        // 模板代码
        $name        = (string)input('post.name', '', 'text');        // 模板名称
        $category    = (string)input('post.category', '', 'text');    // 模板分类
        $content     = (string)input('post.content', '', 'text');     // 模板内容
        $variables   = request()->post('variables');                  // 模板变量（JSON 字符串/数组，原始读取避免 text 过滤转义引号）
        $isDefault   = $this->parseBool(input('post.isDefault')); // 是否默认模板
        $status      = $this->parseBool(input('post.status')); // 状态
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $tags        = (string)input('post.tags', '', 'text');        // 标签（JSON 字符串）
        $metadata    = (string)input('post.metadata', '', 'text');    // 元数据（JSON 字符串）

        if (empty($code)) {
            return $this->error('模板代码不能为空');
        }

        // 公共提示词模板（app_code 为 null）为全局共享资源，禁止通过 php_server 编辑
        try {
            $detail = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/code/' . $code);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        if (empty($detail['appCode'])) {
            return $this->error('公共提示词模板（appCode 为 null）禁止编辑，请在中台管理后台操作');
        }

        $data = [];

        if (!empty($name)) {
            $data['name'] = $name;
        }
        if (!empty($category)) {
            $data['category'] = $category;
        }
        if (!empty($content)) {
            $data['content'] = $content;
        }
        if (!empty($variables)) {
            $parsed = is_string($variables) ? json_decode($variables, true) : $variables;
            if (is_array($parsed)) {
                $data['variables'] = $parsed;
            }
        }
        if (isset($isDefault)) {
            $data['isDefault'] = $isDefault;
        }
        if (isset($status)) {
            $data['status'] = $status;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($tags)) {
            $data['tags'] = is_string($tags) ? json_decode($tags, true) : $tags;
        }
        if (!empty($metadata)) {
            $data['metadata'] = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        }

        try {
            $result = $this->muuAgent->callAdmin('PUT', '/admin/prompt-template/' . $code, $data);
            return $this->success('更新成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除提示词模板
     *
     * @return mixed 返回删除结果
     */
    public function del()
    {
        $id = (string)input('post.id', '', 'text');  // 提示词模板 ID

        if (empty($id)) {
            return $this->error('提示词模板 ID 不能为空');
        }

        // 公共提示词模板（app_code 为 null）禁止删除
        try {
            $detail = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/' . $id);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        if (empty($detail['appCode'])) {
            return $this->error('公共提示词模板（appCode 为 null）禁止删除，请在中台管理后台操作');
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/prompt-template/' . $id);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取提示词模板分类列表
     *
     * @return mixed 返回分类列表数据
     */
    public function categories()
    {
        // 管理端没有专门的分类列表接口，通过分页查询模板列表来聚合分类
        // 注意：中台 QueryPromptTemplateDto 校验 pageSize 最大 100，不能一次拉取过大
        $categories = [];
        $page       = 1;
        $pageSize   = 100; // 中台单页上限
        $maxPages   = 50;  // 安全上限（最多聚合 5000 条，防止极端数据量下死循环）

        try {
            do {
                $result = $this->muuAgent->callAdmin('GET', '/admin/prompt-template', [
                    'page'     => $page,
                    'pageSize' => $pageSize,
                ]);

                // callAdmin 已统一解包 envelope，$result 即业务数据 { list, total, page, pageSize }
                if (isset($result['list']) && is_array($result['list'])) {
                    foreach ($result['list'] as $template) {
                        if (isset($template['category']) && !in_array($template['category'], $categories)) {
                            $categories[] = $template['category'];
                        }
                    }
                }

                $total = isset($result['total']) ? (int)$result['total'] : 0;
                $page++;
            } while ($total > 0 && ($page - 1) * $pageSize < $total && $page <= $maxPages);

            return $this->success('请求成功', ['categories' => $categories]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 渲染提示词模板
     *
     * @return mixed 返回渲染结果
     */
    public function render()
    {
        $code = (string)input('post.code', '', 'text'); // 模板代码

        if (empty($code)) {
            return $this->error('模板代码不能为空');
        }

        // 变量值（JSON 对象）：原始读取避免 text 过滤转义引号破坏 JSON；
        // 中台 RenderPromptTemplateDto.variables 为 @IsObject() 必填对象，空变量必须传 {} 而非 []
        $variables = request()->post('variables');
        $data      = ['code' => $code];

        if (is_string($variables) && !empty($variables)) {
            $decoded = json_decode($variables, true);
            $variables = is_array($decoded) ? $decoded : new \stdClass();
        }

        if ($variables instanceof \stdClass) {
            $data['variables'] = $variables;
        } elseif (is_array($variables)) {
            // 空数组会被 json_encode 序列化为 []，中台 DTO 要求对象，需转为 {}
            $data['variables'] = empty($variables) ? new \stdClass() : $variables;
        } else {
            $data['variables'] = new \stdClass();
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/prompt-template/render', $data);
            return $this->success('渲染成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取模板版本历史
     *
     * @return mixed 返回版本历史数据
     */
    public function versions()
    {
        $id    = (string)input('get.id', '', 'text');   // 模板 ID
        $limit = (int)input('get.limit', 10, 'intval'); // 版本数量限制

        if (empty($id)) {
            return $this->error('模板 ID 不能为空');
        }

        try {
            $data = [];
            if ($limit > 0) {
                $data['limit'] = $limit;
            }

            $result = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/' . $id . '/versions', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 回滚提示词模板到指定版本
     *
     * @return mixed 返回回滚结果
     */
    public function rollback()
    {
        $id        = (string)input('post.id', '', 'text');         // 模板 ID
        $version   = (string)input('post.version', '', 'text');    // 目标版本号
        $changeLog = (string)input('post.changeLog', '', 'text');  // 变更说明
        $createdBy = (string)input('post.createdBy', '', 'text');  // 创建者

        if (empty($id)) {
            return $this->error('模板 ID 不能为空');
        }
        if (empty($version)) {
            return $this->error('版本号不能为空');
        }

        // 公共提示词模板（app_code 为 null）禁止回滚
        try {
            $detail = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/' . $id);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        if (empty($detail['appCode'])) {
            return $this->error('公共提示词模板（appCode 为 null）禁止回滚，请在中台管理后台操作');
        }

        $data = [];
        if (!empty($changeLog)) {
            $data['changeLog'] = $changeLog;
        }
        if (!empty($createdBy)) {
            $data['createdBy'] = $createdBy;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/prompt-template/' . $id . '/rollback/' . $version, $data);
            return $this->success('版本回滚成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 设置默认提示词模板
     *
     * @return mixed 返回设置结果
     */
    public function setDefault()
    {
        $id = (string)input('post.id', '', 'text');  // 模板 ID

        if (empty($id)) {
            return $this->error('模板 ID 不能为空');
        }

        // 公共提示词模板（app_code 为 null）禁止设置默认
        try {
            $detail = $this->muuAgent->callAdmin('GET', '/admin/prompt-template/' . $id);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
        if (empty($detail['appCode'])) {
            return $this->error('公共提示词模板（appCode 为 null）禁止设置默认，请在中台管理后台操作');
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/prompt-template/' . $id . '/set-default');
            return $this->success('设置默认模板成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}