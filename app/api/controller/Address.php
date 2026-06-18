<?php

namespace app\api\controller;

use app\common\validate\Address as AddressValidate;
use think\exception\ValidateException;
use app\common\controller\Api;
use app\common\model\Address as AddressModel;
use app\common\logic\Address as AddressLogic;

class Address extends Api
{
    protected AddressModel $AddressModel;
    protected AddressLogic $AddressLogic;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];
    function __construct()
    {
        parent::__construct();
        $this->AddressModel = new AddressModel();
        $this->AddressLogic = new AddressLogic();
    }

    /**
     * 获取默认地址
     */
    public function default()
    {
        $uid = get_uid();
        $map = [
            ['uid', '=', $uid],
            ['shopid', '=', $this->shopid],
            ['first', '=', 1],
            ['status', '=', 1],
        ];
        $data = $this->AddressModel->getDataByMap($map);
        if (!$data) {
            $map = [
                ['uid', '=', $uid],
                ['status', '=', 1],
                ['shopid', '=', $this->shopid],
            ];
            $data = $this->AddressModel->getDataByMap($map);
        }

        if(!empty($data)){
            $data = $this->AddressLogic->formatData($data);
        }

        return $this->success('获取成功！', $data);
    }

    /**
     * 获取地址详情
     */
    public function detail()
    {
        $id = input('get.id', 0);
        $data = $this->AddressModel->getDataById($id);
        $data = $this->AddressLogic->formatData($data);
        return $this->success('获取成功！', $data);
    }

    /**
     * 获取地址列表
     */
    public function lists()
    {
        $uid = get_uid();
        //初始化查询条件
        $map = [
            ['shopid', '=', $this->shopid],
            ['uid', '=', $uid],
            ['status', '=', 1]
        ];
        $order = 'first desc,update_time desc';
        $lists = $this->AddressModel->getList($map, 99, $order);
        foreach ($lists as &$item) {
            $item = $this->AddressLogic->formatData($item);
        }
        unset($item);
        return $this->success('获取成功！', $lists);
    }

    /**
     * 新增/编辑地址
     */
    public function edit()
    {
        if (request()->isPost()) {
            $param = request()->post();
            $uid = get_uid();
            $first = !empty($param['first']) ? $param['first'] : 1;
            $data = [
                'id' => intval($param['id']),
                'uid' => $uid,
                'shopid' => $this->shopid,
                'name' => $param['name'],
                'phone' => $param['phone'],
                'pos_province' => $param['pos_province'],
                'pos_city' => $param['pos_city'],
                'pos_district' => $param['pos_district'],
                'address' => $param['address'],
                'first' => $first, //默认地址
                'status' => 1
            ];

            // 数据验证
            try {
                validate(AddressValidate::class)->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            //写入数据
            $res = $this->AddressModel->edit($data);
            if ($res) {
                //关闭其他默认地址
                if ($data['first'] == 1) {
                    $id = is_object($res) ? $res->id : $res;
                    $this->AddressModel->where([
                        ['id', '<>', $id],
                        ['shopid', '=', $this->shopid],
                        ['uid', '=', $uid]
                    ])->update([
                        'update_time' => time(),
                        'first' => 0
                    ]);
                }
                //返回提示
                return $this->success('编辑成功！', $res);
            } else {
                return $this->error('编辑失败！');
            }
        }
    }

    /**
     * 设为默认地址
     */
    public function setDefault()
    {
        $uid = get_uid();
        $id  = input('get.id');
        $this->AddressModel->where([
            ['uid', '=', $uid],
            ['shopid', '=', $this->shopid]
        ])->update([
            'update_time' => time(),
            'first' => 0
        ]);
        $res = $this->AddressModel->where([
            ['id', '=', $id],
            ['shopid', '=', $this->shopid]
        ])->update([
            'update_time' => time(),
            'first' => 1
        ]);
        if ($res) {
            return $this->success('设置成功！', $res, 'refresh');
        } else {
            return $this->error('设置失败！');
        }
    }

    /**
     * 删除地址
     */
    public function del()
    {
        $id = input('id', 0, 'intval');
        $uid = get_uid();
        $res = $this->AddressModel->edit([
            'id' => $id,
            'uid' => $uid,
            'status' => -1
        ]);
        if ($res) {
            return $this->success('删除成功！');
        } else {
            return $this->error('删除失败！');
        }
    }
}
