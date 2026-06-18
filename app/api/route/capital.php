<?php

use think\facade\Route;

Route::group('capital', function () {
    // 获取资金流水
    Route::get('flow', 'api/Capital/flow');
});
