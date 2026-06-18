<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\History as HistoryModel;
use app\common\logic\History as HistoryLogic;

class History extends Api
{
    protected HistoryLogic $HistoryLogic;
    protected HistoryModel $HistoryModel;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->HistoryLogic = new HistoryLogic();
        $this->HistoryModel = new HistoryModel();
        //添加jwt中间件
    }

    public function lists()
    {
        $uid = get_uid();
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', '=', 1]
        ];

        $rows = 15;
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
        $lists = $this->HistoryModel->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->HistoryLogic->formatData($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 记录数量
     */
    public function count()
    {
        $uid = get_uid();
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', '=', 1]
        ];

        $count = $this->HistoryModel->where($map)->count();

        return $this->success('success', $count);
    }
}
