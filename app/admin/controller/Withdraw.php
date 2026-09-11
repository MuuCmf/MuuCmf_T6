<?php

namespace app\admin\controller;

use think\Exception;
use think\facade\Db;
use app\common\model\CapitalFlow;
use app\common\model\MemberWallet;
use app\common\model\Withdraw as WithdrawModel;
use app\common\logic\Withdraw as WithdrawLogic;
use app\common\facade\channel\Pay as PayServer;
use app\common\facade\channel\Channel as ChannelServer;

class Withdraw extends Admin
{
    protected WithdrawModel $WithdrawModel;
    protected WithdrawLogic $WithdrawLogic;
    function __construct()
    {
        parent::__construct();
        $this->WithdrawModel = new WithdrawModel();
        $this->WithdrawLogic = new WithdrawLogic();
    }

    /**
     * @title 提现列表
     */
    public function list()
    {
        $order_no = input('get.order_no', '', 'string'); //提现单号
        $map = [
            ['shopid', '=', $this->shopid]
        ];

        //订单号查询
        if (!empty($order_no)) {
            $map[] = ['order_no', 'like', "%{$order_no}%"];
        }
        // 每页显示数量
        $rows = input('rows', 15, 'intval');
        // rows限制
        $rows = min($rows, 100);

        // 获取分页列表
        $lists = $this->WithdrawModel->getListByPage($map, 'id desc create_time desc', '*', $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$item) {
            $item = $this->WithdrawLogic->formatData($item);
        }
        unset($item);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 详情
     */
    public function detail()
    {
        // ID
        $id = input('id', 0, 'intval');

        $data = [];
        if (!empty($id)) {
            $data = $this->WithdrawModel->getDataById($id);
            $data = $this->WithdrawLogic->formatData($data);
        }
        // json response
        return $this->success('success', $data);
    }

    /**
     * @title 手动处理
     */
    public function action()
    {
        $id = input('id', 0, 'intval');
        if (request()->isPost()) {

            try {
                $map = [
                    ['id', '=', $id],
                    ['error', '=', 1],
                    ['paid', '=', 0]
                ];
                $data = $this->WithdrawModel->where($map)->find();
                if (!$data) throw new Exception('该提现记录无法人工处理');
                $data = $data->toArray();

                Db::startTrans(); //开启事务
                //扣除用户余额
                (new MemberWallet())->spending($data['shopid'], $data['uid'], $data['price']);
                //更改提现记录状态
                $update_data = [
                    'id'        => $data['id'],
                    'paid'      => 1,
                    'paid_time' => time(),
                    'error'     =>  0,
                ];
                $result = $this->WithdrawModel->edit($update_data);
                if (!$result)   throw new Exception('操作失败,请稍后再试');

                //写入资金流水表
                $result_capital_flow = (new CapitalFlow())->createFlow([
                    'uid' => $data['uid'],
                    'order_no' => $data['order_no'],
                    'price' => $data['price'],
                    'shopid' => $data['shopid'],
                    'app' => 'system',
                    'channel' => $data['channel'],
                    'remark' => '用户提现',
                ]);
                if (!$result_capital_flow)  throw new Exception('写入资金流失失败');
                Db::commit();
                return $this->success('处理成功');
            } catch (Exception $e) {
                Db::rollback();
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 取消提现申请
     * 
     * 处理用户提交的取消提现请求，执行以下操作：
     * 1. 验证提现记录是否存在且未支付
     * 2. 更新提现状态为已取消(paid=-1)
     * 3. 解冻冻结资金并返还至用户余额
     * 
     * @return \think\response\Json 操作结果(成功/失败)
     * @throws Exception 操作过程中可能抛出的异常
     */
    public function cancel()
    {
        $id = input('id', 0, 'intval');
        if (request()->isPost()) {
            try {
                $map = [
                    ['id', '=', $id],
                    ['paid', '=', 0]
                ];
                $data = $this->WithdrawModel->where($map)->find();
                if (!$data) throw new Exception('数据不存在');
                Db::startTrans(); //开启事务
                //更改提现记录状态
                $update_data = [
                    'id'        => $data['id'],
                    'paid'      => -1,
                    'paid_time' => time(),
                ];
                $result = $this->WithdrawModel->edit($update_data);
                if (!$result)   throw new Exception('操作失败,请稍后再试');

                // 请求平台撤销转账(无论是否成功，都执行后续操作)
                $channel = $data['channel'];
                $pay_channel = $data['pay_channel'];
                $pay_config = ChannelServer::config($channel, $this->shopid);
                $PayService = PayServer::init($pay_config['appid'], $pay_channel, $this->shopid);
                $result = $PayService->server->cancelTransfer($data['order_no']);

                //解冻冻结资金(返还至用户余额)
                (new MemberWallet())->freeze($data['shopid'], $data['uid'], $data['price'], 0);

                Db::commit();
                return $this->success('操作成功');
            } catch (Exception $e) {
                Db::rollback();
                return $this->error($e->getMessage());
            }
        }
    }
}
