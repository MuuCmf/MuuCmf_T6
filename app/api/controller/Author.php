<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Author as AuthorModel;
use app\common\logic\Author as AuthorLogic;
use app\common\model\AuthorFollow as AuthorFollowModel;
use app\common\logic\AuthorFollow as AuthorFollowLogic;

class Author extends Api
{
    protected AuthorModel $AuthorModel;
    protected AuthorLogic $AuthorLogic;
    
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
    }

    /**
     * 作者列表
     */
    public function lists()
    {
        $rows = input('rows', 20, 'intval');
        $keyword = input('keyword', '', 'text');
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        $order = 'sort DESC,' . $order_field . ' ' . $order_type;

        // 查询条件
        $map = $this->AuthorLogic->getMap($this->shopid, $keyword, 1);
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

        // 判断是否已关注
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['author_id', '=', $author_id],
        ];
        $follow = (new AuthorFollowModel())->where($map)->find();

        // 写入数据初始化
        $data = [];
        if ($follow) {
            $data['id'] = $follow['id'];
            if ($follow['status'] == 1) $data['status'] = 0;
            if ($follow['status'] == 0) $data['status'] = 1;
        } else {
            $data['shopid'] = $this->shopid;
            $data['uid'] = $uid;
            $data['author_id'] = $author_id;
            $data['status'] = 1;
        }

        $res = (new AuthorFollowModel())->edit($data);
        if ($res) {
            return $this->success($data['status'] ? '已关注' : '已取消');
        }

        return $this->error('发生错误');
    }

    /**
     * 是否已关注
     */
    public function isfollow()
    {
        $author_id = input('author_id', 0, 'intval');
        $uid = get_uid();

        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['author_id', '=', $author_id],
            ['status', '=', 1]
        ];
        $res = (new AuthorFollowModel())->where($map)->find();
        if ($res) {
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
        // 排序
        $order =  $order_field . ' ' . $order_type;

        // 查询条件
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', '=', 1]
        ];
        $fields = '*';
        $lists = (new AuthorFollowModel())->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();
        foreach ($lists['data'] as &$val) {
            $val = (new AuthorFollowLogic())->formatData($val);
        }
        unset($val);
        // ajax请求返回数据
        return $this->success('success', $lists);
    }
}
