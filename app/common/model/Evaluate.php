<?php

namespace app\common\model;

/**
 * 评价模型
 * Class Evaluate
 * @package app\common\model
 */
class Evaluate extends Base
{
    protected $autoWriteTimestamp = true;

    /**
     * 获取统计数据
     * @return [type] [description]
     */
    public function getStatistical($shopid, $app, $type, $type_id)
    {
        //所有
        $where = [];
        $where[] = ['shopid', '=', $shopid];
        $where[] = ['app', '=', $app];
        $where[] = ['type', '=', $type];
        $where[] = ['type_id', '=', $type_id];
        $where[] = ['status', '=', 1];
        $count['all_num']  = $this->getCount($where);

        //计算平均分
        $avg = $this->getAvg($where, 'value');
        if ($count['all_num'] > 0) {
            $count['avg'] =  sprintf("%.2f", $avg);
        } else {
            $count['avg'] = 5.00;
        }

        //好评
        $goods_rate_where = $where;
        $goods_rate_where[] = ['value', '>=', 4];
        $count['goods_num'] = $this->getCount($goods_rate_where);
        //好评率
        if ($count['all_num'] > 0) {
            $count['goods_rate'] = sprintf("%.2f", $count['goods_num'] / $count['all_num']) * 100;
        } else {
            $count['goods_rate'] = 100;
        }

        //中评
        $mediums_num_where = $where;
        $mediums_num_where[] = ['value', 'in', [2, 3]];
        $count['mediums_num'] = $this->getCount($mediums_num_where);

        //差评评数
        $bads_num_where = $where;
        $bads_num_where[] = ['value', '<', 2];
        $count['bads_num'] = $this->getCount($bads_num_where);

        //嗮图
        $pic_num_where = $where;
        $pic_num_where[] = ['images', '<>', ''];
        $count['pic_num'] = $this->getCount($pic_num_where);

        return $count;
    }

    /**
     * 查询是否已评价
     */
    public function getThisEvaluate($map)
    {
        $map[] = ['status', '=', 1];
        $result = $this->where($map)->find();
        if ($result) {
            if ($result['create_time'] + 30 * 3600 > time() && $result['update_time'] == $result['create_time']) {
                $result['edit'] = true;
            }
            return $result;
        } else {
            return 0;
        }
    }

    /**
     * 根据自定义参数获取评价列表并分页
     * 
     * @param array $params 查询参数
     *                      - shopid: 商户ID
     *                      - app: 应用名称
     *                      - type: 评价类型
     *                      - type_id: 类型ID
     *                      - rows: 每页显示数量，默认10条
     *                      - order_type: 排序方式，默认desc
     *                      - order_field: 排序字段，默认id
     * 
     * @return \think\Paginator 分页后的评价列表数据
     */
    public function getListByPageCustom($params)
    {
        $shopid = $params['shopid'] ?? 0;
        $map = [
            ['e.shopid', '=', $shopid],
            ['e.status', '=', 1],
            ['e.app', '=', $params['app']],
            ['e.type', '=', $params['type']],
            ['e.type_id', '=', $params['type_id']]
        ];

        if(empty($params['rows'])){
            $rows = 10;
        }else{
            $rows = $params['rows'];
        }

        $order_type = $params['order_type'] ?? 'desc';
        $order_field = $params['order_field'] ?? 'id';
        // 排序字段/方向白名单，防止 order by SQL 注入
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'id';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = 'e.' . $order_field . ' ' . $order_type;

        // 获取分页列表
        $lists = $this->alias('e')->join('member m', 'e.uid = m.uid')->where($map)->order($order)->field('e.*')->paginate(['list_rows' => $rows, 'query' => $params], false);

        return $lists;
    }
}
