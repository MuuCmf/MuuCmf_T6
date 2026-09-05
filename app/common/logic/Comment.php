<?php

namespace app\common\logic;

use app\common\model\Module;
use app\common\model\Support as SupportModel;

/*
 * 评论数据逻辑层
 */

class Comment extends Base
{
    /**
     * 内容状态
     */
     protected array $__status = [
        1  => '启用',
        0  => '禁用',
        -1 => '未审核',
        -2 => '审核未通过',
        -3 => '已删除',
    ];

    public function getMap($shopid, $keyword = '', $app = '', $info_type = '', $info_id = 0, $status = 1)
    {
        //初始化查询条件
        $map = [];

        if (!empty($shopid)) {
            $map[] = ['shopid', '=', $shopid];
        }

        if (is_numeric($status)) {
            $map[] = ['status', '=', $status];
        }
        if (is_array($status) || empty($status)) {
            $map[] = ['status', 'in', $status];
        }

        if (!empty($keyword)) {
            $map[] = ['content', 'like', '%' . $keyword . '%'];
        }

        if (!empty($app)) {
            $map[] = ['app', '=', $app];
        }
        //内容id
        if (!empty($info_id)) {
            $map[] = ['info_id', '=', $info_id];
        }
        if (!empty($info_type)) {
            $map[] = ['info_type', '=', $info_type];
        }

        return $map;
    }

    /**
     * 数据格式化
     */
    public function formatData($data = [])
    {
        if (!empty($data)) {
            
            $data['info_id'] = (string)$data['info_id'];
            if(!empty($data['content'])){
                $data['content'] = htmlspecialchars_decode($data['content']);
            }

            // 处理图片
            if ($data['images'] !== null && $data['images'] !== 'null' && !empty($data['images'])) {
                $data['images'] = json_decode($data['images'], true);
                if (is_array($data['images']) && !empty($data['images'])) {
                    $temp_images = [];
                    foreach ($data['images'] as $key => $val) {
                        $thumb = get_thumb_image($val, 60, 60);
                        $temp_images[$key]['thumb'] = $thumb;
                        $temp_images[$key]['preview'] = get_attachment_src($val);
                        $temp_images[$key]['image'] = $val;
                    }
                    $data['images'] = $temp_images;
                }
            }

            $data['status_str'] = $this->__status[$data['status']];
            if (!empty($data['create_time'])) {
                $data['create_time_str'] = time_format($data['create_time']);
                $data['create_time_friendly_str'] = friendly_date($data['create_time']);
            }
            if (!empty($data['update_time'])) {
                $data['update_time_str'] = time_format($data['update_time']);
                $data['update_time_friendly_str'] = friendly_date($data['update_time']);
            }
            // 获取用户信息
            $data['user_info'] = query_user($data['uid'], ['nickname', 'avatar']);
            // 获取to_uid用户数据
            $data['to_user_info'] = query_user($data['to_uid'], ['nickname', 'avatar']);
            // 获取应用信息
            $data['app_info'] = (new Module())->getModule($data['app']);
            //判断是否点赞
            if ((new SupportModel())->yesSupport($data['shopid'], $data['app'], get_uid(), $data['id'], 'Comment')) {
                $data['support_yesno'] = 1;
            } else {
                $data['support_yesno'] = 0;
            }
        }

        return $data;
    }
}
