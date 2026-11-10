<?php

namespace app\ucenter\controller;

use think\Exception;
use think\facade\View;
use app\common\logic\Orders as OrdersLogic;
use app\common\model\Orders as OrdersModel;

/**
 * 订单页
 */
class Orders extends Base
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    private $OrdersModel; //订单模型
    private $OrdersLogic; //订单逻辑
    function __construct()
    {
        parent::__construct();
        $this->OrdersLogic = new OrdersLogic();
        $this->OrdersModel = new OrdersModel();
    }

    /**
     * @title 订单列表
     */
    public function lists()
    {
        $uid = get_uid();
        $rows = input('rows', 15, 'intval');
        // rows限制在1-100之间
        $rows = min($rows, 100);
        $status = input('status', 'all', 'text');
        // 状态仅允许 all 或合法整数，避免非法值进入查询条件
        if ($status !== 'all') {
            $status = (string)intval($status);
        }
        View::assign('status', $status);
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
        ];

        if ($status  == 'all') {
            $map[] = ['status', 'between', [0, 9]];
        } else {
            $map[] = ['status', '=', $status];
        }

        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        // 拼接排序字段
        $order =  $order_field . ' ' . $order_type;
        $fields = '*';
        $lists = $this->OrdersModel->getListByPage($map, $order, $fields, $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->OrdersLogic->formatData($val);
        }
        unset($val);
        View::assign('pager', $pager);
        View::assign('lists', $lists);

        // 设置菜单识别TAB
        View::assign('tab', 'orders');
        $this->setTitle('我的订单');
        // 输出模板
        return View::fetch();
    }

    /**
     * @title 订单详情
     */
    public function detail()
    {
        $order_no = input('order_no', '', 'text');
        $id = input('id', 0, 'intval');

        // 仅允许查询当前店铺下当前用户自己的订单
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', get_uid()],
        ];
        if ($order_no !== '') {
            $map[] = ['order_no', '=', $order_no];
        } elseif ($id) {
            $map[] = ['id', '=', $id];
        } else {
            return $this->error('参数错误');
        }

        $order_data = $this->OrdersModel->getDataByMap($map);
        if (empty($order_data)) {
            return $this->error('订单不存在');
        }
        $order_data = $this->OrdersLogic->formatData($order_data);

        View::assign('order', $order_data);
        // 设置菜单识别TAB
        View::assign('tab', 'orders');
        $this->setTitle('我的订单');
        // 输出模板
        return View::fetch();
    }
}
