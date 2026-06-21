<?php

namespace app\common\model;

use think\facade\Db;

class Action extends Base
{
    /**
     * 新增或更新一个行为
     * @return boolean fasle 失败 ， int  成功 返回完整的数据
     */
    public function editAction($data)
    {
        if (empty($data)) {
            return false;
        }

        if (!empty($data['action_rule'])) {
            $action_rule = $data['action_rule'];
            if (!empty($action_rule)) {

                for ($i = 0; $i < count($action_rule['table']); $i++) {
                    $data['rule'][] = [
                        'table' => $action_rule['table'][$i],
                        'field' => $action_rule['field'][$i],
                        'rule' => $action_rule['rule'][$i],
                        'cycle' => $action_rule['cycle'][$i],
                        'max' => $action_rule['max'][$i],
                    ];
                }
            }
        }

        if (empty($data['rule'])) {
            $data['rule'] = '';
        } else {
            $data['rule'] = json_encode($data['rule']);
        }

        unset($data['action_rule']);
        /* 添加或新增行为 */
        if (empty($data['id'])) { //新增数据

            $res = $this->save($data); //添加行为
            if (!$res) {
                $this->error = lang('_NEW_BEHAVIOR_WITH_EXCLAMATION_');
                return false;
            }
        } else { //更新数据
            $res = $this->update($data); //更新基础内容
            if (!$res) {
                $this->error = lang('_UPDATE_BEHAVIOR_WITH_EXCLAMATION_');
                return false;
            }
        }
        //删除缓存
        cache('action_list', null);

        //内容添加或更新完成
        return $data;
    }


    public function getAction($map)
    {
        $result = $this->where($map)->select()->toArray();
        foreach ($result as &$v) {
            if ($v['module'] == '' || empty($v['module'])) {
                //默认系统行为模块名
                $v['module'] = 'admin';
            }
        }
        unset($v);
        return $result;
    }

    public function getActionOpt()
    {
        $result = $this->where(['status' => 1])->field('name,title')->select()->toArray();
        return $result;
    }

    public function getActionName($key)
    {
        !is_array($key) && $key = explode(',', str_replace(array('[', ']'), '', $key));
        $return = array();
        foreach ($key as $val) {
            $return[] = $this->where(['name' => $val])->value('title');
        }

        return implode(',', $return);
    }

    /**
     * 解析行为规则
     * 规则定义  table:$table|field:$field|condition:$condition|rule:$rule[|cycle:$cycle|max:$max][;......]
     * 规则字段解释：table->要操作的数据表，不需要加表前缀；
     *              field->要操作的字段；
     *              condition->操作的条件，目前支持字符串，默认变量{$self}为执行行为的用户
     *              rule->对字段进行的具体操作，目前支持四则混合运算，如：1+score*2/2-3
     *              cycle->执行周期，单位（小时），表示$cycle小时内最多执行$max次
     *              max->单个周期内的最大执行次数（$cycle和$max必须同时定义，否则无效）
     * 单个行为后可加 ； 连接其他规则
     * @param string $action 行为id或者name
     * @param int $self 替换规则里的变量为执行用户的id
     * @return boolean|array: false解析出错 ， 成功返回规则数组
     * @author huajie <banhuajie@163.com>
     */
    public function parseAction($action, $uid)
    {
        if (empty($action)) {
            return false;
        }

        //参数支持id或者name
        if (is_numeric($action)) {
            $map = array('id' => $action);
        } else {
            $map = array('name' => $action);
        }

        //查询行为信息
        $info = Db::name('Action')->where($map)->find();

        if (!$info || $info['status'] != 1) {
            return false;
        }


        //解析规则:table:$table|field:$field|condition:$condition|rule:$rule[|cycle:$cycle|max:$max][;......]
        $rules = json_decode($info['rule'], true);

        if (empty($rules) || !is_array($rules)) {
            return false;
        }

        foreach ($rules as $key => &$rule) {
            foreach ($rule as $k => &$v) {
                if (empty($v)) {
                    unset($rule[$k]);
                }
            }
            unset($k, $v);
        }
        unset($key, $rule);

        return $rules;
    }

    /**
     * 执行行为
     * @param array $rules 解析后的规则数组
     * @param int $action_id 行为id
     * @param array $uid 执行的用户id
     * @return boolean false 失败 ， true 成功
     * @author huajie <banhuajie@163.com>
     */
    public function executeAction($rules = false, $action_id = null, $uid = null, $log_id = null)
    {
        if (!$rules || empty($action_id) || empty($uid)) {
            return false;
        }
        $return = true;

        $action_log = Db::name('ActionLog')->where(['id' => $log_id])->find();


        //行为日志
        foreach ($rules as $rule) {

            //检查执行周期
            $map = [];
            $map[] = ['action_id', '=', $action_id];
            $map[] = ['uid', '=', $uid];
            $map[] = ['create_time',  '>', time() - intval($rule['cycle']) * 3600];
            $exec_count = Db::name('ActionLog')->where($map)->count();
            if ($exec_count > $rule['max']) {
                continue;
            }

            //获取现在的积分数量
            $memberModel = new \app\common\model\Member();
            $field = 'score' . $rule['field'];
            $nowScore = $memberModel->where(['uid' => $uid])->value($field);
            $rule['rule'] = (is_bool(strpos($rule['rule'], '+')) ? '+' : '') . $rule['rule'];
            $rule['rule'] = is_bool(strpos($rule['rule'], '-')) ?  $rule['rule'] : substr($rule['rule'], 1);
            //应该设置的积分
            $newScore = floatval($nowScore) + $rule['rule'];
            //设置积分 //执行数据库操作
            $res = $memberModel->where(['uid' => $uid, 'status' => 1])->save([$field => $newScore]);
            if (!$res) {
                $return = false;
            } else {
                $scoreModel = new \app\common\model\ScoreLog();

                $sType = Db::name('score_type')->where(['id' => $rule['field']])->find();
                $log_score = '';
                $log_score .= '【' . $sType['title'] . '：' . $rule['rule'] . $sType['unit'] . '】';

                $action = strpos($rule['rule'], '-') ? 'dec' : 'inc';
                //写积分日志
                $scoreModel->addScoreLog($uid, $rule['field'], $action, substr($rule['rule'], 1, strlen($rule['rule']) - 1), $action_log['model'], $action_log['record_id'], $action_log['remark'] . '【' . $sType['title'] . '：' . $rule['rule'] . $sType['unit'] . '】');
            }

            if (isset($log_score)) {
                Db::name('ActionLog')->where(['id' => $log_id])->update(['remark' => $action_log['remark'] . ',' . $log_score]);
            }
        }

        return $return;
    }
}
