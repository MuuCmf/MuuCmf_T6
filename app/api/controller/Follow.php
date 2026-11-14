<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Follow as FollowModel;
use app\common\logic\UserFollow as UserFollowLogic;

/**
 * @title 用户关注接口类
 * @package app\api\controller
 */
class Follow extends Api
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    /**
     * 关注 / 取消关注用户
     */
    public function follow()
    {
        $user_id = input('user_id', 0, 'intval');
        if (empty($user_id)) {
            return $this->error('缺少参数');
        }

        $result = (new UserFollowLogic())->toggleFollow($this->shopid, request()->uid, $user_id);
        if (empty($result['success'])) {
            return $this->error($result['msg']);
        }

        return $this->success($result['msg'], ['is_follow' => $result['status']]);
    }

    /**
     * 是否已关注指定用户
     */
    public function isfollow()
    {
        $user_id = input('user_id', 0, 'intval');
        if (empty($user_id)) {
            return $this->error('缺少参数');
        }

        $is_follow = (new UserFollowLogic())->isFollow($this->shopid, request()->uid, $user_id);

        return $this->success('success', ['is_follow' => $is_follow ? 1 : 0]);
    }

    /**
     * 我关注的用户列表
     */
    public function lists()
    {
        return $this->followList('following');
    }

    /**
     * 关注我的粉丝列表
     */
    public function fans()
    {
        return $this->followList('fans');
    }

    /**
     * 关注数 / 粉丝数
     */
    public function count()
    {
        $uid = input('uid', 0, 'intval') ?: request()->uid;

        return $this->success('success', (new UserFollowLogic())->countFollow($this->shopid, $uid));
    }

    /**
     * 关注 / 粉丝列表
     *
     * @param string $direction following=我关注的用户，fans=关注我的用户
     * @return mixed
     */
    protected function followList($direction)
    {
        $rows = input('rows', 20, 'intval');
        $rows = min(max($rows, 1), 100);
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = $order_field . ' ' . $order_type;

        $map = [
            ['shopid', '=', $this->shopid],
            [$direction === 'fans' ? 'follow_who' : 'who_follow', '=', request()->uid],
            ['status', '=', 1],
        ];

        $lists = (new FollowModel())->getListByPage($map, $order, '*', $rows)->toArray();
        foreach ($lists['data'] as &$val) {
            $val = (new UserFollowLogic())->formatData($val, $direction);
        }
        unset($val);

        return $this->success('success', $lists);
    }
}
