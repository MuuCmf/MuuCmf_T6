<?php

namespace app\admin\controller;

use app\common\model\CapitalFlow as CapitalFlowModel;

/**
 * 后台资金流水控制器
 */
class CapitalFlow extends Admin
{
    protected CapitalFlowModel $CapitalFlowModel;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->CapitalFlowModel = new CapitalFlowModel();
    }

    /**
     * 资金流水列表
     */
    public function list()
    {
        $map = [
            ['shopid', '=', $this->shopid]
        ];

        // 用户ID查询
        $uid = input('uid', 0, 'intval');
        if (!empty($uid)) {
            $map[] = ['uid', '=', $uid];
        }

        // 流水号/订单号查询
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $map[] = ['flow_no|order_no', 'like', "%{$keyword}%"];
        }

        // 渠道查询
        $channel = input('channel', '', 'text');
        if (!empty($channel)) {
            $map[] = ['channel', '=', $channel];
        }

        // 收支类型查询（1：支出 2：收入）
        $type = input('type', 0, 'intval');
        if ($type > 0) {
            $map[] = ['type', '=', $type];
        }

        // 每页显示数量
        $rows = input('rows', 15, 'intval');
        $rows = min($rows, 100);

        // 排序字段白名单
        $order_field = input('order_field', 'id', 'text');
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'id';
        $order_type = input('order_type', 'desc', 'text');
        $order_type = in_array($order_type, ['asc', 'desc']) ? $order_type : 'desc';
        $order = $order_field . ' ' . $order_type;

        $lists = $this->CapitalFlowModel->getListByPage($map, $order, '*', $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->CapitalFlowModel->handle($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }
}
