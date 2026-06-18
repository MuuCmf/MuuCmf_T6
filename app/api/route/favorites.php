<?php

use think\facade\Route;

Route::group('favorites', function () {
    // 获取收藏列表
    Route::get('lists', 'api/Favorites/lists');
    // 获取收藏数量
    Route::get('count', 'api/Favorites/count');
});
