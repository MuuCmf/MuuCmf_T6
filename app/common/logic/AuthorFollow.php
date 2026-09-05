<?php

namespace app\common\logic;

use app\common\model\Author as AuthorModel;
use app\common\model\AuthorGroup as AuthorGroupModel;
/*
 * 关注创作者数据逻辑层
 */

class AuthorFollow extends Base
{

    public array $_status = [
        0 => '未关注',
        1 => '已关注',
    ];

    /**
     * 格式化数据
     */
    public function formatData($data)
    {
        // 绑定用户的创造者获取用户数据
        if (!empty($data['uid'])) {
            $data['user_info'] = query_user($data['uid']);
        }

        if (!empty($data['author_id'])) {
            $author = (new AuthorModel())->where('id', $data['author_id'])->field('id,group_id,name,cover,description')->find();
            //处理缩微图
            $width = 100;
            $height = 100;
            $author['cover_100'] = get_thumb_image($author['cover'], intval($width), intval($height));
            $author['cover_200'] = get_thumb_image($author['cover'], intval($width * 2), intval($height * 2));
            $author['cover_300'] = get_thumb_image($author['cover'], intval($width * 3), intval($height * 3));
            $author['cover_400'] = get_thumb_image($author['cover'], intval($width * 4), intval($height * 4));
            $author['cover_800'] = get_thumb_image($author['cover'], intval($width * 8), intval($height * 8));

            if (!empty($author['group_id'])) {
                $author['group'] = (new AuthorGroupModel())->where('id', $author['group_id'])->value('title');
            } else {
                $author['group'] = '';
            }

            $data['author'] = $author;
        }

        // 状态描述
        $data['status_str'] = $this->_status[$data['status']];
        // 时间处理
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
