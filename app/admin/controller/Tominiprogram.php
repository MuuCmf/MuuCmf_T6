<?php

namespace app\admin\controller;

use app\common\logic\Tominiprogram as TominiprogramLogic;
use app\common\model\Tominiprogram as TominiprogramModel;

/**
 * @title 跳转小程序
 * Class Tominiprogram
 * @package app\admin\controller
 */
class Tominiprogram extends Admin
{
    protected TominiprogramModel $TominiprogramModel;
    protected TominiprogramLogic $TominiprogramLogic;
    protected $type;
    public function __construct()
    {
        parent::__construct();
        $this->TominiprogramLogic = new TominiprogramLogic();
        $this->TominiprogramModel = new TominiprogramModel();
        $this->shopid = request()->param('shopid') ?? 0;
        $this->type = request()->param('type') ?? 'weixin_app';
    }

    public function list()
    {
        $rows = input('rows', 10, 'intval');
        $map = [
            ['shopid', '=', $this->shopid],
            ['type', '=', $this->type]
        ];
        // 获取列表
        $lists = $this->TominiprogramModel->getListByPage($map, 'id DESC', '*', $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->TominiprogramLogic->formatData($val);
        }
        unset($val);

        // 返回数据
        return $this->success('', $lists);
    }

    /**
     * 编辑小程序信息
     * 
     * 处理AJAX请求和非AJAX请求两种情况：
     * - AJAX请求时，接收并验证表单数据，调用模型保存数据
     * - 非AJAX请求时，根据ID获取小程序信息并渲染编辑页面
     * 
     * @return mixed AJAX请求返回JSON响应，非AJAX请求返回视图渲染结果
     * @throws \think\Exception 数据库操作异常时抛出
     */
    public function edit()
    {
        $id = input('id', 0);
        if (request()->isAjax()) {
            $data = [
                'id'    => $id,
                'title' => request()->param('title'),
                'appid' => request()->param('appid'),
                'qrcode' => request()->param('qrcode'),
                'shopid' => $this->shopid,
                'type'  => request()->param('type')
            ];
            $result = $this->TominiprogramModel->edit($data);
            if ($result) {
                return $this->success('保存成功', $result, url('list'));
            }
            return $this->error('保存失败');
        }
    }

    /**
     * 更新跳转小程序状态
     */
    public function status()
    {
        $ids = input('ids');
        $ids = is_array($ids) ? $ids : explode(',', (string)$ids);
        if (empty($ids)) {
            return $this->error('请选择要操作的数据');
        }

        $status = input('status', 0, 'intval');
        $result = $this->TominiprogramModel->where('id', 'in', $ids)->where('shopid', $this->shopid)->update(['status' => $status]);
        if ($result) {
            return $this->success('更新成功');
        }
        return $this->error('更新失败');
    }

    /**
     * 设置分组状态：删除=-1，禁用=0，启用=1
     * @param $status
     */
    public function del()
    {
        $id = array_unique((array)input('id', 0));
        if (empty($id)) {
            return $this->error('请选择要操作的数据');
        }
        $id = is_array($id) ? $id : explode(',', $id);
        $result = $this->TominiprogramModel->where('id', 'in', $id)->delete();
        if ($result) {
            return $this->success('删除成功');
        }
        return $this->error('删除失败,请稍后再试');
    }
}
