<?php
namespace app\articles\logic;

use app\articles\model\ArticlesCategory as CategoryModel;
use app\articles\logic\Category as CategoryLogic;
use app\articles\model\ArticlesConfig as ConfigModel;
use app\articles\logic\Config as ConfigLogic;
use app\common\model\Favorites as FavoritesModel;
use app\common\model\Support as SupportModel;
use app\common\model\Author as AuthorModel;
use app\common\logic\Author as AuthorLogic;

/*
 * 数据逻辑层
 */
class Articles extends Base
{
    /**
     * 内容状态
     */
	public $_status = [
        1  => '启用',
        0  => '禁用',
        -1 => '未审核',
        -2 => '审核未通过',
        -3 => '已删除',
    ];

    protected $ConfigModel;
    protected $ConfigLogic;
    protected $FavoritesModel;
    protected $SupportModel;
    protected $CategoryModel;
    protected $CategoryLogic;

    public function __construct()
    {
        $this->ConfigModel = new ConfigModel();
        $this->ConfigLogic = new ConfigLogic();
        $this->FavoritesModel = new FavoritesModel();
        $this->SupportModel = new SupportModel();
        $this->CategoryModel = new CategoryModel();
        $this->CategoryLogic = new CategoryLogic();
    }

    /**
     * 条件查询
     * @param  string $keyword       [description]
     * @param  string $category_id   [description]
     * @param  string $attribute_ids [description]
     * @param  string $type          [description]
     * @param  string $status        
     * @return [type]                [description]
     */
    public function getMap($shopid, $keyword, $category_id, $status)
    {
        //初始化查询条件
        $map = [];
        
        if(!empty($shopid)){
            $map[] = ['shopid', '=', $shopid];
        }
        if(is_numeric($status)){
            $map[] = ['status', '=', $status];
        }
        if(is_array($status)){
            $map[] = ['status', 'in', $status];
        }
        
        if(!empty($keyword)){
            $map[] = ['title', 'like', '%'. $keyword .'%'];
        }
        
        //分类id
        if(!empty($category_id)){
            $category_ids = $this->CategoryLogic->yesParent($category_id); 
            if(!empty($category_ids)){
                $category_ids = implode(',', $category_ids);
                $map[] = ['category_id', 'in', $category_ids];
            }else{
                $map[] = ['category_id', '=', $category_id];
            }
        }

        return $map;
    }

    /**
     * 数据格式化
     */
    public function formatData($data)
    {
        if(!empty($data)){
            $shopid = intval($data['shopid']);

            // 获取uid
            $uid = get_uid();
            
            $id = $data['id'];

            // 关键词拆分（逗号分隔 → 数组，供页面标签展示；keywords 原字符串保留给管理端回填与 meta）
            $data['keywords_arr'] = !empty($data['keywords'])
                ? array_values(array_filter(array_map('trim', explode(',', $data['keywords']))))
                : [];

            if(!empty($data['cover'])){
                $data['has_cover'] = 1;
                $data = $this->setCoverAttr($data, '4:3');
            }

            // 获取分类数据
            if(!empty($data['category_id'])){
                $category = $this->CategoryModel->getDataById($data['category_id']);
                $data['category'] = $this->CategoryLogic->formatData($category);
            }

            if(!empty($data['content'])){
                $data['content'] = htmlspecialchars_decode($data['content']);
            }
            
            //判断是否收藏
            if($uid > 0 && $this->FavoritesModel->yesFavorites($shopid, 'articles', $uid, $id, 'Articles')){
                $data['favorites_yesno'] = 1;
            }else{
                $data['favorites_yesno'] = 0;
            }

            //判断是否点赞
            if($uid > 0 && $this->SupportModel->yesSupport($shopid, 'articles', $uid, $id, 'Articles')){
                $data['support_yesno'] = 1;
            }else{
                $data['support_yesno'] = 0;
            }

            // 获取老师(作者)数据
            if(!empty($data['author_id'])){
                $author = (new AuthorModel)->getDataById($data['author_id'], 'id, uid, shopid, name, description, cover, content, status')->toArray();
                $data['author'] = (new AuthorLogic)->formatData($author);
            }
            
            if(isset($data['status'])){
                $data['status_str'] = $this->_status[$data['status']];
            }
            
            if(!empty($data['create_time'])){
                $data['create_time_str'] = time_format($data['create_time']);
                $data['create_time_friendly_str'] = friendly_date($data['create_time']);
            }
            if(!empty($data['update_time'])){
                $data['update_time_str'] = time_format($data['update_time']);
                $data['update_time_friendly_str'] = friendly_date($data['update_time']);
            }

            //访问量、收藏量、点赞量数据处理 | 显示的量为真实数据+虚拟数据
            $data['handling_view'] = intval($data['view']) + intval($data['f_view']);
            $data['handling_favorites'] = intval($data['favorites']) + intval($data['f_favorites']);
            $data['handling_support'] = intval($data['support']) + intval($data['f_support']);
        }

        return $data;
    }

}