<?php
namespace app\articles\controller\admin;

use app\common\model\Search as SearchModel;
use app\articles\model\ArticlesCategory as CategoryModel;
use app\articles\logic\Category as CategoryLogic;
use app\articles\model\ArticlesArticles as ArticlesModel;
use app\articles\logic\Articles as ArticlesLogic;
use app\articles\validate\Articles as ArticlesValidate;
use think\exception\ValidateException;

class Articles extends Admin
{
    protected CategoryModel $CategoryModel;
    protected CategoryLogic $CategoryLogic;
    protected ArticlesModel $ArticlesModel;
    protected ArticlesLogic $ArticlesLogic;

    public function __construct()
    {
        parent::__construct();
        $this->CategoryModel = new CategoryModel(); //分类模型
        $this->CategoryLogic = new CategoryLogic(); //分类逻辑
        $this->ArticlesModel = new ArticlesModel();
        $this->ArticlesLogic = new ArticlesLogic();
    }

    /**
     * 文章列表页
     */
    public function lists()
    {
        $keyword = input('keyword', '', 'text');
        $category_id = input('category_id', 0, 'intval');
        $status = input('status', [0,1,-1,-2]);
        $rows = input('rows', 20, 'intval');

        // 获取查询条件
        $map = $this->ArticlesLogic->getMap($this->shopid, $keyword, $category_id, $status);
        // 排序
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        $order = 'sort DESC,' . $order_field . ' ' . $order_type;
        $fields = '*';
        // 获取列表
        $lists = $this->ArticlesModel->getListByPage($map, $order, '*', $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();
        
        foreach($lists['data'] as &$val){
            $val = $this->ArticlesLogic->formatData($val);
            //拼接url地址
            $val['h5_url'] = url('h5\index', [], true, true) . '#/articles/pages/articles/detail?id=' . $val['id'];
            $val['pc_url'] = (string)url('articles/pc.Index/detail', ['id' => $val['id']], true, true);
        }
        unset($val);

        return $this->success('success', $lists);
    }

    /**
     * 新增、编辑文章
     */
    public function edit()
    {
        $id = input('id',0,'intval');
        $title = $id ? "编辑" : "新建";

        if (request()->isPost()) {
            $data = input();
            $data['shopid'] = $this->shopid;
            // 数据验证
            try {
                validate(ArticlesValidate::class)->check([
                    'title'  => $data['title'],
                    'description' => $data['description'],
                    'cover' => $data['cover'],
                    'category_id' => $data['category_id']
                ]);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            $res = $this->ArticlesModel->edit($data);

            if($res){
                // 写入搜索索引
                (new SearchModel)->add($this->shopid, 'article', $res, 'article', $data['title'], $data['description'], $data['cover']);
                return $this->success($title . '成功', $res, cookie('__forward__'));
            }else{
                return $this->error($title . '失败');
            }
        }

        //获取数据
        $data['id'] = 0;
        $data['title'] = '';
        $data['description'] = '';
        $data['cover'] = '';
        $data['category_id'] = 0;
        $data['sort'] = 0; // 排序
        $data['content'] = '';
        $data['f_view'] = 0;
        $data['f_support'] = 0;
        $data['f_favorites'] = 0;
        $data['status'] = 0; // 状态
        $data['reason'] = ''; // 审核拒绝原因

        if(!empty($id)){
            $data = $this->ArticlesModel->getDataById($id);
            $data = $this->ArticlesLogic->formatData($data);
        }

        return $this->success('success', $data);
    }

    /**
     * 设置状态
     */
    public function status()
    {   
        $ids = input('ids/a');
        !is_array($ids)&&$ids=explode(',',$ids);
        $status = input('status', 0, 'intval');
        $title = '更新';
        if($status == 0){
            $title = '禁用';
        }
        if($status == 1){
            $title = '启用';
        }
        if($status == -3){
            $title = '删除';
        }
        $data['status'] = $status;

        $res = $this->ArticlesModel->where('id', 'in', $ids)->update($data);
        if($res){
            return $this->success($title . '成功');
        }else{
            return $this->error($title . '失败');
        }  
    }

    public function verify()
    {
        $id = input('id',0,'intval');
        if (request()->isPost()) {
            $data['id'] = $id;
            $status = input('status', 0, 'intval');
            $data['status'] = $status;
            $reason = input('reason', '', 'text');
            if(!empty($reason)){
                $data['reason'] = $reason;
            }

            $res = $this->ArticlesModel->edit($data);

            if($res){
                return $this->success('操作成功');
            }else{
                return $this->error('操作失败');
            }
        }


        $data = $this->ArticlesModel->getDataById($id);
        $data = $this->ArticlesLogic->formatData($data);
        
        return $this->success('success', $data);
    }
}