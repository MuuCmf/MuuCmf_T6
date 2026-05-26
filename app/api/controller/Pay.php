<?php

namespace app\api\controller;

use think\Exception;
use think\facade\Db;
use think\Request;
use think\facade\Log;
use app\common\facade\channel\Channel as ChannelServer;
use app\common\facade\channel\Pay as PayServer;
use app\common\controller\Api;
use app\common\model\CapitalFlow;
use app\common\model\MemberSync;
use app\common\model\MemberWallet;
use app\common\model\Orders as OrdersModel;
use app\common\facade\wechat\OfficialAccount;
use app\common\facade\wechat\MiniProgram as WeixinMiniProgramServer;
use app\common\facade\bytedance\MiniProgram as DouyinMiniProgramServer;
use app\common\facade\baidu\MiniProgram as BaiduMiniProgramServer;


class Pay extends Api
{
    private $OrderModel; //订单模型
    private $OrderLogic; //订单模型
    private $CapitalFlowModel;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['only' => 'pay,refund'],
    ];

    function __construct(Request $request)
    {
        parent::__construct();
        $this->OrderModel = new OrdersModel();
        $this->CapitalFlowModel = new CapitalFlow();
    }

    /**
     * 发起支付
     */
    public function pay()
    {
        if (request()->isPost()) {
            try {
                $order_no = $this->params['order_no'];
                $channel = empty($this->params['channel']) ? '' : $this->params['channel'];
                $order_data = $this->OrderModel->getDataByOrderNo($order_no);
                if (!$order_data) {
                    throw new Exception('订单不存在');
                }
                //更新渠道
                if (!empty($channel) && $channel != $order_data['channel']) {
                    $order_no = build_order_no(); // 更好渠道后更新订单号
                    $this->OrderModel->edit(['id' => $order_data['id'], 'order_no' => $order_no, 'channel' => $channel]);
                    $order_data['order_no'] = $order_no;
                    $order_data['channel'] = $channel;
                }
                //数据处理
                if($order_data['app'] == 'vip' || $order_data['order_info_type'] == 'vipcard'){
                    $order_namespace = "app\\common\\logic\\Orders";
                    $this->OrderLogic = new $order_namespace;
                    $order_data = $this->OrderLogic->vipFormatData($order_data);
                }else{
                    $order_namespace = "app\\{$order_data['app']}\\logic\\Orders";
                    $this->OrderLogic = new $order_namespace;
                    $order_data = $this->OrderLogic->formatData($order_data);
                }
                
                //初始化支付数据
                $title = $order_data['products']['title'];
                if (mb_strlen($title, 'utf8') > 20) {
                    $title = mb_substr($title, 0, 20, 'utf8') . '...';
                }

                //支付回调
                if (isset($this->params['notify_url'])) {
                    $notify_url = $this->params['notify_url'];
                } else {
                    $notify_url = request()->domain() . "/api/pay/callback";
                    $notify_url .= "/channel/{$order_data['channel']}";
                    $notify_url .= "/pay_channel/{$this->params['pay_channel']}";
                    $notify_url .= "/shopid/{$order_data['shopid']}";
                    $notify_url .= "/app/{$order_data['app']}";
                }

                // 根据渠道发起支付
                if (
                    $order_data['channel'] == 'weixin_h5' ||
                    $order_data['channel'] == 'weixin_work' ||
                    $order_data['channel'] == 'weixin_mp' ||
                    $order_data['channel'] == 'h5' ||
                    $order_data['channel'] == 'pc' || 
                    $order_data['channel'] == 'app_android' ||
                    $order_data['channel'] == 'app_ios' || 
                    $order_data['channel'] == 'app_harmony'
                ) {
                    // 获取支付参数
                    $config = ChannelServer::config($order_data['channel'], $this->shopid);
                    if ($order_data['channel'] == 'weixin_h5' || $order_data['channel'] == 'weixin_mp' || $order_data['channel'] == 'weixin_work') {
                        // 获取用户openid
                        $openid = MemberSync::where([
                            ['shopid', '=', $this->shopid],
                            ['uid', '=', get_uid()],
                            ['type', '=', $order_data['channel']]
                        ])->value('openid');

                        $pay_data['openid'] = $openid;
                    }

                    //该参数会引起部分情况返回【请求中含有未在api文档中定义的参数】的提示，暂注释
                    //$pay_data['subject'] = $title;
                    $pay_data['body'] = $title;
                    $pay_data['out_trade_no'] = $order_no;
                    $pay_data['total_fee'] = intval($order_data['paid_fee'] * 100);
                    $pay_data['notify_url'] = $notify_url;
                    // 发起支付
                    $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                    $trade_type = 'JSAPI';
                    if ($order_data['channel'] == 'h5') {
                        $trade_type = 'MWEB';
                    }
                    if ($order_data['channel'] == 'pc') {
                        $trade_type = 'NATIVE';
                    }
                    $result_pay = $PayService->server->pay($pay_data, $trade_type);
                    
                    // 初始返回url
                    $return_url = '';
                    if (isset($result_pay['code_url'])) {
                        $return_url = url('index/pay/weixin', ['order_no' => $order_no, 'code_url' => $result_pay['code_url']]);
                    }
                }
                // 抖音小程序支付
                if ($order_data['channel'] == 'douyin_mp') {
                    $pay_data['subject'] = $title;
                    $pay_data['body'] = $title;
                    $pay_data['out_order_no'] = $order_no;
                    $pay_data['total_amount'] = intval($order_data['paid_fee'] * 100);
                    $pay_data['notify_url'] = $notify_url;

                    $result_pay = DouyinMiniProgramServer::createOrder($pay_data);
                }
                // 百度小程序支付
                if ($order_data['channel'] == 'baidu_mp') {
                    $pay_data['dealTitle'] = $title;
                    $pay_data['tpOrderId'] = $order_no;
                    $pay_data['totalAmount'] = (string)intval($order_data['paid_fee'] * 100);
                    $pay_data['notifyUrl'] = $notify_url;

                    $result_pay = BaiduMiniProgramServer::createOrder($pay_data);
                }

                //更改支付渠道标识
                $channel_map = [
                    // 主键ID
                    'id' => $order_data['id'],
                    // 支付渠道
                    'pay_channel' => $this->params['pay_channel']
                ];
                $this->OrderModel->edit($channel_map);

                return $this->success('success', $result_pay, $return_url);
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 查询支付平台订单
     */
    public function query()
    {
        try {
            $order_no = $this->params['order_no'];
            $order_data = $this->OrderModel->getDataByOrderNo($order_no);
            if (!$order_data) {
                throw new Exception('订单不存在');
            }

            // 获取支付参数
            $config = ChannelServer::config($order_data['channel'], $this->shopid);
            // 微信支付
            if ($order_data['channel'] == 'weixin_h5' || $order_data['channel'] == 'weixin_mp' || $order_data['channel'] == 'weixin_work') {
                $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                $result = $PayService->server->queryByOutTradeNumber($order_no);

                return $this->success('success', $result);
            }
        } catch (Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 关闭支付平台订单
     */
    public function close()
    {
        if (request()->isPost()) {
            try {
                $order_no = $this->params['order_no'];
                $order_data = $this->OrderModel->getDataByOrderNo($order_no);
                if (!$order_data) {
                    throw new Exception('订单不存在');
                }

                $config = ChannelServer::config($order_data['channel'], $this->shopid);
                $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                $result = $PayService->server->close($order_no);

                return $this->success('success', $result);
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 退款
     */
    public function refund()
    {
        if (request()->isAjax()) {
            //开启事务
            Db::startTrans();
            try {
                $refund_to = $this->params['refund_to'] ?? 1;
                //处理订单业务
                $refund_info = $this->OrderLogic->refund($this->params);
                //更改订单状态
                $order_data = [
                    'id' => $refund_info['order_id'],
                    'refund' => $this->params['refund'],
                    'refund_to' => $refund_to,
                    'status' => 4, //更改已完成
                ];

                //4为退款流程，其他只更改订单状态
                if ($this->params['refund'] == 4) {
                    //是否已有退款记录
                    $map = [
                        ['order_no', '=', $refund_info['order_no']],
                        ['shopid', '=', $this->params['shopid']],
                        ['app', '=', $this->params['app']],
                    ];
                    $has_refund = $this->CapitalFlowModel->getDataByMap($map);
                    if ($has_refund) {
                        if (is_object($has_refund)) $has_refund = $has_refund->toArray();
                        $refund_info['refund_no'] = $has_refund['flow_no'];
                    } else {
                        //订单流水
                        $flow_no = $this->CapitalFlowModel->createFlow([
                            'uid' => $refund_info['uid'],
                            'order_no' => $refund_info['order_no'],
                            'price' => $refund_info['refund_fee'],
                            'shopid' => $this->params['shopid'],
                            'app' => $this->params['app'],
                            'channel' => $refund_to == 0 ? 'balance' : $refund_info['channel'],
                            'type' => 2,
                            'status' => 0
                        ]);
                        if ($flow_no) {
                            $refund_info['refund_no'] = $flow_no;
                        } else {
                            throw new Exception('创建退款订单失败');
                        }
                    }
                    if (!$has_refund || $has_refund['status'] == 0) {
                        //退款逻辑
                        if ($refund_to == 0) {
                            //退款至用户账户
                            $result = (new MemberWallet())->income($refund_info['uid'], $refund_info['refund_fee'], $refund_info['shopid'], false);
                        } else {
                            //退款至付款账户
                            $config = ChannelServer::config($this->params['channel'], $this->shopid);
                            $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                            $result = $PayService->server->refund($refund_info);
                        }
                        if (!$result) {
                            throw new Exception('网络异常，请稍后再试');
                        }
                        //更改流水状态
                        $this->CapitalFlowModel->where('flow_no', $refund_info['refund_no'])->update([
                            'update_time' => time(),
                            'status' => 1
                        ]);
                    }

                    $order_data['refund_no'] =  $refund_info['refund_no'];
                }

                $this->OrderModel->edit($order_data);
                Db::commit();
                return $this->success('处理成功');
            } catch (Exception $e) {
                Db::rollback();
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 支付成功回调
     */
    public function callback()
    {
        $notify_data = file_get_contents("php://input");
        // 记录日志
        Log::write($notify_data);

        if ($this->params['pay_channel'] == 'weixin' && (
            $this->params['channel'] == 'weixin_mp' ||
            $this->params['channel'] == 'weixin_h5' ||
            $this->params['channel'] == 'weixin_work' ||
            $this->params['channel'] == 'h5' ||
            $this->params['channel'] == 'pc'
        )) {
            //实例化支付服务
            $config = ChannelServer::config($this->params['channel'], $this->shopid);
            $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);

            // v3 回调处理 默认开启
            if (config('extend.WX_PAY_USE_V3', true)) {
                // 验证签名
                $headers = [
                    'wechatpay-timestamp' => $_SERVER['HTTP_WECHATPAY_TIMESTAMP'] ?? '',
                    'wechatpay-nonce' => $_SERVER['HTTP_WECHATPAY_NONCE'] ?? '',
                    'wechatpay-signature' => $_SERVER['HTTP_WECHATPAY_SIGNATURE'] ?? '',
                    'wechatpay-serial' => $_SERVER['HTTP_WECHATPAY_SERIAL'] ?? '',
                ];

                if (!$PayService->server->verifySign($notify_data, $headers)) {
                    Log::write('v3 回调签名验证失败');
                    http_response_code(401);
                    exit();
                }

                // 解密回调数据
                $notify = $PayService->server->decryptNotify($notify_data);

                // 返回商户订单号
                $order_no = $PayService->server->notify($notify);
                if (!$order_no) {
                    http_response_code(200);
                    exit();
                }

                $this->updateOrders($order_no);

                http_response_code(200);
                exit();
            }

            // v2 回调处理
            $jsonxml = json_encode(simplexml_load_string($notify_data, 'SimpleXMLElement', LIBXML_NOCDATA));
            $notify = json_decode($jsonxml, true);
            //返回商户订单号
            $order_no = $PayService->server->notify($notify);
            //判断订单是否已支付
            if (!$order_no) {
                return $this->payXmlMsg('FAIL', '通信失败，请稍后再通知我');
            }

            $this->updateOrders($order_no);

            return $this->payXmlMsg('SUCCESS');
        }

        if ($this->params['channel'] == 'douyin_mp') {
            if (empty($notify_data)) {
                return DouyinMiniProgramServer::returnMsg(-1, 'error');
            }
            $content = json_decode($notify_data, true);
            $sign = DouyinMiniProgramServer::handler($content);
            if ($sign == $content['msg_signature']) {
                $msg = json_decode($content['msg'], true);
                $order_no = $msg['cp_orderno'];
                // 这里更新应用业务逻辑代码，使用$msg跟应用订单比对更新订单,可以用 $content['type']判断是支付回调还是退款回调，payment支付回调 refund退款回调。

                if ($content['type'] == 'payment') {
                    $this->updateOrders($order_no);
                }

                // 同步订单
                DouyinMiniProgramServer::ordersPush($order_no);

                return DouyinMiniProgramServer::returnMsg(0, 'success');
            }
        }

        if ($this->params['channel'] == 'baidu_mp') {
            if (empty($notify_data)) {
                return BaiduMiniProgramServer::returnMsg(-1, 'error');
            }

            parse_str($notify_data, $content);
            $sign = BaiduMiniProgramServer::checkSign($content);
            if ($sign) {
                $order_no = $content['tpOrderId'];
                if ($content['status'] == 2) {
                    $this->updateOrders($order_no);
                }

                return BaiduMiniProgramServer::returnMsg(0, 'success');
            }
            // 返回验签失败
            return BaiduMiniProgramServer::returnMsg(-1, 'checkSign fail');
        }
    }


    public function test()
    {
        $notify_data = "<xml><appid><![CDATA[wx90fcefad8616a371]]></appid>
                <bank_type><![CDATA[OTHERS]]></bank_type>
                <cash_fee><![CDATA[1]]></cash_fee>
                <fee_type><![CDATA[CNY]]></fee_type>
                <is_subscribe><![CDATA[N]]></is_subscribe>
                <mch_id><![CDATA[1602403282]]></mch_id>
                <nonce_str><![CDATA[69bbfed090e7f]]></nonce_str>
                <openid><![CDATA[odDW80Xr2djHkcNTrfHO4VOAppkY]]></openid>
                <out_trade_no><![CDATA[202603193527670141]]></out_trade_no>
                <result_code><![CDATA[SUCCESS]]></result_code>
                <return_code><![CDATA[SUCCESS]]></return_code>
                <sign><![CDATA[ECD4E6CD56A12841E367CDE8D383F385]]></sign>
                <time_end><![CDATA[20260319215246]]></time_end>
                <total_fee>1</total_fee>
                <trade_type><![CDATA[NATIVE]]></trade_type>
                <transaction_id><![CDATA[4200003009202603190805100997]]></transaction_id>
                </xml>";
        
    }

    /**
     * 更新订单
     */
    protected function updateOrders($order_no)
    {
        // 获取订单数据
        $order_info = $this->OrderModel->getDataByOrderNo($order_no);
        if (!$order_info) {
            if (in_array($this->params['channel'], ['weixin_mp', 'weixin_h5', 'weixin_work'])) {
                return $this->payXmlMsg('FAIL', '没有查询到订单');
            }
            if ($this->params['channel'] == 'douyin_mp') {
                return DouyinMiniProgramServer::returnMsg(-1, 'error');
            }
        }
        if ($order_info['paid'] == 1) {
            if (in_array($this->params['channel'], ['weixin_mp', 'weixin_h5', 'weixin_work'])) {
                return $this->payXmlMsg('SUCCESS', '订单支付完成');
            }
            if ($this->params['channel'] == 'douyin_mp') {
                return DouyinMiniProgramServer::returnMsg(0, 'success');
            }
            if ($this->params['channel'] == 'baidu_mp') {
                return BaiduMiniProgramServer::returnMsg(0, 'success');
            }
        }

        // 各应用后续业务逻辑
        if ($order_info['order_info_type'] == 'vipcard') {
            $order_namespace = "app\\common\\service\\VipOrders";
        } else {
            $order_namespace = "app\\{$order_info['app']}\\service\\Orders";
        }
        $OrderService = new $order_namespace;
        $result = $OrderService->paySuccess($order_info);

        //消息通知
        $this->sendPaySuccessTmplmsg($order_info['channel'], $order_info);

        return $result;
    }

    /**
     * 返回xml信息
     * @param string $code
     * @param string $msg
     * @return string
     */
    protected function payXmlMsg($code = 'SUCCESS', $msg = '')
    {
        $data = [
            'return_code' => $code,
            'return_msg'  => $msg
        ];
        echo array_to_xml($data);
        exit();
    }

    /**
     * 发送支付成功模板消息
     * @param $tmplmsg_config
     * @param $order_info
     */
    protected function sendPaySuccessTmplmsg($channel, $order_info)
    {
        // 格式化商品数据
        if (!empty($order_info['products']) && !is_array($order_info['products'])) {
            $order_info['products'] = json_decode($order_info['products'], true);
        } else {
            return false;
        }

        // 公众号消息
        if ($channel  == 'weixin_h5') {
            // 获取配置
            $weixin_config = (new \app\common\model\WechatConfig())->getWechatConfigByShopId($this->shopid);
            // 获取支付时间戳
            $paid_time = $this->OrderModel->where('order_no', $order_info['order_no'])->value('paid_time');
            //消息模板是否设置
            if (empty($weixin_config['tmplmsg']['tmplmsg']['pay_success'])) {
                return false;
            }
            $msg_list = [];
            if (in_array('manager', $weixin_config['tmplmsg']['to']) && !empty($weixin_config['tmplmsg']['manager_uid'])) {
                $msg_item['openid'] = get_openid($this->shopid, $weixin_config['tmplmsg']['manager_uid']);
                $msg_item['user_info'] = query_user($order_info['uid']);
                $msg_item['remark'] = '客户的订单已支付成功，如有任何问题请联系平台客服！';
                $msg_list[] = $msg_item;
            }
            if (in_array('user', $weixin_config['tmplmsg']['to'])) {
                $msg_item['openid'] = get_openid($this->shopid, $order_info['uid']);
                $msg_item['user_info'] = query_user($order_info['uid']);
                $msg_item['remark'] = '感谢您的支持，如有任何问题请联系平台客服！';
                $msg_list[] = $msg_item;
            }

            foreach ($msg_list as $item) {
                $msg = [
                    'touser' => $item['openid'],
                    'template_id' => $weixin_config['tmplmsg']['tmplmsg']['pay_success'],
                    'data' => [
                        'character_string3' => [ // 订单编号
                            'value' => $order_info['order_no'],
                        ],
                        'amount4' => [ // 订单金额
                            'value' => sprintf("%.2f", $order_info['paid_fee'] / 100) . '元',
                        ],
                        'thing11' => [ // 产品名称
                            'value' => $order_info['products']['title'] ?? '商品',
                        ],
                        'time7' => [ // 支付时间
                            'value' => time_format($paid_time, 'Y-m-d H:i:s')
                        ]
                    ],
                ];
                $res = @OfficialAccount::sendTemplateMsg($msg);
                //成功返回数据结构
                //array: [
                //     "errcode" => 0
                //     "errmsg" => "ok"
                //     "msgid" => 2538082547780780032
                //]
                return $res;
            }
        }

        // 微信小程序消息
        if ($channel == 'weixin_mp') {
            // 获取配置
            $weixin_mp_config = (new \app\common\model\WechatMpConfig())->getWechatMpConfigByShopId($this->shopid);
            //消息模板是否设置
            if (empty($weixin_mp_config['tmplmsg']['tmplmsg']['pay_success'])) {
                return false;
            }

            if (!empty($order_info['form_id'])) {
                $form_id = $order_info['form_id'];
                $msg_list = [];
                if (in_array('manager', $weixin_mp_config['tmplmsg']['to']) && !empty($weixin_config['tmplmsg']['manager_uid'])) {
                    $msg_item['openid'] = get_openid($this->shopid, $weixin_mp_config['tmplmsg']['manager_uid'], $channel);
                    $msg_item['user_info'] = query_user($order_info['uid']);
                    $msg_list[] = $msg_item;
                }
                if (in_array('user', $weixin_mp_config['tmplmsg']['to'])) {
                    $msg_item['openid'] = get_openid($this->shopid, $order_info['uid'], $channel);
                    $msg_item['user_info'] = query_user($order_info['uid']);
                    $msg_list[] = $msg_item;
                }

                foreach ($msg_list as $item) {
                    $msg = [
                        'touser' => $item['openid'],
                        'template_id' => $weixin_mp_config['tmplmsg']['tmplmsg']['pay_success'],
                        'page' => 'micro/pages/index',
                        'form_id' => $form_id,
                        'data' => [
                            'thing1' => [
                                'value' => $order_info['products']['title'],
                            ],
                            'character_string3' => [
                                'value' => $order_info['order_no'],
                            ],
                            'amount4' => [
                                'value' => sprintf("%.2f", $order_info['paid_fee'] / 100) . '元',
                            ],
                            'time7' => [
                                'value' => $order_info['create_time'],
                            ],
                        ],
                    ];
                    $res = @WeixinMiniProgramServer::sendTemplateMsg($msg);

                    return $res;
                }
            } else {
                return false;
            }
        }
    }

    /**
     * 获取v3微信平台证书
     * 该接口可放入计划任务，每日更新证书信息，避免证书过期
     */
    public function getV3cert()
    {
        // 获取支付参数
        $config = ChannelServer::config('weixin_h5', $this->shopid);
        // 发起支付
        $PayService = PayServer::init($config['appid'], 'weixin');

        $result = $PayService->server->getFormCert();

        echo $result;
    }
}
