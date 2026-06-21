<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 日志管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理日志
 */
class Log extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取AI调用日志列表
     *
     * @return mixed 返回AI调用日志列表数据
     */
    public function ai()
    {
        $page      = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize  = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $modelId   = (string)input('get.model_id', '', 'text');     // 模型 ID 筛选
        $modelCode = (string)input('get.model_code', '', 'text');   // 模型代码筛选
        $modelType = (string)input('get.model_type', '', 'text');   // 模型类型筛选
        $success   = (string)input('get.success', '', 'text');      // 成功状态筛选
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($modelId)) {
            $data['modelId'] = $modelId;
        }
        if (!empty($modelCode)) {
            $data['modelCode'] = $modelCode;
        }
        if (!empty($modelType)) {
            $data['modelType'] = $modelType;
        }
        if (!empty($success)) {
            $data['success'] = $success === 'true' || $success === '1';
        }
        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/ai', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取单个AI调用日志详情
     *
     * @return mixed 返回AI调用日志详情数据
     */
    public function aiDetail()
    {
        $id = (string)input('get.id', '', 'text');  // 日志 ID

        if (empty($id)) {
            return $this->error('日志 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/ai/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取技能调用日志列表
     *
     * @return mixed 返回技能调用日志列表数据
     */
    public function skill()
    {
        $page      = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize  = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $skillId   = (string)input('get.skill_id', '', 'text');     // 技能 ID 筛选
        $skillCode = (string)input('get.skill_code', '', 'text');   // 技能代码筛选
        $success   = (string)input('get.success', '', 'text');      // 成功状态筛选
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($skillId)) {
            $data['skillId'] = $skillId;
        }
        if (!empty($skillCode)) {
            $data['skillCode'] = $skillCode;
        }
        if (!empty($success)) {
            $data['success'] = $success === 'true' || $success === '1';
        }
        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/skill', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取单个技能调用日志详情
     *
     * @return mixed 返回技能调用日志详情数据
     */
    public function skillDetail()
    {
        $id = (string)input('get.id', '', 'text');  // 日志 ID

        if (empty($id)) {
            return $this->error('日志 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/skill/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取Agent调用日志列表
     *
     * @return mixed 返回Agent调用日志列表数据
     */
    public function agent()
    {
        $page      = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize  = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $agentId   = (string)input('get.agent_id', '', 'text');     // 智能体 ID 筛选
        $agentCode = (string)input('get.agent_code', '', 'text');   // 智能体代码筛选
        $success   = (string)input('get.success', '', 'text');      // 成功状态筛选
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($agentId)) {
            $data['agentId'] = $agentId;
        }
        if (!empty($agentCode)) {
            $data['agentCode'] = $agentCode;
        }
        if (!empty($success)) {
            $data['success'] = $success === 'true' || $success === '1';
        }
        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/agent', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取单个Agent调用日志详情
     *
     * @return mixed 返回Agent调用日志详情数据
     */
    public function agentDetail()
    {
        $id = (string)input('get.id', '', 'text');  // 日志 ID

        if (empty($id)) {
            return $this->error('日志 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/agent/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取Agent调用日志的推理步骤
     *
     * @return mixed 返回推理步骤数据
     */
    public function agentReasoning()
    {
        $id = (string)input('get.id', '', 'text');  // 日志 ID

        if (empty($id)) {
            return $this->error('日志 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/agent/' . $id . '/reasoning');
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取知识库检索日志列表
     *
     * @return mixed 返回知识库检索日志列表数据
     */
    public function retrieval()
    {
        $page      = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize  = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $kbId      = (string)input('get.kb_id', '', 'text');        // 知识库 ID 筛选
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($kbId)) {
            $data['kbId'] = $kbId;
        }
        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/retrieval', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取单个知识库检索日志详情
     *
     * @return mixed 返回知识库检索日志详情数据
     */
    public function retrievalDetail()
    {
        $id = (string)input('get.id', '', 'text');  // 日志 ID

        if (empty($id)) {
            return $this->error('日志 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/retrieval/' . $id);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取检索统计
     *
     * @return mixed 返回检索统计数据
     */
    public function retrievalStatistics()
    {
        $kbId      = (string)input('get.kb_id', '', 'text');        // 知识库 ID（可选）
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [];

        if (!empty($kbId)) {
            $data['kbId'] = $kbId;
        }
        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/retrieval/statistics', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取调用统计
     *
     * @return mixed 返回调用统计数据
     */
    public function statistics()
    {
        $startTime = (string)input('get.start_time', '', 'text');   // 开始时间
        $endTime   = (string)input('get.end_time', '', 'text');     // 结束时间

        $data = [];

        if (!empty($startTime)) {
            $data['startTime'] = $startTime;
        }
        if (!empty($endTime)) {
            $data['endTime'] = $endTime;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/log/statistics', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}