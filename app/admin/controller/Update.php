<?php

namespace app\admin\controller;

use app\admin\lib\Cloud;
use app\admin\lib\Upgrade as UpgradeServer;
use think\Exception;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;
use think\Response;

/**
 * 升级包制作规则
 * 压缩方式：zip
 */
class Update extends Admin
{
    private $UpgradeServer;
    public $app_name; //应用标识

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->app_name = input('app_name', 'system') ?: 'system';
        $this->UpgradeServer = new UpgradeServer();
    }

    /**
     * 系统升级首页
     * @return [type] [description]
     */
    public function index()
    {
        // 设置页面title
        $this->setTitle('在线更新');
        // 读取本地版本号
        $local_version = $this->UpgradeServer->version($this->app_name);
        // 读取云端最新版本号
        $cloud_version = $this->UpgradeServer->cloudVersion([
            'app_name' => $this->app_name,
        ]);
        if (is_array($cloud_version) && !empty($cloud_version)) {
            $cloud_version = $cloud_version['data'];
        }
        // 是否可升级
        $upgrade = false;
        if (!empty($cloud_version['version'])) {
            $upgrade = get_upgrade_status($local_version, $cloud_version['version']);
        }

        //备份地址
        $backup_path = $this->app_name == 'system' ? '网站根目录->data->upgrade' : '网站根目录->app->' . $this->app_name . '->info->backup';

        // ajax请求返回
        if (request()->isAjax()) {
            $result = [
                'local_version' => $local_version,
                'cloud_version' => $cloud_version,
                'upgrade' => $upgrade,
                'backup_path' => $backup_path,
                'app_name' => $this->app_name,
                'scene' => 'upgrade',
                'frame' => 't6',
            ];
            return $this->success('success', $result);
        }

        View::assign('localVersion', $local_version);
        View::assign('cloudVersion', $cloud_version);
        View::assign('upgrade', $upgrade);
        View::assign('appName', $this->app_name);
        View::assign('backupPath', $backup_path);

        return View::fetch();
    }

    /*开始在线更新数据*/
    public function start()
    {
        $version = input('version', '', 'text');
        $app_name = input('app_name', '', 'text');
        $scene = input('scene', 'upgrade', 'text');
        $frame = input('frame', 't6', 'text');
        $this->setTitle('在线更新');

        View::assign([
            'appName' => $app_name,
            'frame' => $frame,
            'localVersion' => $this->UpgradeServer->version(),
            'upgradeVersion' => $version,
            'scene' => $scene,
            'authCode' => Cloud::authCode(),
            'cloud' => config('cloud.api')
        ]);

        return View::fetch();
    }

    /**
     * 获取升级包
     */
    public function package()
    {
        $version = input('version');
        $app_name = input('app_name');
        $auth_code = Cloud::authCode();
        // 生成请求json
        $result = $this->UpgradeServer->buildJson($app_name, $version, $auth_code);

        return json($result);
    }

    /**
     * @title 更新
     * @return Response|void
     */
    public function upgrade()
    {
        if (request()->isAjax()) {
            $params = request()->param();
            $path = $params['file']; //文件路径
            $md5 = $params['md5']; //文件md5
            $app_name = $this->app_name; //应用标识
            $version = $params['version']; //应用类型
            $local_path = root_path() . $path;
            $local_version = $this->UpgradeServer->version($app_name); //本地版本
            $upgrade = get_upgrade_status($local_version, $version); //版本号对比
            if (!$upgrade) return $this->success('已经是最新版本！', 'same_version');

            try {
                //检查忽略文件
                $ignore = $this->UpgradeServer->checkIgnoreFile($path);
                // 忽略文件直接跳过
                if ($ignore === true) {
                    return $this->success('success', '忽略的文件');
                }

                //对比文件
                if (file_exists($local_path)) {
                    $upgrade = !boolval($md5 == @md5_file($local_path));
                } else {
                    $upgrade = true;
                }
                //md5不同，请求远端文件
                if ($upgrade) {
                    $params = [
                        'md5'       =>  $md5,
                        'app_name'  =>  $this->app_name,
                        'version'   =>  $version
                    ];
                    // 下载文件
                    $this->UpgradeServer->downFile($params, $local_path);
                }
                return $this->success('success', $upgrade);
            } catch (Exception $e) {
                return $this->result($e->getCode(), $e->getMessage());
            }
        }
    }

    /**
     * @title 更新完成
     * @return Response|void
     */
    public function finish()
    {
        if (request()->isAjax()) {
            $params = request()->post();
            try {
                //执行升级sql
                $this->UpgradeServer->executeUpgradeSql($this->app_name);

                // 更新版本号
                if ($params['skip'] == 0) {

                    // 系统更新
                    if ($this->app_name == 'system') {
                        // 更改root_path() . 'data/version.ini'文件内容为版本号
                        file_put_contents(root_path() . 'data/version.ini', $params['version']);
                        
                        //更新系统版本号
                        //$this->UpgradeServer->downFile($params, root_path() . 'data/version.ini');
                    } else {
                        //更新应用版本号
                        Db::name('module')->where('name', '=', $this->app_name)->update(['version' => $params['version']]);
                    }
                }

                //队列 worker 与 crontab 调度器都是常驻进程，升级后需重新加载代码，
                //否则新模块投递的队列任务仍会走旧代码；此处写入重启信号（等同 php think queue:restart）
                Cache::set('think:queue:restart', time());

                //入口域名/IP 变更后旧授权码仍会缓存 30 分钟，提前失效避免后续检查更新误报未授权
                Cache::delete(request()->host() . '_MUUCMF_CLOUD_AUTH');

                //返回
                return $this->success('更新完成');
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
        }
    }

    /**
     * 检查是否可升级
     */
    public function check()
    {
        // 读取本地版本号
        $local_version = $this->UpgradeServer->version($this->app_name);
        // 读取云端最新版本号
        $cloud_version = $this->UpgradeServer->cloudVersion([
            'app_name' => $this->app_name,
        ]);
        if (is_array($cloud_version) && !empty($cloud_version)) {
            $cloud_version = $cloud_version['data'];
        }
        // 是否可升级
        $upgrade = false;
        if (!empty($cloud_version['version'])) {
            $upgrade = get_upgrade_status($local_version, $cloud_version['version']);
        }

        if ($upgrade) {
            return $this->success('云端有新版版可更新', $cloud_version['version']);
        }
        return $this->error('已经是最新版本', $local_version);
    }
}
