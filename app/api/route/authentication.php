<?php

use think\facade\Route;

Route::group('authentication', function () {
    // 提交/编辑认证资料
    Route::post('edit', 'api/Authentication/edit');
    // 获取认证数据详情
    Route::get('detail', 'api/Authentication/detail');
});
