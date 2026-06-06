<?php
namespace app\admin\controller;

use think\facade\Db;
use think\facade\Cache;
use app\common\model\ExtendConfig as MuuExtendConfigModel;
use app\admin\validate\Common as CommonValidate;
use think\exception\ValidateException;

/**
 * 后台配置控制器
 */
class Extend extends Admin
{
    protected MuuExtendConfigModel $extendConfigModel;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->extendConfigModel = new MuuExtendConfigModel();
    }

    /**
     * 短信发送参数配置
     */
    /**
     * 获取缓存键前缀（与 DbConfig 中间件保持一致）
     */
    protected function getExtConfigCacheKey()
    {
        return request()->host() . '_MUUCMF_EXT_CONFIG_DATA';
    }

    /**
     * 短信发送参数配置
     */
    public function sms() {

        if (request()->isPost()) {
            $config = input('post.');
            //dump($config);exit;
            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    Db::name('ExtendConfig')->where($map)->save(['value' => $value]);
                }
            }
            // 清理缓存
            Cache::delete($this->getExtConfigCacheKey());

            return $this->success('保存成功',$config, 'refresh');

        }
    }

    /**
     * 支付参数配置
     */
    public function payment() {

        if (request()->isPost()) {
            $config = input('post.');
            //dump($config);exit;
            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    Db::name('ExtendConfig')->where($map)->save(['value' => $value]);
                }
            }
            // 清理缓存
            Cache::delete($this->getExtConfigCacheKey());

            return $this->success('保存成功',$config, 'refresh');
        }
    }

    /**
     * 存储配置
     */
    public function store()
    {
        if (request()->isPost()) {
            $config = input('post.');

            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    Db::name('ExtendConfig')->where($map)->save(['value' => $value]);
                }
            }
            // 清理缓存
            Cache::delete($this->getExtConfigCacheKey());
    
            return $this->success('保存成功',$config, 'refresh');
        }
    }

    /**
     * 云点播配置管理
     */
    public function vod()
    {
        if (request()->isPost()) {
            $config = input('post.');
            
            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    Db::name('ExtendConfig')->where($map)->save(['value' => $value]);
                }
            }
            // 清理缓存
            Cache::delete($this->getExtConfigCacheKey());
    
            return $this->success('保存成功',$config, 'refresh');
        }
    }

    /**
     * muuAgent代理配置管理
     */
    public function muuAgent()
    {
        if (request()->isPost()) {
            $config = input('post.');
            
            if ($config && is_array($config)) {
                foreach ($config as $name => $value) {
                    $map = ['name' => $name];
                    Db::name('ExtendConfig')->where($map)->save(['value' => $value]);
                }
            }
            // 清理缓存
            Cache::delete($this->getExtConfigCacheKey());
    
            return $this->success('保存成功',$config, 'refresh');
        }
    }

    /**
     * 获取扩展配置分组列表
     */
    public function groupList()
    {
        // 配置分组
        $group = config('extend.GROUP_LIST');
        return $this->success('success', $group);
    }

    /**
     * 扩展配置管理
     */
    public function list()
    {
        // 加载方式 all 全量查询  page 分页查询
        $load = input('load', 'page', 'text');
        // 配置分组 多个分组用,号隔开
        $group = input('group', '', 'text');
        $keyword = input('keyword', '', 'trim');
        $rows = input('rows', 20, 'intval');

        /* 查询条件初始化 */
        $map = [];
        $map[] = ['status', '=', 1];
        if (!empty($group)) {
            // 如果$group为,分割的字符串，转换为数组
            $group = explode(',', $group);
            // 筛选出$group中包含的配置分组
            $group = array_intersect($group, array_keys(config('extend.GROUP_LIST')));
            // 筛选出配置项
            $map[] = ['group', 'in', $group];
        }
        if (!empty($keyword)) {
            $map[] = function ($query) use ($keyword) {
                $query->where('name', 'like', "%{$keyword}%")
                    ->whereOr('title', 'like', "%{$keyword}%");
            };
        }

        if ($load == 'page') {
            // 分页查询
            $list = $this->extendConfigModel->getListByPage($map, 'sort asc,id desc', '*', $rows);
            $list = $list->toArray();
            foreach ($list['data'] as $key => $item) {
                $list['data'][$key]['type_name'] = get_config_type($item['type']);
                $list['data'][$key]['group_name'] = get_extend_config_group($item['group']);
                // pic类型生成缩微图组
                if ($item['type'] == 'pic' && !empty($item['value'])) {
                    $list['data'][$key]['thumb'] = thumb_group($item['value']);
                }
            }
        } else {
            // 全量查询
            $list = $this->extendConfigModel->where($map)->field('id,name,title,extra,value,group,remark,type')->order('sort asc')->select()->toArray();
            foreach ($list as $key => $item) {
                $list[$key]['type_name'] = get_config_type($item['type']);
                $list[$key]['group_name'] = get_extend_config_group($item['group']);
                // pic类型生成缩微图组
                if ($item['type'] == 'pic' && !empty($item['value'])) {
                    $list['data'][$key]['thumb'] = thumb_group($item['value']);
                }
            }
        }

        // json result
        return $this->success('success', $list);
    }

    /**
     * 编辑系统配置
     */
    public function edit()
    {
        $id = input('id', 0, 'intval');

        if (request()->isPost()) {
            $data = request()->param();
            //验证器
            try {
                validate(CommonValidate::class)->scene('config')->check($data);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return $this->error($e->getError());
            }

            $data['status'] = 1;//默认状态为启用
            $res = $resId = $this->extendConfigModel->edit($data);
            if($res){
                Cache::delete($this->getExtConfigCacheKey());
                //记录行为
                action_log('update_config', 'extend_config', $resId, is_login());

                return $this->success('操作成功','',url('list')->build());
            }else{
                return $this->error('操作失败');
            }
        }
    }

    /**
     * 删除配置
     */
    public function del()
    {
        $id = array_unique((array)input('id', 0));

        if (empty($id)) {
            $this->error('参数错误');
        }

        if (Db::name('ExtendConfig')->where('id','in', $id)->delete()) {
            Cache::delete($this->getExtConfigCacheKey());
            //记录行为
            action_log('update_config', 'extend_config', $id, is_login());
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }

}