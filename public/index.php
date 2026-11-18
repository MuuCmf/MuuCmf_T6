<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// [ 应用入口文件 ]
namespace think;

require __DIR__ . '/../vendor/autoload.php';

//模块目录
define('APP_PATH', __DIR__ . '/../app/');
//public目录
define('PUBLIC_PATH', __DIR__);
//static目录
define('STATIC_URL', __DIR__ . '/static');
// 判断是否安装MuuCmf
if (!is_file(__DIR__ . '/../data/install.lock'))
{	
    header("location: /install.php");
    exit;
}

// 升级替换阶段：租户端进入维护页，后台接口保持可用以便续传、回滚与收尾
$upgrade_maintenance_lock = __DIR__ . '/../data/upgrade/maintenance.lock';
if (is_file($upgrade_maintenance_lock)) {
    // 僵死判定：替换阶段是按批次推进的，正常几分钟内结束。浏览器被关掉、批处理失败后无人重试时，
    // 这个标志会一直留着，整站永久 503 且没有入口自救。超过 30 分钟视为中断，改名留证后恢复访问，
    // 备份区与状态文件不动，管理员仍可从后台「回滚」恢复到升级前。
    $upgrade_lock_age = time() - (int) @filemtime($upgrade_maintenance_lock);
    if ($upgrade_lock_age > 1800) {
        @rename($upgrade_maintenance_lock, $upgrade_maintenance_lock . '.stale.' . date('YmdHis'));
        error_log('[upgrade] maintenance lock stale for ' . $upgrade_lock_age . 's, released: ' . $upgrade_maintenance_lock);
    } else {
        $request_uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $is_backend = strpos($request_uri, '/admin/') !== false || strpos($request_uri, '/static/') !== false;
        if (!$is_backend) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 30');
            echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>系统升级中</title></head>'
                . '<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;background:#f5f7fa;color:#303133;">'
                . '<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;">'
                . '<div style="background:#fff;padding:48px 40px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.08);text-align:center;max-width:480px;">'
                . '<h1 style="font-size:22px;margin:0 0 16px;">系统升级中</h1>'
                . '<p style="font-size:14px;line-height:1.8;color:#606266;margin:0;">升级正在应用新文件，完成后将自动恢复访问，请稍后刷新页面。</p>'
                . '</div></div></body></html>';
            exit;
        }
    }
}

// 执行HTTP应用并响应
$http = (new App())->http;

$response = $http->run();

$response->send();

$http->end($response);
