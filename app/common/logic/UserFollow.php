<?php

namespace app\common\logic;

use think\facade\Db;
use app\common\model\Follow as FollowModel;

/**
 * 用户关注用户数据逻辑层
 *
 * 关注关系存于 follow 表：who_follow = 关注者UID，follow_who = 被关注用户UID。
 * 普通用户关注不依赖创作者，author_id 记 0；与创作者关注共用唯一键
 * shopid + who_follow + follow_who，重复关注同一用户不会产生重复记录。
 */
class UserFollow extends Base
{
    protected array $__status = [
        0 => '未关注',
        1 => '已关注',
    ];

    /**
     * 关注、取消关注（切换）
     *
     * @param int $shopid    店铺ID
     * @param int $uid       关注者UID
     * @param int $targetUid 被关注用户UID
     * @return array{success: bool, msg: string, status?: int}
     */
    public function toggleFollow($shopid, $uid, $targetUid)
    {
        $uid = intval($uid);
        $targetUid = intval($targetUid);

        if ($uid <= 0) {
            return ['success' => false, 'msg' => '请先登录'];
        }
        if ($targetUid <= 0) {
            return ['success' => false, 'msg' => '缺少参数'];
        }
        if ($uid === $targetUid) {
            return ['success' => false, 'msg' => '不能关注自己'];
        }

        $target = Db::name('member')->where('uid', $targetUid)->field('uid,status')->find();
        if (empty($target) || intval($target['status']) !== 1) {
            return ['success' => false, 'msg' => '用户不存在或已被禁用'];
        }

        $followModel = new FollowModel();
        $follow = $followModel->where([
            ['shopid', '=', $shopid],
            ['who_follow', '=', $uid],
            ['follow_who', '=', $targetUid],
        ])->find();

        if ($follow) {
            $status = intval($follow['status']) === 1 ? 0 : 1;
            $data = ['id' => $follow['id'], 'status' => $status];
        } else {
            $status = 1;
            $data = [
                'shopid'     => $shopid,
                'who_follow' => $uid,
                'follow_who' => $targetUid,
                'author_id'  => 0,
                'status'     => 1,
            ];
        }

        if ($followModel->edit($data) === false) {
            return ['success' => false, 'msg' => '发生错误'];
        }

        return ['success' => true, 'msg' => $status ? '已关注' : '已取消', 'status' => $status];
    }

    /**
     * 是否已关注指定用户
     *
     * @param int $shopid    店铺ID
     * @param int $uid       关注者UID
     * @param int $targetUid 被关注用户UID
     * @return bool
     */
    public function isFollow($shopid, $uid, $targetUid)
    {
        $uid = intval($uid);
        $targetUid = intval($targetUid);
        if ($uid <= 0 || $targetUid <= 0) {
            return false;
        }

        return (bool)(new FollowModel())->where([
            ['shopid', '=', $shopid],
            ['who_follow', '=', $uid],
            ['follow_who', '=', $targetUid],
            ['status', '=', 1],
        ])->find();
    }

    /**
     * 关注数 / 粉丝数
     *
     * @param int $shopid 店铺ID
     * @param int $uid    用户UID
     * @return array{following: int, fans: int}
     */
    public function countFollow($shopid, $uid)
    {
        $uid = intval($uid);
        if ($uid <= 0) {
            return ['following' => 0, 'fans' => 0];
        }

        $model = new FollowModel();

        return [
            'following' => (int)$model->where([
                ['shopid', '=', $shopid],
                ['who_follow', '=', $uid],
                ['status', '=', 1],
            ])->count(),
            'fans' => (int)$model->where([
                ['shopid', '=', $shopid],
                ['follow_who', '=', $uid],
                ['status', '=', 1],
            ])->count(),
        ];
    }

    /**
     * 格式化关注记录
     *
     * @param array  $data      关注记录
     * @param string $direction following=我关注的用户，fans=关注我的用户
     * @return array
     */
    public function formatData($data, $direction = 'following')
    {
        if (empty($data)) {
            return $data;
        }

        $otherUid = $direction === 'fans'
            ? intval($data['who_follow'] ?? 0)
            : intval($data['follow_who'] ?? 0);

        $user = $otherUid > 0
            ? query_user($otherUid, ['uid', 'nickname', 'avatar', 'sex', 'signature'])
            : [];

        return [
            'uid'            => $otherUid,
            'nickname'       => $user['nickname'] ?? '',
            'avatar'         => $user['avatar'] ?? '',
            'avatar128'      => $user['avatar128'] ?? '',
            'sex'            => intval($user['sex'] ?? 0),
            'sex_str'        => $user['sex_str'] ?? '',
            'signature'      => $user['signature'] ?? '',
            'follow_time'    => intval($data['create_time'] ?? 0),
            'follow_time_str' => !empty($data['create_time']) ? time_format($data['create_time']) : '',
        ];
    }
}
