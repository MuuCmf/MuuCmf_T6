<?php

namespace app\admin\controller;

use app\common\model\History as HistoryModel;
use app\common\logic\History as HistoryLogic;

class History extends Admin
{
    /** @var HistoryModel 浏览记录模型 */
    protected HistoryModel $HistoryModel;

    /** @var HistoryLogic 浏览记录逻辑 */
    protected HistoryLogic $HistoryLogic;

    public function __construct()
    {
        parent::__construct();
        $this->HistoryModel = new HistoryModel();
        $this->HistoryLogic = new HistoryLogic();
    }

    /**
     * 浏览记录列表
     */
    public function list()
    {
        $app = input('get.app', 'all');
        $keyword = input('keyword', '');
        $rows = input('rows', 20, 'intval');
        //rows限制
        $rows = min($rows, 100);

        $map = [
            ['shopid', '=', $this->shopid],
            ['status', 'in', [0, 1]]
        ];
        if ($app != 'all')  $map[] = ['app', '=', $app]; //标识

        if (!empty($keyword)) {
            $map[] = ['metadata', 'like', '%' . $keyword . '%'];
        }
        // 获取分页列表
        $lists = $this->HistoryModel->getListByPage($map, 'id desc create_time desc', '*', $rows);
        // 格式化数据
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->HistoryLogic->formatData($val);
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
        if ($status == -1) {
            $title = '删除';
        }
        $data['status'] = $status;

        $res = $this->HistoryModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功', $res, 'refresh');
        } else {
            return $this->error($title . '失败');
        }
    }
}
