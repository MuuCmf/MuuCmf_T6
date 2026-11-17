<?php

namespace app\common\model;

use app\common\service\CloudAppSource;
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
     * @return bool 云端应用同步是否成功
     */
    public function reload(): bool
    {
        $this->getLocalApp();
        return $this->getCloudAuthApp();
    }

    /**
     * 同步云端授权应用
     * @return bool 同步是否成功
     */
    public function getCloudAuthApp(): bool
    {
        $url = config('cloud.api') . 'authorization/allapp';
        $domain = request()->host();
        $params = ['domain' => $domain];
        // 与云端 allapp 同发版：先带凭证，再由域名 legacy_off 决定是否只接受签名
        // 生成失败时保持旧行为，避免「重置应用」整体不可用
        try {
            $params['auth_code'] = \app\admin\lib\Cloud::authCode();
        } catch (\Throwable $e) {
            // 凭证不可用时回落为仅传域名，由云端 legacy_off 决定是否放行
        }
        // S1：已激活则叠加签名参数；未激活时保持 auth_code 单通道
        try {
            $sig = \app\admin\lib\CloudSignature::payload('authorization.allapp');
            if (!empty($sig)) {
                $params = array_merge($params, $sig);
            }
        } catch (\Throwable $e) {
            // 签名不可用时回落 legacy，不阻断「重置应用」
        }
        $result = curl_request($url, $params);

        $result = json_decode($result, true);
        if (is_array($result) && $result['code'] == 200) {
            // 写入应用模块表
            // 查询存在应用
            $has = $this->column('name');
            $list = $result['data'] ?? [];
            if (!is_array($list)) {
                trace('cloud allapp sync failed: invalid data', 'error');
                return false;
            }
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

            // 仅已购口径：清理已不在授权清单内的未安装云端应用。
            // 只删「纯云端行」：已安装或本地目录仍存在的应用一律保留，
            // 避免撤权后站点无法继续使用/续费，也避免误删随包内置应用。
            // 只有云端成功返回完整清单才会走到这里，失败已提前 return false。
            $syncedNames = [];
            foreach ($list as $v) {
                if (!empty($v['app']['name']) && !empty($v['app']['version'])) {
                    $syncedNames[] = (string) $v['app']['name'];
                }
            }
            $stale = $this->where('source', 'cloud')
                ->where('is_setup', 0)
                ->when(!empty($syncedNames), function ($query) use ($syncedNames) {
                    $query->where('name', 'not in', $syncedNames);
                })
                ->field('id,name')
                ->select()
                ->toArray();
            $removeIds = [];
            foreach ($stale as $row) {
                if (!is_dir(APP_PATH . $row['name'])) {
                    $removeIds[] = $row['id'];
                }
            }
            if (!empty($removeIds)) {
                $removed = $this->where('id', 'in', $removeIds)->delete();
                if ($removed) {
                    trace('cloud app sync removed ' . $removed . ' unpurchased rows', 'info');
                }
            }
            return true;
        }

        // 云端同步失败不再静默：刷新应用是用户主动操作，失败需要回显（多为激活密钥失效）
        trace(
            'cloud allapp sync failed: '
            . (is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : 'invalid response'),
            'error'
        );
        return false;
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
        $isNewLocal = [];
        foreach ($dir as $subdir) {
            if (file_exists(APP_PATH . '/' . $subdir . '/info/info.php') && $subdir != '.' && $subdir != '..') {
                // 获取配置数据
                $info = $this->getInfo($subdir);
                $info['sort'] = 0;
                $info['source'] = 'local';
                // 合并数据表内模块（此处跳过单条云端判定，避免逐个应用发请求，
                // 循环结束后统一批量判定、纠正来源并拦截重名）
                $module_info = $this->getModule($info['name'], '*', false);
                // 无库记录的本地目录才算「新登记」，重名拦截只针对这类应用
                $isNewLocal[(string) $info['name']] = empty($module_info);
                if ($module_info) {
                    $info = array_merge($info, $module_info);
                    $info['id'] = $module_info['id'];
                    // 保留库内来源标记：云端安装的应用同样有本地目录与 info.php，
                    // 无条件改写为 local 会永久丢失 cloud 标记（allapp 同步失败时无法自愈）
                    $info['source'] = !empty($module_info['source']) ? (string) $module_info['source'] : 'local';
                }
                $module[] = $info;
            }
        }

        if (!empty($module)) {
            // 一次批量云端判定：既用于重名拦截，也用于历史来源纠正
            $cloudStates = CloudAppSource::versions(array_column($module, 'name'));
            $cloudNames = [];
            $conflicts = [];
            $registered = [];
            foreach ($module as $item) {
                $name = (string) $item['name'];
                if (empty($cloudStates[$name]['exists'])) {
                    $registered[] = $item;
                    continue;
                }
                // 标识唯一：本地应用禁止占用云端已发布标识，重名直接拒绝登记
                if (!empty($isNewLocal[$name])) {
                    $conflicts[] = $name;
                    continue;
                }
                // 已有库记录：云端已发布即按云端应用处理，纠正历史 local 标记
                $registered[] = $item;
                $cloudNames[] = $name;
            }

            if (!empty($registered)) {
                $this->saveAll($registered);
            }
            CloudAppSource::heal($cloudNames);

            if (!empty($conflicts)) {
                $this->error = '应用标识与云端应用重名，已拒绝登记：' . implode('、', $conflicts)
                    . '。请使用唯一标识后重新刷新应用。';
                trace('local app name conflicts with cloud app: ' . implode(',', $conflicts), 'error');
            }
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

        if (empty($module)) {
            // 未登记通常意味着本地目录标识与云端应用重名被拒，或应用已被移除
            throw new Exception('应用不存在或标识与云端应用重名，请检查应用标识');
        }

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

        // 模块专属消息模板由模块自身 info/message_templates.sql 提供，安装时重放（幂等）
        try {
            \app\common\service\message\ModuleTemplateSeeder::replayApp($module['name']);
        } catch (\Throwable $e) {
            // 模板种子失败不影响模块安装
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
            if ($withoutData == 1) {
                // 模块专属消息模板与渠道行由装配器按 app 清理，避免卸载残留
                \app\common\service\message\ModuleTemplateSeeder::purge($module['name']);
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
     * @param string $field 查询字段
     * @param bool $resolveSource 是否按云端发布状态纠正来源标记
     * @return array|mixed
     */
    public function getModule($name, $field = '*', $resolveSource = true)
    {
        if ($name == 'admin' || $name == 'common' || $name == 'channel' || $name == 'ucenter') {
            return false;
        }

        $info = $this->where('name', $name)->field($field)->find();
        if ($info) {
            $info = $info->toArray();
            // 全局强制云端来源：库内 source 可能因 allapp 同步失败、历史数据或
            // 本地目录重建停留在 local，这里以云端是否已发布为准纠正，
            // 避免版本检测、安装拦截、运行时授权等入口按旧标记漏判。
            if ($resolveSource && array_key_exists('source', $info) && (string) $info['source'] !== 'cloud') {
                if (CloudAppSource::isCloud($name)) {
                    $info['source'] = 'cloud';
                }
            }
            if (empty($info['icon'])) {
                //图标所在位置为模块静态目录下（推荐）
                if (defined('PUBLIC_PATH') && file_exists(PUBLIC_PATH . '/static/' . $name . '/images/icon.png')) {
                    $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = '/static/' . $name . '/images/icon.png';
                } else {
                    $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = '/static/admin/images/module_default_icon.png';
                }
            } elseif (!defined('PUBLIC_PATH')) {
                // CLI/计划任务/队列等非 Web 入口：PUBLIC_PATH 未定义，
                // 缩略图生成（get_thumb_image）与 file_exists 均依赖该常量，
                // 直接使用默认图标，避免抛异常导致 checkInstalled 误判模块未安装、折扣读路径静默降级。
                $info['icon_100'] = $info['icon_200'] = $info['icon_300'] = $info['icon_400'] = '/static/admin/images/module_default_icon.png';
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
