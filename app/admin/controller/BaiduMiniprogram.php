<?php
namespace app\admin\controller;

use app\common\model\BaiduMpConfig;

class BaiduMiniprogram extends Admin
{
    private BaiduMpConfig $MiniProgramModel;
    function __construct()
    {
        parent::__construct();
        $this->MiniProgramModel = new BaiduMpConfig();
    }

    /**
     * 小程序配置
     */
    public function index()
    {
        if (request()->isPost()){
            $params = input('post.');
            $rsa_public_key = str_replace("\r\n", "", $params['rsa_public_key']);
            $rsa_private_key = str_replace("\r\n", "", $params['rsa_private_key']);

            $data = [
                'id' => 0,
                'shopid' => $this->shopid,
                'title' => $params['title'],
                'description' => $params['description'],
                'appid' => $params['appid'],
                'appkey' => $params['appkey'],
                'secret' => $params['secret'],
                'pay_appid' => $params['pay_appid'],
                'pay_appkey' => $params['pay_appkey'],
                'dealId' => $params['dealId'],
                'rsa_public_key' => $rsa_public_key,
                'rsa_private_key' => $rsa_private_key
            ];
            $map = [
                ['shopid' ,'=' ,$this->shopid],
            ];
            $id = $this->MiniProgramModel->where($map)->value('id');
            if (!empty($id)){
                $data['id'] = $id;
            }
            $res = $this->MiniProgramModel->edit($data);
            if($res){
                return $this->success('保存成功');
            }else{
                return $this->error('保存失败');
            }
            
        }else{
            //查询分组数据
            $config = $this->MiniProgramModel->where([
                ['shopid' ,'=' ,$this->shopid],
            ])->find();

            // 设置回调地址
            $callback_url = url('api/baidu/callback', ['shopid'=>$this->shopid], false, true);
            $config['callback'] = (string)$callback_url;
            
            // json response
            return $this->success('success', $config);
        }
    }
}