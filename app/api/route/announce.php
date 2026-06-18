<?php

use think\facade\Route;

Route::group('announce', function () {
    // 获取公告详情
    Route::get('detail', 'api/Announce/detail');
    // 获取公告列表
    Route::get('lists', 'api/Announce/lists');
    // 获取公告分页列表
    Route::get('pageLists', 'api/Announce/pageLists');
});
