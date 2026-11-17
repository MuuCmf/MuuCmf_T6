<?php

namespace app\admin\controller;

use think\Exception;
use think\facade\Cache;
use app\admin\lib\Upgrade as UpgradeServer;
use app\admin\lib\Cloud as CloudServer;
use app\common\model\Menu as MenuModel;
use app\common\model\Module as ModuleModel;
use app\common\model\AuthRule;

class Module extends Admin
{
    protected MenuModel $MenuModel;
    protected ModuleModel $ModuleModel;
    /** @var array 应用 info.php 请求级缓存 */
    protected array $moduleInfoCache = [];

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
        $aType = (string)input('type', 'all', 'text');

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

        $rows = min(100, max(1, (int)input('rows', 15, 'intval')));

        $upgradeServer = new UpgradeServer();
        $modules = $this->ModuleModel->getListByPage($map, 'sort desc,id desc', '*', $rows);
        $cloud = new CloudServer();
        // 分页项可能是模型对象，直接 array_column 无法读取 name，需通过数组访问取值
        $itemNames = [];
        foreach ($modules as $module) {
            $itemNames[] = $module['name'];
        }
        $cloudVersions = $this->fetchCloudVersions($upgradeServer, $itemNames);

        $cloudNames = [];
        $authNames = [];
        foreach ($modules as &$item) {
            // 云端已发布应用口径：批量接口返回 exists=true 即视为云端存在。
            // 命中后先纠正 source，再执行升级/授权检测，避免历史 local 标记漏检。
            $versionInfo = $cloudVersions[$item['name']] ?? [];
            $existsInCloud = !empty($versionInfo['exists']);

            if ($existsInCloud && $item['source'] !== 'cloud') {
                $item['source'] = 'cloud';
                $cloudNames[] = $item['name'];
            }

            // 升级检测不依赖 source 标记与授权校验状态：
            // 云端批量版本接口是公开接口，密钥过期（云端重置后未重新激活）时
            // allapp 同步会失败，若仍按 source 过滤就可能漏掉新版本提示
            $item['new_version'] = $existsInCloud && !empty($versionInfo['version'])
                ? $versionInfo['version']
                : $item['version'];
            $item['upgrade'] = get_upgrade_status($item['version'], $item['new_version']) ? 1 : 0;

            // 授权过期检测只针对已安装的云端应用，批量请求后续统一回填
            $item['expired'] = 0;
            if ($item['source'] == 'cloud' && $item['is_setup'] == 1) {
                $authNames[] = $item['name'];
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
            $info = $this->getModuleInfo($item['name']);
            if (isset($info['entry_spa']) && $info['entry_spa'] == true) {
                $item['entry_spa'] = true;
            }
        }
        unset($item);

        // 批量纠正来源标记，避免逐行写库
        if (!empty($cloudNames)) {
            $this->ModuleModel->where('name', 'in', $cloudNames)
                ->where('source', '<>', 'cloud')
                ->update(['source' => 'cloud']);
        }

        // 批量授权检测：仅已安装的云端应用需要回填过期状态
        $authStatus = $this->fetchAuthorizationBatch($cloud, $authNames);
        if (!empty($authStatus)) {
            foreach ($modules as &$item) {
                if (!empty($authStatus[$item['name']]['expired'])) {
                    $item['expired'] = 1;
                }
            }
            unset($item);
        }

        // ajax请求返回数据
        return $this->success('success', $modules);
    }

    /**
     * 批量获取云端应用版本信息
     * @param UpgradeServer $upgradeServer 升级服务
     * @param array $names 应用标识列表
     * @return array name => ['exists' => bool, 'version' => string]
     */
    private function fetchCloudVersions(UpgradeServer $upgradeServer, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $names))));
        if (empty($names)) {
            return [];
        }
        sort($names);

        $cacheKey = 'module_cloud_versions_' . md5(
            request()->host() . '|t6|'
            . \app\admin\lib\CloudSignature::fingerprint() . '|'
            . implode(',', $names)
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = $upgradeServer->cloudVersionBatch($names);
        } catch (\Throwable $e) {
            trace('module cloud batch version exception: ' . $e->getMessage(), 'error');
            return [];
        }
        if (!is_array($result) || (int)($result['code'] ?? 0) !== 200) {
            return [];
        }

        $apps = $result['data']['apps'] ?? [];
        if (!is_array($apps)) {
            return [];
        }

        $map = [];
        foreach ($apps as $name => $info) {
            if (!is_array($info)) {
                continue;
            }
            $map[(string) $name] = [
                'exists' => !empty($info['exists']),
                'version' => (string) ($info['version'] ?? ''),
            ];
        }
        Cache::tag('module_cloud')->set($cacheKey, $map, 300);

        return $map;
    }

    /**
     * 批量获取应用授权状态
     * @param CloudServer $cloud 云端服务
     * @param array $names 应用标识列表
     * @return array name => ['authorized' => bool, 'expired' => bool, 'auth_data' => string]
     */
    private function fetchAuthorizationBatch(CloudServer $cloud, array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('strval', $names))));
        if (empty($names)) {
            return [];
        }
        sort($names);

        $cacheKey = 'module_cloud_auth_' . md5(
            request()->host() . '|'
            . \app\admin\lib\CloudSignature::fingerprint() . '|'
            . implode(',', $names)
        );
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = $cloud->needAuthorizationBatch($names);
        } catch (\Throwable $e) {
            trace('module cloud batch auth exception: ' . $e->getMessage(), 'error');
            return [];
        }

        $status = (string) ($result['status'] ?? '');
        if ($status === 'denied' || $status === 'unsupported') {
            // 云端可达但批量口径失败（密钥失效、批量接口未部署）：逐应用复核，
            // 避免授权到期提示静默丢失；网络不可达不回退，防止列表页被多次超时拖慢
            $map = $this->fetchAuthorizationSingle($cloud, array_slice($names, 0, 50));
            if (!empty($map)) {
                Cache::tag('module_cloud')->set($cacheKey, $map, 60);
            }
            return $map;
        }
        if ($status !== 'ok') {
            return [];
        }

        $map = $result['apps'] ?? [];
        if (!is_array($map)) {
            return [];
        }
        Cache::tag('module_cloud')->set($cacheKey, $map, 60);

        return $map;
    }

    /**
     * 逐应用复核授权状态（批量接口失败时的兜底）
     * @param CloudServer $cloud 云端服务
     * @param array $names 应用标识列表
     * @return array name => ['authorized' => bool, 'expired' => bool, 'auth_data' => string]
     */
    private function fetchAuthorizationSingle(CloudServer $cloud, array $names): array
    {
        $map = [];
        foreach ($names as $name) {
            try {
                $state = $cloud->needAuthorization($name);
            } catch (\Throwable $e) {
                trace('module cloud single auth exception: ' . $e->getMessage(), 'error');
                continue;
            }
            if ($state === true) {
                $map[$name] = ['authorized' => true, 'expired' => false, 'auth_data' => ''];
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            $authData = (string) ($state['data'] ?? '');
            $map[$name] = [
                'authorized' => false,
                'expired' => $authData === 'end_auth',
                'auth_data' => $authData,
            ];
        }

        return $map;
    }

    /**
     * 读取应用 info.php
     * @param string $appName 应用标识
     * @return array 应用配置，文件不存在或格式错误时返回空数组
     */
    private function getModuleInfo(string $appName): array
    {
        if (array_key_exists($appName, $this->moduleInfoCache)) {
            return $this->moduleInfoCache[$appName];
        }

        $infoFile = base_path() . $appName . '/info/info.php';
        if (!file_exists($infoFile)) {
            return $this->moduleInfoCache[$appName] = [];
        }

        $info = require $infoFile;
        return $this->moduleInfoCache[$appName] = is_array($info) ? $info : [];
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
                // 云端应用安装/重新安装只在授权有效时允许（到期、未授权、未激活均拒绝）
                $module = $this->ModuleModel->getModule($aName);
                if (!empty($module) && ($module['source'] ?? '') === 'cloud') {
                    $state = (new CloudServer())->appState($aName);
                    if ($state['state'] !== 'ok') {
                        return $this->error(CloudServer::stateMessage($state['state'], 'install'));
                    }
                }
                $res = $this->ModuleModel->install($aName);

                if ($res === true) {
                    Cache::tag('module_cloud')->clear();
                    // 云端来源的应用安装完成后上报安装记录（失败不影响安装结果）
                    try {
                        $module = $this->ModuleModel->getModule($aName);
                        if (!empty($module) && ($module['source'] ?? '') == 'cloud' && !empty($module['version'])) {
                            (new CloudServer())->reportAppRecord($aName, 'install', '', $module['version']);
                        }
                    } catch (\Throwable $e) {
                        trace('cloud app record report exception: ' . $e->getMessage(), 'error');
                    }

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
        if (request()->isPost()) {
            $id = input('id', 0, 'intval');
            $aWithoutData = input('withoutData', 0, 'intval'); //0.保留数据 1.删除数据

            try {
                $res = $this->ModuleModel->uninstall($id, $aWithoutData);
            } catch (\Throwable $e) {
                return $this->error('卸载模块失败：' . $e->getMessage());
            }

            if ($res === true) {
                //删除module表中记录（菜单、权限、行为等已由模型卸载时清理）
                $this->ModuleModel->where(['id' => $id])->delete();
                Cache::tag('module_cloud')->clear();
                return $this->success('卸载模块成功。', '', cookie('__forward__'));
            } else {
                return $this->error($this->ModuleModel->error ?: '卸载模块失败。');
            }
        }
    }

    /**
     * 重置应用
     */
    public function reload()
    {
        $cloudSynced = $this->ModuleModel->reload();
        Cache::tag('module_cloud')->clear();
        if (!$cloudSynced) {
            return $this->error('本地应用已刷新，云端应用同步失败，请检查激活状态后重试');
        }
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
