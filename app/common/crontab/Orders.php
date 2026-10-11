<?php

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\model\Orders as OrdersModel;
use think\Exception;
use think\facade\Db;

/**
 * @title 自动取消24小时未付款订单
 * Class Evaluation
 */
class Orders
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
                ['status', '=', 1],
                ['paid', '=', 0],
                ['shopid', '=', $shopid],
                ['create_time', 'between', [0, time() - (24 * 60 * 60)]],
            ];
            $lists = $this->OrdersModel
                ->field('id,order_no,uid,products,status')
                ->where($map)
                ->limit(100) //并发限制
                ->select()
                ->toArray();
            if (!empty($lists)) {
                //更改订单状态
                $ids = array_column($lists, 'id');
                $data = [
                    'status'    =>  0, //已取消
                    'update_time' => time(),
                ];
                $result = $this->OrdersModel->where('id', 'in', $ids)->update($data);
                if ($result === false) throw new Exception('订单更新失败');

                //限时折扣：自动取消订单，逐单回滚活动销量与限购计数
                foreach ($lists as $order) {
                    \app\common\service\Discount::rollback($order['order_no']);
                }
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
