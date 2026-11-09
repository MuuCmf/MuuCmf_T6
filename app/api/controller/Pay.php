<?php

namespace app\api\controller;

use think\Exception;
use think\facade\Db;
use think\Request;
use think\facade\Log;
use app\common\facade\channel\Channel as ChannelServer;
use app\common\facade\channel\Pay as PayServer;
use app\common\controller\Api;
use app\common\model\MemberSync;
use app\common\model\Orders as OrdersModel;
use app\common\facade\bytedance\MiniProgram as DouyinMiniProgramServer;
use app\common\facade\baidu\MiniProgram as BaiduMiniProgramServer;
use app\common\service\aftersale\RefundCenter;
use app\common\service\message\ChannelConfigProvider;
use app\common\service\message\MessageCenter;


class Pay extends Api
{
    private OrdersModel $OrderModel; //订单模型
    private $OrderLogic; //订单逻辑
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['only' => 'pay,refund,close,query'],
    ];

    function __construct()
    {
        parent::__construct();
        $this->OrderModel = new OrdersModel();
    }

    /**
     * 发起支付
     */
    public function pay()
    {
        if (request()->isPost()) {
            try {
                $order_no = isset($this->params['order_no']) ? (string)$this->params['order_no'] : '';
                $channel = empty($this->params['channel']) ? '' : $this->params['channel'];
                $order_data = $this->OrderModel->getDataByOrderNo($order_no);
                if (!$order_data) {
                    throw new Exception('订单不存在');
                }
                // 支付前置校验：仅订单本人、未支付、且仍处于「待付款」状态的订单可发起支付。
                // 订单被取消/删除（status != 1）后仍允许支付，会导致取消时已归还的优惠券、
                // 限时折扣额度（限购计数/活动库存）、拼团名额在支付成功后再次生效，形成优惠复用漏洞。
                if (intval($order_data['uid']) !== intval(get_uid())) {
                    throw new Exception('无权操作该订单');
                }
                if (intval($order_data['paid']) === 1) {
                    throw new Exception('订单已支付，请勿重复支付');
                }
                if (intval($order_data['status']) !== 1) {
                    throw new Exception('订单已取消或已关闭，无法支付');
                }
                //更新渠道
                if (!empty($channel) && $channel != $order_data['channel']) {
                    $old_order_no = (string)$order_data['order_no'];
                    $order_no = build_order_no(); // 更好渠道后更新订单号
                    $shopid = intval($order_data['shopid'] ?? 0);
                    // 订单号改写与优惠记录迁移必须同事务：任一环节失败整体回滚，
                    // 避免「订单号已变、记录仍挂旧号」导致支付后的 settle 与取消/退款时的 rollback 全部落空
                    Db::transaction(function () use ($order_data, $old_order_no, $order_no, $channel, $shopid) {
                        // 乐观锁：仅当订单号仍是本次读取到的旧号时才允许改写，
                        // 防止并发/重复请求各自换号，导致订单与优惠记录挂在不同单号上而失联
                        $affected = $this->OrderModel->where('id', $order_data['id'])
                            ->where('order_no', $old_order_no)
                            ->update([
                                'order_no'    => $order_no,
                                'channel'     => $channel,
                                'update_time' => time(),
                            ]);
                        if (intval($affected) !== 1) {
                            throw new Exception('订单状态已变化，请重新发起支付');
                        }

                        // 订单号变更后同步迁移优惠链路记录（折扣占用 / 券占用 / 拼团成员）：
                        // 三者均以 order_no 为键，若不迁移，支付成功后的 settle 与取消/退款时的 rollback 都会失效
                        $migrations = [
                            '限时折扣' => \app\common\service\Discount::migrateOrderNo($old_order_no, $order_no),
                            '优惠券'   => \app\common\service\Coupon::migrateOrderNo($shopid, $old_order_no, $order_no),
                            '拼团'     => \app\common\service\Groupbuy::migrateOrderNo($shopid, $old_order_no, $order_no),
                        ];
                        // null 表示 business 模块不可用（此类订单不会有对应记录），跳过；
                        // false 表示迁移失败，抛错回滚订单号改写，避免优惠记录失联
                        $failed = [];
                        foreach ($migrations as $name => $result) {
                            if ($result === false) {
                                $failed[] = $name;
                            }
                        }
                        if (!empty($failed)) {
                            throw new Exception('订单优惠记录迁移失败：' . implode('、', $failed));
                        }
                    });
                    $order_data['order_no'] = $order_no;
                    $order_data['channel'] = $channel;
                }
                //数据处理
                if ($order_data['app'] == 'vip' || $order_data['app'] == 'svip' || $order_data['order_info_type'] == 'vipcard') {
                    $order_namespace = "app\\common\\logic\\Orders";
                    $this->OrderLogic = new $order_namespace;
                    $order_data = $this->OrderLogic->vipFormatData($order_data);
                } else {
                    // 应用名白名单校验，防止订单 app 字段被污染导致实例化任意类
                    $app_name = isset($order_data['app']) ? (string)$order_data['app'] : '';
                    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $app_name)) {
                        throw new Exception('订单应用标识不合法');
                    }
                    $order_namespace = "app\\{$app_name}\\logic\\Orders";
                    if (!class_exists($order_namespace)) {
                        throw new Exception('订单应用逻辑不存在');
                    }
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
                    // 回调地址协议白名单：仅允许 http/https，防止注入非 HTTP 协议
                    if (!preg_match('#^https?://#i', (string)$notify_url)) {
                        throw new Exception('回调地址不合法');
                    }
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
                    // 获取支付参数（以订单 shopid 为准，防止客户端 shopid 篡改导致用错商户配置）
                    $order_shopid = intval($order_data['shopid'] ?? 0);
                    $config = ChannelServer::config($order_data['channel'], $order_shopid);
                    if ($order_data['channel'] == 'weixin_h5' || $order_data['channel'] == 'weixin_mp' || $order_data['channel'] == 'weixin_work') {
                        // 获取用户openid（同样以订单 shopid 取会员绑定，保证 openid 与商户 appid 匹配）
                        $openid = MemberSync::where([
                            ['shopid', '=', $order_shopid],
                            ['uid', '=', get_uid()],
                            ['type', '=', $order_data['channel']]
                        ])->value('openid');

                        $pay_data['openid'] = $openid;
                    }

                    //该参数会引起部分情况返回【请求中含有未在api文档中定义的参数】的提示，暂注释
                    //$pay_data['subject'] = $title;
                    $pay_data['body'] = $title;
                    $pay_data['out_trade_no'] = $order_no;
                    $pay_data['total_fee'] = intval(round($order_data['paid_fee'] * 100));
                    $pay_data['notify_url'] = $notify_url;
                    // 发起支付
                    $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                    if (!$PayService || !$PayService->server) {
                        throw new Exception('支付服务初始化失败');
                    }
                    $trade_type = 'JSAPI';
                    if ($order_data['channel'] == 'h5') {
                        $trade_type = 'MWEB';
                    }
                    if ($order_data['channel'] == 'pc') {
                        $trade_type = 'NATIVE';
                    }
                    if (in_array($order_data['channel'], ['app_android', 'app_ios', 'app_harmony'])) {
                        $trade_type = 'APP';
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
                    $pay_data['total_amount'] = intval(round($order_data['paid_fee'] * 100));
                    $pay_data['notify_url'] = $notify_url;

                    $result_pay = DouyinMiniProgramServer::createOrder($pay_data);
                }
                // 百度小程序支付
                if ($order_data['channel'] == 'baidu_mp') {
                    $pay_data['dealTitle'] = $title;
                    $pay_data['tpOrderId'] = $order_no;
                    $pay_data['totalAmount'] = (string)intval(round($order_data['paid_fee'] * 100));
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
            // 归属校验：仅订单本人可查询支付状态，防止越权查询他人订单
            if (intval($order_data['uid']) != get_uid()) {
                throw new Exception('无权操作该订单');
            }

            // 获取支付参数（以订单 shopid 为准）
            $config = ChannelServer::config($order_data['channel'], intval($order_data['shopid'] ?? 0));
            // 微信支付
            if ($order_data['channel'] == 'weixin_h5' || $order_data['channel'] == 'weixin_mp' || $order_data['channel'] == 'weixin_work') {
                $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                if (!$PayService || !$PayService->server) {
                    throw new Exception('支付服务初始化失败');
                }
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
                // 归属校验：仅订单本人可关闭支付单，防止越权关闭他人订单
                if (intval($order_data['uid']) != get_uid()) {
                    throw new Exception('无权操作该订单');
                }

                $config = ChannelServer::config($order_data['channel'], intval($order_data['shopid'] ?? 0));
                $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
                if (!$PayService || !$PayService->server) {
                    throw new Exception('支付服务初始化失败');
                }
                $result = $PayService->server->close($order_no);

                return $this->success('success', $result);
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 退款申请（兼容旧客户端入口）
     * 仅生成待审核售后单，不在此接口直接改状态或打款
     */
    public function refund()
    {
        if (request()->isAjax()) {
            // 越权防护：校验退款订单归属当前登录用户，防止操作他人订单
            $uid = get_uid();
            $order_no = isset($this->params['order_no']) ? (string)$this->params['order_no'] : '';
            $order_id = isset($this->params['id']) ? intval($this->params['id']) : 0;
            $query = $this->OrderModel->newQuery();
            if ($order_no !== '') {
                $query->where('order_no', $order_no);
            } elseif ($order_id > 0) {
                $query->where('id', $order_id);
            } else {
                return $this->error('参数错误');
            }
            $refund_order = $query->find();
            // 模型对象统一转为数组：售后执行器以数组契约接收订单，避免类型错误
            if (is_object($refund_order) && method_exists($refund_order, 'toArray')) {
                $refund_order = $refund_order->toArray();
            }
            if (!$refund_order || $refund_order['uid'] != $uid) {
                return $this->error('无权操作该订单');
            }
            // 应用商店订单为授权类虚拟商品，用户不允许申请退款（如需退款请由后台人工处理）
            if ((string)$refund_order['app'] === 'appstore') {
                return $this->error('应用商店订单不支持申请退款');
            }
            // 通用入口仅开放已接入统一售后的应用（实现 app\{app}\service\aftersale\Policy）：
            // 统一生成待审核售后单，禁止通过该入口直接修改退款状态或触发打款，避免绕过商家后台审核。
            if (!RefundCenter::supports((string)$refund_order['app'])) {
                return $this->error('该订单暂不支持在线申请退款，请联系商家处理');
            }
            try {
                $result = RefundCenter::apply($refund_order, [
                    'remark' => (string)($this->params['remark'] ?? ''),
                ]);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
            // 通用入口与模块用户端保持同一返回契约：{id, order_no, refund, refund_apply}
            return $this->success('退款申请已提交，请等待商家审核', [
                'id'           => intval($refund_order['id'] ?? 0),
                'order_no'     => (string)($refund_order['order_no'] ?? ''),
                'refund'       => 1,
                'refund_apply' => $result,
            ], 'refresh');
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
            $this->params['channel'] == 'pc' ||
            $this->params['channel'] == 'app_android' ||
            $this->params['channel'] == 'app_ios' ||
            $this->params['channel'] == 'app_harmony'
        )) {
            //实例化支付服务
            $config = ChannelServer::config($this->params['channel'], $this->shopid);
            $PayService = PayServer::init($config['appid'], $this->params['pay_channel']);
            if (!$PayService || !$PayService->server) {
                throw new Exception('支付服务初始化失败');
            }

            // v3 回调处理 默认开启
            if (config('extend.WX_PAY_USE_V3', true)) {
                // 验证签名（使用框架 request->header() 获取，兼容不同 Web 服务器的键名大小写差异）
                $rawHeaders = request()->header();
                $headers = [
                    'wechatpay-timestamp' => $rawHeaders['wechatpay-timestamp'] ?? '',
                    'wechatpay-nonce' => $rawHeaders['wechatpay-nonce'] ?? '',
                    'wechatpay-signature' => $rawHeaders['wechatpay-signature'] ?? '',
                    'wechatpay-serial' => $rawHeaders['wechatpay-serial'] ?? '',
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

    /**
     * 更新订单
     */
    protected function updateOrders($order_no)
    {
        // 获取订单数据
        $order_info = $this->OrderModel->getDataByOrderNo($order_no);
        if (!$order_info) {
            if (in_array($this->params['channel'], ['weixin_mp', 'weixin_h5', 'weixin_work', 'h5', 'pc', 'app_android', 'app_ios', 'app_harmony'])) {
                return $this->payXmlMsg('FAIL', '没有查询到订单');
            }
            if ($this->params['channel'] == 'douyin_mp') {
                return DouyinMiniProgramServer::returnMsg(-1, 'error');
            }
            if ($this->params['channel'] == 'baidu_mp') {
                return BaiduMiniProgramServer::returnMsg(-1, 'error');
            }
        }
        if ($order_info['paid'] == 1) {
            if (in_array($this->params['channel'], ['weixin_mp', 'weixin_h5', 'weixin_work', 'h5', 'pc', 'app_android', 'app_ios', 'app_harmony'])) {
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
            // 应用名白名单校验，防止订单 app 字段被污染导致实例化任意类
            $app_name = isset($order_info['app']) ? (string)$order_info['app'] : '';
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $app_name)) {
                Log::write('支付回调订单 app 字段非法: ' . $app_name . ' order_no=' . $order_no);
                return false;
            }
            $order_namespace = "app\\{$app_name}\\service\\Orders";
        }
        // 类存在性校验后再实例化
        if (!class_exists($order_namespace)) {
            Log::write('支付回调订单服务类不存在: ' . $order_namespace . ' order_no=' . $order_no);
            return false;
        }
        $OrderService = new $order_namespace;
        try {
            $result = $OrderService->paySuccess($order_info);
        } catch (\Throwable $e) {
            // 记录异常后继续抛出，保持回调非 200 触发微信重试（paid 已回滚，重试可安全补执行业务）
            Log::write('支付回调业务处理异常: ' . $e->getMessage() . ' order_no=' . $order_no);
            throw $e;
        }

        //消息通知（并发重复回调未抢到订单状态迁移时，业务未重复执行，跳过模板消息避免重复推送）
        if (!is_array($result) || empty($result['already_paid'])) {
            // 统一消息中心：站内信 + 已启用的微信渠道；
            // 模块订单服务已自行接入通知（如 classroom::notifyPaid）时跳过，避免同一次支付重复推送
            if (!method_exists($OrderService, 'notifyPaid')) {
                $this->sendPaySuccessMessage($order_info);
            }
        }

        // SCRM 业务事件接入：支付成功后回流订单事件，失败不影响支付主流程
        if (!is_array($result) || empty($result['already_paid'])) {
            $this->pushScrmBusinessEvent('pay', $order_info);
        }

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
     * 推送 SCRM 业务事件
     *
     * 仅做事件回流，异常一律吞掉，保证支付/退款主流程不受影响；SCRM 未安装时直接跳过。
     *
     * @param string $scene      场景 pay支付 refund退款
     * @param array  $order      订单数据
     * @param array  $refundInfo 退款数据（uid / order_no / refund_no / refund_fee）
     * @return void
     */
    protected function pushScrmBusinessEvent(string $scene, array $order, array $refundInfo = []): void
    {
        try {
            $shopid = (int)($order['shopid'] ?? 0);
            if ($shopid <= 0 || !class_exists('\\app\\scrm\\service\\BusinessEventService')) {
                return;
            }
            if (!(new \app\common\model\Module())->checkInstalled('scrm')) {
                return;
            }

            $service = new \app\scrm\service\BusinessEventService();
            if ($scene === 'pay') {
                $service->fromOrderPay($shopid, $order);
            } else {
                $service->fromOrderRefund($shopid, $order, $refundInfo);
            }
        } catch (\Throwable $e) {
            Log::write('SCRM业务事件推送失败 scene=' . $scene . '：' . $e->getMessage());
        }
    }

    /**
     * 发送支付成功消息（统一消息推送中心）
     *
     * 用户侧站内信必发，微信模板/订阅消息取消息中心已启用的渠道统一发送；
     * 同时按模板「默认接收人」发运营提醒 order.pay_success_admin；
     * 调用点在支付业务事务提交之后，异常一律吞掉，不影响支付结果。
     *
     * @param array $order_info 订单数据
     * @return void
     */
    protected function sendPaySuccessMessage(array $order_info)
    {
        try {
            $uid = (int)($order_info['uid'] ?? 0);
            $orderNo = (string)($order_info['order_no'] ?? '');
            if ($uid <= 0 || $orderNo === '') {
                return;
            }

            $products = $order_info['products'] ?? [];
            if (!is_array($products)) {
                $products = json_decode((string)$products, true) ?: [];
            }

            // 支付回调拿到的订单是支付前快照，paid_time 以库内最新值为准
            $paidTime = (int)($order_info['paid_time'] ?? 0);
            if ($paidTime <= 0) {
                $paidTime = (int)$this->OrderModel->where('order_no', $orderNo)->value('paid_time');
            }

            // 微信渠道开关打开后由消息中心接管：显式传 channels 覆盖模板默认兜底链
            $shopid = (int)($order_info['shopid'] ?? 0);
            $channels = array_merge(['inbox'],
                (new ChannelConfigProvider())->enabledWechatChannels($shopid));
            $amount = sprintf('%.2f', (int)($order_info['paid_fee'] ?? 0) / 100);
            $paidTimeText = $paidTime > 0 ? time_format($paidTime, 'Y-m-d H:i:s') : '';

            MessageCenter::send('order.pay_success', [
                'type'  => 'uids',
                'value' => [$uid],
            ], [
                'order_no'     => $orderNo,
                'amount'       => $amount,
                'product_name' => (string)($products['title'] ?? '商品'),
                'paid_time'    => $paidTimeText,
            ], [
                'shopid'   => $shopid,
                'biz_key'  => $orderNo,
                'channels' => $channels,
            ]);

            // 运营提醒：接收人取模板「默认接收人」配置，失败不影响用户消息
            try {
                MessageCenter::send('order.pay_success_admin', [
                    'type' => 'template',
                ], [
                    'order_no'  => $orderNo,
                    'amount'    => $amount,
                    'paid_time' => $paidTimeText,
                ], [
                    'shopid'   => $shopid,
                    'biz_key'  => $orderNo . '_admin',
                    'channels' => $channels,
                ]);
            } catch (\Throwable $e) {
                Log::write('[message] 支付成功运营提醒发送失败 order_no=' . $orderNo . '：' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::write('[message] 支付成功消息发送失败 order_no=' . ($order_info['order_no'] ?? '') . '：' . $e->getMessage());
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
        if (!$PayService || !$PayService->server) {
            throw new Exception('支付服务初始化失败');
        }

        $result = $PayService->server->getFormCert();

        echo $result;
    }
}
