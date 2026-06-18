<?php

use think\facade\Route;

Route::group('config', function () {
    // 获取前台系统配置
    Route::get('system', 'api/Config/system');
});
