<?php

namespace app\common\logic;

use app\common\model\Author as AuthorModel;
use app\common\model\AuthorGroup as AuthorGroupModel;
use app\common\model\Follow as FollowModel;
/*
 * 关注创作者数据逻辑层
 */

class AuthorFollow extends Base
{

     protected array $__status = [
        0 => '未关注',
        1 => '已关注',
    ];

    /**
     * 关注、取消关注
     *
     * 关注对象为作者绑定的用户，唯一键：shopid + who_follow + follow_who。
     *
     * @param int $shopid   店铺ID
     * @param int $uid      关注者UID
     * @param int $authorId 关注来源作者ID
     * @return array{success: bool, msg: string, status?: int}
     */
    public function toggleFollow($shopid, $uid, $authorId)
    {
        $author = (new AuthorModel())->where('id', $authorId)->field('id,uid')->find();
        if (empty($author)) {
            return ['success' => false, 'msg' => '创作者不存在'];
        }
        if (empty($author['uid'])) {
            return ['success' => false, 'msg' => '该创作者未绑定用户，暂不支持关注'];
        }
        if ($author['uid'] == $uid) {
            return ['success' => false, 'msg' => '不能关注自己'];
        }

        $followModel = new FollowModel();
        $follow = $followModel->where([
            ['shopid', '=', $shopid],
            ['who_follow', '=', $uid],
            ['follow_who', '=', $author['uid']],
        ])->find();

        if ($follow) {
            $status = $follow['status'] == 1 ? 0 : 1;
            $data = ['id' => $follow['id'], 'status' => $status];
            if ($status == 1) {
                $data['author_id'] = $authorId;
            }
        } else {
            $status = 1;
            $data = [
                'shopid'     => $shopid,
                'who_follow' => $uid,
                'follow_who' => $author['uid'],
                'author_id'  => $authorId,
                'status'     => 1,
            ];
        }

        if ($followModel->edit($data) === false) {
            return ['success' => false, 'msg' => '发生错误'];
        }

        return ['success' => true, 'msg' => $status ? '已关注' : '已取消', 'status' => $status];
    }

    /**
     * 是否已关注
     *
     * @param int $shopid   店铺ID
     * @param int $uid      关注者UID
     * @param int $authorId 被关注作者ID
     * @return bool
     */
    public function isFollow($shopid, $uid, $authorId)
    {
        if (empty($uid) || empty($authorId)) {
            return false;
        }

        $followWho = (new AuthorModel())->where('id', $authorId)->value('uid');
        if (empty($followWho)) {
            return false;
        }

        return (bool)(new FollowModel())->where([
            ['shopid', '=', $shopid],
            ['who_follow', '=', $uid],
            ['follow_who', '=', $followWho],
            ['status', '=', 1],
        ])->find();
    }

    /**
     * 格式化数据
     */
    public function formatData($data)
    {
        if (empty($data)) {
            return $data;
        }

        // 关注者：新表为 who_follow，兼容旧数据中的 uid
        $followerUid = $data['who_follow'] ?? ($data['uid'] ?? 0);
        if (!empty($followerUid)) {
            $data['user_info'] = query_user($followerUid);
        }
        $data['uid'] = $followerUid;

        // 被关注作者：优先按 author_id 取，取不到再按绑定用户反查
        $author = null;
        if (!empty($data['author_id'])) {
            $author = (new AuthorModel())->where('id', $data['author_id'])
                ->field('id,group_id,name,cover,description')
                ->find();
        }
        if (empty($author) && !empty($data['follow_who'])) {
            $map = [['uid', '=', $data['follow_who']]];
            if (!empty($data['shopid'])) {
                $map[] = ['shopid', '=', $data['shopid']];
            }
            $author = (new AuthorModel())->where($map)
                ->field('id,group_id,name,cover,description')
                ->find();
        }

        if (!empty($author)) {
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
            if (empty($data['author_id'])) {
                $data['author_id'] = $author['id'];
            }
        }

        // 状态描述
        if (isset($data['status'])) {
            $data['status_str'] = $this->__status[$data['status']] ?? '';
        }
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
