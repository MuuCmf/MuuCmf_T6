<?php

namespace app\admin\controller;

use app\common\model\Field as FieldModel;
use app\common\model\FieldGroup as FieldGroupModel;
use app\common\model\FieldSetting as FieldSettingModel;

/**
 * 后台扩展字段控制器
 */
class Field extends Admin
{
    protected FieldModel $FieldModel;
    protected FieldGroupModel $FieldGroupModel;
    protected FieldSettingModel $FieldSettingModel;

    public function __construct()
    {
        parent::__construct();

        $this->FieldModel = new FieldModel();
        $this->FieldGroupModel = new FieldGroupModel();
        $this->FieldSettingModel = new FieldSettingModel();
    }

    /**
     * 扩展用户信息分组列表
     */
    public function group()
    {
        $map[] = ['status', '>=', 0];
        $profileList = $this->FieldGroupModel->where($map)->order("sort asc")->select();
        $profileList = $profileList->toArray();
        foreach ($profileList as &$value) {
            // 可见性
            $value['visiable_str'] = $value['visiable'] == 1 ? '可见' : '隐藏';
            // 状态
            $value['status_str'] = $value['status'] == 1 ? '启用' : '禁用';
            
            // 处理创建时间
            if (!empty($value['create_time'])) {
                $value['create_time_str'] = time_format($value['create_time']);
                $value['create_time_friendly_str'] = friendly_date($value['create_time']);
            }
        }
        unset($value);

        // json response
        return $this->success('success', $profileList);
    }

    /**
     * 添加、编辑分组信息
     * @param $id
     * @param $profile_name
     * @author dameng <59262424@qq.com>
     */
    public function editGroup()
    {
        if (request()->isPost()) {
            $id = input('id', 0, 'intval');
            $profile_name = input('profile_name', '', 'text');
            $visiable = input('visiable', 1, 'intval');
            $status = input('status', 1, 'intval');
            if (empty($profile_name)) {
                return $this->error('分组名称不能为空！');
            }

            $map[] = ['profile_name', '=', $profile_name];
            $map[] = ['status', '>=', 0];
            if ($id != 0) {
                $map[] = ['id', '<>', $id];
            }

            if ($this->FieldGroupModel->where($map)->count() > 0) {
                return $this->error('已经有同名分组，请使用其他分组名称！');
            }

            $data['id'] = $id;
            $data['profile_name'] = $profile_name;
            $data['visiable'] = $visiable;
            $data['status'] = $status;
            $data['create_time'] = time();
            $res = $this->FieldGroupModel->edit($data);

            if ($res) {
                return $this->success($id == '' ? '新增分组成功' : '编辑分组成功', '', url('group')->build());
            } else {
                return $this->error($id == '' ? '新增分组失败' : '编辑分组失败');
            }
        }
    }

    /**
     * 设置分组状态：删除=-1，禁用=0，启用=1
     * @param $status
     */
    public function setGroupStatus()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '禁用分组';
        }
        if ($status == 1) {
            $title = '启用分组';
        }
        if ($status == -1) {
            $title = '删除分组';
        }
        $data['status'] = $status;
        $res = $this->FieldGroupModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }

    /**
     * 扩展字段列表
     * @param $id
     */
    public function list()
    {
        $group_id = input('group_id', 0, 'intval');

        // 获取字段列表
        $map[] = ['status', '>', 0];
        $map[] = ['group_id', '=', $group_id];
        $field_list = $this->FieldSettingModel->where($map)->order("sort asc")->select();

        // 表单类型
        $type_default = [
            'input' => '文本框',
            'radio' => '单选项',
            'checkbox' => '多选项',
            'select' => '下拉框',
            'time' => '日期/时间',
            'textarea' => '文本域'
        ];

        foreach ($field_list as &$val) {
            if(!empty($val['form_type']) && array_key_exists($val['form_type'], $type_default)){
                $val['form_type_str'] = $type_default[$val['form_type']];
            }
        }
        unset($val);

        // json response
        return $this->success('success', $field_list);
    }

    /**
     * 添加、编辑字段信息
     * @param $id
     * @param $group_id
     * @param $field_name
     * @param $child_form_type
     * @param $visiable
     * @param $required
     * @param $form_type
     * @param $form_default_value
     * @param $validation
     * @param $input_tips
     */
    public function editField()
    {
        if (request()->isPost()) {

            $data = input('');
            if ($data['field_name'] == '') {
                return $this->error('字段名称不能为空！');
            }
            if ($data['field_alias'] == '') {
                return $this->error('字段描述不能为空！');
            }

            //当表单类型为以下三种是默认值不能为空判断@MingYang
            $form_types = array('radio', 'checkbox', 'select');
            if (in_array($data['form_type'], $form_types)) {
                if ($data['form_default_value'] == '') {
                    return $this->error($data['form_type'] . '表单类型默认值不能为空');
                }
            }

            $map[] = ['field_name', '=', $data['field_name']];
            $map[] = ['status', '>=', 0];
            $map[] = ['group_id', '=', $data['group_id']];
            if (!empty($data['id'])) {
                $map[] = ['id', '<>', $data['id']];
            }
            if ($this->FieldSettingModel->where($map)->count() > 0) {
                return $this->error('该分组下已经有同名字段，请使用其他名称！');
            }
            $data['status'] = 1;
            $data['sort'] = 0;

            $res = $this->FieldSettingModel->edit($data);
            if ($res) {
                return $this->success($data['id'] == '' ? '添加字段成功' : '编辑字段成功', $res, cookie('__forward__'));
            } else {
                return $this->error($data['id'] == '' ? '添加字段失败' : '编辑字段失败');
            }
        } 
    }

    /**
     * 设置字段状态：删除=-1，禁用=0，启用=1
     * @param $ids
     * @param $status
     * @author dameng<59262424@qq.com>
     */
    public function setFieldStatus()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '禁用字段';
        }
        if ($status == 1) {
            $title = '启用字段';
        }
        if ($status == -1) {
            $title = '删除字段';
        }
        $data['status'] = $status;

        $res = $this->FieldSettingModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }
}
