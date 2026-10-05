<?php

namespace app\common\model;

use app\common\logic\Channel;

/**
 * 资金流水
 */
class CapitalFlow extends Base
{
    protected $autoWriteTimestamp = true;

    public function getPriceAttr($value, $data)
    {
        return sprintf("%.2f", $value / 100);
    }
    /**
     * 生成流水号
     * @return string
     */
    public function build_flow_no()
    {
        return date('Ymd') . substr(implode('', array_map('ord', str_split(substr(uniqid(), 7, 13), 1))), 0, 14);
    }

    public function createFlow($data)
    {
        $flow_no = $this->build_flow_no();
        //生成订单流水
        $flow_data = [
            'shopid'    => $data['shopid'],
            'app'       => $data['app'],
            'uid'       => $data['uid'],
            'flow_no'   => $flow_no,
            'order_no'  => $data['order_no'],
            'channel'   => $data['channel'],
            'type'      => $data['type'] ?? 1,
            'price'     => $data['price'],
            'remark'    => $data['remark'] ?? '',
            'status'    => $data['status'] ?? 1
        ];
        $res = $this->edit($flow_data);
        if ($res) {
            return $flow_no;
        }

        return false;
    }

    /**
     * 获取收支明细数量
     */
    public function chargesTotal($shopid = 0, $app = '', $uid = 0)
    {
        $map = [
            ['shopid', '=', $shopid],
            ['uid', '=', $uid]
        ];
        if (!empty($app)) {
            $map[] = ['app', '=', $app];
        }
        $total = $this->where($map)->count();

        return $total;
    }

    /**
     * 数据处理
     */
    public function handle($data)
    {
        if (!empty($data['uid'])) {
            $data['user_info'] = query_user($data['uid']);
        }

        if ($data['type'] == 1) {
            $data['type_str'] = '支出';
        }
        if ($data['type'] == 2) {
            $data['type_str'] = '收入';
        }

        if (!empty($data['channel'])) {
            // 渠道未在映射表中时返回空，由调用方回退显示原始渠道
            $data['channel_str'] = Channel::$_channel[$data['channel']] ?? '';
        }

        if (!empty($data['create_time'])) {
            $data['create_time_str'] = time_format($data['create_time']);
            $data['create_time_friendly_str'] = friendly_date($data['create_time']);
        }
        if (!empty($data['update_time'])) {
            $data['update_time_str'] = time_format($data['update_time']);
            $data['update_time_friendly_str'] = friendly_date($data['update_time']);
        }
        return $data;
    }
}
