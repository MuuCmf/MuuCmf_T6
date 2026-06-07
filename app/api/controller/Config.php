<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\logic\Config as ConfigLogic;

class Config extends Api
{
    protected ConfigLogic $ConfigLogic;

    function __construct()
    {
        parent::__construct();
        $this->ConfigLogic = new ConfigLogic();
    }

    /**
     * @title 获取前台系统配置
     */
    public function system()
    {
        $config = $this->ConfigLogic->frontend();
        return $this->success('success', $config);
    }
}
