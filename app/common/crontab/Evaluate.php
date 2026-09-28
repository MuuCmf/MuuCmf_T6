<?php

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\model\Orders as OrdersModel;
use app\common\model\Evaluate as EvaluateModel;
use think\Exception;
use think\facade\Db;

/**
 * @title 自动评价
 * Class Evaluation
 */
class Evaluate
{
    protected $OrdersModel;
    public function __construct()
    {
        $this->OrdersModel = new OrdersModel();
    }

    /**
     * @title 业务处理
     * @param int $shopid
     * @param int $task_id
     * @return bool
     */
    public function handle(int $shopid, int $task_id)
    {
        Db::startTrans();
        try {
            //查询需要完成的订单
            $map = [
                ['status', '=', 4],
                ['paid', '=', 1],
                ['shopid', '=', $shopid],
                ['evaluate', '=', 0],
                ['update_time', 'between', [0, time() - (7 * 24 * 60 * 60)]],
            ];
            $lists = $this->OrdersModel
                ->field('id,shopid,app,order_no,paid,uid,order_info_type,order_info_id,products,evaluate,status,update_time')
                ->where($map)
                ->limit(100) //并发限制
                ->select()
                ->toArray();

            if (!empty($lists)) {
                //写入评价
                $evaluate_data = [];
                foreach ($lists as $item) {
                    if ($item['evaluate'] == 0) {
                        $evaluate_data[] = [
                            'shopid' => $shopid,
                            'app' => $item['app'],
                            'uid' => $item['uid'],
                            'type' => $item['order_info_type'],
                            // 评价对象以订单表 order_info_id 为准，避免 products JSON 结构差异导致 type_id 为空
                            'type_id' => intval($item['order_info_id']),
                            'order_no' => $item['order_no'],
                            'content' => '系统默认好评',
                            'images' => '',
                            'value' => 5.00,
                            'status' => 1,
                            'create_time' => time(),
                            'update_time' => time(),
                        ];
                    }
                }
                $evaluate_result = (new EvaluateModel())->insertAll($evaluate_data);
                if ($evaluate_result === false) throw new Exception('评价失败');
                //更改订单状态
                $ids = array_column($lists, 'id');
                $data = [
                    'status'    =>  5, //已评价
                    'evaluate' => 1,
                    'update_time' => time(),
                ];
                $result = $this->OrdersModel->where('id', 'in', $ids)->update($data);
                if ($result === false) throw new Exception('订单更新失败');
            }


            Db::commit();
            CrontabLog::addLog([
                'shopid' => $shopid,
                'cid'    => $task_id,
                'description'   =>  'success'
            ]);
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            CrontabLog::addLog([
                'shopid' => $shopid,
                'cid'    => $task_id,
                'description'   =>  $e->getMessage(),
                'status'        =>  0
            ]);
            return false;
        }
    }
}
