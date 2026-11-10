<?php
declare (strict_types = 1);

namespace app\ucenter\controller;

use think\facade\View;
use app\common\model\Message as MessageModel;
use app\common\model\MessageType as MessageTypeModel;

class Message extends Base
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    /**
     * 消息模态框
     */
    public function modal()
    {
        // 获取消息类型
        $MessageModel = new MessageModel();
        $MessageTypeModel = new MessageTypeModel();
        // 查询条件
        $t_map[] = ['shopid', '=', $this->shopid];
        $t_map[] = ['status', '=', 1];
        // 排序值越大越靠前，相同排序值按创建顺序（id 升序）
        $type_list = $MessageTypeModel->getList($t_map, 100, 'sort desc,id asc')->toArray();

        // 一次聚合查询各类型未读数量，避免逐类型 count
        $unread_map = [];
        $unread_map[] = ['shopid', '=', $this->shopid];
        $unread_map[] = ['is_read', '=', 0];
        $unread_map[] = ['status', '=', 1];
        $unread_map[] = ['to_uid', '=', get_uid()];
        $unread_list = $MessageModel->where($unread_map)
            ->field('type_id, COUNT(*) as num')
            ->group('type_id')
            ->select()
            ->toArray();
        $unread = array_column($unread_list, 'num', 'type_id');

        foreach($type_list as &$val){
            $val = $MessageTypeModel->formatData($val);
            // 未读消息数量
            $num = isset($unread[$val['id']]) ? intval($unread[$val['id']]) : 0;
            if($num > 99){
                $val['unread'] = '99+';
            }else{
                $val['unread'] = $num;
            }
        }
        unset($val);
        View::assign('type_list', $type_list);

        return View::fetch();
    }

}