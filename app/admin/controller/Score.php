<?php

namespace app\admin\controller;

use think\facade\Db;
use app\common\model\Member as MemberModel;
use app\common\model\ScoreLog as ScoreLogModel;
use app\common\model\ScoreType as ScoreTypeModel;

/**
 * 积分相关功能控制器
 */
class Score extends Admin
{
    protected MemberModel $MemberModel;
    protected ScoreLogModel $scoreLogModel;
    protected ScoreTypeModel $scoreTypeModel;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->MemberModel = new MemberModel();
        $this->scoreLogModel = new ScoreLogModel();
        $this->scoreTypeModel = new ScoreTypeModel();
    }

    /**
     * 积分日志
     * @param  integer $r [description]
     * @param  integer $p [description]
     * @return [type]     [description]
     */
    public function log()
    {
        $keyword = input('keyword', '', 'trim');
        $rows = input('rows', 20, 'intval');
        // rows限制
        $rows = min($rows, 100);
        // 初始化查询条件
        $where = [];
        if (!empty($keyword) && $keyword != 'undefined') {
            $where[] = function($query) use ($keyword) {
                $query->where('m.nickname', 'like', '%' . $keyword . '%')
                      ->whereOr('m.mobile', 'like', '%' . $keyword . '%')
                      ->whereOr('m.uid', 'like', '%' . $keyword . '%');
            };
        }

        $scoreLog = $this->scoreLogModel
        ->alias('sl')
        ->join('member m', 'sl.uid = m.uid')
        ->field('sl.*, m.nickname, m.mobile')
        ->order('sl.create_time desc')
        ->where($where)
        ->paginate([
                'list_rows' => $rows,
                'query' => [
                    'keyword' => $keyword
                ],
            ], false);
        
        // 转数组处理
        $scoreLog = $scoreLog->toArray();
        // 处理积分类型名称
        $scoreTypes = $this->scoreTypeModel->getTypeListByIndex();
        // 处理积分变动类型
        foreach ($scoreLog['data'] as &$v) {
            if (empty($v['uid'])){
                $v['uid'] = 0;
            }else{
                $v['user_info'] = $this->MemberModel->info($v['uid'], '*');
            }
            $v['adjust_type'] = $v['action'] == 'inc' ? '增加' : '减少';
            $v['score_type'] = $scoreTypes[$v['type']]['title'];
            $v['value'] = ($v['action'] == 'inc' ? '+' : '-') . $v['value'] . $scoreTypes[$v['type']]['unit'];
            $v['final_value'] = $v['finally_value'] . $scoreTypes[$v['type']]['unit'];
            if (!empty($v['create_time'])) {
                $v['create_time_str'] = time_format($v['create_time']);
                $v['create_time_friendly_str'] = friendly_date($v['create_time']);
            }
        }
        unset($v);

        // json result
        return $this->success('success',$scoreLog);
    }

    /**
     * 清空积分日志
     */
    public function clear()
    {
        Db::name('ScoreLog')->where('id', '>', 0)->delete();
        return $this->success('清空成功。', url('scoreLog'));
    }

    /**
     * 积分列表
     * @return [type] [description]
     */
    public function type()
    {
        $status = input('status', '', 'text');
        $keyword = input('keyword', 0, '');
        $map = [];
        if (!empty($keyword)) {
            $map[] = ['title', 'like', '%' . $keyword . '%'];
        }
        //读取数据
        if (empty($status)) {
            $map[] = ['status', 'in', [0, 1]];
        } else {
            $map[] = ['status', '=', intval($status)];
        }
        $list = $this->scoreTypeModel->getTypeList($map);

        // json result
        return $this->success('success',$list);
    }

    /**
     * 编辑积分类型
     */
    public function typeEdit()
    {
        $aId = input('id', 0, 'intval');

        if (request()->isPost()) {
            $data['title'] = input('post.title', '', 'text');
            $data['status'] = input('post.status', 1, 'intval');
            $data['unit'] = input('post.unit', '', 'text');

            if (!empty($aId)) {
                $data['id'] = $aId;
                $res = $this->scoreTypeModel->editType($data);
            } else {
                $res = $this->scoreTypeModel->addType($data);
            }
            if ($res) {
                return $this->success(($aId == 0 ? lang('Add') : lang('Edit')) . lang('Success'), $res, cookie('__forward__'));
            } else {
                return $this->error(($aId == 0 ? lang('Add') : lang('Edit')) . lang('Failed'));
            }
        }
    }

    /**
     * 设置积分类型状态
     */
    public function typeStatus($ids, $status)
    {
        $ids = input('ids/a');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $ids = array_unique((array)$ids);
        
        $status = input('status', 0, 'intval');
        $title = '更新';
        if($status == 0){
            $title = '禁用';
        }
        if($status == 1){
            $title = '启用';
        }
        if($status == -1){
            $title = '删除';
        }
        $data['status'] = $status;
        
        $res = $this->scoreTypeModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }

    /**
     * 删除积分类型
     */
    public function typeDel()
    {
        $ids = input('ids');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $ids = array_unique((array)$ids);

        $res = $this->scoreTypeModel->delType($ids);
        if ($res) {
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }
}
