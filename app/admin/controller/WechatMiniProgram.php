<?php

namespace app\admin\controller;

use think\exception\ValidateException;
use app\common\logic\TemplateMessage;
use app\common\model\WechatMpConfig;
use app\admin\validate\WechatMiniProgram as WechatMiniProgramValidate;

class WechatMiniProgram extends Admin
{
    private WechatMpConfig $MiniProgramModel;
    
    function __construct()
    {
        parent::__construct();
        $this->MiniProgramModel = new WechatMpConfig();
    }

    /**
     * 商户小程序配置
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function config()
    {
        if (request()->isPost()) {
            $params = input('post.');
            $data = [
                'id' => 0,
                'shopid' => $this->shopid,
                'title' => $params['title'],
                'description' => $params['description'],
                'appid' => $params['appid'],
                'secret' => $params['secret'],
                'originalid' => $params['originalid'],
            ];
            $map = [
                ['shopid', '=', $this->shopid],
            ];
            $id = $this->MiniProgramModel->where($map)->value('id');
            if ($id) {
                $data['id'] = $id;
            }
            // 数据验证
            try {
                validate(WechatMiniProgramValidate::class)->scene('edit')->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }
            // 写入数据
            $this->MiniProgramModel->edit($data);
            return $this->success('保存成功');
        } else {
            //查询数据
            $config = $this->MiniProgramModel->where([
                ['shopid', '=', $this->shopid],
            ])->find();

            // json response
            return $this->success('success', $config);
        }
    }
    /**
     * @title 模板消息通知
     */
    public function templateMessage()
    {
        if (request()->isPost()) {
            $params = request()->post();
            $data = [
                'switch'      => $params['switch'],
                'to'          => $params['to'],
                'manager_uid' => $params['manager_uid'],
                'tmplmsg'     => $params['tmplmsg']
            ];
            $data = json_encode($data);
            $result = $this->MiniProgramModel->where('shopid', $this->shopid)->save(['tmplmsg' => $data]);
            if ($result) {
                return $this->success('保存成功');
            }
            return $this->error('保存失败，请稍后再试');
        }

        $TemplateMessageLogic = new TemplateMessage();
        $detail = $this->MiniProgramModel->where('shopid', $this->shopid)->value('tmplmsg');
        $detail = $TemplateMessageLogic->formatData($detail); //格式化原始数据
        
        // json response
        return $this->success('success', $detail);
    }
}
