<?php

namespace app\common\logic;

use app\common\logic\Channel;
use app\common\model\Evaluate as EvaluateModel;

class Orders extends Base
{
    public $shipper = [
        'SF' => '顺丰快递',
        'HTKY' => '百世快递',
        'ZTO' => '中通快递',
        'STO' => '申通快递',
        'YTO' => '圆通速递',
        'YD' => '韵达速递',
        'YZPY' => '邮政快递包裹',
        'EMS' => 'EMS',
        'HHTT' => '天天快递',
        'JD' => '京东快递'
    ];

    /**
     * 订单通用状态
     * @var array
     */
    public array $_status = [
        1 => '待付款',
        2 => '待发货',
        3 => '待收货',
        4 => '待评价', //确认收货
        5 => '已完成', //已完成评价
        0 => '已取消',
        -1 => '已删除'
    ];

    /**
     * 支付状态
     * @var string[]
     */
    protected $_paid = [
        0 => '未支付',
        1 => '已支付'
    ];

    /**
     * 退款状态
     * @var string[]
     */
    public $_refund = [
        -1 => '拒绝退款',
        0 => '未申请退款',
        1 => '退款申请中',
        2 => '退货中',
        3 => '已退货',
        4 => '退款完成',
        //5 => '已完成',
    ];

    /**
     * 支付渠道
     * @var [type]
     */
    public $_pay_channel = [
        'weixin' => '微信',
        'alipay' => '支付宝',
        'douyin' => '抖音',
        'baidu'  => '百度',
        'offline' => '线下支付',
        'score' => '积分',
        'convert' => '兑换码',
        'password' => '密码',
        'admin' => '后台手动设置',
        '' => '未知'
    ];

    /**
     * 格式化数据
     */
    public function formatData($data)
    {
        if($data['app'] == 'vip' || $data['order_info_type'] == 'vipcard'){
            $order_namespace = "app\\common\\logic\\Orders";
            if (class_exists($order_namespace)) {
                $appOrdersLogic = new $order_namespace;
                $data = $appOrdersLogic->vipFormatData($data);
            }
        }else{
            $order_namespace = "app\\{$data['app']}\\logic\\Orders";
            if (class_exists($order_namespace)) {
                $appOrdersLogic = new $order_namespace;
                $data = $appOrdersLogic->formatData($data);
            }
        }
        
        return $data;
    }

    /**
     * 格式化VIP订单数据
     * 
     * 1. 转换对象为数组（如果输入是对象）
     * 2. 添加状态、退款状态、渠道等文字描述
     * 3. 格式化用户信息、价格和时间字段
     * 4. 处理商品信息（包括封面图、价格等）
     * 5. 检查评价状态并添加评价信息
     * 
     * @param array|object $data 原始订单数据
     * @return array 格式化后的订单数据
     */
    public function vipFormatData($data)
    {
        if (is_object($data)) {
            $data = $data->toArray();
        }
        //订单状态
        $data['status_str'] = $this->_status[$data['status']];
        //售后退款状态
        $data['refund_str'] = $this->_refund[$data['refund']];
        //来源渠道
        $data['channel_str'] = Channel::$_channel[$data['channel']];
        //支付渠道
        $data['pay_channel_str'] = $this->_pay_channel[$data['pay_channel']];
        //买家信息
        $data['user_info'] = query_user($data['uid'], ['nickname', 'avatar', 'mobile', 'email', 'create_time']);
        if (is_array($data['user_info'])) {
            //格式化用户注册时间
            $data['user_info'] = $this->setTimeAttr($data['user_info']);
        }
        //订单架构
        if(isset($data['price']) && $data['pay_channel'] != 'score' ){
            $data['price'] = sprintf("%.2f",$data['price']/100);
        }
        //实际支付金额
        if(isset($data['paid_fee']) && $data['pay_channel'] != 'score') {
            $data['paid_fee'] = sprintf("%.2f",$data['paid_fee']/100);
        }
        //支付状态
        $data['paid_str'] = $this->_paid[$data['paid']];
        //支付时间
        $data = $this->setTimeAttr($data);

        //商品信息
        $data['products'] = json_decode($data['products'], true);
        $data['products']['cover_100'] = get_thumb_image($data['products']['cover'], 100, 100);
        $data['products']['cover_200'] = get_thumb_image($data['products']['cover'], 200, 200);
        $data['products']['cover_300'] = get_thumb_image($data['products']['cover'], 300, 300);
        $data['products']['cover_400'] = get_thumb_image($data['products']['cover'], 400, 400);
        $data['products']['cover_800'] = get_thumb_image($data['products']['cover'], 800, 800);
        $data['products']['price'] = sprintf("%.2f", $data['products']['price'] / 100); //商品单价
        if ($data['products']['type'] == 'vipcard') $data['products']['type_str'] = 'VIP卡';

        //是否已评价,已评价获取评价内容，未评价值为0
        if ($data['paid'] == 1) {
            $evaluate_map = [];
            $evaluate_map[] = ['uid', '=', $data['uid']];
            $evaluate_map[] = ['order_no', '=', $data['order_no']];
            $evaluate_map[] = ['shopid', '=', $data['shopid']];
            $evaluate = (new EvaluateModel)->getThisEvaluate($evaluate_map);
            if (!empty($evaluate)) {
                $data['evaluate'] = $evaluate;
            } else {
                $data['evaluate'] = 0;
            }
        } else {
            $data['evaluate'] = 0;
        }

        return $data;
    }

    /**
     * 导出数据格式化
     */
    public function exportParse($list, $header = array())
    {
        if (empty($list)) {
            return '';
        }

        $keys = array_keys($header);
        $html = "\xEF\xBB\xBF";
        foreach ($header as $li) {
            $html .= $li . "\t ,";
        }
        $html .= "\n";
        $count = count($list);
        $pagesize = ceil($count / 5000);
        for ($j = 1; $j <= $pagesize; $j++) {
            $list = array_slice($list, ($j - 1) * 5000, 5000);

            if (!empty($list)) {
                $size = ceil(count($list) / 500);
                for ($i = 0; $i < $size; $i++) {
                    $buffer = array_slice($list, $i * 500, 500);

                    $column_data = array();
                    foreach ($buffer as &$row) {
                        if ($row)
                            if ($row['paid'] == 1) {
                                $row['paid_time'] = date('Y-m-d H:i:s', $row['paid_time']);
                            }
                        $row['paid'] = $this->_paid[$row['paid']];
                        $row['paid_fee'] = '￥' . sprintf("%.2f", $row['paid_fee'] / 100);
                        $row['create_time'] = date('Y-m-d H:i:s', $row['create_time']);
                        $row['products'] = json_decode($row['products'], true);
                        $row['products_title'] = $row['products']['title'];
                        foreach ($keys as $key) {
                            $data[] = $row[$key];
                        }
                        $column_data[] = implode("\t ,", $data) . "\t ,";
                        unset($data);
                    }
                    unset($row);
                    $html .= implode("\n", $column_data) . "\n";
                }
            }
        }
        return $html;
    }
}
