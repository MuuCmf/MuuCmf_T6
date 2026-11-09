<?php

namespace app\admin\controller;

use app\common\model\MessageContent as MessageContentModel;
use app\common\model\Message as MessageModel;

use app\admin\validate\Common;
use think\exception\ValidateException;

/**
 * 消息控制器
 */
class Message extends Admin
{
    protected MessageModel $MessageModel;
    protected MessageContentModel $MessageContentModel;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct()
    {
        parent::__construct();
        // 消息发送列表
        $this->MessageModel = new MessageModel();
        // 消息内容
        $this->MessageContentModel = new MessageContentModel();
        // 设置页面title
        $this->setTitle('消息管理');
    }

    /**
     * 消息发送列表
     */
    public function list()
    {
        // 查询条件
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['status', 'in', [0, 1]];

        // 搜索关键字（message 表无 title 字段，标题存在 message_content 中）
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $content_ids = $this->MessageContentModel->where('shopid', $this->shopid)
                ->where('title', 'like', '%' . $keyword . '%')
                ->limit(5000)
                ->column('id');
            if (empty($content_ids)) {
                return $this->success('success', ['data' => [], 'total' => 0, 'page' => 1, 'rows' => 0]);
            }
            $map[] = ['content_id', 'in', $content_ids];
        }

        // 消息类型
        $type_id = input('type_id', 0, 'intval');
        if (!empty($type_id)) {
            $map[] = ['type_id', '=', $type_id];
        }

        // 已读未读（必须区分「未传」和「传了 0」，否则只会查出未读）
        $is_read = trim((string)input('is_read', '', 'trim'));
        if ($is_read === '0' || $is_read === '1') {
            $map[] = ['is_read', '=', (int)$is_read];
        }

        $fields = '*';
        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 100);

        $lists = $this->MessageModel->getListByPage($map, 'id desc,create_time desc', $fields, $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->MessageModel->formatData($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 消息状态管理
     */
    public function messageStatus()
    {
        $ids = input('ids/a');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '禁用';
        }
        if ($status == 1) {
            $title = '启用';
        }
        if ($status == -1) {
            $title = '删除';
        }
        $data['status'] = $status;

        $res = $this->MessageModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }


    /**
     * 消息内容列表
     */
    public function content()
    {
        // 查询条件
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['status', '>', -1];

        // 搜索关键字
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $map[] = ['title', 'like', '%' . $keyword . '%'];
        }
        
        $fields = '*';
        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 100);
        
        $lists = $this->MessageContentModel->getListByPage($map, 'id desc,create_time desc', $fields, $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->MessageContentModel->formatData($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 消息内容新增、编辑
     */
    public function contentEdit()
    {
        $id = input('id', 0, 'intval');
        $title = $id ? "编辑" : "新建";

        if (request()->isPost()) {
            $data = input();
            $data['shopid'] = $this->shopid;
            // 数据验证
            try {
                validate(Common::class)->scene('message')->check([
                    'title'  => $data['title'],
                    'description'  => $data['description'],
                    'content'  => $data['content'],
                ]);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }
            $res = $this->MessageContentModel->edit($data);

            if ($res) {
                return $this->success($title . '成功', $res, cookie('__forward__'));
            } else {
                return $this->error($title . '失败');
            }
        } else {
            if (!empty($id)) {
                $data = $this->MessageContentModel->getDataById($id);
            } else {
                // 初始化数据结构
                $data['id'] = 0;
                $data['title'] = '';
                $data['description'] = '';
                $data['content'] = '';
                $data['status'] = 1;
            }

            return $this->success('success', $data);
        }
    }

    /**
     * 消息内容状态管理
     */
    public function contentStatus()
    {
        $ids = input('ids/a');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '禁用';
        }
        if ($status == 1) {
            $title = '启用';
        }
        if ($status == -1) {
            $title = '删除';
        }
        $data['status'] = $status;

        $res = $this->MessageContentModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }
}
