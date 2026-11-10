<?php
declare (strict_types = 1);

namespace app\ucenter\controller;

use think\facade\View;
use app\common\model\ScoreType;
use app\common\model\ScoreLog;

class Score extends Base
{
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    public function __construct()
    {
        parent::__construct();
        
        $user = query_user(get_uid());
        View::assign('user', $user);
    }

    /**
     * 我的积分
     * @return [type] [description]
     */
    public function index()
    {
        $scoreTypeModel = new ScoreType();
        // 用户积分类型列表（按ID索引，供日志类型映射复用）
        $type_map = $scoreTypeModel->getTypeListByIndex(['status' => 1]);
        $scores = array_values($type_map);
        foreach ($scores as &$v) {
            $v['value'] = $scoreTypeModel->getUserScore(is_login(), $v['id']);
        }
        unset($v);
        View::assign('scores', $scores);

        // 获取积分日志列表
        $scoreLogModel = new ScoreLog();
        $lists = $scoreLogModel->getListByPage([
            ['uid', '=', get_uid()],
        ], 'create_time desc', '*', 10);
        $pager = $lists->render();
        $lists = $lists->toArray();
        foreach($lists['data'] as &$v){
            $v['type'] = $type_map[$v['type']] ?? [];
            if(!empty($v['create_time'])){
                $v['create_time_str'] = time_format($v['create_time']);
                $v['create_time_friendly_str'] = friendly_date($v['create_time']);
            }
        }
        unset($v);
        View::assign('pager', $pager);
        View::assign('lists', $lists);

        // 设置页面TITLE
        $this->setTitle('我的积分');
        View::assign('tab', 'myScore');
        // 输出模板
        return View::fetch();
    }

    //积分规则
    public function rule()
    {

        View::assign('tab', 'scoreRule');
        $this->setTitle('积分规则');
        return View::fetch();
    }

    /**
     * 积分等级
     */
    public function estate()
    {
        $scoreModel = new ScoreType();

        $scores = $scoreModel->getTypeList(['status'=>1]);
        foreach ($scores as &$v) {
            $v['value'] = $scoreModel->getUserScore(is_login(), $v['id']);
        }
        unset($v);
        View::assign('scores', $scores);

        $level = config('system.USER_LEVEL');
        View::assign('level', $level);

        View::assign('tab', 'scoreEstate');
        // 设置页面title
        $this->setTitle('积分等级');
        // 输出模板
        return View::fetch();
    }
}