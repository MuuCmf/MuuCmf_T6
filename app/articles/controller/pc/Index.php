<?php

namespace app\articles\controller\pc;

use think\facade\View;
use app\common\controller\Common;
use app\common\model\History;
use app\articles\model\ArticlesCategory as CategoryModel;
use app\articles\model\ArticlesArticles as ArticlesModel;
use app\articles\logic\Articles as ArticlesLogic;

class Index extends Common
{
    protected ArticlesModel $ArticlesModel;
    protected ArticlesLogic $ArticlesLogic;

    function __construct()
    {
        parent::__construct();
        $this->ArticlesModel = new ArticlesModel();
        $this->ArticlesLogic = new ArticlesLogic();
        // 热门文章
        $this->_hot();
        // 分类树
        $this->_category();
    }

    public function index()
    {
        $this->redirect('/articles/lists');
    }

    /**
     * 文章列表
     * @return [type] [description]
     */
    public function lists()
    {
        $keyword = input('keyword', '', 'text');
        View::assign('keyword', $keyword);
        $category_id = input('category_id', 0, 'intval');
        View::assign('category_id', $category_id);
        $category = [];
        if (!empty($category_id)) {
            $category = (new CategoryModel)->getDataById($category_id);
        }
        View::assign('category', $category);

        $rows = input('rows', 20, 'intval');
        // 获取查询条件
        $map = $this->ArticlesLogic->getMap($this->shopid, $keyword, $category_id, 1);
        // 获取列表
        $lists = $this->ArticlesModel->getListByPage($map, 'sort DESC,id DESC', '*', $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->ArticlesLogic->formatData($val);
        }
        unset($val);

        View::assign('pager', $pager);
        View::assign('lists', $lists);

        // 设置页面TITLE
        $this->setTitle(!empty($category_id) ? $category['title'] : '全部文章');
        // 输出页面
        return View::fetch();
    }

    /**
     * 文章详情
     */
    public function detail()
    {
        $id = input('id', 0, 'intval');

        /* 标识正确性检测 */
        if (!($id && is_numeric($id))) {
            return $this->error('文档ID错误！');
        }

        $data = $this->ArticlesModel->getDataById($id);
        $data = $this->ArticlesLogic->formatData($data);
        View::assign('data', $data);

        //未审核内容并不是作者浏览时报错
        if ($data['status'] != 1) {
            return $this->error('内容审核中...');
        }

        if (!empty($data)) {
            $uid = get_uid();
            //增加浏览数
            $this->ArticlesModel->setStep($id, 'view', 1);
            //写入浏览记录
            if (!empty($uid)) {
                $products = [
                    'title' =>  $data['title'],
                    'desc'  =>  $data['description'],
                    'cover' =>  $data['cover'],
                    'link_url'   =>  'articles/detail'
                ];
                (new History())->addLog($this->shopid, get_module_name(), $uid, $id, 'articles', $products);
            }

            //获取上一篇下一篇数据
            $prev_detail = $this->ArticlesModel->getPrevDetail($this->shopid, $id);
            View::assign('prev_detail', $prev_detail);
            $next_detail = $this->ArticlesModel->getNextDetail($this->shopid, $id);
            View::assign('next_detail', $next_detail);
        }

        /*用户所要文章访问量*/
        $articles_total_view = $this->ArticlesModel->_totalView($data['author_id']);
        View::assign('articles_total_view', $articles_total_view);

        $this->setTitle($data['title']);
        $this->setDescription($data['description']);

        // 输出页面
        return View::fetch();
    }

    /**
     * 热门文章
     */
    private function _hot($category_id = 0)
    {
        $map[] = ['shopid', '=', $this->shopid];
        $map[] = ['status', '=', 1];
        // 获取查询条件
        $map = $this->ArticlesLogic->getMap($this->shopid, '', $category_id, 1);
        // 获取列表
        $hot_lists = $this->ArticlesModel->getList($map, 10, 'view DESC,id DESC');
        $hot_lists = $hot_lists->toArray();

        foreach ($hot_lists as &$val) {
            $val = $this->ArticlesLogic->formatData($val);
        }
        unset($val);

        View::assign('hot_lists', $hot_lists);
    }


    private function _category()
    {
        // 获取分类
        $category_tree = (new CategoryModel)->tree($this->shopid, 1);
        View::assign('category_tree', $category_tree);
    }
}
