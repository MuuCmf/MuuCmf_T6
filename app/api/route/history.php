<?php

use think\facade\Route;

Route::group('history', function () {
    // 获取浏览历史列表
    Route::get('lists', 'api/History/lists');
    // 获取浏览历史数量
    Route::get('count', 'api/History/count');
});
