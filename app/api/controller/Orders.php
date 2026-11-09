<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\logic\Orders as OrdersLogic;
use app\common\model\Orders as OrdersModel;
use app\common\facade\bytedance\MiniProgram as DouyinMiniProgramServer;
use think\Exception;
use think\facade\Db;
use think\Request;
use app\common\validate\Orders as OrdersValidate;
use app\common\service\aftersale\RefundQuery;
use app\common\service\order\CancelService;
use think\exception\ValidateException;

class Orders extends Api
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    private $OrdersModel; //订单模型
    private $OrdersLogic; //订单逻辑
    function __construct(Request $request)
    {
        parent::__construct();
        $this->OrdersLogic = new OrdersLogic();
        $this->OrdersModel = new OrdersModel();
    }

    /**
     * @title 下单
     * @return \think\Response|void
     */
    public function create()
    {
        if (request()->isPost()) {
            Db::startTrans();
            try {
                //具体业务 分发到相应程序订单类
                $this->params['uid'] = get_uid();
                // 验证数据
                try {
                    validate(OrdersValidate::class)->check($this->params);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    Db::rollback();
                    return $this->error($e->getError());
                }

                // 交给应用内约定类处理数据
                $order_info_type = $this->params['order_info_type'];
                if ($order_info_type == 'vipcard') {
                    $order_namespace = "app\\common\\service\\VipOrders";
                    $appOrdersService = new $order_namespace;
                    $order_data = $appOrdersService->create($this->params);
                } else {
                    // 验证app参数的安全性，防止目录遍历和任意类加载
                    $app = preg_replace('/[^a-zA-Z0-9_]/', '', $this->params['app']);
                    $order_namespace = "app\\{$app}\\service\\Orders";

                    // 确保类存在且可实例化
                    if (!class_exists($order_namespace)) {
                        throw new Exception('订单服务类不存在');
                    }

                    $appOrdersService = new $order_namespace;
                    $order_data = $appOrdersService->create($this->params);
                }

                // 设置表单ID数据
                if (isset($this->params['formId'])) {
                    $order_data['form_id'] = $this->params['formId'];
                }

                //写入订单
                $res = $order_id = $this->OrdersModel->edit($order_data);
                if (!$res) {
                    throw new Exception('创建订单失败，请稍后再试');
                }
                //获取订单数据
                $order = $this->OrdersModel->getDataById($order_id);
                $order = $this->OrdersLogic->formatData($order);
                //免费商品或需退费商品后续业务逻辑处理
                if ($order['paid'] == 1) {
                    if (method_exists($appOrdersService, 'step')) {
                        // 免费商品直接处理后续逻辑，约定step方法
                        $appOrdersService->step($order);
                    }
                }
                // 抖音小程序订单同步
                if ($order['channel'] == 'douyin_mp') {
                    DouyinMiniProgramServer::ordersPush($order['order_no']);
                }

                Db::commit();

                // 免费 / 0 元订单创建时即已置为已支付，不会经过支付回调；
                // 事务提交后由各应用服务补发支付成功站内信，失败不影响下单结果
                if (intval($order['paid'] ?? 0) === 1 && method_exists($appOrdersService, 'notifyPaid')) {
                    try {
                        $appOrdersService->notifyPaid($order);
                    } catch (\Throwable $e) {
                        \think\facade\Log::warning('[order] paid notify failed: ' . $e->getMessage());
                    }
                }

                return $this->success('创建订单成功', $order);
            } catch (\Throwable $e) {
                Db::rollback();
                if (\think\facade\App::isDebug()) {
                    return $this->error($e->getMessage() . $e->getFile() . $e->getLine());
                } else {
                    return $this->error($e->getMessage());
                }
            }
        }
    }

    /**
     * @title 订单列表
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function list()
    {
        $uid = get_uid();
        $status = input('status');
        $rows = input('rows', 15, 'intval');
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
        ];

        if ($this->params['status']  == 'all') {
            $map[] = ['status', 'between', [0, 9999]];
        } else {
            $map[] = ['status', '=', $status];
            $map[] = ['refund', '=', 0];
        }

        $order_field = input('order_field', 'id', 'text');
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
        $lists = $this->OrdersModel->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->OrdersLogic->formatData($val);
        }
        unset($val);

        return $this->success('获取订单成功', $lists);
    }

    /**
     * @title 订单详情
     */
    public function detail()
    {
        $uid = get_uid();
        $order_no = $this->params['order_no'];
        $order_data = $this->OrdersModel->getDataByOrderNo($order_no);
        $order_data = $this->OrdersLogic->formatData($order_data);
        if ($uid != $order_data['uid']) {
            return $this->error('非法操作');
        }

        // pc端商品路径
        if ($order_data['order_info_type'] == 'vipcard') {
            $return_url = url('ucenter/Vip/detail', $order_data['products']['link']['param']);
        } else {
            $return_url = url($order_data['app'] . '/' . $order_data['products']['link']['url'], $order_data['products']['link']['param']);
        }

        // 售后进度以售后单为事实来源：orders.refund 在拒绝/关闭后会回置 0，仅靠订单字段无法展示
        $refundInfo = RefundQuery::latestByOrderNo((string)($order_data['order_no'] ?? ''));
        $order_data['refund_info'] = $refundInfo ? RefundQuery::decorate($refundInfo, $order_data) : null;

        return $this->success('success', $order_data, $return_url);
    }

    /**
     * @title 取消订单
     */
    public function cancel()
    {
        if (!request()->isPost()) {
            return;
        }
        try {
            $uid = get_uid();
            $id = input('id', 0, 'intval');
            $order_no = input('order_no', '', 'text');

            // 获取订单信息
            $order = null;
            if ($id) {
                $order = $this->OrdersModel->getDataById($id);
            }

            if ($order_no) {
                $order = $this->OrdersModel->getDataByOrderNo($order_no);
            }

            if (!$order) {
                return $this->error('订单不存在');
            }

            // 验证订单归属
            if ($order['uid'] != $uid) {
                return $this->error('非法操作');
            }

            // 验证订单状态：只能取消待付款且未支付的订单
            if (intval($order['status']) !== CancelService::STATUS_UNPAID) {
                return $this->error('该订单状态不允许取消');
            }

            if (intval($order['paid']) !== CancelService::PAID_NO) {
                return $this->error('已支付订单不能取消，请申请退款');
            }

            // 状态流转、营销占用与库存回补由统一服务在事务内完成。
            // 返回 false 表示已被支付回调或定时任务抢先处理，本方不再重复回滚。
            $cancelled = (new CancelService())->cancelUnpaid($order);
            if (!$cancelled) {
                return $this->error('订单状态已变更，请刷新后重试');
            }

            return $this->success('取消订单成功');
        } catch (\Throwable $e) {
            if (\think\facade\App::isDebug()) {
                return $this->error($e->getMessage() . $e->getFile() . $e->getLine());
            }
            return $this->error($e->getMessage());
        }
    }

    /**
     * @title 删除订单
     * @return \think\Response|void
     */
    public function delete()
    {
        if (request()->isPost()) {
            Db::startTrans();
            try {
                $uid = get_uid();
                $id = input('id', 0, 'intval');
                $order_no = input('order_no', '', 'text');

                // 获取订单信息
                if ($id) {
                    $order = $this->OrdersModel->getDataById($id);
                }

                if ($order_no) {
                    $order = $this->OrdersModel->getDataByOrderNo($order_no);
                }

                if (!$order) {
                    return $this->error('订单不存在');
                }

                // 验证订单归属
                if ($order['uid'] != $uid) {
                    return $this->error('非法操作');
                }

                // 验证订单状态: 只能删除已取消订单(status=0)或已完成订单(status=5)
                if (!in_array($order['status'], [0, 5])) {
                    return $this->error('该订单状态不允许删除');
                }

                // 更新订单状态为已删除
                $update_data = [
                    'id' => $order['id'],
                    'status' => -1,
                ];
                $res = $this->OrdersModel->edit($update_data);

                if (!$res) {
                    throw new Exception('删除订单失败');
                }

                Db::commit();

                return $this->success('删除订单成功');
            } catch (\Throwable $e) {
                Db::rollback();
                if (\think\facade\App::isDebug()) {
                    return $this->error($e->getMessage() . $e->getFile() . $e->getLine());
                } else {
                    return $this->error($e->getMessage());
                }
            }
        }
    }
}
