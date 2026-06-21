<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 知识库管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理知识库
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
        $pageNum  = (int)input('get.page_num', 1, 'intval');    // 页码
        $pageSize = (int)input('get.page_size', 10, 'intval');  // 每页条数
        $keyword  = (string)input('get.keyword', '', 'text');   // 搜索关键词
        $status   = (string)input('get.status', '', 'text');    // 状态筛选

        $data = [
            'pageNum'  => $pageNum,
            'pageSize' => $pageSize,
        ];

        if (!empty($keyword)) {
            $data['keyword'] = $keyword;
        }
        if (!empty($status)) {
            $data['status'] = $status;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/kb', $data);
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

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/kb/' . $kbId);
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
        $kbName           = (string)input('post.kb_name', '', 'text');        // 知识库名称
        $kbCode           = (string)input('post.kb_code', '', 'text');        // 知识库代码
        $embeddingModel   = (string)input('post.embedding_model', '', 'text'); // 嵌入模型
        $chunkSize        = (int)input('post.chunk_size', 500, 'intval');     // 分块大小
        $chunkOverlap     = (int)input('post.chunk_overlap', 50, 'intval');   // 分块重叠
        $similarityThresh = (float)input('post.similarity_thresh', 0.7, 'float'); // 相似度阈值
        $topN             = (int)input('post.top_n', 5, 'intval');            // 返回数量
        $retrievalMethod  = (string)input('post.retrieval_method', 'hybrid', 'text'); // 检索方法
        $description      = (string)input('post.description', '', 'text');    // 知识库描述
        $uid              = (string)input('post.uid', '', 'text');            // 用户 ID

        if (empty($kbName)) {
            return $this->error('知识库名称不能为空');
        }
        if (empty($kbCode)) {
            return $this->error('知识库代码不能为空');
        }

        $data = [
            'kbName'           => $kbName,
            'kbCode'           => $kbCode,
            'embeddingModel'   => $embeddingModel,
            'chunkSize'        => $chunkSize,
            'chunkOverlap'     => $chunkOverlap,
            'similarityThresh' => $similarityThresh,
            'topN'             => $topN,
            'retrievalMethod'  => $retrievalMethod,
        ];

        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (!empty($uid)) {
            $data['uid'] = $uid;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/kb', $data);
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
        $kbId             = (string)input('post.kb_id', '', 'text');         // 知识库 ID
        $kbName           = (string)input('post.kb_name', '', 'text');       // 知识库名称
        $embeddingModel   = (string)input('post.embedding_model', '', 'text'); // 嵌入模型
        $chunkSize        = (int)input('post.chunk_size', 0, 'intval');      // 分块大小
        $chunkOverlap     = (int)input('post.chunk_overlap', 0, 'intval');   // 分块重叠
        $similarityThresh = (float)input('post.similarity_thresh', 0, 'float'); // 相似度阈值
        $topN             = (int)input('post.top_n', 0, 'intval');           // 返回数量
        $retrievalMethod  = (string)input('post.retrieval_method', '', 'text'); // 检索方法
        $description      = (string)input('post.description', '', 'text');   // 知识库描述
        $status           = (bool)input('post.status', null, 'bool');        // 状态
        $uid              = (string)input('post.uid', '', 'text');           // 用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        $data = ['kbId' => $kbId];

        if (!empty($kbName)) {
            $data['kbName'] = $kbName;
        }
        if (!empty($embeddingModel)) {
            $data['embeddingModel'] = $embeddingModel;
        }
        if ($chunkSize > 0) {
            $data['chunkSize'] = $chunkSize;
        }
        if ($chunkOverlap > 0) {
            $data['chunkOverlap'] = $chunkOverlap;
        }
        if ($similarityThresh > 0) {
            $data['similarityThresh'] = $similarityThresh;
        }
        if ($topN > 0) {
            $data['topN'] = $topN;
        }
        if (!empty($retrievalMethod)) {
            $data['retrievalMethod'] = $retrievalMethod;
        }
        if (!empty($description)) {
            $data['description'] = $description;
        }
        if (isset($status)) {
            $data['status'] = $status;
        }
        if (!empty($uid)) {
            $data['uid'] = $uid;
        }

        try {
            $result = $this->muuAgent->callAdmin('PUT', '/admin/kb', $data);
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
        $uid  = (string)input('post.uid', '', 'text');    // 用户 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        $data = ['kbId' => $kbId];
        if (!empty($uid)) {
            $data['uid'] = $uid;
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/admin/kb/' . $kbId, $data);
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
        $kbId = (string)input('post.kb_id', '', 'text');    // 知识库 ID
        $uid  = (string)input('post.uid', '', 'text');      // 用户 ID
        $file = request()->file('file');                     // 上传文件

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }
        if (empty($uid)) {
            return $this->error('用户 ID 不能为空');
        }
        if (empty($file)) {
            return $this->error('请选择要上传的文件');
        }

        // 将文件转为 Base64
        $fileContent = file_get_contents($file->getPathname());
        $fileBase64  = base64_encode($fileContent);
        $fileName    = $file->getOriginalName();
        $fileExt     = $file->extension();

        $data = [
            'kbId'       => $kbId,
            'uid'        => $uid,
            'fileName'   => $fileName,
            'fileExt'    => $fileExt,
            'fileBase64' => $fileBase64,
            'appCode'    => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/kb/document/upload', $data);
            return $this->success('上传成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 批量上传知识库文档
     *
     * @return mixed 返回批量上传结果
     */
    public function batchUpload()
    {
        $kbId = (string)input('post.kb_id', '', 'text');    // 知识库 ID
        $uid  = (string)input('post.uid', '', 'text');      // 用户 ID
        $files = request()->file('files');                    // 上传文件列表（数组）

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }
        if (empty($uid)) {
            return $this->error('用户 ID 不能为空');
        }
        if (empty($files) || !is_array($files)) {
            return $this->error('请选择要上传的文件');
        }

        $documents = [];
        foreach ($files as $file) {
            // 将文件转为 Base64
            $fileContent = file_get_contents($file->getPathname());
            $fileBase64  = base64_encode($fileContent);
            $fileName    = $file->getOriginalName();
            $fileExt     = $file->extension();

            $documents[] = [
                'fileName'   => $fileName,
                'fileExt'    => $fileExt,
                'fileBase64' => $fileBase64,
            ];
        }

        $data = [
            'kbId'      => $kbId,
            'uid'       => $uid,
            'documents' => $documents,
            'appCode'   => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/kb/document/batch-upload', $data);
            return $this->success('批量上传成功', $result);
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
        $kbId    = (string)input('get.kb_id', '', 'text');     // 知识库 ID
        $pageNum = (int)input('get.page_num', 1, 'intval');    // 页码
        $pageSize = (int)input('get.page_size', 10, 'intval'); // 每页条数
        $docName = (string)input('get.doc_name', '', 'text');  // 文档名称筛选

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }

        $data = [
            'kbId'     => $kbId,
            'pageNum'  => $pageNum,
            'pageSize' => $pageSize,
        ];

        if (!empty($docName)) {
            $data['docName'] = $docName;
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/kb/document/list', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 删除知识库文档
     *
     * @return mixed 返回删除结果
     */
    public function deleteDocument()
    {
        $kbId = (string)input('post.kb_id', '', 'text');  // 知识库 ID
        $uid  = (string)input('post.uid', '', 'text');    // 用户 ID
        $docId = (string)input('post.doc_id', '', 'text'); // 文档 ID

        if (empty($kbId)) {
            return $this->error('知识库 ID 不能为空');
        }
        if (empty($uid)) {
            return $this->error('用户 ID 不能为空');
        }
        if (empty($docId)) {
            return $this->error('文档 ID 不能为空');
        }

        $data = [
            'kbId'  => $kbId,
            'uid'   => $uid,
            'docId' => $docId,
        ];

        try {
            $result = $this->muuAgent->callAdmin('POST', '/admin/kb/document/delete', $data);
            return $this->success('删除成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}