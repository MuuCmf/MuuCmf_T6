<?php

namespace app\admin\controller;

use app\common\model\DouyinMpConfig;
use app\common\model\DouyinMpSettle as DouyinMpSettleModel;
use app\common\service\bytedance\DouyinMp as DouyinMpService;
use app\common\model\Orders as OrdersModel;
use app\common\logic\Orders as OrdersLogic;

class DouyinMiniprogram extends Admin
{
    private DouyinMpConfig $MiniProgramModel;
    protected DouyinMpSettleModel $DouyinMpSettleModel;
    function __construct()
    {
        parent::__construct();
        $this->MiniProgramModel = new DouyinMpConfig();
        $this->DouyinMpSettleModel = new DouyinMpSettleModel();
    }

    /**
     * 小程序配置
     */
    public function config()
    {
        if (request()->isPost()) {
            $params = input('post.');
            $data = [
                'id' => 0,
                'shopid' => $this->shopid,
                'title' => $params['title'],
                'description' => $params['description'],
                'appid' => $params['appid'],
                'weixin_merchant_uid' => $params['weixin_merchant_uid'],
                'alipay_merchant_uid' => $params['alipay_merchant_uid'],
                'secret' => $params['secret'],
                'token' => $params['token'],
                'salt' => $params['salt']
            ];
            $map = [
                ['shopid', '=', $this->shopid],
            ];
            $id = $this->MiniProgramModel->where($map)->value('id');
            if ($id) {
                $data['id'] = $id;
            }
            $this->MiniProgramModel->edit($data);
            return $this->success('保存成功');
        } else {
            //查询分组数据
            //查询数据
            $config = $this->MiniProgramModel->where([
                ['shopid', '=', $this->shopid],
            ])->find();

            // 设置回调地址
            $callback_url = url('api/douyin/callback', ['shopid' => $this->shopid], false, true);
            $config['callback'] = (string)$callback_url;

            // json数据
            return $this->success('success', $config);
        }
    }

    /**
     * 抖音订单结算列表
     */
    public function settle()
    {
        $keyword = input('keyword', '', 'text');
        $status = input('status') == null ? 'all' : input('status');
        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 100);

        // 获取查询条件
        $map = [];
        if ($status == 'all') {
            $map[] = ['status', 'in', [0, 1]];
        } else {
            $map[] = ['status', '=', $status];
        }

        // 获取列表
        $lists = $this->DouyinMpSettleModel->getListByPage($map, 'id DESC', '*', $rows);
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->DouyinMpSettleModel->handle($val);
        }
        unset($val);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 未结算订单列表
     */
    public function orders()
    {
        $OrdersModel = new OrdersModel();
        $OrdersLogic = new OrdersLogic();

        $keyword = input('keyword', '', 'text');
        $status = input('status') == null ? 'all' : input('status');
        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 100);

        // 查询条件
        $map = [
            'shopid' => $this->shopid,
            'paid' => 1,
            'channel' => 'douyin_mp',
            'settle' => 0
        ];

        // 获取列表
        $lists = $OrdersModel->getListByPage($map, 'id DESC', '*', $rows);
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $OrdersLogic->formatData($val);
            // 是否允许结算
            $val['can_settle'] = 0;
            if ($val['paid_time'] + (86400 * 7) < time()) {
                $val['can_settle'] = 1;
            }

            // 查询是否有分账数据
            $val['has_settle'] = 0;
            $has_settle = $this->DouyinMpSettleModel->where('order_no', $val['order_no'])->find();
            if (!empty($has_settle)) {
                $val['has_settle'] = 1;
            }
        }
        unset($val);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 手动触发结算分账
     */
    public function manualSettle()
    {
        $order_no = input('order_no', '', 'text');
        $OrdersModel = new OrdersModel();
        if (!empty($order_no)) {
            $order_info = $OrdersModel->where('order_no', $order_no)->find();
            $settle_no = build_order_no();
            // 查询是否已有该订单分账数据
            $has_settle = $this->DouyinMpSettleModel->where('order_no', '=', $order_no)->find();

            // 预写入分账表
            $settle_id = 0;
            if (empty($has_settle)) {
                $settle_id = $this->DouyinMpSettleModel->edit([
                    'shopid' => $this->shopid,
                    'settle_no' => $settle_no,
                    'order_no' => $order_no,
                    'price' => $order_info['paid_fee'],
                    'status' => 0
                ]);
            }

            // 请求结算分账
            $result = (new DouyinMpService)->settle($settle_no, $order_no);

            // "err_no" => 0
            // "err_tips" => "success"
            // "settle_no" => "7147090344914766092"
            if ($result['err_no'] == 0) {
                // 更新结算分账表
                $this->DouyinMpSettleModel->edit([
                    'id' => $settle_id,
                    'shopid' => $this->shopid,
                    'settle_no' => $settle_no,
                    'order_no' => $order_no,
                    'price' => $order_info['paid_fee'],
                    'douyin_settle_no' => $result['settle_no'],
                    'status' => 0
                ]);
                // 更改分账状态
                $OrdersModel->edit([
                    'id' => $order_info['id'],
                    'settle' => 0
                ]);

                return $this->success('手动结算发送成功');
            } else {
                return $this->error($result['err_tips']);
            }
        }
        return $this->error('参数错误');
    }

    /**
     * 结算及分账结果查询
     */
    public function manualSettleQuery()
    {
        $order_no = input('order_no', '', 'text');
        // 查询订单数据
        $OrdersModel = new OrdersModel();
        $order_info = $OrdersModel->where('order_no', $order_no)->find();
        // 查询分账表数据
        $has_settle = $this->DouyinMpSettleModel->where('order_no', '=', $order_no)->find();
        $settle_no = $has_settle['settle_no'];

        $result = (new DouyinMpService)->settleQuery($settle_no);
        if ($result['err_no'] == 0) {
            // 更新结算分账表
            $this->DouyinMpSettleModel->edit([
                'id' => $has_settle['id'],
                'douyin_settle_no' => $result['settle_info']['settle_no'],
                'status' => 1
            ]);
            // 更改分账状态
            $OrdersModel->edit([
                'id' => $order_info['id'],
                'settle' => 1
            ]);

            return $this->success('校验成功');
        } else {
            return $this->error($result['err_tips']);
        }
    }

    /**
     * 模板消息通知
     * @return \think\response\Json
     */
    public function templateMessage()
    {
        if (request()->isPost()) {
            $params = request()->post();
            $data = [
                'switch'      => $params['switch'],
                'to'          => $params['to'],
                'manager_uid' => $params['manager_uid'],
                'tmplmsg'     => $params['tmplmsg']
            ];
            $data = json_encode($data);
            $result = $this->MiniProgramModel->where('shopid', $this->shopid)->save(['tmplmsg' => $data]);
            if ($result) {
                return $this->success('保存成功');
            }
            return $this->error('保存失败，请稍后再试');
        }
    }
}
