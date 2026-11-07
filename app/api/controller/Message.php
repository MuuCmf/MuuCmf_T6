<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Message as MessageModel;
use app\common\model\MessageType as MessageTypeModel;

/**
 * @title 消息接口类
 * @package app\api\controller
 */
class Message extends Api
{

    protected $MessageModel;
    protected $MessageTypeModel;

    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];
    function __construct()
    {
        parent::__construct();
        $this->MessageModel = new MessageModel();
        $this->MessageTypeModel = new MessageTypeModel();
    }

    /**
     * 消息类型
     */
    public function type()
    {
        $uid = request()->uid;
        // 查询条件
        $t_map[] = ['shopid', '=', $this->shopid];
        $t_map[] = ['status', '=', 1];
        // 排序值越大越靠前，相同排序值按创建顺序（id 升序）
        $list = $this->MessageTypeModel->getList($t_map, 100, 'sort desc,id asc');
        foreach ($list as &$val) {
            $val = $this->MessageTypeModel->formatData($val);
            // 未读消息数量（每轮必须重置条件，否则会叠加出 type_id = a AND type_id = b）
            $map = [];
            $map[] = ['to_uid', '=', $uid];
            $map[] = ['type_id', '=', $val['id']];
            $map[] = ['is_read', '=', 0];
            $map[] = ['status', '=', 1];
            $num = (new MessageModel())->where($map)->count();
            if ($num > 99) {
                $val['unread'] = '99+';
            } else {
                $val['unread'] = $num;
            }
        }
        unset($val);

        return $this->success('success', $list);
    }


    /**
     * 消息列表
     */
    public function lists()
    {
        $uid = request()->uid;
        $type_id = input('type_id', 0, 'intval');
        // 查询条件
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['status', '=', 1];
        $map[] = ['to_uid', '=', $uid];
        if (!empty($type_id)) {
            $map[] = ['type_id', '=', $type_id];
        }

        // 搜索关键字（message 表无 title 字段，标题存在 message_content 中）
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $content_ids = \think\facade\Db::name('message_content')
                ->where('shopid', $this->shopid)
                ->where('title', 'like', '%' . $keyword . '%')
                ->limit(5000)
                ->column('id');
            if (empty($content_ids)) {
                return $this->success('success', ['data' => [], 'total' => 0, 'page' => 1, 'rows' => (int)input('rows', 20, 'intval')]);
            }
            $map[] = ['content_id', 'in', $content_ids];
        }

        $fields = '*';
        $rows = input('rows', 20, 'intval');
        $lists = $this->MessageModel->getListByPage($map, 'id desc,create_time desc', $fields, $rows);
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->MessageModel->formatData($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 消息详细内容
     */
    public function detail()
    {
        $uid = get_uid();
        $id = input('id', '0', 'intval');
        if (!empty($id)) {
            $map = [
                'id' => $id,
                'to_uid' => $uid,
                'shopid' => $this->shopid,
                'status' => 1
            ];
            $data = $this->MessageModel->where($map)->find();
            if (empty($data)) {
                return $this->error('消息不存在');
            }
            $data = $data->toArray();
            $data = $this->MessageModel->formatData($data);
            // 设置已读
            $this->MessageModel->where($map)->update([
                'is_read' => 1
            ]);

            return $this->success('success', $data);
        }

        return $this->error('参数错误');
    }

    /**
     * 删除消息
     *
     * 软删除（status=-1），只作用于当前用户自己的消息；
     * 支持单个 id，或批量 ids（数组 / 逗号分隔字符串）。
     */
    public function delete()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $uid = request()->uid;
        $ids = input('ids', []);
        if (empty($ids)) {
            $ids = input('id', 0, 'intval');
        }
        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return $this->error('参数错误');
        }

        $res = $this->MessageModel
            ->where('shopid', $this->shopid)
            ->where('to_uid', $uid)
            ->whereIn('id', $ids)
            ->where('status', 1)
            ->update(['status' => -1]);

        if ($res === false) {
            return $this->error('删除失败');
        }

        return $this->success('success', ['deleted' => (int)$res]);
    }

    /**
     * 一键退订营销消息（整店）
     *
     * 只写入退订偏好，历史已发消息不受影响；事务/安全消息不受退订影响。
     */
    public function unsubscribe()
    {
        $uid = get_uid();
        if (empty($uid)) {
            return $this->error('请先登录');
        }

        $now = time();
        $where = [
            'shopid'        => $this->shopid,
            'app'           => 'common',
            'uid'           => $uid,
            'template_code' => '*',
            'channel'       => '*',
        ];
        $exists = \think\facade\Db::name('message_subscription')->where($where)->find();

        if ($exists) {
            \think\facade\Db::name('message_subscription')->where('id', $exists['id'])->update([
                'subscribed'       => 0,
                'unsubscribe_time' => $now,
                'update_time'      => $now,
            ]);
        } else {
            $where['subscribed'] = 0;
            $where['unsubscribe_time'] = $now;
            $where['create_time'] = $now;
            $where['update_time'] = $now;
            \think\facade\Db::name('message_subscription')->insert($where);
        }

        return $this->success('已退订营销消息');
    }

    /**
     * 未读消息数量
     * 给消息类型ID返回类型数量，未给消息类型ID返回所有未读数量
     */
    public function unread()
    {
        $uid = request()->uid;
        $map[] = ['to_uid', '=', $uid];
        $map[] = ['shopid', '=', $this->shopid];
        $type_id = input('type_id', 0, 'intval');
        if (!empty($type_id)) {
            $map[] = ['type_id', '=', $type_id];
        } else {
            $t_map[] = ['shopid', '=', $this->shopid];
            $t_map[] = ['status', '=', 1];
            $list = $this->MessageTypeModel->getList($t_map);
            $type_ids = [];
            foreach ($list as $v) {
                $type_ids[] = $v['id'];
            }
            // 店铺还没有消息类型时不能拼出 type_id in ()，否则 SQL 语法错误
            if (!empty($type_ids)) {
                $map[] = ['type_id', 'in', $type_ids];
            }
        }
        $map[] = ['is_read', '=', 0];
        $map[] = ['status', '=', 1];

        $num = $this->MessageModel->where($map)->count();

        if ($num > 99) {
            $friendly_num = '99+';
        } else {
            $friendly_num = $num;
        }

        return $this->success('success', [
            'num' => $num,
            'friendly_num' => $friendly_num
        ]);
    }

    /**
     * 全部未读消息标识为已读
     */
    public function isread()
    {
        $uid = request()->uid;
        $map[] = ['to_uid', '=', $uid];
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['is_read', '=', 0];

        $res = $this->MessageModel->where($map)->update(['is_read' => 1]);

        if ($res) {
            return $this->success('success', '更新成功');
        }

        return $this->error('error', '更新失败');
    }
}
