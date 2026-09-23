<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 对话管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台业务端接口（API Key + 透传 UID）管理对话
 *
 * 2026-09-23 修复：参数与中台 DTO 对齐（snake→camel），路由与中台实际注册对齐：
 *  - send():        POST /agent/chat              { agentId, message, conversationId }
 *  - conversation(): GET  /conversation            { page, pageSize }
 *  - history():      GET  /conversation/{id}/messages { limit }
 *  - del():          DELETE /conversation/{id}
 */
class Chat extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 发送对话消息
     *
     * @return mixed 返回 AI 回复结果
     */
    public function send()
    {
        $agentId        = (string)input('post.agentId', '', 'text');        // 智能体 ID
        $conversationId = (string)input('post.conversationId', '', 'text'); // 会话 ID（可选，为空则新建会话）
        $message        = (string)input('post.message', '', 'text');        // 用户消息内容
        $uid            = (string)input('post.uid', '', 'text');            // 终端用户 ID

        if (empty($agentId)) {
            return $this->error('智能体 ID 不能为空');
        }
        if (empty($message)) {
            return $this->error('消息内容不能为空');
        }

        $data = [
            'agentId' => $agentId,
            'message' => $message,
        ];
        if (!empty($conversationId)) {
            $data['conversationId'] = $conversationId;
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/agent/chat', $data, $uid);
            return $this->success('发送成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取会话列表
     *
     * @return mixed 返回会话列表数据
     */
    public function conversation()
    {
        $page     = (int)input('get.page', 1, 'intval');      // 页码
        $pageSize = (int)input('get.pageSize', 10, 'intval'); // 每页条数（中台 QueryConversationDto 字段）
        $uid      = (string)input('get.uid', '', 'text');     // 终端用户 ID

        try {
            $result = $this->muuAgent->callApi('GET', '/conversation', [
                'page'     => $page,
                'pageSize' => $pageSize,
            ], $uid);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取对话历史记录
     *
     * @return mixed 返回对话历史数据
     */
    public function history()
    {
        $conversationId = (string)input('get.conversationId', '', 'text'); // 会话 ID
        $limit          = (int)input('get.limit', 50, 'intval');           // 消息条数
        $uid            = (string)input('get.uid', '', 'text');            // 终端用户 ID

        if (empty($conversationId)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi(
                'GET',
                '/conversation/' . rawurlencode($conversationId) . '/messages',
                ['limit' => $limit],
                $uid
            );
            return $this->success('请求成功', $result);
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
        $conversationId = (string)input('post.conversationId', '', 'text'); // 会话 ID
        $uid            = (string)input('post.uid', '', 'text');            // 终端用户 ID

        if (empty($conversationId)) {
            return $this->error('会话 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi(
                'DELETE',
                '/conversation/' . rawurlencode($conversationId),
                [],
                $uid
            );
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
