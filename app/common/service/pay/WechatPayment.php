<?php

namespace app\common\service\pay;

use EasyWeChat\Factory;
use WeChatPay\Builder;
use WeChatPay\Crypto\Rsa;
use WeChatPay\Util\PemUtil;
use think\Exception;
use think\facade\Log;
use app\common\model\Attachment;

class WechatPayment extends PayService
{
    protected $v3Instance;
    protected $useV3;

    function __construct($appid)
    {
        $this->type = 'wechat';
        $this->sandbox = false;
        $this->config = $this->initConfig($appid);

        // 是否使用 v3 接口, 默认开启
        $this->useV3 = config('extend.WX_PAY_USE_V3', true);

        if ($this->useV3) {
            parent::__construct(null);
        } else {
            $app = Factory::payment($this->config);
            parent::__construct($app);
        }
    }

    /**
     * 初始化配置
     */
    public function initConfig($appid)
    {
        $mchid = config('extend.WX_PAY_MCH_ID');
        $key = config('extend.WX_PAY_KEY_SECRET');
        $serial = config('extend.WX_PAY_CERT_SERIAL');
        $platform_serial = config('extend.WX_PAY_WITHDRAW_PLATFORM_SERIAL');
        $platform_mode = config('extend.WX_PAY_PLATFORM_MODE', 'cert');
        $platform_public_key_serial = config('extend.WX_PAY_PLATFORM_PUBLIC_KEY_SERIAL', '');

        if (empty($mchid)) {
            throw new Exception('请填写商户ID');
        }
        if (empty($key)) {
            throw new Exception('请填写商户密钥');
        }
        if (empty($serial)) {
            throw new Exception('请填写商户API证书序列号');
        }

        $config = [
            'app_id' => $appid,
            'mch_id' => $mchid,
            'key' => $key,
            'serial' => $serial,
            'cert_path' => app()->getRootPath() . 'public/attachment/' . config('extend.WX_PAY_CERT'),
            'key_path' => app()->getRootPath() . 'public/attachment/' . config('extend.WX_PAY_KEY'),
            'platform_serial' => $platform_serial,
            'platform_mode' => $platform_mode,
            'notify_url' => request()->domain() . "/api/pay/callback",
            'sandbox' => $this->sandbox,
        ];

        if ($platform_mode === 'public_key') {
            $config['platform_public_key_serial'] = $platform_public_key_serial;
            $config['platform_public_key_path'] = app()->getRootPath()
                . 'public/attachment/' . config('extend.WX_PAY_PLATFORM_PUBLIC_KEY');
        }

        return $config;
    }

    /**
     * 获取 v3 API 实例
     * 支持平台证书模式(cert)和平台公钥模式(public_key)
     */
    protected function getV3Instance()
    {
        if ($this->v3Instance) {
            return $this->v3Instance;
        }

        $merchantId = $this->config['mch_id'];
        $merchantPrivateKeyFilePath = 'file://' . $this->config['key_path'];
        $merchantPrivateKeyInstance = Rsa::from($merchantPrivateKeyFilePath, Rsa::KEY_TYPE_PRIVATE);
        $merchantCertificateSerial = $this->config['serial'];

        if ($this->config['platform_mode'] === 'public_key') {
            $publicKeyPath = $this->config['platform_public_key_path'] ?? '';
            $publicKeySerial = $this->config['platform_public_key_serial'] ?? '';

            if (empty($publicKeyPath)) {
                throw new Exception('公钥模式配置不完整：未上传微信支付公钥文件(WX_PAY_PLATFORM_PUBLIC_KEY)');
            }
            if (empty($publicKeySerial)) {
                throw new Exception('公钥模式配置不完整：未填写微信支付公钥ID(WX_PAY_PLATFORM_PUBLIC_KEY_SERIAL)');
            }

            $certs = [];

            if (file_exists($publicKeyPath)) {
                $certs[$publicKeySerial] = Rsa::from('file://' . $publicKeyPath, Rsa::KEY_TYPE_PUBLIC);
            } else {
                $certs[$publicKeySerial] = $this->loadPublicKeyFromRemote($publicKeyPath);
            }

            $platformSerial = $publicKeySerial;
        } else {
            $certs = [];
            $platformFilePath = 'file://' . app()->getRootPath()
                . 'public/attachment/cert/wechatpay_' . $this->config['platform_serial'] . '.pem';
            $platformSerial = PemUtil::parseCertificateSerialNo($platformFilePath);
            $certs[$platformSerial] = Rsa::from($platformFilePath, Rsa::KEY_TYPE_PUBLIC);
        }

        $this->v3Instance = Builder::factory([
            'mchid' => $merchantId,
            'serial' => $merchantCertificateSerial,
            'privateKey' => $merchantPrivateKeyInstance,
            'certs' => $certs,
        ]);

        return $this->v3Instance;
    }

    /**
     * 从远程存储加载公钥内容
     */
    protected function loadPublicKeyFromRemote($localPath)
    {
        $filename = basename($localPath);
        $attachmentPath = str_replace(app()->getRootPath() . 'public/attachment/', '', $localPath);

        $attachModel = new Attachment();
        $attachData = $attachModel->where('attachment', $attachmentPath)->find();

        if (!$attachData) {
            throw new Exception('公钥文件不存在，且未找到对应的附件记录：' . $localPath);
        }

        $driver = $attachData['driver'];

        if ($driver === 'cos') {
            return $this->loadPublicKeyFromCos($attachmentPath);
        }

        if ($driver === 'oss') {
            return $this->loadPublicKeyFromOss($attachmentPath);
        }

        throw new Exception('不支持的附件存储驱动：' . $driver);
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
            throw new Exception('腾讯云COS配置不完整，无法加载远程公钥文件');
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
            throw new Exception('从腾讯云COS下载公钥文件失败：' . $e->getMessage());
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
            throw new Exception('阿里云OSS配置不完整，无法加载远程公钥文件');
        }

        try {
            $ossClient = new \OSS\OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $key = 'attachment/' . $attachmentPath;
            $content = $ossClient->getObject($bucket, $key);
            return Rsa::from($content, Rsa::KEY_TYPE_PUBLIC);
        } catch (\Exception $e) {
            throw new Exception('从阿里云OSS下载公钥文件失败：' . $e->getMessage());
        }
    }

    /**
     * 支付
     * @param $data 数据
     * @param string $trade_type 支付类型
     * @return mixed
     */
    public function pay($data, $trade_type = 'JSAPI')
    {
        if ($this->useV3) {
            return $this->payV3($data, $trade_type);
        }
        return $this->payV2($data, $trade_type);
    }

    /**
     * v2 支付
     */
    protected function payV2($data, $trade_type = 'JSAPI')
    {
        $data['trade_type'] = $trade_type;
        if (!empty($notify_url)) {
            $data['notify_url'] = $notify_url;
        }
        $res = $this->app->order->unify($data);
        if ($res['return_code'] == 'FAIL') {
            throw new Exception($res['return_msg']);
        }
        if ($res['result_code'] == 'FAIL') {
            throw new Exception($res['err_code'] . ':' . $res['err_code_des']);
        }
        if ($trade_type == 'JSAPI') {
            $res = $this->app->jssdk->sdkConfig($res['prepay_id']);
        }

        return $res;
    }

    /**
     * v3 支付
     */
    protected function payV3($data, $trade_type = 'JSAPI')
    {
        switch ($trade_type) {
            case 'JSAPI':
                return $this->payJsapiV3($data);
            case 'NATIVE':
                return $this->payNativeV3($data);
            case 'MWEB':
                return $this->payMwebV3($data);
            default:
                throw new Exception('不支持的支付类型');
        }
    }

    /**
     * JSAPI 支付 v3
     */
    protected function payJsapiV3($data)
    {
        try {
            $instance = $this->getV3Instance();

            $payload = [
                'appid' => $data['appid'] ?? $this->config['app_id'],
                'mchid' => $this->config['mch_id'],
                'description' => $data['body'],
                'out_trade_no' => $data['out_trade_no'],
                'notify_url' => $data['notify_url'],
                'amount' => [
                    'total' => $data['total_fee'],
                    'currency' => 'CNY'
                ],
                'payer' => [
                    'openid' => $data['openid']
                ]
            ];

            $resp = $instance
                ->chain('v3/pay/transactions/jsapi')
                ->post(['json' => $payload]);

            $result = json_decode($resp->getBody(), true);

            if ($resp->getStatusCode() != 200) {
                throw new Exception($result['message'] ?? '统一下单失败');
            }

            return $this->buildJsapiConfig($result['prepay_id']);

        } catch (\Exception $e) {
            throw new Exception('JSAPI支付失败: ' . $e->getMessage());
        }
    }

    /**
     * Native 支付 v3
     */
    protected function payNativeV3($data)
    {
        try {
            $instance = $this->getV3Instance();

            $payload = [
                'appid' => $data['appid'] ?? $this->config['app_id'],
                'mchid' => $this->config['mch_id'],
                'description' => $data['body'],
                'out_trade_no' => $data['out_trade_no'],
                'notify_url' => $data['notify_url'],
                'amount' => [
                    'total' => $data['total_fee'],
                    'currency' => 'CNY'
                ]
            ];

            $resp = $instance
                ->chain('v3/pay/transactions/native')
                ->post(['json' => $payload]);

            $result = json_decode($resp->getBody(), true);

            if ($resp->getStatusCode() != 200) {
                throw new Exception($result['message'] ?? '统一下单失败');
            }

            return [
                'code_url' => $result['code_url']
            ];

        } catch (\Exception $e) {
            throw new Exception('Native支付失败: ' . $e->getMessage());
        }
    }

    /**
     * MWEB 支付 v3
     */
    protected function payMwebV3($data)
    {
        try {
            $instance = $this->getV3Instance();

            $payload = [
                'appid' => $data['appid'] ?? $this->config['app_id'],
                'mchid' => $this->config['mch_id'],
                'description' => $data['body'],
                'out_trade_no' => $data['out_trade_no'],
                'notify_url' => $data['notify_url'],
                'amount' => [
                    'total' => $data['total_fee'],
                    'currency' => 'CNY'
                ],
                'scene_info' => [
                    'payer_client_ip' => request()->ip(),
                    'h5_info' => [
                        'type' => 'Wap'
                    ]
                ]
            ];

            $resp = $instance
                ->chain('v3/pay/transactions/h5')
                ->post(['json' => $payload]);

            $result = json_decode($resp->getBody(), true);

            if ($resp->getStatusCode() != 200) {
                throw new Exception($result['message'] ?? '统一下单失败');
            }

            return [
                'h5_url' => $result['h5_url']
            ];

        } catch (\Exception $e) {
            throw new Exception('H5支付失败: ' . $e->getMessage());
        }
    }

    /**
     * 构建 JSAPI 调起参数
     */
    protected function buildJsapiConfig($prepay_id)
    {
        $appid = $this->config['app_id'];
        $mchid = $this->config['mch_id'];
        $nonceStr = $this->generateNonceStr();
        $timestamp = time();
        $package = 'prepay_id=' . $prepay_id;

        $sign = $this->buildSignForJsapi($appid, $mchid, $nonceStr, $timestamp, $package);

        return [
            'appId' => $appid,
            'timeStamp' => (string)$timestamp,
            'nonceStr' => $nonceStr,
            'package' => $package,
            'signType' => 'RSA',
            'paySign' => $sign
        ];
    }

    /**
     * JSAPI 签名 (v3)
     */
    protected function buildSignForJsapi($appid, $mchid, $nonceStr, $timestamp, $package)
    {
        $message = $appid . "\n" . $timestamp . "\n" . $nonceStr . "\n" . $package . "\n";

        $privateKey = Rsa::from('file://' . $this->config['key_path'], Rsa::KEY_TYPE_PRIVATE);
        return Rsa::sign($message, $privateKey, OPENSSL_ALGO_SHA256);
    }

    /**
     * 生成随机字符串
     */
    protected function generateNonceStr($length = 32)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * @title 退款
     * @param $refund_info
     * @return bool
     * @throws Exception
     */
    public function refund($refund_info)
    {
        if ($this->useV3) {
            return $this->refundV3($refund_info);
        }
        return $this->refundV2($refund_info);
    }

    /**
     * v2 退款
     */
    protected function refundV2($refund_info)
    {
        $result = $this->app->refund->byOutTradeNumber($refund_info['order_no'], $refund_info['refund_no'], $refund_info['total_fee'], $refund_info['refund_fee'], [
            'refund_desc' => $refund_info['title']
        ]);
        return $result;
    }

    /**
     * v3 退款
     */
    protected function refundV3($refund_info)
    {
        try {
            $instance = $this->getV3Instance();

            $payload = [
                'out_trade_no' => $refund_info['order_no'],
                'out_refund_no' => $refund_info['refund_no'],
                'amount' => [
                    'refund' => $refund_info['refund_fee'],
                    'total' => $refund_info['total_fee'],
                    'currency' => 'CNY'
                ],
                'reason' => $refund_info['title'] ?? '用户申请退款'
            ];

            $resp = $instance
                ->chain('v3/refund/domestic/refunds')
                ->post(['json' => $payload]);

            $result = json_decode($resp->getBody(), true);

            return [
                'return_code' => 'SUCCESS',
                'result_code' => 'SUCCESS',
                'data' => $result
            ];

        } catch (\Exception $e) {
            return [
                'return_code' => 'FAIL',
                'errMsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 关闭订单
     * @param string $order_no 商户订单号
     * @return mixed
     */
    public function close($order_no)
    {
        if ($this->useV3) {
            return $this->closeV3($order_no);
        }
        return $this->closeV2($order_no);
    }

    /**
     * v2 关闭订单
     */
    protected function closeV2($order_no)
    {
        return $this->app->order->close($order_no);
    }

    /**
     * v3 关闭订单
     */
    protected function closeV3($order_no)
    {
        try {
            $instance = $this->getV3Instance();

            $payload = [
                'mchid' => $this->config['mch_id']
            ];

            $resp = $instance
                ->chain('v3/pay/transactions/out-trade-no/' . $order_no . '/close')
                ->post(['json' => $payload]);

            return json_decode($resp->getBody(), true);

        } catch (\Exception $e) {
            return ['return_code' => 'FAIL', 'errMsg' => $e->getMessage()];
        }
    }
    public function notify($params)
    {
        if ($this->useV3) {
            return $this->notifyV3($params);
        }
        return $this->notifyV2($params);
    }

    /**
     * v2 回调
     */
    protected function notifyV2($params)
    {
        if ($params['return_code'] == 'SUCCESS' && $params['result_code'] == 'SUCCESS') {
            return $params['out_trade_no'];
        }
        return false;
    }

    /**
     * v3 回调
     */
    protected function notifyV3($params)
    {
        if (isset($params['trade_state']) && $params['trade_state'] == 'SUCCESS') {
            return $params['out_trade_no'];
        }
        return false;
    }

    /**
     * 解密回调通知
     */
    public function decryptNotify($notify_data)
    {
        try {
            $json = json_decode($notify_data, true);

            $ciphertext = $json['resource']['ciphertext'];
            $nonce = $json['resource']['nonce'];
            $associated_data = $json['resource']['associated_data'];

            $plaintext = $this->decryptToString(
                $associated_data,
                $nonce,
                $ciphertext
            );

            return json_decode($plaintext, true);

        } catch (\Exception $e) {
            throw new Exception('回调解密失败: ' . $e->getMessage());
        }
    }

    /**
     * 验证 v3 回调签名
     * 支持平台证书模式(cert)和平台公钥模式(public_key)
     * @param string $body      原始请求体
     * @param array  $headers   HTTP 头信息
     * @return bool
     */
    public function verifySign($body, $headers)
    {
        $timestamp = $headers['wechatpay-timestamp'] ?? '';
        $nonce = $headers['wechatpay-nonce'] ?? '';
        $signature = $headers['wechatpay-signature'] ?? '';
        $serial = $headers['wechatpay-serial'] ?? '';
        $message = $timestamp . "\n" . $nonce . "\n" . $body . "\n";

        Log::write('v3验签诊断: timestamp=' . $timestamp
            . ' nonce=' . $nonce
            . ' signatureLen=' . strlen($signature)
            . ' serial=' . $serial
            . ' bodyLen=' . strlen($body)
            . ' bodyMd5=' . md5($body)
            . ' platform_mode=' . ($this->config['platform_mode'] ?? 'cert'));

        try {
            $platformPublicKeyInstance = null;

            if ($this->config['platform_mode'] === 'public_key') {
                $publicKeyPath = $this->config['platform_public_key_path'] ?? '';
                $publicKeySerial = $this->config['platform_public_key_serial'] ?? '';

                if (file_exists($publicKeyPath)) {
                    $platformPublicKeyInstance = Rsa::from('file://' . $publicKeyPath, Rsa::KEY_TYPE_PUBLIC);
                } else {
                    $platformPublicKeyInstance = $this->loadPublicKeyFromRemote($publicKeyPath);
                }
            } else {
                $certDir = app()->getRootPath() . 'public/attachment/cert/';
                $certPath = $certDir . 'wechatpay_' . $serial . '.pem';

                if (!file_exists($certPath) && !empty($this->config['platform_serial'])) {
                    $fallbackPath = $certDir . 'wechatpay_' . $this->config['platform_serial'] . '.pem';
                    Log::write('v3验签: HTTP-serial文件不存在, fallback到 ' . $fallbackPath);
                    if (file_exists($fallbackPath)) {
                        $certPath = $fallbackPath;
                    }
                }

                Log::write('v3验签: 证书路径=' . $certPath . ' 存在=' . (file_exists($certPath) ? 'true' : 'false'));

                if (!file_exists($certPath)) {
                    Log::write('平台证书文件不存在 HTTP-Serial:' . $serial . ' Config-Serial:' . ($this->config['platform_serial'] ?? '') . '，请执行证书下载');
                    return false;
                }

                $platformFilePath = 'file://' . $certPath;
                $platformPublicKeyInstance = Rsa::from($platformFilePath, Rsa::KEY_TYPE_PUBLIC);

                $pemSerial = PemUtil::parseCertificateSerialNo($platformFilePath);
                Log::write('v3验签: PEM内序列号=' . $pemSerial . ' HTTP-Serial=' . $serial . ' 匹配=' . ($pemSerial === $serial ? 'true' : 'false'));
                Log::write('v3验签: 证书文件MD5=' . md5_file($certPath));
            }

            $result = Rsa::verify($message, $signature, $platformPublicKeyInstance);
            Log::write('v3验签结果: ' . ($result ? '成功' : '失败') . ' serial=' . $serial);
            return $result;
        } catch (\Exception $e) {
            Log::write('v3 验签异常: ' . $e->getMessage() . ' HTTP-Serial:' . $serial);
            return false;
        }
    }

    /**
     * @title 商户订单号查询订单
     * @param $order_no
     * @return mixed
     */
    public function queryByOutTradeNumber($order_no)
    {
        if ($this->useV3) {
            return $this->queryByOutTradeNumberV3($order_no);
        }
        return $this->queryByOutTradeNumberV2($order_no);
    }

    /**
     * v2 商户订单号查询订单
     */
    protected function queryByOutTradeNumberV2($order_no)
    {
        return $this->app->order->queryByOutTradeNumber($order_no);
    }

    /**
     * v3 商户订单号查询订单
     */
    protected function queryByOutTradeNumberV3($order_no)
    {
        try {
            $instance = $this->getV3Instance();

            $resp = $instance
                ->chain('v3/pay/transactions/out-trade-no/' . $order_no)
                ->get([
                    'query' => ['mchid' => $this->config['mch_id']]
                ]);

            return json_decode($resp->getBody(), true);

        } catch (\Exception $e) {
            return ['return_code' => 'FAIL', 'errMsg' => $e->getMessage()];
        }
    }

    /**
     * @title 企业付款到零钱
     * @param $data
     * @return mixed
     */
    public function toBalance($data)
    {
        return $this->app->transfer->toBalance($data);
    }

    /**
     * 商家转账到零钱
     */
    public function toBalanceV3($data)
    {
        try {
            $instance = $this->getV3Instance();

            $resp = $instance
                ->chain('v3/transfer/batches')
                ->post(['json' => $data]);

            return [
                'return_code' => 'SUCCESS',
                'result_code' => 'SUCCESS',
                'status_code' => $resp->getStatusCode(),
                'body' => json_decode($resp->getBody(), true)
            ];
        } catch (\Exception $e) {
            return [
                'errCode' => 0,
                'errMsg' => $e->getMessage()
            ];
        }
    }

    /**
     * 商家转账
     */
    public function transferV3($data)
    {
        try {
            $instance = $this->getV3Instance();

            $resp = $instance
                ->chain('/v3/fund-app/mch-transfer/transfer-bills')
                ->post(['json' => $data]);

            return [
                'status_code' => $resp->getStatusCode(),
                'body' => json_decode($resp->getBody(), true)
            ];
        } catch (\Exception $e) {
            return [
                'errCode' => 0,
                'errMsg' => $e->getMessage()
            ];
        }
    }

    public function cancelTransfer($out_bill_no)
    {
        try {
            $instance = $this->getV3Instance();

            $resp = $instance
                ->chain("/v3/fund-app/mch-transfer/transfer-bills/out-bill-no/{$out_bill_no}/cancel")
                ->post();

            return [
                'status_code' => $resp->getStatusCode(),
                'body' => json_decode($resp->getBody(), true)
            ];
        } catch (\Exception $e) {
        }
    }

    /**
     * 获取APIv3微信支付平台证书
     * 首次手动下载命令（在vendor/wechatpay/wechatpay目录下执行）
     * composer exec CertificateDownloader.php -- -m 商户号 -s 商户API证书序列号 -f 商户的私钥文件 -k ApiV3Key -o 保存的路径
     * 完整示范
     * composer exec CertificateDownloader.php -- -m 1602403282 -s 1C5A97B726EB7EA5EC1212E0CEC14C758C1B427A -f /www/wwwroot/demo.t6.muucmf.cc/public/attachment/file/20230610/30b866b787758af61351edef055206e8.pem -k E0DBCB26C939DEA508A33988CEAFAE79 -o /www/wwwroot/demo.t6.muucmf.cc/public/attachment/cert
     */
    public function getFormCert()
    {
        $instance = $this->getV3Instance();

        $resp = $instance->chain('v3/certificates')->get(
            ['debug' => false]
        );

        $res = json_decode($resp->getBody(), true);

        if (is_array($res) && !empty($res['data'])) {
            foreach ($res['data'] as $v) {
                $cert_content = $this->decryptToString($v['encrypt_certificate']['associated_data'], $v['encrypt_certificate']['nonce'], $v['encrypt_certificate']['ciphertext']);

                $path = app()->getRootPath() . 'public/attachment/cert/wechatpay_' . $v['serial_no'] . '.pem';
                @file_put_contents($path, $cert_content);
                chmod($path, 0777);
            }
        }
    }

    const KEY_LENGTH_BYTE = 32;
    const AUTH_TAG_LENGTH_BYTE = 16;
    /**
     * Decrypt AEAD_AES_256_GCM ciphertext
     *
     * @param string    $associatedData     AES GCM additional authentication data
     * @param string    $nonceStr           AES GCM nonce
     * @param string    $ciphertext         AES GCM cipher text
     *
     * @return string|bool      Decrypted string on success or FALSE on failure
     */
    public function decryptToString($associatedData, $nonceStr, $ciphertext)
    {
        $ciphertext = \base64_decode($ciphertext);
        if (strlen($ciphertext) <= self::AUTH_TAG_LENGTH_BYTE) {
            return false;
        }

        // ext-sodium (default installed on >= PHP 7.2)
        if (function_exists('\sodium_crypto_aead_aes256gcm_is_available') && \sodium_crypto_aead_aes256gcm_is_available()) {
            return \sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $associatedData, $nonceStr, $this->config['key']);
        }

        // openssl (PHP >= 7.1 support AEAD)
        if (PHP_VERSION_ID >= 70100 && in_array('aes-256-gcm', \openssl_get_cipher_methods())) {
            $ctext = substr($ciphertext, 0, -self::AUTH_TAG_LENGTH_BYTE);
            $authTag = substr($ciphertext, -self::AUTH_TAG_LENGTH_BYTE);

            return \openssl_decrypt(
                $ctext,
                'aes-256-gcm',
                $this->config['key'],
                \OPENSSL_RAW_DATA,
                $nonceStr,
                $authTag,
                $associatedData
            );
        }

        throw new \RuntimeException('AEAD_AES_256_GCM需要PHP 7.1以上或者安装libsodium-php');
    }
}