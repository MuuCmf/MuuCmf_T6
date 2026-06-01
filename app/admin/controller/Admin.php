<?php

namespace app\admin\controller;

use think\facade\Db;
use think\facade\View;
use app\common\controller\Base;
use app\common\model\Menu;
use app\common\model\AuthRule;
use app\common\model\Module as ModuleModel;
use app\common\logic\Config as ConfigLogic;

/**
 * 控制器基础类
 */
class Admin extends Base
{
    protected $title;
    public $isRoot;
    protected $menu = [];
    public $app_name = ''; // 应用标识

    protected $middleware = [
        // 鉴权中间件
        'app\admin\middleware\CheckRule::class'
    ];
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        // 控制器初始化
        $this->initialize();
    }

    public function initialize()
    {
        $this->setRoot();
        $this->setTitle();
        //获取系统配置
        $this->initMuuConfig();
        //获取micro模块配置
        $this->initMicroConfig();
        // 当前模块、控制器及方法名
        $this->app_name = strtolower(app('http')->getName());
        View::assign('this_app', $this->app_name);
        View::assign('this_controller', strtolower(request()->controller()));
        View::assign('this_action', strtolower(request()->action()));
        // 当前应用模块信息
        $module = (new ModuleModel())->getModule($this->app_name);
        View::assign('module', $module);
        //当前管理菜单
        $admin_menu = $this->getMenus();
        View::assign('admin_menu', $admin_menu);
        //获取登录用户数据
        View::assign('auth_user', query_user(is_login()));
        //框架版本号
        View::assign('version', $this->version());
    }

    /**
     * 是否超管
     */
    public function setRoot()
    {
        $uid = get_uid();
        if ($uid == 1) $this->isRoot = 1;
        return $uid;
    }

    /**
     * 初始化系统配置项
     */
    protected function initMuuConfig()
    {
        $muu_config_data = (new ConfigLogic())->frontend();
        View::assign('muu_config_data', $muu_config_data);
    }

    /**
     * 初始化micro应用配置项
     */
    protected function initMicroConfig()
    {
        $micro_config_data = [];
        $is_install = (new ModuleModel())->checkInstalled('micro');
        if($is_install){
            $namespace = "\\app\\micro\\model\\MicroConfig";
            if(!class_exists($namespace)){
                return;
            }
            $MicroConfigModel = new $namespace;
            $micro_config_data  = $MicroConfigModel->getConfig($this->shopid);
        }
        View::assign('micro_config_data', $micro_config_data);
    }

    /**
     * 获取控制器菜单数组,二级菜单元素位于一级菜单的'_child'元素中
     */
    final public function getMenus()
    {
        $module = app('http')->getName();
        $controller = request()->controller();
        $action = request()->action();
        // 获取主菜单
        $where[] = ['pid', '=', '0'];
        $menuModel = new Menu();
        $menus['main'] = Db::name('menu')->where($where)->order('sort', 'asc')->select()->toArray();
        $menus['child'] = []; //设置子节点
        //当前菜单
        $current_map[] = [
            ['url', '=', $module . '/' . $controller . '/' . $action],
        ];
        $current = Db::name('menu')->where($current_map)->find();

        //获取顶级菜单数据
        $nav_current_id = 0;
        if ($module != 'admin') {
            foreach ($menus['main'] as $m) {
                if ($m['module'] == $module) {
                    $nav_current_id = $m['id'];
                }
            }
        } elseif ($current) {
            $nav = $menuModel->getPath($current['id']);
            $nav_current_id = $nav[0]['id'];
        }

        if ($nav_current_id) {
            foreach ($menus['main'] as $key => &$item) {

                if (!is_array($item) || empty($item['title']) || empty($item['url'])) {
                    return $this->error('控制器基类{$menus}属性元素配置有误');
                }

                // 判断主菜单权限
                if (!$this->isRoot && !$this->checkRule($item['url'], get_uid(), AuthRule::RULE_MAIN, null)) {
                    unset($menus['main'][$key]);
                    continue; //继续循环
                }

                // 新版url
                $item['spa_url'] = '';
                if($item['type'] == 0){
                    $item['spa_url'] = '/static/admin/#/' . $item['url'];
                }

                // 获取当前主菜单的子菜单项
                if ($item['id'] == $nav_current_id) {
                    $menus['main'][$key]['class'] = 'active';
                    //生成child树
                    $groups = Db::name('menu')->where('pid', $item['id'])->order('sort asc')->column('group');
                    $groups = array_unique($groups);
                    //获取二级分类的合法url
                    $where = [];
                    $where['pid'] = $item['id'];
                    $where['hide'] = 0;
                    $second_urls = Db::name('menu')->where($where)->order('sort asc')->select()->toArray();

                    if (!$this->isRoot) {
                        // 检测菜单权限
                        $to_check_urls = [];
                        foreach ($second_urls as $key => $to_check_url) {
                            $rule = $to_check_url['url'];
                            if ($this->checkRule($rule, get_uid(), 1, null)) {
                                $to_check_urls[] = $to_check_url['url'];
                            }
                        }
                    }
                    // 按照分组生成子菜单树

                    foreach ($groups as $k => $g) {
                        $map = [];
                        $map[] = ['group', '=', $g];
                        if (isset($to_check_urls)) {
                            if (empty($to_check_urls)) {
                                // 没有任何权限
                                continue;
                            } else {
                                $map[] = ['url', 'in', $to_check_urls];
                            }
                        }
                        $map[] = ['pid', '=', $item['id']];
                        $map[] = ['hide', '=', 0];

                        $menuList = Db::name('Menu')->where($map)->field('id,pid,title,url,icon,tip')->order('sort asc')->select()->toArray();
                        if ($menuList) {
                            $menus['child'][$k]['group'] = $g;
                            $menus['child'][$k]['child'] = list_to_tree($menuList, 'id', 'pid', 'operater', $item['id']);
                        }
                    }
                }
            }
            unset($item);
        }

        return $menus;
    }

    /**
     * 权限检测
     * @param string $rule 检测的规则
     * @param string $mode check模式
     * @return boolean
     */
    final protected function checkRule($rule, $uid, $type = AuthRule::RULE_URL, $mode = 'url')
    {
        if ($this->isRoot) {
            return true; //管理员允许访问任何页面
        }
        static $Auth = null;
        if (!$Auth) {
            $Auth = new \muucmf\Auth();
        }
        if (!$Auth->check($rule, $uid, $type, $mode)) {
            return false;
        }
        return true;
    }

    /**
     * 设置标题
     */
    public function setTitle(String $title = 'MuuCmf')
    {
        $this->title = $title;
        View::assign('title', $title);
    }

    /**
     * 对数据表中的单行或多行记录执行修改 GET参数id为数字或逗号分隔的数字
     *
     * @param string $table 模型名称,供M函数使用的参数
     * @param array  $data 修改的数据
     * @param array  $where 查询时的where()方法的参数
     * @param array  $msg 执行正确和错误的消息 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
     *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
     */
    final protected function editRow($table, $data, $where, $msg)
    {
        $id = array_unique((array)input('id/a', 0));
        !is_array($id) && $id = explode(',',$id);

        if ($where) {
            $where = $where;
        } else {
            $where = ['id', 'in', $id];
        }

        $msg = array_merge(['success' => '操作成功！', 'error' => '操作失败', 'url' => ''], (array)$msg);
        
        if (Db::name($table)->where($where)->update($data) !== false) {
            return $this->success($msg['success'], '', $msg['url']);
        } else {
            return $this->error($msg['error'], '', $msg['url']);
        }
    }

    /**
     * 禁用条目
     * @param string $table 模型名称
     * @param array  $where 查询时的 where()方法的参数
     * @param array  $msg 执行正确和错误的消息,可以设置四个元素 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
     *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
     */
    public function forbid($table, $where = [], $msg = ['success' => '状态禁用成功', 'error' => '状态禁用失败', 'url' => 'refresh'])
    {
        $data = ['status' => 0];
        return $this->editRow($table, $data, $where, $msg);
    }

    /**
     * 恢复条目
     * @param string $table 模型名称
     * @param array  $where 查询时的where()方法的参数
     * @param array  $msg 执行正确和错误的消息 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
     *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
     *
     */
    public function resume($table, $where = [], $msg = ['success' => '启用成功', 'error' => '启用失败', 'url' => 'refresh'])
    {
        $data = ['status' => 1];
        return $this->editRow($table, $data, $where, $msg);
    }

    /**
     * 还原条目
     * @param string $table 模型名称
     * @param array  $where 查询时的where()方法的参数
     * @param array  $msg 执行正确和错误的消息 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
     *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
     */
    public function restore($table, $where = [], $msg = ['success' => '状态还原成功！', 'error' => '状态还原失败！', 'url' => 'refresh'])
    {
        $data = ['status' => 1];
        $where = array_merge(['status' => -1], $where);
        return $this->editRow($table, $data, $where, $msg);
    }

    /**
     * 条目假删除
     * @param string $table 模型名称
     * @param array  $where 查询时的where()方法的参数
     * @param array  $msg 执行正确和错误的消息 array('success'=>'','error'=>'', 'url'=>'','ajax'=>false)
     *                     url为跳转页面,ajax是否ajax方式(数字则为倒数计时秒数)
     */
    public function delete($table, $where = [], $msg = ['success' => '删除成功', 'error' => '删除失败', 'url' => 'refresh'])
    {
        $data['status'] = -1;
        return $this->editRow($table, $data, $where, $msg);
    }
}
