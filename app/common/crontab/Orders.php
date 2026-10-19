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
                ->field('id,order_no,uid,shopid,app,order_info_id,order_info_type,products,status')
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

                //逐单回滚营销占用并归还商品库存（无对应记录时均为空操作）
                foreach ($lists as $order) {
                    //限时折扣：回滚活动销量与限购计数
                    \app\common\service\Discount::rollback($order['order_no']);
                    //优惠券：释放占用中的券
                    \app\common\service\Coupon::release($shopid, $order['order_no']);
                    //拼团：成员置为已取消，未成团回退团人数与拼团库存（此前遗漏，导致团人数/拼团库存虚高到团超时）
                    \app\common\service\Groupbuy::release(intval($order['shopid'] ?? $shopid), (string)$order['order_no']);
                    //商品库存回补：按下单时扣减的库存归还（此前遗漏，导致超时未支付订单库存永久丢失）
                    $appName = (string)($order['app'] ?? '');
                    if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $appName)) {
                        $appOrderService = 'app\\' . $appName . '\\service\\Orders';
                        if (class_exists($appOrderService) && method_exists($appOrderService, 'stockRollback')) {
                            (new $appOrderService)->stockRollback($order);
                        }
                    }
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
