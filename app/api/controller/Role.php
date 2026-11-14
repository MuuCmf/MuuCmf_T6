<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Author as AuthorModel;
use app\common\logic\Author as AuthorLogic;
use app\common\model\Follow as FollowModel;
use app\common\logic\AuthorFollow as AuthorFollowLogic;
use app\common\model\AuthorGroup as AuthorGroupModel;

/**
 * 角色列表
 * todo:替代Author.php, 用于角色管理
 */
class Role extends Api
{
    protected $AuthorModel;
    protected $AuthorLogic;
    protected $AuthorGroupModel;

    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['only' => ['follow', 'isfollow']],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->_initialize();
    }

    public function _initialize()
    {
        $this->AuthorModel   = new AuthorModel();  //模型
        $this->AuthorLogic   = new AuthorLogic();  //逻辑
        $this->AuthorGroupModel = new AuthorGroupModel(); //角色分组模型
    }

    /**
     * 作者列表
     * @return     <type>  ( description_of_the_return_value )
     */
    public function lists()
    {
        $group_id = input('group_id', 0, 'intval');
        $rows = input('rows', 20, 'intval');
        $keyword = input('keyword', '', 'text');
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array(strtolower($order_field), $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array(strtolower($order_type), $allowed_types) ? $order_type : 'desc';
        // 排序字段id时，按sort排序
        if ($order_field == 'id') {
            $order = 'sort DESC,' . $order_field . ' ' . $order_type;
        } else {
            $order = $order_field . ' ' . $order_type;
        }

        // 约束rows范围
        $rows = min($rows, 100);

        // 查询条件
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['status', '=', 1];

        if (!empty($group_id)) {
            $map[] = ['group_id', '=', $group_id];
        }
        if (!empty($keyword)) {
            $map[] = ['name', 'like', '%' . $keyword . '%'];
        }

        $fields = '*';
        $lists = $this->AuthorModel->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = $this->AuthorLogic->formatData($val);
        }
        unset($val);
        // ajax请求返回数据
        return $this->success('success', $lists);
    }

    /**
     * 详情
     *
     * @param      integer  $id     The identifier
     * @return     <type>   ( description_of_the_return_value )
     */
    public function detail()
    {
        $id = input('id', 0, 'intval');

        if (!empty($id)) {
            $data = $this->AuthorModel->getDataById($id);
            $data = $this->AuthorLogic->formatData($data);

            //查询条件
            $map = [
                ['shopid', '=', $this->shopid],
                ['author_id', '=', $id],
                ['status', '=', 1]
            ];

            if (!empty($data)) {
                // ajax请求返回数据
                return $this->success('success', $data);
            } else {
                return $this->error('error');
            }
        } else {
            return $this->error('缺少参数');
        }
    }

    /**
     * 关注、取消关注
     */
    public function follow()
    {
        $author_id = input('author_id', 0, 'intval');
        $uid = get_uid();

        if (empty($author_id)) {
            return $this->error('缺少参数');
        }

        $result = (new AuthorFollowLogic())->toggleFollow($this->shopid, $uid, $author_id);
        if (empty($result['success'])) {
            return $this->error($result['msg']);
        }

        return $this->success($result['msg']);
    }

    /**
     * 是否已关注
     */
    public function isfollow()
    {
        $author_id = input('author_id', 0, 'intval');
        $uid = get_uid();

        if ((new AuthorFollowLogic())->isFollow($this->shopid, $uid, $author_id)) {
            return $this->success('已关注');
        }

        return $this->error('未关注或缺少参数');
    }

    /**
     * 关注创作者列表
     */
    public function followList()
    {
        $uid = get_uid();
        $rows = input('rows', 20, 'intval');
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = $order_field . ' ' . $order_type;

        // 查询条件
        $map = [
            ['shopid', '=', $this->shopid],
            ['who_follow', '=', $uid],
            ['status', '=', 1]
        ];
        $fields = '*';
        $lists = (new FollowModel())->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = (new AuthorFollowLogic())->formatData($val);
        }
        unset($val);
        // ajax请求返回数据
        return $this->success('success', $lists);
    }

    /**
     * 角色分组列表
     */
    public function group()
    {
        $map = [
            ['status', '=', 1]
        ];
        $order = 'sort DESC, id ASC';
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 排序字段/方向白名单，防止 order by SQL 注入
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'id';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = $order_field . ' ' . $order_type;

        // 获取列表
        $lists = $this->AuthorGroupModel->getList($map, 999, $order, '*');

        return $this->success('success', $lists);
    }
}
