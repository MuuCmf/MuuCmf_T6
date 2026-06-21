<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 智能体管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理员端接口管理智能体
 */
class Agent extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取智能体列表
     *
     * @return mixed 返回智能体列表数据
     */
    public function lists()
    {
        $page     = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize = (int)input('get.page_size', 10, 'intval');     // 每页条数
        $keyword  = (string)input('get.keyword', '', 'text');      // 搜索关键词
        $status   = (string)input('get.status', '', 'text');       // 状态筛选
        $code     = (string)input('get.code', '', 'text');         // 智能体代码筛选

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
            'appCode'  => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        if (!empty($keyword)) {
            $data['keyword'] = $keyword;
        }
        if (!empty($status)) {
            $data['status'] = $status === 'true' || $status === '1';
        }
        if (!empty($code)) {
            $data['code'] = $code;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/agent', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取智能体详情
     *
     * @return mixed 返回智能体详情数据
     */
    public function detail()
    {
        $id = (string)input('get.id', '', 'text');  // 智能体 ID

        if (empty($id)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/agent/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建智能体
     *
     * @return mixed 返回创建结果
     */
    public function create()
    {
        $name        = (string)input('post.name', '', 'text');        // 智能体名称
        $code        = (string)input('post.code', '', 'text');        // 智能体代码
        $systemPrompt = (string)input('post.system_prompt', '', 'text'); // 系统提示词
        $description = (string)input('post.description', '', 'text'); // 智能体描述
        $skills      = (string)input('post.skills', '', 'text');      // 技能列表（JSON数组）
        $mcpServers  = (string)input('post.mcp_servers', '', 'text'); // MCP 服务器列表（JSON数组）
        $maxSteps    = (int)input('post.max_steps', 5, 'intval');     // 最大步骤数
        $status      = (bool)input('post.status', true, 'bool');      // 状态
        $sort        = (int)input('post.sort', 0, 'intval');          // 排序
        $modelTemplateCode = (string)input('post.model_template_code', '', 'text'); // 模型模板代码
        $customModelParams = (string)input('post.custom_model_params', '', 'text'); // 自定义模型参数（JSON）
        $reasoningMode = (string)input('post.reasoning_mode', 'NONE', 'text'); // 推理模式
        $reasoningPrompt = (string)input('post.reasoning_prompt', '', 'text'); // 推理提示词
        $knowledgeBases = (string)input('post.knowledge_bases', '', 'text'); // 知识库列表（JSON数组）
        $kbRetrievalConfig = (string)input('post.kb_retrieval_config', '', 'text'); // 知识库检索配置（JSON）
        $allowedBuiltinTools = (string)input('post.allowed_builtin_tools', '', 'text'); // 允许的内置工具列表（JSON数组）
        $isPublic    = (bool)input('post.is_public', false, 'bool');  // 是否公开

        if (empty($name)) {
            return $this->error('智能体名称不能为空');
        }
        if (empty($code)) {
            return $this->error('智能体代码不能为空');
        }
        if (empty($systemPrompt)) {
            return $this->error('系统提示词不能为空');
        }

        $data = [
            'name' => $name,
            'code' => $code,
            'systemPrompt' => $systemPrompt,
            'maxSteps' => $maxSteps,
            'status' => $status,
            'sort' => $sort,
            'appCode' => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        // 可选参数
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($skills)) {
            $data['skills'] = $skills;
        }
        if (!empty($mcpServers)) {
            $data['mcpServers'] = $mcpServers;
        }
        if (!empty($modelTemplateCode)) {
            $data['modelTemplateCode'] = $modelTemplateCode;
        }
        if (!empty($customModelParams)) {
            $data['customModelParams'] = $customModelParams;
        }
        if (!empty($reasoningMode)) {
            $data['reasoningMode'] = $reasoningMode;
        }
        if (!empty($reasoningPrompt)) {
            $data['reasoningPrompt'] = $reasoningPrompt;
        }
        if (!empty($knowledgeBases)) {
            $data['knowledgeBases'] = $knowledgeBases;
        }
        if (!empty($kbRetrievalConfig)) {
            $data['kbRetrievalConfig'] = $kbRetrievalConfig;
        }
        if (!empty($allowedBuiltinTools)) {
            $data['allowedBuiltinTools'] = $allowedBuiltinTools;
        }
        if ($isPublic) {
            $data['isPublic'] = $isPublic;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/agent', $data);
            return $this->success('创建成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑智能体
     *
     * @return mixed 返回编辑结果
     */
    public function edit()
    {
        $id           = (string)input('post.id', '', 'text');         // 智能体 ID
        $name         = (string)input('post.name', '', 'text');       // 智能体名称
        $code         = (string)input('post.code', '', 'text');       // 智能体代码
        $description  = (string)input('post.description', '', 'text'); // 智能体描述
        $systemPrompt = (string)input('post.system_prompt', '', 'text'); // 系统提示词
        $skills       = (string)input('post.skills', '', 'text');     // 技能列表（JSON数组）
        $mcpServers   = (string)input('post.mcp_servers', '', 'text'); // MCP 服务器列表（JSON数组）
        $maxSteps     = (int)input('post.max_steps', 0, 'intval');    // 最大步骤数
        $status       = (bool)input('post.status', null, 'bool');     // 状态
        $sort         = (int)input('post.sort', 0, 'intval');         // 排序
        $modelTemplateCode = (string)input('post.model_template_code', '', 'text'); // 模型模板代码
        $customModelParams = (string)input('post.custom_model_params', '', 'text'); // 自定义模型参数（JSON）
        $reasoningMode = (string)input('post.reasoning_mode', '', 'text'); // 推理模式
        $reasoningPrompt = (string)input('post.reasoning_prompt', '', 'text'); // 推理提示词
        $knowledgeBases = (string)input('post.knowledge_bases', '', 'text'); // 知识库列表（JSON数组）
        $kbRetrievalConfig = (string)input('post.kb_retrieval_config', '', 'text'); // 知识库检索配置（JSON）
        $allowedBuiltinTools = (string)input('post.allowed_builtin_tools', '', 'text'); // 允许的内置工具列表（JSON数组）
        $isPublic     = (bool)input('post.is_public', null, 'bool');  // 是否公开

        if (empty($id)) {
            return $this->error('智能体 ID 不能为空');
        }

        $data = [];

        // 更新参数（可选）
        if (!empty($name)) {
            $data['name'] = $name;
        }
        if (!empty($code)) {
            $data['code'] = $code;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($systemPrompt)) {
            $data['systemPrompt'] = $systemPrompt;
        }
        if (!empty($skills)) {
            $data['skills'] = $skills;
        }
        if (!empty($mcpServers)) {
            $data['mcpServers'] = $mcpServers;
        }
        if ($maxSteps > 0) {
            $data['maxSteps'] = $maxSteps;
        }
        if (isset($status)) {
            $data['status'] = $status;
        }
        if ($sort >= 0) {
            $data['sort'] = $sort;
        }
        if (!empty($modelTemplateCode)) {
            $data['modelTemplateCode'] = $modelTemplateCode;
        }
        if (!empty($customModelParams)) {
            $data['customModelParams'] = $customModelParams;
        }
        if (!empty($reasoningMode)) {
            $data['reasoningMode'] = $reasoningMode;
        }
        if (!empty($reasoningPrompt)) {
            $data['reasoningPrompt'] = $reasoningPrompt;
        }
        if (!empty($knowledgeBases)) {
            $data['knowledgeBases'] = $knowledgeBases;
        }
        if (!empty($kbRetrievalConfig)) {
            $data['kbRetrievalConfig'] = $kbRetrievalConfig;
        }
        if (!empty($allowedBuiltinTools)) {
            $data['allowedBuiltinTools'] = $allowedBuiltinTools;
        }
        if (isset($isPublic)) {
            $data['isPublic'] = $isPublic;
        }

        try {
            $result = $this->muuAgent->callAdmin('PUT', '/admin/agent/' . $id, $data);
            return $this->success('更新成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除智能体
     *
     * @return mixed 返回删除结果
     */
    public function del()
    {
        $id = (string)input('post.id', '', 'text');  // 智能体 ID

        if (empty($id)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/agent/' . $id);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 发布/上线智能体
     *
     * @return mixed 返回发布结果
     */
    public function publish()
    {
        $id = (string)input('post.id', '', 'text');  // 智能体 ID

        if (empty($id)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            // 管理端通过更新状态来发布智能体
            $result = $this->muuAgent->callAdmin('PUT', '/admin/agent/' . $id, ['status' => true]);
            return $this->success('发布成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取工具缓存统计信息
     *
     * @return mixed 返回缓存统计数据
     */
    public function cacheStats()
    {
        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/agent/cache/stats');
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取工具缓存配置
     *
     * @return mixed 返回缓存配置数据
     */
    public function cacheConfig()
    {
        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/agent/cache/config');
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 清空工具缓存
     *
     * @return mixed 返回清空结果
     */
    public function cacheClear()
    {
        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/agent/cache');
            return $this->success('缓存已清空', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 手动清理过期缓存
     *
     * @return mixed 返回清理结果
     */
    public function cacheCleanup()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/agent/cache/cleanup');
            return $this->success('清理成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取全局缓存概览
     *
     * @return mixed 返回缓存概览数据
     */
    public function cacheOverview()
    {
        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/agent/cache/overview');
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}