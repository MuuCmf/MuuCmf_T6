<?php
namespace app\common\logic;

/*
 * MuuCmf
 * 用户反馈逻辑层
 */

use app\common\model\Module;

class Feedback
{
     protected array $__status  = [
        2  => '已完成',
        1  => '处理中',
        0  => '待处理',
        -1 => '已删除',
    ];

    public $_type = [
        'suggestion' => '功能建议',
        'bug' => '问题反馈',
        'other' => '其他',
    ];

    /**
     * 格式化数据
     */
    public function formatData($data)
    {   
        $data['user_info'] = query_user($data['uid']);
        if (!is_array($data['user_info'])) {
            $data['user_info'] = [];
            $data['user_info']['nickname'] = '用户不存在';
            $data['user_info']['avatar'] = request()->domain() . '/static/common/images/default_avatar.jpg';
        }
        $module = (new Module())->where('name',$data['app'])->find();
        $data['app_alias'] = $module['alias'] ?? '';

        $data['images_format'] = [];
        if (!empty($data['images'])){
            $images = explode(',',$data['images']);
            foreach($images as $k=>$v){
                $data['images_format'][$k]['original'] = $v;
                $data['images_format'][$k]['format'] = get_attachment_src($v);
            }
        }
        $data['type_str'] = $this->_type[$data['type']] ?? '';
        $data['status_str'] = $this->__status[$data['status']] ?? '';
        //时间戳格式化
        $data['create_time_str'] = time_format($data['create_time']);
        $data['update_time_str'] = time_format($data['update_time']);
        return $data;
    }
}