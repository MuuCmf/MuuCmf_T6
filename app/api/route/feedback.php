<?php

use think\facade\Route;

Route::group('feedback', function () {
    // 提交建议/反馈
    Route::post('add', 'api/Feedback/add');
});
