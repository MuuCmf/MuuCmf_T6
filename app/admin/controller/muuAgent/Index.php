<?php

namespace app\admin\controller\muuAgent;

use app\admin\controller\Admin;
use app\common\service\MuuAgent;

/**
 * @title 智能体基础接口
 * @package app\admin\controller\muuAgent
 */
class Index extends Admin
{
    /** @var MuuAgent MuuAgent 中台客户端 */
    protected MuuAgent $muuAgent;

    public function __construct()
    {
        parent::__construct();
        $this->muuAgent = new MuuAgent();
    }

    /**
     * 基础信息接口
     * 
     * 返回智能体模块的版本信息和运行状态，用于健康检查
     *
     * @return mixed 返回模块信息和状态
     */
    public function index()
    {
        $data = [
            'module'      => 'muuagent',
            'alias'       => '智能体',
            'version'     => '1.0.0',
            'status'      => 'active',
            'timestamp'   => time(),
        ];

        return $this->success('请求成功', $data);
    }
}