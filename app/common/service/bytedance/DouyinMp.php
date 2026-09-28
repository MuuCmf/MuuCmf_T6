<?php
namespace app\common\service\bytedance;

use think\facade\Cache;
use app\common\model\DouyinMpConfig;
use app\common\model\Orders as OrdersModel;
use think\Exception;

class DouyinMp
{
    protected $title;
    protected $shopid;
    protected $appid;
    protected $secret;
    protected $token;
    protected $salt;
    protected $alipayMerchantUid;
    protected $weixinMerchantUid;
    protected $api;
    protected $config;
    protected $accessToken;

    /**
     * 构造配置项
     **/
    public function __construct()
    {
        // 沙箱环境
        //$this->api = 'https://open-sandbox.douyin.com';
        // 生产环境
        $this->api = 'https://developer.toutiao.com';
        //服务配置文件
        $config = $this->config = $this->initConfig();

        $this->title             = $config['title'];
        $this->appid             = $config['appid'];
        $this->secret            = $config['secret'];
        $this->salt              = $config['salt'];
        $this->token             = $config['token'];
        $this->alipayMerchantUid = $config['alipay_merchant_uid'];
        $this->weixinMerchantUid = $config['weixin_merchant_uid'];
    }

    public function initConfig()
    {
        $this->shopid = request()->param('shopid') ?? 0;
        //获取配置信息
        $map = [
            ['shopid' ,'=' ,$this->shopid],
        ];
        $data = (new DouyinMpConfig())->where($map)->find();
        if (empty($data)){
            throw  new Exception('小程序配置信息不存在');
        }
        $data = $data->toArray();
        return [
            'title' => $data['title'],
            'appid' => $data['appid'],
            'secret' => $data['secret'],
            'token' => $data['token'],
            'salt' => $data['salt'],
            'weixin_merchant_uid' => $data['weixin_merchant_uid'],
            'alipay_merchant_uid' => $data['alipay_merchant_uid']
        ];
    }

    /**
     * 获取access_token
     **/
    public function getAccessToken()
    {
        $access_token = Cache::get(request()->host() . '_bytedance_access_token_' . $this->shopid);
        if(!$access_token){
            $params = [
                'appid' => $this->appid,
                'secret' => $this->secret,
                'grant_type' => 'client_credential'
            ];
    
            $result = $this->sendPost('/api/apps/v2/token',$params);
            $result = json_decode($result, true);
            if(isset($result['err_no']) && $result['err_no'] == 0){
                Cache::set(request()->host() . '_bytedance_access_token_' . $this->shopid, $result['data']['access_token'], 3600);
                return $result['data']['access_token'];
            }
        }else{
            return $access_token;
        }
        
        return false;
    }

    /**
     * 获取session_key 和 openId。
     */
    public function code2Session($code, $anonymous_code)
    {
        $params = [
            'appid' => $this->appid,
            'secret' => $this->secret,
            'code' => $code,
            'anonymous_code' => $anonymous_code
        ];

        $access_token = $this->accessToken = $this->getAccessToken();
        if($access_token){
            $result = $this->sendPost('/api/apps/v2/jscode2session?access_token=' . $access_token, $params);
            
            return json_decode($result, true);
        }

        return false;
        
    }

    /**
     * 预下单接口
     */
    public function createOrder($params)
    {
        $params = $params;
        $params['app_id'] = $this->appid;
        $params['valid_time'] = 172800;
        $params['sign'] = $this->sign($params);

        $access_token = $this->accessToken = $this->getAccessToken();
        if($access_token){
            $result = $this->sendPost('/api/apps/ecpay/v1/create_order?access_token=' . $access_token, $params);
            
            return json_decode($result, true);
        }
    }

    /**
     * 订单推送
     */
    public function ordersPush($order_no)
    {
        // 获取订单数据
        $order_info = (new OrdersModel)->getDataByOrderNo($order_no);
        $products = json_decode($order_info['products'], true);
        // 获取用户openid
        $open_id = get_openid($this->shopid, $order_info['uid'] ,'douyin_mp');

        // 订单状态
        $status = $order_info['status'];
        if($status == 1){
            $status_str = '待支付';
            $order_status = 0;
        } 
        if($status == 2){
            $status_str = '已支付';
            $order_status = 1;
        } 
        if($status == 4 || $status == 5){
            $status_str = '已核销';
            $order_status = 4;
        } 
        if($status == 0){
            $status_str = '已取消';
            $order_status = 2;
        } 
        if($order_info['refund'] == 1 || $order_info['refund'] == 2 || $order_info['refund'] == 3){
            $status_str = '退款中';
            $order_status = 5;
        }
        if($order_info['refund'] == 4){
            $status_str = '已退款';
            $order_status = 6;
        }
        // 商品数量
        $quantity = 1;
        if(isset($products['quantity'])){
            $quantity = $products['quantity'];
        }

        // 子订单列表
        $item_list = [
            [
                'item_code' => $order_info['app'] .'_'. $order_info['order_info_type'] .'_'. $order_info['order_info_id'],
                'img' => get_thumb_image($products['cover'], 400, 400),
                'title' => $products['title'],
                'sub_title' => $products['description'],
                'amount' => intval($quantity),
                'price' => intval(($products['price'] * $quantity) * 100)
            ]
        ];
        // 订单详情
        $order_detail = [
            'order_id' => $order_no,
            'create_time' => intval($order_info['create_time'] . '000'),
            'status' => $status_str,
            'amount' => intval($quantity),
            'total_price' => intval($order_info['paid_fee']),
            'detail_url' => $order_info['app'] . '/' . $products['link']['url'] .'?'. http_build_query($products['link']['param']),
            'item_list' => $item_list
        ];

        // 组装请求数据
        $params['app_name'] = 'douyin';
        $params['open_id'] = $open_id;
        $params['order_detail'] = json_encode($order_detail);
        $params['order_status'] = intval($order_status);
        $params['order_type'] = 0;
        $params['update_time'] = intval($this->getMillisecond());
        $params['access_token'] = $this->getAccessToken();

        $result = $this->sendPost('/api/apps/order/v2/push',$params);
        return json_decode($result, true);
    }

    /**
     * 结算分账
     */
    public function settle($settle_no, $order_no)
    {   
        //$access_token = $this->getAccessToken();
        $params = [
            'app_id' => $this->appid,
            'out_settle_no' => $settle_no,
            'out_order_no' => $order_no,
            'settle_desc' => '主动结算',
        ];
        $params['sign'] = $this->sign($params);

        $result = $this->sendPost('/api/apps/ecpay/v1/settle',$params);
        return json_decode($result, true);
    }

    /**
     * 查询结算分账
     */
    public function settleQuery($settle_no)
    {   
        $params = [
            'app_id' => $this->appid,
            'out_settle_no' => $settle_no,
        ];
        $params['sign'] = $this->sign($params);

        $result = $this->sendPost('/api/apps/ecpay/v1/query_settle',$params);
        return json_decode($result, true);
    }

    /**
     * 商户余额查询
     * @param $channel_type 提现渠道枚举值:alipay: 支付宝wx: 微信 hz: 抖音支付
     */
    public function merchantbalance($channel_type)
    {
        if($channel_type == 'alipay'){
            $merchant_uid = $this->alipayMerchantUid;
        }
        if($channel_type == 'wx'){
            $merchant_uid = $this->weixinMerchantUid;
        }
        $params = [
            'app_id' => $this->appid,
            'merchant_uid' => $merchant_uid, // 进件完成返回的商户号
            'channel_type' => $channel_type, // 提现渠道枚举值:alipay: 支付宝wx: 微信 hz: 抖音支付
        ];
        $params['sign'] = $this->sign($params);

        $result = $this->sendPost('/api/apps/ecpay/saas/query_merchant_balance',$params);
        return json_decode($result, true);
    }

    /**
     * 商户提现
     */
    public function merchantWithdraw($withdraw_amount, $channel_type, $out_order_id)
    {
        if($channel_type == 'alipay'){
            $merchant_uid = $this->alipayMerchantUid;
        }
        if($channel_type == 'wx'){
            $merchant_uid = $this->weixinMerchantUid;
        }
        $params = [
            'app_id' => $this->appid,
            'merchant_uid' => $merchant_uid, // 进件完成返回的商户号
            'channel_type' => $channel_type, // 提现渠道枚举值:alipay: 支付宝wx: 微信 hz: 抖音支付
            'withdraw_amount' => $withdraw_amount, // 提现金额；单位分
            'out_order_id' => $out_order_id, // 外部单号（开发者侧）；唯一标识一笔提现请求
        ];
        $params['sign'] = $this->sign($params);

        $result = $this->sendPost('/api/apps/ecpay/saas/merchant_withdraw',$params);
        return json_decode($result, true);
    }

    public function post($url, $data = [], $second = 30, $header = [])
    {
        $curl = curl_init();

        if (stripos($url, "https") === 0) {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_SSLVERSION, 1);
        }
        curl_setopt($curl, CURLOPT_TIMEOUT, $second);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        if (!empty($header)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        }
        list($content, $status) = [curl_exec($curl), curl_getinfo($curl), curl_close($curl)];
        return (intval($status["http_code"]) === 200) ? $content : false;
    }

    /**
     * 生成二维码
     */
    public function createQRCode($path)
    {
        $params = [
            'access_token' => $this->getAccessToken(),
            'appname' => 'douyin',
            'path' => $path,
        ];

        return $this->sendPost('/api/apps/qrcode',$params);
    }

    /**
     * 请求签名
     */
    public function sign($map)
    {
        $rList = array();
        foreach($map as $k =>$v) {
            if ($k == "other_settle_params" || $k == "app_id" || $k == "sign" || $k == "thirdparty_id")
                continue;
            $value = trim(strval($v));
            $len = strlen($value);
            if ($len > 1 && substr($value, 0,1)=="\"" && substr($value,$len, $len-1)=="\"")
                $value = substr($value,1, $len-1);
            $value = trim($value);
            if ($value == "" || $value == "null")
                continue;
            array_push($rList, $value);
        }
        array_push($rList, $this->salt);
        sort($rList, 2);
        return md5(implode('&', $rList));
    }

    /**
     * 回调验签
     * @param array $map 验签参数
     * @return stirng
    */
    public function handler($map){
        $rList = array();
        array_push($rList, $this->token);
        foreach($map as $k =>$v) {
            if ( $k == "type" || $k=='msg_signature')
                continue;
            $value = trim(strval($v));
            if ($value == "" || $value == "null")
                continue;
            array_push($rList, $value);
        }
        sort($rList,2);
        return sha1(implode($rList));
    }

    /**
     * 渠道回调数据返回
     */
    public function returnMsg($code = 0, $msg = 'success')
    {
        return json([
            'err_no' => $code,
            'err_tips' => $msg
        ]);
    }

    /**
     * post请求
     **/
    protected function sendPost($url,$data)
    {
        $post_data = json_encode($data);
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-type:application/json',
                'content' => $post_data,
                'timeout' => 15 * 60 // 超时时间（单位:s）
            )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($this->api.$url, false, $context);
        return $result;
    }

    /**
     * 13位时间戳
     */
    protected function getMillisecond() { 
        list($t1, $t2) = explode(' ', microtime()); 
        return (int)sprintf('%.0f',(floatval($t1)+floatval($t2))*1000); 
    } 
}