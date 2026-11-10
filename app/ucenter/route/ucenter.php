<?php

use think\facade\Route;

// ==================== ucenter 路由 ====================

// 地址管理
Route::group('address', function () {
    Route::get('lists', 'ucenter/Address/lists')->name('ucenter/Address/lists');
    // 兼容旧链接
    Route::get('list', 'ucenter/Address/lists');
    Route::get('edit', 'ucenter/Address/edit')->name('ucenter/Address/edit');
});

// 用户认证（登录、注册、找回密码）
Route::group('common', function () {
    Route::get('register', 'ucenter/Common/register')->name('ucenter/Common/register');
    Route::post('register', 'ucenter/Common/register');
    Route::get('login', 'ucenter/Common/login')->name('ucenter/Common/login');
    Route::post('login', 'ucenter/Common/login');
    Route::get('quickLogin', 'ucenter/Common/quickLogin')->name('ucenter/Common/quickLogin');
    Route::post('checkNickname', 'ucenter/Common/checkNickname')->name('ucenter/Common/checkNickname');
    Route::post('logoff', 'ucenter/Common/logoff')->name('ucenter/Common/logoff');
    Route::get('logout', 'ucenter/Common/logout')->name('ucenter/Common/logout');
    Route::get('privacy', 'ucenter/Common/privacy')->name('ucenter/Common/privacy');
    Route::get('mi', 'ucenter/Common/mi')->name('ucenter/Common/mi');
    Route::post('mi', 'ucenter/Common/mi');
    Route::get('agreement', 'ucenter/Common/agreement')->name('ucenter/Common/agreement');
});

// VIP会员
Route::group('vip', function () {
    Route::get('lists', 'ucenter/Vip/lists')->name('ucenter/Vip/lists');
    // 兼容旧链接
    Route::get('list', 'ucenter/Vip/lists');
    Route::get('detail', 'ucenter/Vip/detail')->name('ucenter/Vip/detail');
    Route::get('create', 'ucenter/Vip/create')->name('ucenter/Vip/create');
});

// 积分
Route::group('score', function () {
    Route::get('index', 'ucenter/Score/index')->name('ucenter/Score/index');
    Route::get('rule', 'ucenter/Score/rule')->name('ucenter/Score/rule');
    Route::get('estate', 'ucenter/Score/estate')->name('ucenter/Score/estate');
});

// 订单
Route::group('orders', function () {
    Route::get('lists', 'ucenter/Orders/lists')->name('ucenter/Orders/lists');
    // 兼容旧链接
    Route::get('list', 'ucenter/Orders/lists');
    Route::get('detail', 'ucenter/Orders/detail')->name('ucenter/Orders/detail');
});

// 消息
Route::group('message', function () {
    Route::get('modal', 'ucenter/Message/modal')->name('ucenter/Message/modal');
});

// 用户中心配置
Route::group('config', function () {
    Route::get('/', 'ucenter/Config/index')->name('ucenter/Config/index');
    Route::get('user_info', 'ucenter/Config/userInfo')->name('ucenter/Config/userInfo');
    Route::post('edit', 'ucenter/Config/edit')->name('ucenter/Config/edit');
    Route::get('password', 'ucenter/Config/password')->name('ucenter/Config/password');
    Route::post('password', 'ucenter/Config/password');
    Route::post('saveUsername', 'ucenter/Config/saveUsername')->name('ucenter/Config/saveUsername');
    Route::get('bind', 'ucenter/Config/bind')->name('ucenter/Config/bind');
    Route::post('mobile', 'ucenter/Config/mobile')->name('ucenter/Config/mobile');
    Route::post('email', 'ucenter/Config/email')->name('ucenter/Config/email');
    Route::post('avatar', 'ucenter/Config/avatar')->name('ucenter/Config/avatar');
    Route::get('wechat', 'ucenter/Config/wechat')->name('ucenter/Config/wechat');
    Route::post('wechat', 'ucenter/Config/wechat');
    Route::post('unbind', 'ucenter/Config/unbind')->name('ucenter/Config/unbind');
    Route::get('authentication', 'ucenter/Config/authentication')->name('ucenter/Config/authentication');
    Route::post('authentication', 'ucenter/Config/authentication');
});

// 创作者
Route::group('author', function () {
    Route::get('lists', 'ucenter/Author/lists')->name('ucenter/Author/lists');
    // 兼容旧链接
    Route::get('list', 'ucenter/Author/lists');
    Route::get('detail', 'ucenter/Author/detail')->name('ucenter/Author/detail');
});
