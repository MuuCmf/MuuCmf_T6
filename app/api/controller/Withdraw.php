<?php

namespace app\api\controller;

use think\Exception;
use think\facade\Db;
use think\Request;
use think\facade\Log;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Crypto\AesGcm;
use WeChatPay\Formatter;
use app\common\controller\Api;
use app\common\facade\channel\Channel as ChannelServer;
use app\common\facade\channel\Pay as PayServer;
use app\common\model\CapitalFlow;
use app\common\model\CapitalFlow as CapitalFlowModel;
use app\common\model\MemberWallet;
use app\common\model\Attachment;
use app\common\model\Withdraw as WithdrawModel;
use app\common\logic\Withdraw as WithdrawLogic;

class Withdraw extends Api
{
    protected Request $request;
    protected WithdrawModel $WithdrawModel;
    protected WithdrawLogic $WithdrawLogic;
    protected CapitalFlowModel $CapitalFlowModel;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['except' => 'notify']
    ];

    function __construct(Request $request)
    {
        parent::__construct();
        $this->request = $request;
        $this->WithdrawModel = new WithdrawModel();
        $this->WithdrawLogic = new WithdrawLogic();
        $this->CapitalFlowModel = new CapitalFlowModel();
    }

    /**
     * @title 提现
     */
    public function withdraw()
    {
        $uid = get_uid();
        $price = input('price', '', 'text');
        $price = floatval($price);
        $price = intval($price * 100); // 单位转为分
        $channel = input('channel', 'weixin_h5', 'text');
        $pay_channel = input('pay_channel', 'weixin', 'text');

        Db::startTrans();
        try {
            $config = $this->WithdrawModel->getConfig(); //获取提现配置
            //是否开启提现
            if ($config['status'] < 1) throw new Exception('提现暂时关闭，如有特殊需求请联系客服');

            //初始化提现数据
            $data['shopid'] =   $this->shopid;
            $data['uid']    =   $uid;
            $data['price']  =   $price;
            $data['order_no']   =   build_order_no(); //生成提现单号
            $data['channel']    =   $channel;
            $data['pay_channel'] = $pay_channel;
            $data['error']  =   0;
            $data['paid']  =   0;

            //获取用户信息
            $user_info = query_user($uid);
            if ($user_info == -1) throw new Exception('用户数据不存在');

            //扣除平台手续费后，实际到账金额
            $rate = floatval($config['tax_rate']) / 1000;
            $deduct_money = intval($data['price'] * $rate);
            $data['real_price'] = intval(ceil(($data['price'] - $deduct_money))); //单位分
            
            //最低金额
            if ($data['price'] < intval($config['min_price']) * 100) throw new Exception('提现金额最少为' . $config['min_price'] . '元');
            //最大金额
            if ($data['price'] > intval($config['max_price']) * 100) throw new Exception('提现金额最多为' . $config['max_price'] . '元');

            //查询今日提现次数
            $today_unixtime = dayTime(); //今日时间戳
            $check_map = [
                ['shopid', '=', $this->shopid],
                ['uid', '=', $uid],
                ['create_time', 'between', [$today_unixtime[0], $today_unixtime[1]]]
            ];
            $withdraw_order_total = $this->WithdrawModel->where($check_map)->count();
            if ($withdraw_order_total >= $config['day_num']) throw new Exception('每日最多可提现' . $config['day_num'] . '次');

            //获取用户openid
            $openid = get_openid($this->shopid, $uid, $channel);
            if (!$openid)   throw new Exception('用户未绑定微信');

            //用户钱包模型
            $WalletModel = new MemberWallet();
            $wallet = $WalletModel->where('uid', $uid)->find()->toArray();
            if (intval($wallet['balance']) < $data['price']) {
                throw new Exception('账户余额不足');
            }
            //冻结资金
            $WalletModel->freeze($this->shopid, $uid, $data['price']);

            //提现记录
            $withdraw_id = $this->WithdrawModel->edit($data);
            if (!$withdraw_id)  throw new Exception('网络异常，请稍后再试');

            // 发起提现
            $pay_config = ChannelServer::config($channel, $this->shopid);
            $PayService = PayServer::init($pay_config['appid'], $pay_channel);

            if (!$PayService || !$PayService->server) {
                throw new Exception('支付服务初始化失败');
            }

            // 提现接口
            $withdraw_api = config('extend.WX_PAY_WITHDRAW_API');
            if ($withdraw_api == 'v2') {
                $result = $PayService->server->toBalance([
                    'check_name' => 'NO_CHECK',
                    'partner_trade_no'  =>  $data['order_no'],
                    'openid'    =>  $openid,
                    'amount'    =>  $data['real_price'],
                    'desc'      =>  '提现'
                ]);

                $return_result = $result;
            }

            if ($withdraw_api == 'v3') {
                // $result = $PayService->server->toBalanceV3([
                //     'appid'                 => $pay_config['appid'],
                //     'out_batch_no'          => $data['order_no'], //商户系统内部的商家批次单号，要求此参数只能由数字、大小写字母组成，在商户系统内部唯一,
                //     'batch_name'            => '用户提现',       //该笔批量转账的名称
                //     'batch_remark'          => 'uid:' . $data['uid'] . "-" . '提现', //转账说明，UTF8编码，最多允许32个字符
                //     'total_amount'          => $data['price'], //转账总金额 单位为“分”
                //     'total_num'             => 1,
                //     'transfer_detail_list'  => [
                //         [
                //             'out_detail_no'     => $data['order_no'],
                //             'transfer_amount'   => $data['price'],
                //             'transfer_remark'   => $user_info['nickname'] . '(uid:' . $data['uid'] . ')' . '主动提现',
                //             'openid'            => $openid,
                //             //'user_name'         => $encryptor($params['name']) // 金额超过`2000`才填写
                //         ]
                //     ]
                // ]);
                $scenc_id = config('extend.WITHDRAW_TRANSFER_SCENE_ID');
                if ($scenc_id == 1000) {
                    $transfer_scene_report_infos = [
                        [
                            "info_type" =>   "活动名称",
                            "info_content" => "会员奖励"
                        ],
                        [
                            "info_type" =>   "奖励说明",
                            "info_content" => "用户打卡、分享奖励"
                        ]
                    ];
                }
                if ($scenc_id == 1005) {
                    $transfer_scene_report_infos = [
                        [
                            "info_type" =>   "岗位类型",
                            "info_content" => "分享员"
                        ],
                        [
                            "info_type" =>   "报酬说明",
                            "info_content" => "用户分享佣金"
                        ]
                    ];
                }

                if($scenc_id != 1000 && $scenc_id != 1005){
                    return $this->success('商家转账场景ID填写有误，请联系管理员');
                }

                $options = [
                    'appid'                 => $pay_config['appid'],
                    'out_bill_no'           => $data['order_no'], //商户系统内部的商家批次单号，要求此参数只能由数字、大小写字母组成，在商户系统内部唯一,
                    'transfer_scene_id'     => $scenc_id, // 转账场景ID 该笔转账使用的转账场景，可前往“商户平台-产品中心-商家转账”中申请。如：1000（现金营销），1006（企业报销）等
                    'openid'                => $openid,
                    'transfer_amount'       => $data['real_price'], //转账金额 单位为“分
                    'transfer_remark'       => '用户ID:' . $data['uid'] . "|" . '提现', //转账备注，用户收款时可见该备注信息，UTF8编码，最多允许32个字符
                    'notify_url'            => request()->doMain() . '/api/withdraw/notify', // 异步接收微信支付结果通知的回调地址，通知url必须为外网可访问的url，不能携带参数。
                    'transfer_scene_report_infos' => $transfer_scene_report_infos
                ];

                $result = $PayService->server->transferV3($options);

                // 返回示例
                // "status_code" => 200
                // "body" => array:5 [
                //     "create_time" => "2025-05-29T18:00:53+08:00"
                //     "out_bill_no" => "202505298287613722"
                //     "package_info" => "ABBQO+oYAAABAAAAAAADUhPSNnrx8xJ/VTA4aBAAAADnGpepZahT9IkJjn90+1qgsP0zLrKUHGgUOR/ri0T6AgUpYxHx/wpkEI120w8zFt1RQ5Ochg4ZpiYI1Jki7TMjTMGzFlznwcDasf2VUDJb2UrkmHY="
                //     "state" => "WAIT_USER_CONFIRM"
                //     "transfer_bill_no" => "1330001234218022505290017891803214"
                // ]

                if (is_array($result) && isset($result['errCode']) && $result['errCode'] == 0) throw new Exception($result['errMsg']);

                $return_result = $result['body'];
            }

            // 记录日志
            Log::write($result, 'notice');

            Db::commit();

            return $this->success('提现已提交，正在处理...', $return_result);
        } catch (Exception $e) {
            Db::rollback();
            return $this->error('提示：' . $e->getMessage());
        }
    }

    /**
     * 商家转账回调
     * 获取原始回调数据并记录日志
     * 
     * @return void
     */
    public function notify()
    {
        $header = $this->request->header();
        $inBody   = file_get_contents('php://input'); //读取微信传过来的信息，是一个json字符串
        Log::write($header, 'notice_v3_header');
        Log::write($inBody, 'notice_v3_body');
        
        // 平台证书验签
        Db::startTrans();
        try {
            if (empty($header) || empty($inBody)) {
                throw new \Exception('通知参数为空', 2001);
            }

            $inWechatpayTimestamp             = $header['wechatpay-timestamp'];
            $inWechatpayNonce                 = $header['wechatpay-nonce'];
            $inWechatpaySignature             = $header['wechatpay-signature'];
            $inWechatpaySerial                = $header['wechatpay-serial'];
            if (empty($inWechatpayTimestamp) || empty($inWechatpayNonce) || empty($inWechatpaySignature) || empty($inWechatpaySerial)) {
                throw new \Exception('通知头参数为空', 2002);
            }

            // 兼容验签：根据 Wechatpay-Serial 判断使用平台证书还是公钥
            // 公钥ID以 "PUB_KEY_ID_" 开头，平台证书序列号为纯十六进制
            $isPublicKeySerial = (strpos($inWechatpaySerial, 'PUB_KEY_ID_') === 0);

            if ($isPublicKeySerial) {
                // 使用微信支付公钥验签
                $publicKeySerial = config('extend.WX_PAY_PLATFORM_PUBLIC_KEY_SERIAL', '');
                $publicKeyPath = app()->getRootPath() . 'public/attachment/' . config('extend.WX_PAY_PLATFORM_PUBLIC_KEY');

                if (empty($publicKeySerial) || empty($publicKeyPath)) {
                    throw new \Exception('回调使用公钥签名但未配置微信支付公钥', 2006);
                }
                if ($publicKeySerial !== $inWechatpaySerial) {
                    throw new \Exception('回调公钥ID与配置不匹配', 2007);
                }

                if (file_exists($publicKeyPath)) {
                    $platformPublicKeyInstance = Rsa::from('file://' . $publicKeyPath, Rsa::KEY_TYPE_PUBLIC);
                } else {
                    $platformPublicKeyInstance = $this->loadPublicKeyFromRemote($publicKeyPath);
                }
            } else {
                // 使用平台证书验签
                $platform_serial  =  config('extend.WX_PAY_WITHDRAW_PLATFORM_SERIAL');
                if ($platform_serial != $inWechatpaySerial) {
                    throw new \Exception('验签失败', 2005);
                }

                // 平台证书路径
                $pingtai_public_key_path = app()->getRootPath() . 'public/attachment/cert/wechatpay_' . $platform_serial . '.pem';
                $platformPublicKeyInstance = Rsa::from('file://' . $pingtai_public_key_path, Rsa::KEY_TYPE_PUBLIC);
            }

            $apiv3Key = config('extend.WX_PAY_KEY_SECRET'); // 在商户平台上设置的APIv3密钥

            // 检查通知时间偏移量，允许5分钟之内的偏移
            $timeOffsetStatus = 300 >= abs(Formatter::timestamp() - (int)$inWechatpayTimestamp);
            $verifiedStatus = Rsa::verify(
                // 构造验签名串
                Formatter::joinedByLineFeed($inWechatpayTimestamp, $inWechatpayNonce, $inBody),
                $inWechatpaySignature,
                $platformPublicKeyInstance
            );
            if (!$timeOffsetStatus || !$verifiedStatus) {
                throw new \Exception('验签失败：时间偏移=' . ($timeOffsetStatus ? 'ok' : 'invalid') . ' 签名=' . ($verifiedStatus ? 'ok' : 'invalid'), 2003);
            }

            // 转换通知的JSON文本消息为PHP Array数组
            $inBodyArray = (array)json_decode($inBody, true);
            // 使用PHP7的数据解构语法，从Array中解构并赋值变量
            ['resource' => [
                'ciphertext'      => $ciphertext,
                'nonce'           => $nonce,
                'associated_data' => $aad
            ]] = $inBodyArray;
            // 加密文本消息解密
            $inBodyResource = AesGcm::decrypt($ciphertext, $apiv3Key, $nonce, $aad);
            // 把解密后的文本转换为PHP Array数组
            $inBodyResourceArray = (array)json_decode($inBodyResource, true);
            Log::write($inBodyResourceArray, 'notice');
            //执行自己的代码start
            $data = $this->WithdrawModel->where('order_no', $inBodyResourceArray['out_bill_no'])->find();
            $cash_with = true;
            if ($inBodyResourceArray['state'] == 'SUCCESS') {
                // 扣除冻结余额
                (new MemberWallet())->minusFreeze($data['shopid'], $data['uid'], $data['price']);

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
                if (!$result_capital_flow)  throw new Exception('写入资金流水失败');

                //更改提现记录状态
                $submit_data = [
                    'id'        => $data['id'],
                    'paid'      => 1,
                    'paid_time' => time(),
                ];
                $cash_with = $this->WithdrawModel->edit($submit_data);
            }

            if ($inBodyResourceArray['state'] == 'FAIL') {
                //解冻冻结资金(返还至用户余额)
                (new MemberWallet())->freeze($data['shopid'], $data['uid'], $data['price'], 0);
                //付款到零钱失败
                $submit_data = [
                    'id'     => $data['id'],
                    'error'  => 1,
                    'error_msg'  => '转账失败'
                ];
                $cash_with = $this->WithdrawModel->edit($submit_data);
            }
            if (!$cash_with) {
                throw new Exception('数据处理失败,请稍后再试');
            }
            Db::commit();
            //执行自己的代码end

            return json([
                "code" => "SUCCESS", 
                "message" => ""
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            Log::error($e->getMessage());
            return json([
                "code" => "FAIL", 
                "message" => '失败'
            ], 500);
        }
    }

    /**
     * 从远程存储加载公钥（文件不在本地时兜底）
     */
    protected function loadPublicKeyFromRemote($localPath)
    {
        $attachmentPath = str_replace(app()->getRootPath() . 'public/attachment/', '', $localPath);

        $attachModel = new Attachment();
        $attachData = $attachModel->where('attachment', $attachmentPath)->find();

        if (!$attachData) {
            throw new \Exception('公钥文件不存在，且未找到对应的附件记录：' . $localPath);
        }

        $driver = $attachData['driver'];

        if ($driver === 'cos') {
            return $this->loadPublicKeyFromCos($attachmentPath);
        }

        if ($driver === 'oss') {
            return $this->loadPublicKeyFromOss($attachmentPath);
        }

        throw new \Exception('不支持的附件存储驱动：' . $driver);
    }

    /**
     * 从腾讯云COS加载公钥
     */
    protected function loadPublicKeyFromCos($attachmentPath)
    {
        $secretId = config('extend.COS_TENCENT_SECRETID');
        $secretKey = config('extend.COS_TENCENT_SECRETKEY');
        $region = config('extend.COS_TENCENT_REGION');
        $bucket = config('extend.COS_TENCENT_BUCKET');

        if (empty($secretId) || empty($secretKey) || empty($region) || empty($bucket)) {
            throw new \Exception('腾讯云COS配置不完整，无法加载远程公钥文件');
        }

        $cosClient = new \Qcloud\Cos\Client([
            'region' => $region,
            'schema' => 'http',
            'credentials' => [
                'secretId'  => $secretId,
                'secretKey' => $secretKey,
            ],
        ]);

        try {
            $key = 'attachment/' . $attachmentPath;
            $result = $cosClient->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
            ]);
            $content = (string)$result['Body'];
            return Rsa::from($content, Rsa::KEY_TYPE_PUBLIC);
        } catch (\Exception $e) {
            throw new \Exception('从腾讯云COS下载公钥文件失败：' . $e->getMessage());
        }
    }

    /**
     * 从阿里云OSS加载公钥
     */
    protected function loadPublicKeyFromOss($attachmentPath)
    {
        $accessKeyId = config('extend.OSS_ALIYUN_ACCESSKEYID');
        $accessKeySecret = config('extend.OSS_ALIYUN_ACCESSKEYSECRET');
        $endpoint = config('extend.OSS_ALIYUN_ENDPOINT');
        $bucket = config('extend.OSS_ALIYUN_BUCKET');

        if (empty($accessKeyId) || empty($accessKeySecret) || empty($endpoint) || empty($bucket)) {
            throw new \Exception('阿里云OSS配置不完整，无法加载远程公钥文件');
        }

        try {
            $ossClient = new \OSS\OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $key = 'attachment/' . $attachmentPath;
            $content = $ossClient->getObject($bucket, $key);
            return Rsa::from($content, Rsa::KEY_TYPE_PUBLIC);
        } catch (\Exception $e) {
            throw new \Exception('从阿里云OSS下载公钥文件失败：' . $e->getMessage());
        }
    }
}