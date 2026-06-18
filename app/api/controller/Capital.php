<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\CapitalFlow as CapitalFlowModel;

class Capital extends Api
{
    protected CapitalFlowModel $CapitalFlowModel;

    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['except' => 'lists']
    ];

    public function __construct()
    {
        parent::__construct();
        $this->CapitalFlowModel = new CapitalFlowModel();
    }

    /**
     * 获取资金流水
     */
    public function flow()
    {
        $uid = get_uid();
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', '=', 1]
        ];

        $rows = 10;
        $order_field = input('order_field', 'update_time', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        // 排序
        $order =  $order_field . ' ' . $order_type;
        $fields = '*';
        $lists = $this->CapitalFlowModel->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->CapitalFlowModel->handle($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }
}
