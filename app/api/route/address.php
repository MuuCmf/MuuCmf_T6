<?php

use think\facade\Route;

Route::group('address', function () {
    // 获取默认地址
    Route::get('default', 'api/Address/default');
    // 获取地址详情
    Route::get('detail', 'api/Address/detail');
    // 获取地址列表
    Route::get('lists', 'api/Address/lists');
    // 新增/编辑地址
    Route::post('edit', 'api/Address/edit');
    // 设为默认地址
    Route::get('set_default', 'api/Address/setDefault');
    // 删除地址
    Route::post('del', 'api/Address/del');
});
