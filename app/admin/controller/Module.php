<?php

namespace app\admin\controller;

use think\Exception;
use app\admin\lib\Upgrade as UpgradeServer;
use app\admin\lib\Cloud as CloudServer;
use think\facade\Db;
use app\common\model\Menu as MenuModel;
use app\common\model\Module as ModuleModel;
use app\common\model\AuthRule;

class Module extends Admin
{
    protected MenuModel $MenuModel;
    protected ModuleModel $ModuleModel;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->MenuModel = new MenuModel();
        $this->ModuleModel = new ModuleModel();
    }

    /**
     * 模块管理列表
     * @return array
     */
    public function list()
    {
        $this->setTitle('应用管理');

        // 应用类型
        $aType = (string)input('type', 'installed', 'text');

        $map = [];
        switch ($aType) {
            case 'all':
                $map = [];
                break;
            // 已安装
            case 'installed':
                $map[] = ['is_setup', '=', 1];
                break;
            // 未安装
            case 'uninstalled':
                $map[] = ['is_setup', '=', 0];
                break;
        };

        $rows = (int)input('rows', 15, 'intval');

        $upgradeServer = new UpgradeServer();
        $modules = $this->ModuleModel->getListByPage($map, 'sort desc,id desc', '*', $rows);
        $cloud = new CloudServer();
        foreach ($modules as &$item) {
            // 云端应用数据处理
            if ($item['source'] == 'cloud') {
                $result = $upgradeServer->cloudVersion([
                    'app_name' => $item['name'],
                    'appid'    => $item['appid']
                ]);
                $item['new_version'] = isset($result['data']['version']) ? $result['data']['version'] : $item['version'];
                $item['upgrade'] = get_upgrade_status($item['version'], $item['new_version']) ? 1 : 0;

                $item['expired'] = 0;
                $auth = $cloud->needAuthorization($item['name']);
                if (is_array($auth) && $auth['code'] == 0 && $auth['data'] == 'end_auth') {
                    $item['expired'] = 1;
                }
            }
            // 本地应用数据处理
            if ($item['source'] == 'local') {
                // 获取文件配置信息
                $info = $this->ModuleModel->getModule($item['name']);
            }

            //获取应用图标
            if (empty($item['icon'])) {
                //图标所在位置为模块静态目录下（推荐）
                if (file_exists(PUBLIC_PATH . '/static/' . $item['name'] . '/images/icon.png')) {
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = '/static/' . $item['name'] . '/images/icon.png';
                } else {
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = '/static/admin/images/module_default_icon.png';
                }
            } else {
                $width = 100;
                $height = 100;
                $item['icon_100'] = get_thumb_image($item['icon'], intval($width), intval($height));
                $item['icon_200'] = get_thumb_image($item['icon'], intval($width * 2), intval($height * 2));
                $item['icon_300'] = get_thumb_image($item['icon'], intval($width * 3), intval($height * 3));
                $item['icon_400'] = get_thumb_image($item['icon'], intval($width * 4), intval($height * 4));

                if (strpos($item['icon'], 'https://') !== false && file_exists(PUBLIC_PATH . '/static/' . $item['name'] . '/images/icon.png')) {
                    //图标所在位置为模块静态目录下（推荐）
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = '/static/' . $item['name'] . '/images/icon.png';
                }
            }

            // 判断管理端是否支持SPA单页应用
            $item['entry_spa'] = false;
            if (file_exists(base_path() . $item['name'] . '/info/info.php')) {
                $info = require base_path() . $item['name'] . '/info/info.php';
                if (isset($info['entry_spa']) && $info['entry_spa'] == true) {
                    $item['entry_spa'] = true;
                }
            }
        }
        unset($item);

        // ajax请求返回数据
        return $this->success('success', $modules);
    }

    /**
     * 编辑模块数据
     */
    public function edit()
    {
        $id = input('id', 0, 'intval');
        $title = $id ? "编辑" : "新建";
        if (request()->isPost()) {
            $data = input();

            $res = $this->ModuleModel->edit($data);
            if ($res) {
                return $this->success($title . '成功', $res, cookie('__forward__'));
            } else {
                return $this->error($title . '失败');
            }
        }
    }

    /**
     * 获取应用模块详情
     * @return array
     */
    public function info()
    {
        $app = input('app', '', 'text');
        if (empty($app)) {
            return $this->error('参数错误');
        }
        if (!empty($app)) {
            $data = $this->ModuleModel->getModule($app);
        } else {
            return $this->error('应用不存在');
        }

        return $this->success('success', $data);
    }

    /**
     * 获取云端应用版本更新列表
     */
    public function cvList()
    {
        $name = input('name', '', 'text');
        $module = $this->ModuleModel->getModule($name, 'name, version, is_setup, source');
        if (!empty($module)) {
            $upgradeServer = new UpgradeServer();
            
            if ($module['source'] == 'cloud') {
                //获取云端版本更新列表
                $result = $upgradeServer->cloudVersionList(['app_name' => $module['name']]);
            }

            if($result['code'] != 200){
                return $this->error($result['data']);
            }

            return $this->success('success', $result['data']);
        } else {
            return $this->error('应用不存在');
        }
    }

    /**
     * 获取云端最新版本
     */
    public function cv()
    {
        $name = input('name', '', 'text');
        $module = $this->ModuleModel->getModule($name, 'name, version, is_setup, source');
        if (!empty($module)) {
            $upgradeServer = new UpgradeServer();
            $cloud = new CloudServer();
            if ($module['source'] == 'cloud') {
                //获取云端版本
                $result = $upgradeServer->cloudVersion(['app_name' => $module['name']]);
                $module['cloud_version'] = isset($result['data']['version']) ? $result['data']['version'] : $module['version'];
                $module['upgrade'] = get_upgrade_status($module['version'], $module['cloud_version']) ? 1 : 0;
                $module['remark'] = isset($result['data']['remark']) ? $result['data']['remark'] : '';
                $module['expired'] = 0;
                $auth = $cloud->needAuthorization($module['name']);
                if (is_array($auth) && $auth['code'] == 0 && $auth['data'] == 'end_auth') {
                    $module['expired'] = 1;
                }
            }

            return $this->success('success', $module);
        } else {
            return $this->error('应用不存在');
        }
    }

    /**
     * 安装模块
     * @return array
     */
    public function install()
    {
        $aName = input('name', '', 'text');

        if (request()->isPost()) {
            try {
                $res = $this->ModuleModel->install($aName);

                if ($res === true) {
                    return $this->success('安装成功。', '', cookie('__forward__'));
                }
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 卸载模块
     * @return array
     */
    public function uninstall()
    {
        $id = input('id', 0, 'intval');
        $module = $this->ModuleModel->where('id', $id)->find();

        if (request()->isPost()) {
            $aWithoutData = input('withoutData', 1, 'intval'); //是否保留数据
            $res = $this->ModuleModel->uninstall($id, $aWithoutData);

            if ($res == true) {
                //删除菜单
                Db::name('menu')->where(['module' => $module['name']])->delete();
                //删除module表中记录
                $this->ModuleModel->where(['id' => $id])->delete();
                return $this->success('卸载模块成功。', '', cookie('__forward__'));
            } else {
                return $this->error('卸载模块失败。');
            }
        }
    }

    /**
     * 重置应用
     */
    public function reload()
    {
        $this->ModuleModel->reload();
        return $this->success('重置成功');
    }

    /**
     * 应用权限菜单首页
     * @return array
     */
    public function menu()
    {
        $app = input('app', '', 'text');
        $title = input('title', '', 'text');
        $pid  = input('pid', '0', 'text');
        $map = [];

        $list_map = [];
        if (!empty($app)) {
            //获取上级数据
            $map['name'] = $app;
            $data = $this->ModuleModel->where($map)->find();
            $list_map[] = ['module', '=', $app];
        }

        if (!empty($title)) {
            $list_map['title'] = ['like', '%' . $title . '%'];
        }

        $list = $this->MenuModel->where($list_map)->order('sort asc')->select()->toArray();
        foreach ($list as &$val) {
            $val = $this->MenuModel->handle($val);
        }
        unset($val);
        // 转树结构
        $list = list_to_tree($list, 'id', 'pid', '_child', $pid);

        // 返回数据
        return $this->success('success', $list);
        
    }

    /**
     * 新增/编辑应用权限菜单
     */
    public function menuEdit()
    {
        if (request()->isPost()) {
            $data = input('');
            if ($data['title'] == '') {
                return $this->error('菜单标题不能为空');
            }
            if ($data['url'] == '') {
                return $this->error('菜单链接不能为空');
            }

            $res = $this->MenuModel->edit($data);
            if ($res) {
                //记录行为
                action_log('update_menu', 'Menu', $res, is_login());
                return $this->success('保存成功', $res, cookie('__forward__'));
            } else {
                return $this->error('保存失败');
            }
        }
    }

    /**
     * 菜单删除
     * @return array
     */
    public function menudel()
    {
        $ids = input('id');
        !is_array($ids) && $ids = explode(',', (string)$ids);

        $res = $this->MenuModel->where('id', 'in', $ids)->delete();
        if ($res) {
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }

    /**
     * 获取所有已安装应用模块列表
     */
    public function all()
    {
        // 支持的端（PC端、移动端）默认是所有端
        $support = input('support', 'all', 'text');
        // 获取所有应用模块
        $all_module_list = (new ModuleModel())->getAll([
            ['is_setup', '=', 1],
            ['name', '<>', 'ucenter'],
            ['name', '<>', 'channel']
        ]);
        // 应用权限
        foreach ($all_module_list as $key => $item) {
            // 判断主菜单权限
            if (!$this->isRoot && !$this->checkRule(strtolower($item['entry']), get_uid(), AuthRule::RULE_MAIN, null)) {
                unset($all_module_list[$key]);
                continue; //继续循环
            }

            // 判断管理端是否支持SPA单页应用
            if (file_exists(base_path() . $item['name'] . '/info/info.php')) {
                $all_module_list[$key]['entry_spa'] = false;
                $info = require base_path() . $item['name'] . '/info/info.php';
                if (isset($info['entry_spa']) && $info['entry_spa'] == true) {
                    $all_module_list[$key]['entry_spa'] = true;
                }
            }

            // 判断是否支持PC端
            if ($support == 'pc') {
                if (!file_exists(base_path() . $item['name'] . '/controller/pc/Index.php')) {
                    unset($all_module_list[$key]);
                }
            }
        }

        // 重新索引数组，确保返回的是数组而不是对象
        $all_module_list = array_values($all_module_list);

        return $this->success('success', $all_module_list);
    }
}
