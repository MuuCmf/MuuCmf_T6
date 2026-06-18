<?php

use think\facade\Route;

Route::group('keywords', function () {
    // 获取用户搜索历史
    Route::get('history', 'api/Keywords/history');
    // 获取系统热门搜索关键字
    Route::get('hot', 'api/Keywords/hot');
    // 新增搜索关键字
    Route::post('add', 'api/Keywords/add');
});
