<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 会话管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理会话
 */
class Conversation extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取会话列表
     *
     * @return mixed 返回会话列表数据
     */
    public function lists()
    {
        $page             = (int)input('get.page', 1, 'intval');           // 页码
        $pageSize         = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $conversationType = (string)input('get.conversation_type', '', 'text'); // 会话类型筛选
        $targetId         = (string)input('get.target_id', '', 'text');    // 目标 ID 筛选
        $uid              = (string)input('get.uid', '', 'text');          // 用户 ID 筛选
        $status           = (string)input('get.status', '', 'text');       // 状态筛选
        $keyword          = (string)input('get.keyword', '', 'text');      // 搜索关键词

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
        ];

        if (!empty($conversationType)) {
            $data['conversationType'] = $conversationType;
        }
        if (!empty($targetId)) {
            $data['targetId'] = $targetId;
        }
        if (!empty($uid)) {
            $data['uid'] = $uid;
        }
        if (!empty($status)) {
            $data['status'] = $status;
        }
        if (!empty($keyword)) {
            $data['keyword'] = $keyword;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/conversation', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取会话详情
     *
     * @return mixed 返回会话详情数据
     */
    public function detail()
    {
        $id            = (string)input('get.id', '', 'text');  // 会话 ID
        $messageLimit  = (int)input('get.message_limit', 50, 'intval'); // 消息数量限制

        if (empty($id)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $data = [];
            if ($messageLimit > 0) {
                $data['messageLimit'] = $messageLimit;
            }

            $result = $this->muuAgent->callAdmin('GET', '/admin/conversation/' . $id, $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建会话
     *
     * @return mixed 返回创建结果
     */
    public function create()
    {
        $conversationType = (string)input('post.conversation_type', 'agent', 'text'); // 会话类型
        $targetId         = (string)input('post.target_id', '', 'text');             // 目标 ID（智能体ID/模型标识/知识库ID）
        $title            = (string)input('post.title', '', 'text');                 // 会话标题
        $uid              = (string)input('post.uid', '', 'text');                   // 用户唯一标识

        if (empty($targetId)) {
            return $this->error('目标 ID 不能为空');
        }

        $data = [
            'conversationType' => $conversationType,
            'targetId'         => $targetId,
        ];

        if (!empty($title)) {
            $data['title'] = $title;
        }
        if (!empty($uid)) {
            $data['uid'] = $uid;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/conversation', $data);
            return $this->success('创建成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 更新会话
     *
     * @return mixed 返回更新结果
     */
    public function edit()
    {
        $id     = (string)input('post.id', '', 'text');     // 会话 ID
        $title  = (string)input('post.title', '', 'text');  // 会话标题
        $status = (string)input('post.status', '', 'text'); // 会话状态（active/archived/deleted）

        if (empty($id)) {
            return $this->error('会话 ID 不能为空');
        }

        $data = [];

        if (!empty($title)) {
            $data['title'] = $title;
        }
        if (!empty($status)) {
            $data['status'] = $status;
        }

        try {
            $result = $this->muuAgent->callAdmin('PUT', '/admin/conversation/' . $id, $data);
            return $this->success('更新成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除会话
     *
     * @return mixed 返回删除结果
     */
    public function del()
    {
        $id = (string)input('post.id', '', 'text');  // 会话 ID

        if (empty($id)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/conversation/' . $id);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取会话消息列表
     *
     * @return mixed 返回消息列表数据
     */
    public function messages()
    {
        $id    = (string)input('get.id', '', 'text');   // 会话 ID
        $limit = (int)input('get.limit', 50, 'intval'); // 消息数量限制

        if (empty($id)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $data = [];
            if ($limit > 0) {
                $data['limit'] = $limit;
            }

            $result = $this->muuAgent->callAdmin('GET', '/admin/conversation/' . $id . '/messages', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 生成会话标题
     *
     * @return mixed 返回生成结果
     */
    public function generateTitle()
    {
        $id = (string)input('post.id', '', 'text');  // 会话 ID

        if (empty($id)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/conversation/' . $id . '/generate-title');
            return $this->success('生成标题成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}