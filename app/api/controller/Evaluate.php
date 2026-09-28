<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Evaluate as EvaluateModel;
use app\common\logic\Evaluate as EvaluateLogic;
use app\common\model\Orders as OrdersModel;
use \app\common\logic\Orders as OrdersLogic;

class Evaluate extends Api
{
    protected $EvaluateModel;
    protected $EvaluateLogic;
    protected $OrdersModel;
    protected $OrdersLogic;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['except' => ['lists', 'statistical']]
    ];
    function __construct()
    {
        parent::__construct();
        $this->EvaluateLogic = new EvaluateLogic();
        $this->EvaluateModel = new EvaluateModel();
        $this->OrdersModel = new OrdersModel();
        $this->OrdersLogic = new OrdersLogic();
    }

    public function statistical()
    {
        $shopid = $this->shopid;
        $app = input('app', '', 'text');
        $type = input('type', '', 'text');
        $type_id = intval(input('type_id', 0, 'intval'));
        $res = $this->EvaluateModel->getStatistical($shopid, $app, $type, $type_id);

        return $this->success('SUCCESS', $res);
    }

    public function lists()
    {
        $params = request()->param();
        if (empty($params['app']) || empty($params['type']) || empty($params['type_id'])) {
            return $this->error('缺少参数');
        }
        $lists = $this->EvaluateModel->getListByPageCustom($params);
        foreach ($lists as &$item) {
            $item = $this->EvaluateLogic->formatData($item);
        }
        unset($item);

        return $this->success('SUCCESS', $lists);
    }

    /**
     * 题交和修改评价
     */
    public function edit()
    {
        $order_no = input('order_no');
        $content = input('content', '', 'text');
        $images = input('images', '', 'text');
        $value = input('value');
        $uid = get_uid();
        $id = 0;
        if (empty($content)) {
            return $this->error('评价内容不能为空');
        }
        //获取订单数据
        $order_data = $this->OrdersModel->getDataByOrderNo($order_no);
        if (!$order_data) {
            return $this->error('订单不存在');
        }
        // 订单归属校验：仅允许订单本人评价，防止越权刷评价
        if ($order_data['uid'] != $uid) {
            return $this->error('非法操作，无法评价该订单');
        }
        // 评价对象以订单数据为准（服务端取值），防止攻击者指定任意 type/type_id 刷评价
        $type = $order_data['order_info_type'];
        $type_id = intval($order_data['order_info_id']);
        // 评分范围限制 1-5
        $value = intval($value);
        $value = min(5, max(1, $value));
        //检测是否已评论
        $evaluate_map = [];
        $evaluate_map[] = ['uid', '=', $uid];
        $evaluate_map[] = ['order_no', '=', $order_no];
        $evaluate_map[] = ['shopid', '=', $this->shopid];
        $is_have = $this->EvaluateModel->getDataByMap($evaluate_map);
        if ($is_have && $is_have['status'] == 1) {
            if ($is_have['create_time'] != $is_have['update_time']) {
                $this->error('您已经评价过了');
            }
            $id = $is_have['id'];
        }
        //处理评价图片
        if (!empty($images)) {
            $images = $images;
            $images = explode(',', $images);
        }

        //提交
        $data = [
            'id' => $id,
            'shopid' => $this->shopid,
            'app' => $order_data['app'],
            'uid' => $uid,
            'type' => $type,
            'type_id' => $type_id,
            'order_no' => $order_no,
            'content' => html_entity_decode($content),
            'images' => json_encode($images),
            'value' => $value,
            'status' => 1
        ];
        $res = $this->EvaluateModel->edit($data);
        if ($res) {
            //更改订单评价状态
            $order_edit_data = [
                'id' => $order_data['id'],
                'status' => 5, //已评价
                'evaluate' => 1
            ];
            $this->OrdersModel->edit($order_edit_data);
            return $this->success('提交成功', $res);
        }
        return $this->error('提交失败，请稍后再试');
    }

    public function detail()
    {
        $uid = request()->uid;
        $order_no = input('get.order_no');
        //获取评价数据
        $map = [
            ['shopid', '=', $this->shopid],
            ['order_no', '=', $order_no],
            ['uid', '=', $uid]
        ];
        $result = $this->EvaluateModel->getDataByMap($map);
        $result = $this->EvaluateLogic->formatData($result);
        return $this->success('SUCCESS', $result);
    }
}
