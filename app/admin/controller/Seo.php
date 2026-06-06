<?php

namespace app\admin\controller;

use app\common\model\SeoRule as SeoRuleModel;

class Seo extends Admin
{
    protected SeoRuleModel $seoRuleModel;

    public function __construct()
    {
        parent::__construct();
        $this->seoRuleModel = new SeoRuleModel();
    }

    /**
     * 规则列表
     */
    public function list()
    {
        //读取规则列表
        $app = input('app', '', 'text');
        $keyword = input('keyword', '', 'text');
        $map = [
            ['status', 'in', [0, 1]]
        ];
        if (!empty($app)) {
            $map[] = ['app', '=', $app];
        }
        if (!empty($keyword)) {
            $map[] = ['title|controller|action|seo_title|seo_keywords|seo_description', 'like', "%{$keyword}%"];
        }

        $ruleList = $this->seoRuleModel->getListByPage($map, 'sort asc,id desc');
        
        // json response
        return $this->success(lang('操作成功'), $ruleList);
    }

    /**
     * 编辑规则
     */
    public function edit($id = 0)
    {
        //判断是否为编辑模式
        $isEdit = $id ? true : false;
        if (request()->isPost()) {
            $params = input();
            //写入数据库
            $data = [
                'title' => $params['title'],
                'app' => strtolower($params['app']),
                'controller' => strtolower($params['controller']),
                'action' => strtolower($params['action2']),
                'seo_title' => $params['seo_title'],
                'seo_keywords' => $params['seo_keywords'],
                'seo_description' => $params['seo_description'],
                'status' => $params['status']
            ];

            //查询是否包含相同规则
            $has_map = [
                ['app', '=', $data['app']],
                ['controller', '=', $data['controller']],
                ['action', '=', $data['action']],
                ['status', 'in', [0, 1]]
            ];

            $has_rule = $this->seoRuleModel->where($has_map)->find();
            if ($has_rule && !$isEdit) {
                return $this->error('已存在相同规则');
            }

            if ($isEdit) {
                $result = $this->seoRuleModel->where(['id' => $id])->update($data);
            } else {
                $result = $this->seoRuleModel->insert($data);
            }

            //如果失败的话，显示失败消息
            if (!$result) {
                return $this->error($isEdit ? '编辑失败' : '创建失败');
            }

            //显示成功信息，并返回规则列表
            return $this->success($isEdit ? '编辑成功' : '创建成功', $result, url('list'));
        }
    }

    /**
     * 配置状态
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

        $result = $this->seoRuleModel->where('id', 'in', $ids)->update($data);
        if (!$result) {
            return $this->error('操作失败');
        }
        return $this->success($title . '成功', url('list'));
    }
}
