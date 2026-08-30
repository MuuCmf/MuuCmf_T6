<?php

namespace app\admin\controller;

use think\facade\Cache;
use think\exception\ValidateException;
use app\common\model\Config as MuuConfigModel;
// +----------------------------------------------------------------------
// | TODO:系统设置 站点信息内容包含：站点基本信息 联络和客服信息 版权信息
// +----------------------------------------------------------------------

/**
 * 后台配置控制器
 */
class Config extends Admin
{
    protected MuuConfigModel $ConfigModel;
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->ConfigModel = new MuuConfigModel();
    }

    // 获取某个标签的配置参数
    public function group()
    {
        if (request()->isPost()) {
            $config = input('post.config/a');
            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    $this->ConfigModel->where($map)->save(['value' => $value]);
                }
            }
            // 清除缓存
            Cache::delete(request()->host() . '_MUUCMF_SYS_CONFIG_DATA');
            return $this->success('保存成功', $config, cookie('__forward__'));
        } else {
            $group = input('group', 1, 'intval');
            // 配置分组
            $type = config('system.CONFIG_GROUP_LIST');
            // 配置项列表
            $list = $this->ConfigModel->where(['status' => 1, 'group' => $group])->field('id,name,title,extra,value,group,remark,type')->order('sort asc')->select()->toArray();
            foreach ($list as $key => $value) {
                // pic类型生成缩微图组
                if ($value['type'] == 'pic' && !empty($value['value'])) {
                    $list[$key]['thumb'] = thumb_group($value['value']);
                }
            }

            $result = [
                'group' => $type,
                'list' => $list,
            ];
            return $this->success('success', $result);
        }
    }

    /**
     * 获取配置分组列表
     */
    public function groupList()
    {
        // 配置分组
        $type = config('system.CONFIG_GROUP_LIST');
        return $this->success('success', $type);
    }

    /**
     * 系统配置参数管理
     */
    public function list()
    {
        // 加载方式 all 全量查询  page 分页查询
        $load = input('load', 'page', 'text');
        $keyword = input('keyword', '', 'trim');
        $group = input('group', 0, 'intval');
        $rows = input('rows', 20, 'intval');

        /* 查询条件初始化 */
        $map = [];
        $map[] = ['status', '=', 1];
        if (!empty($group)) {
            $map[] = ['group', 'in', $group];
        }
        
        if (!empty($keyword)) {
            $map[] = function ($query) use ($keyword) {
                $query->where('name', 'like', "%{$keyword}%")
                    ->whereOr('title', 'like', "%{$keyword}%");
            };
        }
        
        if ($load == 'page') {
            // 分页查询
            $list = $this->ConfigModel->getListByPage($map, 'sort asc,id desc', '*', $rows);
            $list = $list->toArray();
            foreach ($list['data'] as $key => $item) {
                $list['data'][$key]['type_name'] = get_config_type($item['type']);
                $list['data'][$key]['group_name'] = get_config_group($item['group']);
                // pic类型生成缩微图组
                if ($item['type'] == 'pic' && !empty($item['value'])) {
                    $list['data'][$key]['thumb'] = thumb_group($item['value']);
                }
            }
        } else {
            // 全量查询
            $list = $this->ConfigModel->where($map)->field('id,name,title,extra,value,group,remark,type')->order('sort asc')->select()->toArray();
            foreach ($list as $key => $item) {
                $list[$key]['type_name'] = get_config_type($item['type']);
                $list[$key]['group_name'] = get_config_group($item['group']);
                // pic类型生成缩微图组
                if ($item['type'] == 'pic' && !empty($item['value'])) {
                    $list['data'][$key]['thumb'] = thumb_group($item['value']);
                }
            }
        }

        // ajax请求返回数据
        return $this->success('success', $list);
    }

    /**
     * 编辑系统配置
     */
    public function edit()
    {
        if (request()->isPost()) {
            $data = input() ?? [];
            $data['status'] = 1; //默认状态为启用

            //验证器
            try {
                validate(
                    [
                        'name'  => 'require|max:36',
                        'title'   => 'require',
                    ],
                    [
                        'name.require' => '标识必须填写',
                        'name.max'     => '标识最多不能超过36个字符',
                        'title.require'   => '标题必须填写',
                    ]
                )->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            $res = $resId = $this->ConfigModel->edit($data);

            if ($res) {
                Cache::delete(request()->host() . '_MUUCMF_SYS_CONFIG_DATA', null);
                //记录行为
                action_log('update_config', 'config', $resId, is_login());
                return $this->success('操作成功', $res, cookie('__forward__'));
            } else {
                return $this->error('操作失败');
            }
        }
    }

    /**
     * 删除配置
     */
    public function del()
    {
        $id = array_unique((array)input('id', 0));

        if (empty($id)) {
            return $this->error('参数错误');
        }

        if ($this->ConfigModel->where('id', 'in', $id)->delete()) {
            Cache::delete(request()->host() . '_MUUCMF_SYS_CONFIG_DATA', null);
            //记录行为
            action_log('update_config', 'config', $id, is_login());
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }
}
