<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 知识库管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台业务端接口（API Key + 透传 UID）管理知识库
 */
class Kb extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取知识库列表
     *
     * @return mixed 返回知识库列表数据
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
            $result = $this->muuAgent->callApi('GET', '/kb/list', $data, $uid);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取知识库详情
     *
     * @return mixed 返回知识库详情数据
     */
    public function detail()
    {
        $kbId = (string)input('get.kb_id', '', 'text');  // 知识库 ID
        $uid  = (string)input('get.uid', '', 'text');    // 终端用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('GET', '/kb/detail', ['kb_id' => $kbId], $uid);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 创建知识库
     *
     * @return mixed 返回创建结果
     */
    public function create()
    {
        $name        = (string)input('post.name', '', 'text');        // 知识库名称
        $description = (string)input('post.description', '', 'text'); // 知识库描述
        $uid         = (string)input('post.uid', '', 'text');         // 终端用户 ID

        if (empty($name)) {
            return $this->error('知识库名称不能为空');
        }

        $data = array_filter([
            'name'        => $name,
            'description' => $description,
        ], function ($val) {
            return $val !== '' && $val !== null;
        });

        try {
            $result = $this->muuAgent->callApi('POST', '/kb/create', $data, $uid);
            return $this->success('创建成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 编辑知识库
     *
     * @return mixed 返回编辑结果
     */
    public function edit()
    {
        $kbId        = (string)input('post.kb_id', '', 'text');         // 知识库 ID
        $name        = (string)input('post.name', '', 'text');          // 知识库名称
        $description = (string)input('post.description', '', 'text');   // 知识库描述
        $uid         = (string)input('post.uid', '', 'text');           // 终端用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        $data = ['kb_id' => $kbId];

        if (!empty($name)) {
            $data['name'] = $name;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/kb/update', $data, $uid);
            return $this->success('更新成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除知识库
     *
     * @return mixed 返回删除结果
     */
    public function del()
    {
        $kbId = (string)input('post.kb_id', '', 'text');  // 知识库 ID
        $uid  = (string)input('post.uid', '', 'text');    // 终端用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callApi('POST', '/kb/delete', ['kb_id' => $kbId], $uid);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 上传知识库文档
     *
     * @return mixed 返回上传结果
     */
    public function upload()
    {
        $kbId      = (string)input('post.kb_id', '', 'text');        // 知识库 ID
        $file      = request()->file('file');                         // 上传文件
        $uid       = (string)input('post.uid', '', 'text');          // 终端用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }
        if (empty($file)) {
            return $this->error('请选择要上传的文件');
        }

        // 将文件转为 Base64 或直接通过 cURL 上传
        $fileContent = file_get_contents($file->getPathname());
        $fileBase64  = base64_encode($fileContent);
        $fileName    = $file->getOriginalName();
        $fileExt     = $file->extension();

        $data = [
            'kb_id'      => $kbId,
            'file_name'  => $fileName,
            'file_ext'   => $fileExt,
            'file_base64' => $fileBase64,
        ];

        try {
            $result = $this->muuAgent->callApi('POST', '/kb/document/upload', $data, $uid);
            return $this->success('上传成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取知识库文档列表
     *
     * @return mixed 返回文档列表数据
     */
    public function documents()
    {
        $kbId  = (string)input('get.kb_id', '', 'text');     // 知识库 ID
        $page  = (int)input('get.page', 1, 'intval');        // 页码
        $limit = (int)input('get.limit', 10, 'intval');      // 每页条数
        $uid   = (string)input('get.uid', '', 'text');       // 终端用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        $data = [
            'kb_id' => $kbId,
            'page'  => $page,
            'limit' => $limit,
        ];

        try {
            $result = $this->muuAgent->callApi('GET', '/kb/document/list', $data, $uid);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}