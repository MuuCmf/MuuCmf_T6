<?php

namespace app\admin\controller;

use app\common\model\Module as ModuleModel;
use app\common\model\Announce as AnnounceModel;
use app\common\logic\Announce as AnnounceLogic;

use app\admin\validate\Common;
use think\exception\ValidateException;

/**
 * 公告控制器
 */
class Announce extends Admin
{
    protected ModuleModel $ModuleModel;
    protected AnnounceModel $AnnounceModel;
    protected AnnounceLogic $AnnounceLogic;

    /**
     * 构造方法
     * @access public
     * @return void
     */
    public function __construct() {
        parent::__construct();
        $this->ModuleModel = new ModuleModel();
        $this->AnnounceModel = new AnnounceModel();
        $this->AnnounceLogic = new AnnounceLogic();
    }

    /**
     * 列表
     */
    public function list()
    {
        // 查询条件
        $map = [
            ['status', '>', -1],
            ['shopid', '=', 0]
        ];
        // 搜索关键字
        $keyword = input('keyword', '', 'text');
        if (!empty($keyword)) {
            $map[] = ['title', 'like', '%' . $keyword . '%'];
        }

        $fields = '*';
        $rows = input('rows', 20, 'intval');
        $lists = $this->AnnounceModel->getListByPage($map, 'sort desc,create_time desc', $fields, $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->AnnounceLogic->formatData($val);
        }
        unset($val);

        // 返回数据
        return $this->success('success', $lists);
    }

    /**
     * 编辑、新增
     */
    public function edit()
    {
        $id = input('id', 0, 'intval');
        $title = $id ? "编辑" : "新建";
        $teminal = input('teminal', 'mobile', 'text');

        if (request()->isPost()) {
            return $this->handleEdit((int)$id, $title);
        }
        
        // return $this->showEditForm((int)$id, (string)$teminal);
    }
    
    /**
     * 处理编辑提交
     * @param int $id
     * @param string $title
     * @return \think\response\Json
     */
    protected function handleEdit(int $id, string $title)
    {
        $data = input();
        $data['shopid'] = $this->shopid;
        $data['uid'] = get_uid();
        
        // 数据验证
        try {
            validate(Common::class)->scene('announce')->check([
                'title' => $data['title'] ?? '',
                'content' => $data['content'] ?? '',
            ]);
        } catch (ValidateException $e) {
            return $this->error($e->getError());
        }
        
        // 处理链接数据
        $data['link_to'] = $this->buildLinkData($data);
        
        // 写入数据表
        $res = $this->AnnounceModel->edit($data);
        
        if ($res) {
            return $this->success($title . '成功', $res, cookie('__forward__'));
        }
        
        return $this->error($title . '失败');
    }
    
    /**
     * 构建链接数据
     * @param array $data
     * @return string
     */
    protected function buildLinkData(array $data): string
    {
        if (empty($data['link_type']) && empty($data['link_title'])) {
            return '';
        }
        
        $linkTo = [
            'app' => $data['link_app'] ?? '',
            'type' => $data['link_type'] ?? '',
            'title' => $data['link_title'] ?? '',
            'type_title' => $data['link_type_title'] ?? '',
            'param' => json_decode($data['link_param'] ?? '{}', true)
        ];
        
        return json_encode($linkTo, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 显示编辑表单
     * @param int $id
     * @param string $teminal
     * @return void
     */
    protected function showEditForm(int $id, string $teminal): void
    {
        if ($id > 0) {
            $data = $this->AnnounceModel->getDataById($id);
            $data = $this->AnnounceLogic->formatData($data);
            $teminal = $data['teminal'] ?? $teminal;
            
            // 链接参数二次处理
            if (!empty($data['link'])) {
                $link = $data['link'];
                $link['param'] = json_encode($link['param'], JSON_UNESCAPED_UNICODE);
                $data['link'] = $link;
            }
        } else {
            // 初始化数据
            $data = $this->getDefaultData($teminal);
        }
        
        // 获取Micro应用是否安装
        $microIsSetup = $this->ModuleModel->checkInstalled('micro');

        if ($microIsSetup) {
            $this->loadMicroLinks($teminal);
        }
    }
    
    /**
     * 获取默认数据
     * @param string $teminal
     * @return array
     */
    protected function getDefaultData(string $teminal): array
    {
        return [
            'id' => 0,
            'teminal' => $teminal,
            'type' => 1,
            'title' => '',
            'content' => '',
            'cover' => '',
            'status' => 1,
            'sort' => 0,
        ];
    }
    
    /**
     * 加载 Micro 链接数据
     * @param string $teminal
     * @return void
     */
    protected function loadMicroLinks(string $teminal): void
    {
        bind('micro\\LinksSevice', 'app\\micro\\service\\Link');
        $links = app('micro\\LinksSevice')->getAllLinks($teminal);
        $linkStaticTmpl = app('micro\\LinksSevice')->getStaticTmpl($teminal);
    }

    /**
     * 状态管理
     */
    public function status()
    {
        $ids = input('ids/a');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if ($status == 0) {
            $title = '禁用';
        }
        if ($status == 1) {
            $title = '启用';
        }
        if ($status == -1) {
            $title = '删除';
        }
        $data['status'] = $status;

        $res = $this->AnnounceModel->where('id', 'in', $ids)->update($data);
        if ($res) {
            return $this->success($title . '成功');
        } else {
            return $this->error($title . '失败');
        }
    }
}
