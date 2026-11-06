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
        $type_list = $MessageTypeModel->getList($t_map)->toArray();
        foreach($type_list as &$val){
            $val = $MessageTypeModel->formatData($val);
            // 未读消息数量
            $map = [];
            $map[] = ['shopid', '=', $this->shopid];
            $map[] = ['type_id', '=', $val['id']];
            $map[] = ['is_read', '=', 0];
            $map[] = ['status', '=', 1];
            $map[] = ['to_uid', '=', get_uid()];
            $num = $MessageModel->where($map)->count();
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