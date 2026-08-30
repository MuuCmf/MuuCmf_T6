<?php

namespace app\admin\controller;

use app\common\model\Keywords as KeywordsModel;
use app\common\logic\Keywords as KeywordsLogic;
use app\admin\validate\Common;
use think\exception\ValidateException;

/**
 * 搜索关键字控制器
 */
class Keywords extends Admin
{
    protected KeywordsModel $KeywordsModel;
    protected KeywordsLogic $KeywordsLogic;
    /**
     * 构造方法
     * @access public
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->KeywordsModel = new KeywordsModel();
        $this->KeywordsLogic = new KeywordsLogic();
        // 设置页面title
        $this->setTitle('搜索关键字管理');
    }

    /**
     * 列表
     */
    public function list()
    {
        // 查询条件
        $map = [
            ['status', '>', -1],
            ['shopid', '=', 0]
        ];
        // 搜索关键字
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $map[] = ['keyword', 'like', '%' . $keyword . '%'];
        }

        $fields = '*';
        $rows = input('rows', 20, 'intval');
        //rows限制
        $rows = min($rows, 100);

        $lists = $this->KeywordsModel->getListByPage($map, 'create_time desc', $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->KeywordsLogic->formatData($val);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 编辑、新增
     */
    public function edit()
    {
        $id = input('id', 0, 'intval');
        $title = $id ? "编辑" : "新建";

        if (request()->isPost()) {
            $data = input();
            // 数据验证
            try {
                validate(Common::class)->scene('keywords')->check([
                    'keyword'  => $data['keyword'],
                ]);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            // 写入数据表
            $res = $this->KeywordsModel->edit($data);

            if ($res) {
                return $this->success($title . '成功', $res, cookie('__forward__'));
            } else {
                return $this->error($title . '失败');
            }
        }
    }

    /**
     * 状态管理
     */
    public function status()
    {
        $ids = input('ids');
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

        $res = $this->KeywordsModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }

    /**
     * 推荐状态管理
     * @description 批量更新关键字的推荐状态
     */
    public function recommend()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $recommend = input('recommend', 0, 'intval');
        $title = $recommend == 1 ? '推荐' : '取消推荐';

        $res = $this->KeywordsModel->where('id', 'in', $ids)->update(['recommend' => $recommend]);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }
}
