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
        $page     = (int)input('get.page', 1, 'intval');       // 页码
        $limit    = (int)input('get.limit', 10, 'intval');     // 每页条数
        $keyword  = (string)input('get.keyword', '', 'text');  // 搜索关键词

        $data = [
            'page'    => $page,
            'limit'   => $limit,
            'keyword' => $keyword,
        ];

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/api/agent/list', $data);
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
        $agentId = (string)input('get.agent_id', '', 'text');  // 智能体 ID
        $uid     = (string)input('get.uid', '', 'text');       // 终端用户 ID

        if (empty($agentId)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('GET', '/agent/detail', ['agent_id' => $agentId], $uid);
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
        $description = (string)input('post.description', '', 'text'); // 智能体描述
        $avatar      = (string)input('post.avatar', '', 'text');      // 智能体头像
        $prompt      = (string)input('post.prompt', '', 'text');      // 系统提示词
        $model       = (string)input('post.model', '', 'text');       // 模型标识
        $temperature = (float)input('post.temperature', 0.7, 'float');// 温度参数
        $uid         = (string)input('post.uid', '', 'text');         // 终端用户 ID

        if (empty($name)) {
            return $this->error('智能体名称不能为空');
        }

        $data = array_filter([
            'name'        => $name,
            'description' => $description,
            'avatar'      => $avatar,
            'prompt'      => $prompt,
            'model'       => $model,
            'temperature' => $temperature,
        ], function ($val) {
            return $val !== '' && $val !== null;
        });

        try {
            $result = $this->muuAgent->callApi('POST', '/agent/create', $data, $uid);
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
        $agentId     = (string)input('post.agent_id', '', 'text');    // 智能体 ID
        $name        = (string)input('post.name', '', 'text');        // 智能体名称
        $description = (string)input('post.description', '', 'text'); // 智能体描述
        $avatar      = (string)input('post.avatar', '', 'text');      // 智能体头像
        $prompt      = (string)input('post.prompt', '', 'text');      // 系统提示词
        $model       = (string)input('post.model', '', 'text');       // 模型标识
        $temperature = (float)input('post.temperature', 0, 'float');  // 温度参数
        $uid         = (string)input('post.uid', '', 'text');         // 终端用户 ID

        if (empty($agentId)) {
            return $this->error('智能体 ID 不能为空');
        }

        $data = ['agent_id' => $agentId];

        $optionalFields = ['name', 'description', 'avatar', 'prompt', 'model'];
        foreach ($optionalFields as $field) {
            if (!empty($$field)) {
                $data[$field] = $$field;
            }
        }

        if ($temperature > 0) {
            $data['temperature'] = $temperature;
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/agent/update', $data, $uid);
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
        $agentId = (string)input('post.agent_id', '', 'text');  // 智能体 ID
        $uid     = (string)input('post.uid', '', 'text');       // 终端用户 ID

        if (empty($agentId)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/agent/delete', ['agent_id' => $agentId], $uid);
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
        $agentId = (string)input('post.agent_id', '', 'text');  // 智能体 ID
        $uid     = (string)input('post.uid', '', 'text');       // 终端用户 ID

        if (empty($agentId)) {
            return $this->error('智能体 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/agent/publish', ['agent_id' => $agentId], $uid);
            return $this->success('发布成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}