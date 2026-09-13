<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 模型模板管理接口
 * @package app\admin\controller\muuAgent
 *
 * 通过 MuuAgent 中台管理端接口读取模型模板
 * 模型模板为智能体提供预设的模型参数组合（temperature/topP/maxTokens/contextWindow），
 * 智能体通过 modelTemplateCode 引用模板
 */
class ModelTemplate extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 获取模型模板列表（分页）
     *
     * @return mixed 返回模型模板列表数据
     */
    public function list()
    {
        $page      = (int)input('get.page', 1, 'intval');       // 页码，从1开始
        $pageSize  = (int)input('get.pageSize', 100, 'intval'); // 每页条数（模板量少，默认取全量供下拉选择）
        $modelType = (string)input('get.modelType', '', 'text'); // 模型类型筛选，如 llm
        $status    = (string)input('get.status', '', 'text');   // 状态筛选：true/false

        $data = [
            'page'     => $page,
            'pageSize' => $pageSize,
            'appCode'  => $this->muuAgent->getAppCode(), // 从扩展配置自动获取
        ];

        if (!empty($modelType)) {
            $data['modelType'] = $modelType;
        }
        if (!empty($status)) {
            $data['status'] = $status === 'true' || $status === '1';
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/model-template', $data);
            // callAdmin 已统一解包中台 envelope，$result 即业务数据 { list, total, page, pageSize }
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取模型模板详情
     *
     * @return mixed 返回模型模板详情数据
     */
    public function detail()
    {
        $code = (string)input('get.code', '', 'text'); // 模型模板代码

        if (empty($code)) {
            return $this->error('模型模板代码不能为空');
        }

        try {
            $result = $this->muuAgent->callAdmin('GET', '/admin/model-template/' . $code);
            // callAdmin 已统一解包中台 envelope，$result 即模板详情业务数据
            return $this->success('请求成功', $result);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }
}
