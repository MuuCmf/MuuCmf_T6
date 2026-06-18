<?php

use think\facade\Route;

Route::group('evaluate', function () {
    // 获取评价统计
    Route::get('statistical', 'api/Evaluate/statistical');
    // 获取评价列表
    Route::get('lists', 'api/Evaluate/lists');
    // 提交/修改评价
    Route::post('edit', 'api/Evaluate/edit');
    // 获取评价详情
    Route::get('detail', 'api/Evaluate/detail');
});
