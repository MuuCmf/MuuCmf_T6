<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Announce as AnnounceModel;
use app\common\logic\Announce as AnnounceLogic;

class Announce extends Api
{
    protected $AnnounceModel;
    protected $AnnounceLogic;

    function __construct()
    {
        parent::__construct();
        $this->AnnounceModel = new AnnounceModel();
        $this->AnnounceLogic = new AnnounceLogic();
    }

    /**
     * 获取公告详情
     * 
     * @return \think\response\Json 返回JSON格式的响应数据
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * 
     * @api
     * @param int $id 公告ID
     * @success-json {"code":200,"msg":"获取成功！","data":{}}
     */
    public function detail()
    {
        $id = input('id', 0, 'intval');
        $data = $this->AnnounceModel->getDataById($id);
        $data = $this->AnnounceLogic->formatData($data);

        return $this->success('获取成功！', $data);
    }

    /**
     * 获取公告列表
     * 
     * @return \think\response\Json 返回公告列表数据
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * 
     * @api
     * @param string $teminal 终端标识（可选）
     * @success-json {"code":200,"msg":"获取成功！","data":[...]}
     */
    public function lists()
    {
        $teminal = input('teminal', '', 'text');
        $rows = input('rows', 3, 'intval');

        // rows限制
        $rows = min($rows, 10);
        
        //初始化查询条件
        $map = [
            ['shopid', '=', $this->shopid],
            ['status', '=', 1]
        ];
        if(!empty($teminal)){
            $map[] = ['teminal', '=', $teminal];
        }
        
        $lists = $this->AnnounceModel->getList($map, $rows, 'sort desc, create_time desc');
        foreach ($lists as &$item) {
            $item = $this->AnnounceLogic->formatData($item);
        }
        unset($item);

        return $this->success('获取成功！', $lists);
    }

    /**
     * 获取公告分页列表
     * 
     * @return \think\response\Json 返回公告分页列表数据
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * 
     * @api
     * @param string $teminal 终端标识（可选）
     * @success-json {"code":200,"msg":"获取成功！","data":[...]}
     */
    public function pageLists()
    {
        $teminal = input('teminal', '', 'text');
        $rows = input('rows', 10, 'intval');

        // rows限制
        $rows = min($rows, 10);
        
        //初始化查询条件
        $map = [
            ['shopid', '=', $this->shopid],
            ['status', '=', 1]
        ];
        if(!empty($teminal)){
            $map[] = ['teminal', '=', $teminal];
        }
        
        $lists = $this->AnnounceModel->getListByPage($map, 'sort desc, create_time desc', '*', $rows);
        
        foreach ($lists as &$item) {
            $item = $this->AnnounceLogic->formatData($item);
        }
        unset($item);

        return $this->success('获取成功！', $lists);
    }
}
