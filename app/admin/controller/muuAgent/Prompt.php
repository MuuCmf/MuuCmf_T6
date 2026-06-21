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
    public function lists()
    {
        $page     = (int)input('get.page', 1, 'intval');        // 页码
        $pageSize = (int)input('get.page_size', 10, 'intval');  // 每页条数
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
        $variables   = input('post.variables', '', 'text');           // 模板变量（JSON 字符串）
        $isDefault   = (bool)input('post.is_default', false, 'bool'); // 是否默认模板
        $status      = (bool)input('post.status', true, 'bool');      // 状态
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $tags        = (string)input('post.tags', '', 'text');        // 标签（JSON 字符串）
        $metadata    = (string)input('post.metadata', '', 'text');    // 元数据（JSON 字符串）
        $isPublic    = (bool)input('post.is_public', false, 'bool');  // 是否公开
        $createdBy   = (string)input('post.created_by', '', 'text');  // 创建者

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
            $data['variables'] = is_string($variables) ? json_decode($variables, true) : $variables;
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
        if ($isPublic) {
            $data['isPublic'] = $isPublic;
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
        $variables   = input('post.variables', '', 'text');           // 模板变量（JSON 字符串）
        $isDefault   = (bool)input('post.is_default', null, 'bool');  // 是否默认模板
        $status      = (bool)input('post.status', null, 'bool');      // 状态
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $tags        = (string)input('post.tags', '', 'text');        // 标签（JSON 字符串）
        $metadata    = (string)input('post.metadata', '', 'text');    // 元数据（JSON 字符串）
        $isPublic    = (bool)input('post.is_public', null, 'bool');   // 是否公开

        if (empty($code)) {
            return $this->error('模板代码不能为空');
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
            $data['variables'] = is_string($variables) ? json_decode($variables, true) : $variables;
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
        if (isset($isPublic)) {
            $data['isPublic'] = $isPublic;
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
        // 管理端没有专门的分类列表接口,可以通过查询模板列表来获取分类
        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/prompt-template', ['pageSize' => 1000]);
            
            // 从模板列表中提取分类
            $categories = [];
            if (isset($result['data']['list']) && is_array($result['data']['list'])) {
                foreach ($result['data']['list'] as $template) {
                    if (isset($template['category']) && !in_array($template['category'], $categories)) {
                        $categories[] = $template['category'];
                    }
                }
            }
            
            return $this->success('请求成功', ['categories' => $categories]);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}