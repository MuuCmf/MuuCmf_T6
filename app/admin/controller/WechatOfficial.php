<?php

namespace app\admin\controller;

use think\Exception;
use think\exception\ValidateException;
use app\common\model\WechatAutoReply;
use app\common\model\WechatConfig;
use app\admin\validate\Account as AccountValidate;

/**
 * 公众号管理
 * Class OfficialAccount
 * @package app\admin\controller
 */
class WechatOfficial extends Admin
{
    private WechatConfig $wechatConfigModel;
    private WechatAutoReply $autoReplyModel;
    function __construct()
    {
        parent::__construct();
        $this->wechatConfigModel = new WechatConfig();
        $this->autoReplyModel = new WechatAutoReply;
    }

    /**
     * 公众号配置
     */
    public function config()
    {
        if (request()->isPost()) {
            $data = input('post.');
            $data['shopid'] = $this->shopid;
            // 数据验证
            try {
                validate(AccountValidate::class)->scene('edit')->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            $res = $this->wechatConfigModel->edit($data);
            if ($res) {
                return $this->success('保存成功', $data, 'refresh');
            }
            return $this->error('网络异常，请稍后再试');
        } else {
            //查询微信平台配置
            $data = $this->wechatConfigModel->getWechatConfigByShopId($this->shopid);

            return $this->success('success', $data);
        }
    }

    /**
     * 菜单管理
     */
    public function menu()
    {
        if (request()->isPost()) {
            $json = input('json');
            $menu = json_decode((string)$json, true);
            try {
                $res = \app\common\facade\wechat\OfficialAccount::createMenu($menu);

                if ($res['errcode'] != 0) {
                    return $this->error($res['errmsg']);
                }
                $updateRes = $this->wechatConfigModel->where('shopid', $this->shopid)->save(['menu_json' => $json]);
                if ($updateRes) {
                    return $this->success('更新成功', 'refresh');
                }
                return $this->error('更新失败');
            } catch (Exception $e) {
                return $this->error('发生错误：' . $e->getMessage());
            }
        }

        // 输出页面或json数据
        $menu = $this->wechatConfigModel->where(['shopid' => $this->shopid])->value('menu_json');
        if ($menu) {
            $menu = json_decode($menu, true);
        } else {
            $menu = [];
        }
        return $this->success('success', $menu);
    }
    
    /**
     * 自动回复列表
     */
    public function autoReply()
    {
        $params = input('');
        $where = [
            ['status', '>=', 0],
            ['shopid', '=', $this->shopid]
        ];
        if (isset($params['keyword']) && !empty($params['keyword'])) $where[] = ['keyword', 'like', '%' . $params['keyword'] . '%'];
        $page = max(1, isset($params['page']) ?? $params['page']);
        $list = $this->autoReplyModel
        ->where($where)
        ->field(
            '*,
            type as type_str,
            status as status_str,
            msg_type as msg_type_str,
            create_time as create_time_str,
            update_time as update_time_str'
        )
        ->order('sort', 'DESC')
        ->page($page, 20)
        ->paginate();

        // json数据
        return $this->success('success', $list);
    }

    /**
     * 添加、更新自动回复
     * @return \think\Response|void
     */
    public function editAutoReply()
    {
        $aId = input('param.id', 0, 'intval');
        if (request()->isPost()) {
            $data = input('post.');

            // 兼容性处理
            if (is_numeric($data['msg_type']) && $data['msg_type'] == 1) {
                $data['msg_type'] = 'text';
            }
            if (is_numeric($data['msg_type']) && $data['msg_type'] == 2) {
                $data['msg_type'] = $data['material_type'] ?? 'text';
            }

            // 验证data['type'] == 0的数据仅能有一条
            if ($data['type'] == 1 && $this->autoReplyModel->where(['shopid' => $this->shopid, 'type' => 1, 'status' => 1])->count() > 0) {
                return $this->error('关注公众号只能设置一条自动回复');
            }

            if (!empty($data['material_json'])) {
                $data['material_json'] = json_encode($data['material_json']);
            }else{
                $data['material_json'] = '';
            }
            
            //验证文本唯一性
            if (!empty($data['text']) && !$this->autoReplyModel->checkUnique('text', $data['text'], $aId)) {
                return $this->error('内容重复');
            }
            
            $res = $this->autoReplyModel->edit($data);
            if ($res) {
                return $this->success(($aId == 0 ? '新增' : '编辑') . '成功', '', cookie('__forward__'));
            } else {
                return $this->error('提交失败');
            }
        }
    }

    /**
     * 修改自动回复状态
     */
    public function autoReplyStatus(int $status = 0)
    {
        $ids = array_unique((array)input('ids/a', 0));
        $ids = is_array($ids) ? implode(',', $ids) : $ids;

        if (empty($ids)) {
            return $this->error('请选择要操作的数据');
        }

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

        $res = $this->autoReplyModel->where('id', 'in', $ids)->update($data);
        if($res){
            return $this->success($title . '成功');
        }else{
            return $this->error($title . '失败');
        }  
    }

    /**
     * 素材列表
     */
    public function material()
    {
        $type = input('type', 'text', 'trim');
        $page = input('page', 1, 'intval');
        $pageSize = input('page_size', 20, 'intval');

        $offset = ($page - 1) * $pageSize;
        $data = \app\common\facade\wechat\OfficialAccount::getMaterialList($type, $offset, $pageSize);
        if (isset($data['item'])) {
            return  $this->success('success', $data);
        } elseif (isset($data['errmsg'])) {
            return $this->error($data['errmsg']);
        }
        return $this->error('请检查公众号配置');
    }

    /**
     * 获取单个素材详情
     */
    public function materialDetail()
    {
        $mediaId = input('media_id', '', 'trim');
        if (empty($mediaId)) {
            return $this->error('素材ID不能为空');
        }

        $data = \app\common\facade\wechat\OfficialAccount::getMaterial($mediaId);
        
        // 检查是否是错误数组
        if (is_array($data) && isset($data['errmsg'])) {
            return $this->error($data['errmsg']);
        }
        
        // 处理StreamResponse对象（二进制素材：图片、语音、视频等）
        if ($data instanceof \EasyWeChat\Kernel\Http\StreamResponse) {
            // 对于二进制素材，可以返回文件信息或直接输出文件
            // 这里返回文件的基本信息
            $filename = $data->getHeaderLine('Content-disposition');
            $contentType = $data->getHeaderLine('Content-type');
            
            return $this->success('success', [
                'type' => 'binary',
                'filename' => $filename,
                'content_type' => $contentType,
                'stream' => 'StreamResponse object',
                'message' => 'For binary materials, please use the stream directly'
            ]);
        }
        
        // 处理数组类型（图文素材等）
        return $this->success('success', $data);
    }

    /**
     * @title 模板消息通知
     * @return json
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
            $result = $this->wechatConfigModel->where('shopid', $this->shopid)->save(['tmplmsg' => $data]);
            if ($result) {
                return $this->success('保存成功');
            }
            return $this->error('保存失败，请稍后再试');
        }
    }
}
