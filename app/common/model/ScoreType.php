<?php

namespace app\common\model;

use think\Model;
use think\facade\Db;
use think\Exception;

/**
 * 用户积分类型模型
 */
class ScoreType extends Model
{
    /**
     * getTypeList  获取类型列表
     * @param string $map
     * @return mixed
     */
    public function getTypeList($map)
    {
        $list = $this->where($map)->order('id asc')->select()->toArray();

        return $list;
    }

    /**
     * 获取类型索引ID
     */
    public function getTypeListByIndex($map = []){
        $list = $this->where($map)->order('id asc')->select()->toArray();
        $array = [];
        foreach($list as $v)
        {
            $array[$v['id']] = $v;
        }
        return $array;
    }

    /**
     * getType  获取单个类型
     * @param string $map
     * @return mixed
     */
    public function getType(array $map)
    {
        $type = $this->where($map)->find();
        return $type;
    }

    /**
     * addType 增加积分类型
     * @param $data
     * @return mixed
     */
    public function addType($data)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $id = $this->insertGetId($data);
        // 转义单引号，防止 SQL 注入（ALTER TABLE 无法参数绑定）
        $title = str_replace("'", "''", isset($data['title']) ? (string)$data['title'] : '');
        $query = "alter table `{$db_prefix}member` ADD  `score" . $id . "` INT(11) NOT NULL DEFAULT '0' COMMENT  '" . $title . "'";

        Db::execute($query);

        return $id;
    }

    /**
     * delType  删除积分类型
     * @param $ids
     * @return mixed
     */
    public function delType($ids)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $res = $this->where([
            ['id','in', $ids], 
            ['id','>', 4]
        ])->delete();
        foreach ($ids as $v) {
            $v = intval($v);
            if ($v > 4) {
                $query = "alter table `{$db_prefix}member` drop column score" . $v;
                try {
                    Db::execute($query);
                } catch (Exception $e) {
                    // 不做处理
                }
            }
        }
        return $res;
    }

    /**
     * editType  修改积分类型
     * @param $data
     * @return mixed
     */
    public function editType($data)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $res = $this->update($data);
        $id = intval($data['id']);
        // 转义单引号，防止 SQL 注入（ALTER TABLE 无法参数绑定）
        $title = str_replace("'", "''", isset($data['title']) ? (string)$data['title'] : '');
        $query = "alter table `{$db_prefix}member` CHANGE `score{$id}` `score{$id}` INT(11) NOT NULL DEFAULT '0' COMMENT '" . $title . "'";
        Db::execute($query);
        return $res;
    }


    /**
     * getUserScore  获取用户的积分
     * @param int $uid
     * @param int $type
     * @return mixed
     */
    public function getUserScore($uid, $type)
    {
        $type = intval($type);
        $score = Db::name('member')->where(['uid' => $uid])->value('score' . $type);
        return $score;
    }

    /**
     * setUserScore  设置用户的积分
     * @param $uids
     * @param $score
     * @param $type
     * @param string $action
     */
    public function setUserScore($uids, $score, $type, $action = 'inc',$action_model ='',$record_id=0,$remark='')
    {
        $model = Db::name('member');
        // 类型字段仅允许数字，防止字段名拼接注入
        $type = intval($type);
        switch ($action) {
            case 'inc':
                $score = abs($score);
                $res = $model->where(['uid' => ['in', $uids]])->setInc('score' . $type, $score);
                break;
            case 'dec':
                $score = abs($score);
                $res = $model->where(['uid' => ['in', $uids]])->setDec('score' . $type, $score);
                break;
            case 'to':
                $res = $model->where(['uid' => ['in', $uids]])->setField('score' . $type, $score);
                break;
            default:
                $res = false;
                break;
        }

        if(!($action != 'to' && $score == 0)){
            $this->addScoreLog($uids,$type,$action,$score,$action_model,$record_id,$remark);
        }

        foreach ($uids as $val) {
           $this->cleanUserCache($val,$type);
        }
        unset($val);
        return $res;
    }

    public function getAllScore($uid)
    {
        $typeList = $this->getTypeList(array('status'=>1));
        $return = array();
        foreach($typeList as $key => &$v){
            $v['value'] = $this->getUserScore($uid,$v['id']);
            $return[$v['id']] = $v;
        }
        unset($v);
        return $return;
    }

}