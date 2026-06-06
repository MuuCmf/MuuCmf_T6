<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 提示词模板管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台业务端接口（API Key + 透传 UID）管理提示词模板
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
        $page    = (int)input('get.page', 1, 'intval');        // 页码
        $limit   = (int)input('get.limit', 10, 'intval');      // 每页条数
        $keyword = (string)input('get.keyword', '', 'text');   // 搜索关键词
        $uid     = (string)input('get.uid', '', 'text');       // 终端用户 ID

        $data = [
            'page'    => $page,
            'limit'   => $limit,
            'keyword' => $keyword,
        ];

        try {
            $result = $this->muuAgent->callApi('GET', '/prompt/list', $data, $uid);
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
        $promptId = (string)input('get.prompt_id', '', 'text');  // 提示词模板 ID
        $uid      = (string)input('get.uid', '', 'text');        // 终端用户 ID

        if (empty($promptId)) {
            return $this->error('提示词模板 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('GET', '/prompt/detail', ['prompt_id' => $promptId], $uid);
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
        $content     = (string)input('post.content', '', 'text');     // 模板内容（提示词文本）
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $category    = (string)input('post.category', '', 'text');    // 模板分类
        $variables   = input('post.variables', '', 'text');           // 模板变量（JSON 字符串）
        $uid         = (string)input('post.uid', '', 'text');         // 终端用户 ID

        if (empty($name)) {
            return $this->error('模板名称不能为空');
        }
        if (empty($content)) {
            return $this->error('模板内容不能为空');
        }

        $data = [
            'name'    => $name,
            'content' => $content,
        ];

        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($category)) {
            $data['category'] = $category;
        }
        if (!empty($variables)) {
            $data['variables'] = is_string($variables) ? json_decode($variables, true) : $variables;
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/prompt/create', $data, $uid);
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
        $promptId    = (string)input('post.prompt_id', '', 'text');   // 提示词模板 ID
        $name        = (string)input('post.name', '', 'text');        // 模板名称
        $content     = (string)input('post.content', '', 'text');     // 模板内容
        $description = (string)input('post.description', '', 'text'); // 模板描述
        $category    = (string)input('post.category', '', 'text');    // 模板分类
        $variables   = input('post.variables', '', 'text');           // 模板变量（JSON 字符串）
        $uid         = (string)input('post.uid', '', 'text');         // 终端用户 ID

        if (empty($promptId)) {
            return $this->error('提示词模板 ID 不能为空');
        }

        $data = ['prompt_id' => $promptId];

        if (!empty($name)) {
            $data['name'] = $name;
        }
        if (!empty($content)) {
            $data['content'] = $content;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($category)) {
            $data['category'] = $category;
        }
        if (!empty($variables)) {
            $data['variables'] = is_string($variables) ? json_decode($variables, true) : $variables;
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/prompt/update', $data, $uid);
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
        $promptId = (string)input('post.prompt_id', '', 'text');  // 提示词模板 ID
        $uid      = (string)input('post.uid', '', 'text');        // 终端用户 ID

        if (empty($promptId)) {
            return $this->error('提示词模板 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/prompt/delete', ['prompt_id' => $promptId], $uid);
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
        $uid = (string)input('get.uid', '', 'text');  // 终端用户 ID

        try {
            $result = $this->muuAgent->callApi('GET', '/prompt/categories', [], $uid);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}