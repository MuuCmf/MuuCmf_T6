<?php

namespace app\common\model;

use think\facade\Db;
use think\Exception;

class Module extends Base
{
    public $error = '';
    /**
     * [getAll description]
     * @return [type] [description]
     */
    public function getAll($where = [])
    {
        $list = $this->where($where)->order('sort desc,id desc')->select()->toArray();
        foreach ($list as &$item) {

            if (empty($item['icon'])) {
                //图标所在位置为模块静态目录下（推荐）
                if (file_exists(PUBLIC_PATH . '/static/' . $item['name'] . '/images/icon.png')) {
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = request()->domain() . '/static/' . $item['name'] . '/images/icon.png';
                } else {
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = request()->domain() . '/static/admin/images/module_default_icon.png';
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
                    $item['icon_100'] = $item['icon_200'] = $item['icon_300'] = $item['icon_400'] = request()->domain() . '/static/' . $item['name'] . '/images/icon.png';
                }
            }

            // 调整入口路径
            if (file_exists(APP_PATH . '/' . $item['name'] . '/info/info.php') && $item['name'] != '.' && $item['name'] != '..') {
                // 获取配置数据
                $info = $this->getInfo($item['name']);
                if (empty($info['entry'])) {
                    $item['entry'] = $item['name'] . 'admin.Index/index';
                } else {
                    $item['entry'] = $info['entry'];
                }
            }
        }
        unset($item);

        return $list;
    }

    /**
     * 重置应用
     */
    public function reload()
    {
        $this->getLocalApp();
        $this->getCloudAuthApp();
    }

    /**
     * 同步云端授权应用
     */
    public function getCloudAuthApp()
    {
        $url = config('cloud.api') . 'authorization/allapp';
        $domain = request()->host();
        $result = curl_request($url, [
            'domain'  =>  $domain,
        ]);

        $result = json_decode($result, true);
        if (is_array($result) && $result['code'] == 200) {
            // 写入应用模块表
            // 查询存在应用
            $has = $this->column('name');
            $list = $result['data'];
            $data = [];
            foreach ($list as $v) {
                // 排除版本未发布的
                if (!empty($v['app']['version'])) {
                    if (!in_array($v['app']['name'], $has)) {
                        $data[] = [
                            'name' => $v['app']['name'],
                            'alias' => $v['app']['title'],
                            'icon' => $v['app']['cover_400'],
                            'version' => $v['app']['version'],
                            'summary' => $v['app']['summary'],
                            'developer' => $v['app']['developer'],
                            'website' => '',
                            'entry' => $v['app']['name'] . '/admin.index/index',
                            'is_setup' => 0,
                            'sort' => 0,
                            'source' => 'cloud'
                        ];
                    } else {
                        $has_data = $this->where('name', $v['app']['name'])->find();
                        $icon = $has_data['icon'];
                        if (empty($has_data['icon'])) {
                            $icon = $v['app']['cover_400'];
                        }
                        $data[] = [
                            'id' => $has_data['id'],
                            'source' => 'cloud',
                            'icon' => $icon
                        ];
                    }
                }
            }

            $this->saveAll($data);
        }
    }

    /**
     * 重新通过文件来同步模块
     */
    public function getLocalApp()
    {
        //获取所有本地模块
        $dir[] = NULL;
        if (false != ($handle = opendir(APP_PATH))) {
            $i = 0;
            while (false !== ($file = readdir($handle))) {
                //去掉"“.”、“..”以及带“.xxx”后缀的文件
                if ($file != "." && $file != ".." && !strpos($file, ".")) {
                    $dir[$i] = $file;
                    $i++;
                }
            }
            //关闭句柄
            closedir($handle);
        }

        // 排除无效目录
        $exclude = ['.htaccess', 'extra', 'lang', 'admin', 'common', 'api', 'channel', 'index', 'ucenter'];
        foreach ($dir as $k => $v) {
            if (in_array($v, $exclude)) {
                unset($dir[$k]);
            }
        }

        $module = [];
        foreach ($dir as $subdir) {
            if (file_exists(APP_PATH . '/' . $subdir . '/info/info.php') && $subdir != '.' && $subdir != '..') {
                // 获取配置数据
                $info = $this->getInfo($subdir);
                $info['sort'] = 0;
                $info['source'] = 'local';
                // 合并数据表内模块
                $module_info = $this->getModule($info['name']);
                if ($module_info) {
                    $info = array_merge($info, $module_info);
                    $info['id'] = $module_info['id'];
                    $info['source'] = 'local';
                }
                $module[] = $info;
            }
        }

        if (!empty($module)) {
            //写入数据库
            $this->saveAll($module);
        }

        // 获取所有本地未安装应用
        $list = $this->where([
            ['source', '=', 'local'],
            ['is_setup', '=', 0]
        ])->select();

        if (!empty($list)) {
            foreach ($list as $v) {
                if (!is_dir(APP_PATH . '/' . $v['name'])) {
                    // 清除无文件应用
                    $this->where('id', $v['id'])->delete();
                }
            }
        }
    }

    /**
     * 安装某个模块
     * @param $id
     * @return bool
     */
    public function install($name)
    {
        $log = '';

        $module = $this->getModule($name);

        if ($module['is_setup'] == 1) {
            throw new Exception('应用已安装');
        }

        // 更新info内配置数据
        if (file_exists(APP_PATH . '/' . $name . '/info/info.php')) {
            // 获取配置数据
            $info = $this->getInfo($name);
            // 合并数据表内模块
            $module = array_merge($module, $info);
        }

        // 导入菜单
        if (file_exists(APP_PATH . $module['name'] . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'guide.json')) {
            //如果存在guide.json
            $guide = file_get_contents(APP_PATH . $module['name'] . DIRECTORY_SEPARATOR . 'info' . DIRECTORY_SEPARATOR . 'guide.json');
            $data = json_decode($guide, true);

            if (!empty($data['menu'])) {
                //导入菜单项,menu
                $menu = json_decode($data['menu'], true);
                if (!empty($menu)) {
                    $this->cleanMenus($module['name']);
                    if ($this->addMenus($menu[0]) == true) {
                        $log .= '&nbsp;&nbsp;>菜单成功安装;<br/>';
                    }
                }
            }

            if (!empty($data['action'])) {
                //导入
                $action = json_decode($data['action'], true);
                if (!empty($action)) {
                    $this->cleanAction($module['name']);
                    if ($this->addAction($action)) {
                        $log .= '&nbsp;&nbsp;>行为成功导入。<br/>';
                    }
                }
            }

            if (!empty($data['action_limit'])) {
                $action_limit = json_decode($data['action_limit'], true);
                if (!empty($action_limit)) {
                    $this->cleanActionLimit($module['name']);
                    if ($this->addActionLimit($action_limit)) {
                        $log .= '&nbsp;&nbsp;>行为限制成功导入。<br/>';
                    }
                }
            }
        }

        // 导入sql文件
        if (file_exists(APP_PATH . '/' . $module['name'] . '/info/install.sql')) {
            $install_sql = APP_PATH . '/' . $module['name'] . '/info/install.sql';
            $install_sql = file_get_contents($install_sql);
            $install_sql = str_replace("\r", "\n", $install_sql);
            $install_sql = explode(";\n", $install_sql);
            //系统配置表前缀
            $prefix = config('database.connections.mysql.prefix');

            foreach ($install_sql as $value) {

                $value = trim($value);
                if (empty($value)) continue;
                if (strpos($value, 'CREATE TABLE') !== false) { //创建表
                    //获取表名
                    $name = preg_replace("/[\s\S]*CREATE TABLE IF NOT EXISTS `(\w+)`[\s\S]*/", "\\1", $value);
                    //获取表前缀
                    $orginal = preg_replace("/[\s\S]*CREATE TABLE IF NOT EXISTS `([a-zA-Z]+_)[\s\S]*/", "\\1", $value);
                    //替换表前缀
                    $value = str_replace(" `{$orginal}", " `{$prefix}", $value);
                    $msg = "创建数据表{$name}";
                    if (false === Db::execute($value)) {
                        throw new Exception($msg . '...失败;');
                    }
                }
                //写入前清空
                if (strpos($value, 'INSERT INTO') !== false) { //写入数据
                    //获取表名
                    $name = preg_replace("/[\s\S]*INSERT INTO `(\w+)`[\s\S]*/", "\\1", $value);
                    //获取表前缀
                    $orginal = preg_replace("/[\s\S]*INSERT INTO `([a-zA-Z]+_)[\s\S]*/", "\\1", $value);
                    //替换表前缀
                    $value = str_replace(" `{$orginal}", " `{$prefix}", $value);
                    //如果存在数据就跳过
                    $value = str_replace('INSERT INTO', 'INSERT IGNORE INTO', $value);

                    Db::execute($value);
                }
            }
        }

        $module['is_setup'] = 1;
        // 写入数据库
        $rs = $this->update($module);
        if ($rs === false) {
            throw new Exception('应用数据修改失败');
        }

        return true;
    }

    /**
     * 卸载模块
     * @param $id 模块ID
     * @param int $withoutData 0.保留数据 1.删除数据
     * @return bool
     */
    public function uninstall($id, $withoutData = 0)
    {
        $module = $this->find($id);
        if (!$module) {
            $this->error = '应用不存在';
            return false;
        }
        if ($module['is_setup'] == 0) {
            $this->error = '应用未安装';
            return false;
        }
        $module = $module->toArray();

        Db::startTrans();
        try {
            $this->cleanMenus($module['name']);
            $this->cleanAuthRules($module['name']);
            $this->cleanAction($module['name']);
            $this->cleanActionLimit($module['name']);

            if ($withoutData == 1) {
                //删除数据：执行 uninstall.sql 清理数据表
                $uninstall_file = APP_PATH . $module['name'] . '/info/uninstall.sql';
                if (file_exists($uninstall_file)) {
                    //读取sql语句
                    $uninstallSql = file_get_contents($uninstall_file);
                    $uninstallSql = str_replace("\r", "", $uninstallSql);
                    $uninstallSql = explode(";\n", $uninstallSql);

                    //系统配置表前缀
                    $prefix = config('database.connections.mysql.prefix');

                    foreach ($uninstallSql as $value) {
                        $value = trim($value);
                        if (empty($value)) continue;

                        //获取表名
                        $name = preg_replace("/[\s\S]*DROP TABLE IF EXISTS `(\w+)`[\s\S]*/", "\\1", $value);
                        //获取表前缀
                        $orginal = preg_replace("/[\s\S]*DROP TABLE IF EXISTS `([a-zA-Z]+_)[\s\S]*/", "\\1", $value);
                        //替换表前缀
                        $value = str_replace(" `{$orginal}", " `{$prefix}", $value);

                        Db::execute($value);
                    }
                }
            }

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollback();
            $this->error = '卸载模块失败：' . $e->getMessage();
            return false;
        }
    }

    /**
     * 更新本地应用
     */
    public function localUpgrade($name) {}

    /**
     * 通过name来获取应用
     * @param $name 应用名
     * @return array|mixed
     */
    public function getModule($name, $field = '*')
    {
        if ($name == 'admin' || $name == 'common' || $name == 'channel' || $name == 'ucenter') {
            return false;
        }

        $info = $this->where('name', $name)->field($field)->find();
        if ($info) {
            $info = $info->toArray();
            if (empty($info['icon'])) {
                //图标所在位置为模块静态目录下（推荐）
                if (file_exists(PUBLIC_PATH . '/static/' . $name . '/images/icon.png')) {
                    $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = '/static/' . $name . '/images/icon.png';
                } else {
                    $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = '/static/admin/images/module_default_icon.png';
                }
            } else {
                $width = 100;
                $height = 100;
                $info['icon_100'] = get_thumb_image($info['icon'], intval($width), intval($height));
                $info['icon_200'] = get_thumb_image($info['icon'], intval($width * 2), intval($height * 2));
                $info['icon_300'] = get_thumb_image($info['icon'], intval($width * 3), intval($height * 3));
                $info['icon_400'] = get_thumb_image($info['icon'], intval($width * 4), intval($height * 4));

                if (strpos($info['icon'], 'https://') !== false && file_exists(PUBLIC_PATH . '/static/' . $info['name'] . '/images/icon.png')) {
                    //图标所在位置为模块静态目录下（推荐）
                    $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = request()->domain() . '/static/' . $info['name'] . '/images/icon.png';
                }
            }
        }

        return $info;
    }

    /**
     * 检查模块是否已经安装
     * @param $name
     * @return bool
     */
    public function checkInstalled($name)
    {
        if (
            $name == 'admin'
            || $name == 'api'
            || $name == 'common'
            || $name == 'channel'
            || $name == 'ucenter'
            || $name == 'index'
        ) {
            return true;
        }

        $m = $this->getModule($name);
        if (!empty($m) && $m['name'] == $name && $m['is_setup'] == 1) {
            return true;
        }

        return false;
    }

    private function addAction($action)
    {
        foreach ($action as $v) {
            unset($v['id']);
            Db::name('Action')->insert($v);
        }
        return true;
    }

    private function addActionLimit($action)
    {
        foreach ($action as $v) {
            unset($v['id']);
            Db::name('ActionLimit')->insert($v);
        }
        return true;
    }

    private function cleanActionLimit($module_name)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $sql = "DELETE FROM `{$db_prefix}action_limit` where `module` = '" . $module_name . "'";
        Db::execute($sql);
    }

    /**
     * 清理应用行为
     */
    private function cleanAction($module_name)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $sql = "DELETE FROM `{$db_prefix}action` where `module` = '" . $module_name . "'";
        Db::execute($sql);
    }

    /**
     * 清理应用权限
     */
    private function cleanAuthRules($module_name)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $sql = "DELETE FROM `{$db_prefix}auth_rule` where `module` = '" . $module_name . "'";
        Db::execute($sql);
    }

    /**
     * 清理应用菜单
     */
    private function cleanMenus($module_name)
    {
        $db_prefix = config('database.connections.mysql.prefix');
        $sql = "DELETE FROM `{$db_prefix}menu` where `module` = '" . $module_name . "'";
        Db::execute($sql);
    }

    /**
     * 写入模块菜单
     * @param [type] $menu [description]
     */
    private function addMenus($menu)
    {
        Db::name('Menu')->strict(false)->insert($menu);

        if (!empty($menu['_']))
            foreach ($menu['_'] as $v) {
                $this->addMenus($v);
            }
        return true;
    }

    /**
     * 获取应用配置信息
     * @param  [type] $name [description]
     * @return [type]       [description]
     */
    private function getInfo($name)
    {
        // 严格验证模块名，防止目录遍历
        if (empty($name) || $name == '.' || $name == '..' || strpos($name, '/') !== false || strpos($name, '\\') !== false || strpos($name, '.') !== false) {
            return [];
        }
        
        $modulePath = APP_PATH . '/' . $name . '/info/info.php';
        if (file_exists($modulePath)) {
            $module = require($modulePath);
            return $module;
        } else {
            return [];
        }
    }
}
