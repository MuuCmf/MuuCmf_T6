<?php

namespace app\admin\controller;

use think\exception\ValidateException;
use app\common\validate\Author as AuthorValidate;
use app\common\model\Author as AuthorModel;
use app\common\logic\Author as AuthorLogic;
use app\common\model\AuthorGroup as AuthorGroupModel;

class Role extends Admin
{
    protected AuthorModel $AuthorModel;
    protected AuthorLogic $AuthorLogic;
    protected AuthorGroupModel $AuthorGroupModel;

    public function __construct()
    {
        parent::__construct();
        $this->AuthorModel = new AuthorModel();
        $this->AuthorLogic = new AuthorLogic();
        $this->AuthorGroupModel = new AuthorGroupModel();
    }

    /**
     * 角色用户列表
     */
    public function list()
    {
        $map = [];
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $map[] = ['name', 'like', '%' . $keyword . '%'];
        }

        $status = input('status', 'all');
        if ($status === 'all') {
            $map[] = ['status', '>', -3];
        } else {
            if (intval($status) == 1) {
                $map[] = ['status', '=', 1];
            }
            if (intval($status) == 0 && $status != 'all') {
                $map[] = ['status', '=', 0];
            }
            if (intval($status) == -1) {
                $map[] = ['status', '=', -1];
            }
            if (intval($status) == -2) {
                $map[] = ['status', '=', -2];
            }
            if (intval($status) == -3) {
                $map[] = ['status', '=', -3];
            }
        }

        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 10);
        // 排序
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = 'sort DESC,' . $order_field . ' ' . $order_type;

        // 获取分页列表
        $lists = $this->AuthorModel->getListByPage($map, $order, '*', $rows);

        // 格式化数据
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->AuthorLogic->formatData($val);
        }
        unset($val);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 编辑/添加
     */
    public function edit()
    {
        $id = input('id', 0, 'intval');
        $title = $id ? "编辑" : "新建";

        if (request()->isPost()) {
            $data = input();
            $data['shopid'] = $this->shopid;
            // 数据验证
            try {
                validate(AuthorValidate::class)->scene('edit')->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }
            $res = $this->AuthorModel->edit($data);
            if ($res) {
                return $this->success($title . '成功', $res, cookie('__forward__'));
            } else {
                return $this->success($title . '失败');
            }
        }
    }

    /**
     * 设置内容状态
     */
    public function status()
    {
        $ids = input('ids');
        if (empty($ids)) {
            return $this->error('请选择要操作的数据');
        }
        !is_array($ids) && $ids = explode(',', $ids);
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

        $res = $this->AuthorModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }

    /**
     * 真实删除
     * 
     * 根据传入的ID数组删除对应的作者记录
     * @access public
     * @param mixed $ids 要删除的作者ID，支持数组或逗号分隔的字符串
     * @return json 返回删除操作的结果信息
     */
    public function del()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $res = $this->AuthorModel->where('id', 'in', $ids)->delete();
        if ($res) {
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }

    /**
     * 检查角色用户绑定状态，禁止用户绑定多个同类型角色
     */
    public function checkBind()
    {
        $id = intval(input('id'));
        $uid = intval(input('uid'));

        $res = $this->AuthorModel->getDataByMap([
            ['shopid', '=', 0],
            ['uid', '=', $uid]
        ]);
        if ($res && $res['id'] != $id) {
            return $this->error('该用户已绑定角色');
        } else {
            return $this->success('验证成功，允许绑定角色');
        }
    }

    /**
     * 状态审核
     */
    public function verify()
    {
        $id = input('id', 0, 'intval');
        if (request()->isPost()) {
            $status = input('status', -1, 'intval');
            $reason = input('reason', '', 'text');
            $res = $this->AuthorModel->where('id', $id)->update([
                'status' => $status,
                'reason' => $reason
            ]);

            if ($res) {
                return $this->success('操作成功');
            } else {
                return $this->error('操作失败');
            }
        }
    }

    /**
     * 角色分组
     */
    public function group()
    {
        $rows = input('rows', 20, 'intval');
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        // 拼接排序字段
        $order =  $order_field . ' ' . $order_type;
        //读取数据
        $map[] = ['status', '>', -1];
        $list = $this->AuthorGroupModel->getListByPage($map, $order, '*', $rows);

        // json response
        return $this->success('success', $list);
    }

    /**
     * 编辑分组
     */
    public function groupEdit()
    {
        $id = input('id', 0, 'intval');
        if (request()->isPost()) {
            $data = input();
            if (!empty($id)) {
                $res = $this->AuthorGroupModel->edit($data);
            } else {
                if ($this->AuthorGroupModel->where('title', '=', $data['title'])->count() > 0) {
                    return $this->error('已经有同名分组，请使用其他分组名称！');
                }
                $res = $this->AuthorGroupModel->edit($data);
            }
            if ($res) {
                return $this->success(empty($id) ? '新增分组成功' : '编辑分组成功', $res, cookie('__forward__'));
            } else {
                return $this->error(empty($id) ? '新增分组失败' : '编辑分组失败');
            }
        }
    }

    /**
     * 设置分组状态
     */
    public function groupStatus()
    {
        $ids = input('ids');
        if (empty($ids)) {
            return $this->error('请选择要操作的数据');
        }
        !is_array($ids) && $ids = explode(',', $ids);

        $status = input('status', 0, 'intval');

        $rs = $this->AuthorGroupModel->where('id', 'in', $ids)->update(['status' => $status]);
        if ($rs) {
            return $this->success('设置成功', $_SERVER['HTTP_REFERER']);
        } else {
            return $this->error('设置失败');
        }
    }
}
