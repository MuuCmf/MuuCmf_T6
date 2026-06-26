<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 技能管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口管理技能
 * 实现三层缓存架构：
 * - L1层：技能元数据列表（Redis缓存，TTL 30分钟）
 * - L2层：完整技能描述符（内存LRU缓存，TTL 5分钟）
 * - L3层：参考文档内容（Redis缓存，TTL 1小时）
 */
class Skill extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 列出所有可用技能（经过L1缓存，支持分页）
     *
     * @return mixed 返回技能列表数据
     */
    public function list()
    {
        $page      = (int)input('get.page', 1, 'intval');           // 页码，从1开始
        $pageSize  = (int)input('get.page_size', 20, 'intval');     // 每页条数
        $sortBy    = (string)input('get.sort_by', 'name', 'text');  // 排序字段
        $sortOrder = (string)input('get.sort_order', 'asc', 'text'); // 排序方向

        $data = [
            'page'      => $page,
            'pageSize'  => $pageSize,
            'sortBy'    => $sortBy,
            'sortOrder' => $sortOrder,
            'appCode'   => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        try {
            $result = $this->muuAgent->callAdmin('GET', '/api/admin/skill/standard/list', $data);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 触发技能扫描并同步到数据库（清除所有缓存）
     *
     * @return mixed 返回扫描结果
     */
    public function scan()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/api/admin/skill/standard/scan');
            return $this->success('扫描成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取单个标准技能详情（经过L2缓存）
     *
     * @return mixed 返回技能详情数据
     */
    public function detail()
    {
        $name = (string)input('get.name', '', 'text');  // 技能名称

        if (empty($name)) {
            return $this->error('技能名称不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/api/admin/skill/standard/' . $name);
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 导入标准技能（.zip 上传）
     *
     * @return mixed 返回导入结果
     */
    public function import()
    {
        $file      = request()->file('file');                        // 上传文件（.zip）
        $isPublic  = (bool)input('post.is_public', false, 'bool');   // 是否公开
        $overwrite = (bool)input('post.overwrite', false, 'bool');   // 是否覆盖

        if (empty($file)) {
            return $this->error('请上传技能文件');
        }

        // 将文件转为 Base64
        $fileContent = file_get_contents($file->getPathname());
        $fileBase64  = base64_encode($fileContent);
        $fileName    = $file->getOriginalName();

        $data = [
            'fileName'   => $fileName,
            'fileBase64' => $fileBase64,
            'appCode'    => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        if ($isPublic) {
            $data['isPublic'] = $isPublic;
        }
        if ($overwrite) {
            $data['overwrite'] = $overwrite;
        }

        try {
            $result = $this->muuAgent->callAdmin('POST', '/api/admin/skill/import', $data);
            return $this->success('导入成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 验证 SKILL.md 内容
     *
     * @return mixed 返回验证结果
     */
    public function validate()
    {
        $content = (string)input('post.content', '', 'text');  // SKILL.md 完整内容

        if (empty($content)) {
            return $this->error('SKILL.md 内容不能为空');
        }

        $data = [
            'content' => $content,
        ];

        try {
            $result = $this->muuAgent->callAdmin('POST', '/api/admin/skill/validate', $data);
            return $this->success('验证成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 刷新技能索引（扫描 + 同步 + 清除缓存）
     *
     * @return mixed 返回刷新结果
     */
    public function refresh()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/api/admin/skill/refresh');
            return $this->success('索引已刷新，数据库已同步，缓存已清除', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 清除指定技能的缓存
     *
     * @return mixed 返回清除结果
     */
    public function clearCache()
    {
        $name = (string)input('post.name', '', 'text');  // 技能名称

        if (empty($name)) {
            return $this->error('技能名称不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/api/admin/skill/cache/' . $name);
            return $this->success('技能 "' . $name . '" 的缓存已清除', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 清除所有技能缓存
     *
     * @return mixed 返回清除结果
     */
    public function clearAllCache()
    {
        try {
            $result = $this->muuAgent->callAdmin('DELETE', '/api/admin/skill/cache');
            return $this->success('所有技能缓存已清除', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 手动同步技能到数据库
     *
     * @return mixed 返回同步结果
     */
    public function sync()
    {
        try {
            $result = $this->muuAgent->callAdmin('POST', '/api/admin/skill/sync');
            return $this->success('同步成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取技能统计信息
     *
     * @return mixed 返回统计数据
     */
    public function stats()
    {
        try {
            $result = $this->muuAgent->callAdmin('GET', '/api/admin/skill/stats');
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}