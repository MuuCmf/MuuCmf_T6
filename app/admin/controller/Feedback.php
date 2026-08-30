<?php

namespace app\admin\controller;

use app\common\model\Feedback as FeedbackModel;
use app\common\logic\Feedback as FeedbackLogic;

/**
 * 用户反馈控制器
 */
class Feedback extends Admin
{
    protected FeedbackModel $FeedbackModel;
    protected FeedbackLogic $FeedbackLogic;

    /**
     * 构造方法
     * @access public
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->FeedbackModel = new FeedbackModel();
        $this->FeedbackLogic = new FeedbackLogic();
    }

    /**
     * 反馈列表
     */
    public function list()
    {
        $type = input('type', '', 'trim');
        $status = input('status', 'all', 'text');
        $keyword = input('keyword', '', 'trim');
        $rows = input('rows', 20, 'intval');
        //rows限制
        $rows = min($rows, 100);

        $map = [];
        if (!empty($type)) {
            $map[] = ['type', '=', $type];
        }
        if ($status != 'all' && $status != '') {
            $map[] = ['status', '=', $status];
        } else {
            $map[] = ['status', 'in', [0, 1, 2]];
        }
        if (!empty($keyword)) {
            $map[] = ['content', 'like', '%' . $keyword . '%'];
        }

        // 获取列表
        $lists = $this->FeedbackModel->getListByPage($map, 'id DESC', '*', $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->FeedbackLogic->formatData($val);
        }
        unset($val);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 设置状态
     */
    public function status()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '设置待处理';
        }
        if ($status == 1) {
            $title = '设置处理中';
        }
        if ($status == 2) {
            $title = '设置已完成';
        }

        if ($status == -1) {
            $title = '已删除';
        }
        $data['status'] = $status;

        $res = $this->FeedbackModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }
}
