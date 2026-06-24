<?php
// +----------------------------------------------------------------------
// | Admin Routes
// +----------------------------------------------------------------------

use think\facade\Route;

// 行为控制器路由
Route::group('action', function () {
    // 行为日志相关
    Route::get('log', 'admin/Action/log'); // 行为日志列表
    Route::get('log/detail', 'admin/Action/detail'); // 查看行为日志详情
    Route::post('log/delete', 'admin/Action/remove'); // 删除日志
    Route::post('log/clear', 'admin/Action/clear'); // 清空日志
    Route::any('log/csv', 'admin/Action/csv'); // 导出CSV
    
    // 行为管理相关
    Route::get('list', 'admin/Action/list'); // 用户行为、积分规则列表
    Route::any('edit', 'admin/Action/edit'); // 新增、编辑行为
    Route::post('status', 'admin/Action/setStatus'); // 设置行为状态
    
    // 行为限制相关
    Route::get('limit', 'admin/Action/limit'); // 行为限制列表
    Route::any('limit/edit', 'admin/Action/limitEdit'); // 编辑行为限制
    Route::post('limit/status', 'admin/Action/limitStatus'); // 行为限制状态
});

Route::group('auth', function () {
    // 权限组管理
    Route::any('group/list', 'admin/Auth/group');
    Route::post('group/edit', 'admin/Auth/groupEdit');
    Route::post('group/status', 'admin/Auth/groupStatus');
    Route::post('group/delete', 'admin/Auth/groupDelete');
    
    // 用户相关
    Route::get('group/user', 'admin/Auth/user');
    Route::post('group/user/remove', 'admin/Auth/removeFromGroup');
    
    // 权限访问
    Route::get('access', 'admin/Auth/access');
});

Route::group('config', function () {
    // 配置分组列表
    Route::any('group/list', 'admin/Config/groupList');
    
    // 获取配置分组数据列表
    Route::get('group', 'admin/Config/group');
    
    // 系统配置参数管理
    Route::get('list', 'admin/Config/list');
    
    // 编辑系统配置
    Route::post('edit', 'admin/Config/edit');
    
    // 删除配置参数
    Route::post('del', 'admin/Config/del');
});

Route::group('crontab', function () {
    // 定时任务管理
    Route::get('list', 'admin/Crontab/list');
    Route::post('edit', 'admin/Crontab/edit');
    Route::post('status', 'admin/Crontab/status');
    Route::post('clear', 'admin/Crontab/clear');
    Route::get('log', 'admin/Crontab/log');
});

Route::group('douyin', function () {
    // 抖音小程序配置
    Route::any('config', 'admin/DouyinMiniprogram/config');

    // 抖音订单结算列表
    Route::get('settle', 'admin/DouyinMiniprogram/settle');

    // 未结算订单列表
    Route::get('orders', 'admin/DouyinMiniprogram/orders');

    // 手动触发结算分账
    Route::post('manual/settle', 'admin/DouyinMiniprogram/manualSettle');

    // 结算及分账结果查询
    Route::post('manual/settle_query', 'admin/DouyinMiniprogram/manualSettleQuery');

    // 模板消息通知
    Route::any('template_message', 'admin/DouyinMiniprogram/templateMessage');
});

Route::group('extend', function () {
    // 扩展配置分组列表
    Route::any('group/list', 'admin/Extend/groupList');
    
    // 扩展配置管理
    Route::get('list', 'admin/Extend/list');
    Route::any('edit', 'admin/Extend/edit');
    // 删除扩展配置参数
    Route::post('del', 'admin/Extend/del');
});

Route::group('field', function () {
    // 分组管理
    Route::get('group', 'admin/Field/group');
    Route::post('group/edit', 'admin/Field/editGroup');
    Route::post('group/status', 'admin/Field/setGroupStatus');
    
    // 字段管理
    Route::get('list', 'admin/Field/list');
    Route::any('edit', 'admin/Field/editField');
    Route::post('status', 'admin/Field/setFieldStatus');
});

Route::group('menu', function () {
    // 权限菜单列表
    Route::get('list', 'admin/Menu/list');
    // 权限菜单树
    Route::get('tree', 'admin/Menu/tree');
});

Route::group('message', function () {
    // 消息类型管理
    Route::get('type', 'admin/Message/type');
    Route::post('type/edit', 'admin/Message/typeEdit');
    Route::post('type/status', 'admin/Message/typeStatus');
    
    // 消息发送管理
    Route::post('send', 'admin/Message/send');
    Route::get('list', 'admin/Message/list');
    Route::post('status', 'admin/Message/messageStatus');
    
    // 消息内容管理
    Route::get('content', 'admin/Message/content');
    Route::post('content/edit', 'admin/Message/contentEdit');
    Route::post('content/status', 'admin/Message/contentStatus');
});

// 模块管理路由配置
Route::group('module', function () {
    // 模块列表
    Route::get('index', 'admin/Module/index');

    // 模块列表
    Route::get('list', 'admin/Module/list');
    
    // 编辑模块数据
    Route::post('edit', 'admin/Module/edit');
    
    // 获取应用模块详情
    Route::get('info', 'admin/Module/info');
    
    // 获取云端应用版本更新列表
    Route::get('cvlist', 'admin/Module/cvList');
    // 获取云端最新版本
    Route::get('cv', 'admin/Module/cv');
    
    // 安装应用模块
    Route::get('install', 'admin/Module/install');
    Route::post('install', 'admin/Module/install');
    
    // 卸载应用模块
    Route::get('uninstall', 'admin/Module/uninstall');
    Route::post('uninstall', 'admin/Module/uninstall');
    
    // 应用权限菜单
    Route::get('menu', 'admin/Module/menu');

    // 新增/编辑应用权限菜单
    Route::any('menu/edit', 'admin/Module/menuEdit');
    
    // 菜单删除
    Route::post('menu/del', 'admin/Module/menuDel');
    
    // 获取应用模块列表
    Route::get('all', 'admin/Module/all');
});

Route::group('pc', function () {
    // 顶部通用导航
    Route::any('navbar', 'admin/Pc/navbar');
    // 底部快捷导航
    Route::any('footer', 'admin/Pc/footer');
    // 用户导航
    Route::any('user', 'admin/Pc/user');
});

// 消息队列管理
Route::group('queue', function () {
    // 消息队列状态
    Route::get('status', 'admin/Queue/status');
    // 消息队列列表
    Route::get('list', 'admin/Queue/list');
    // 消息队列详情
    Route::get('detail', 'admin/Queue/detail');
});

Route::group('role', function () {
    // 角色管理
    Route::get('list', 'admin/Role/list');
    Route::post('edit', 'admin/Role/edit');
    Route::post('status', 'admin/Role/status');
    Route::post('del', 'admin/Role/del');
    Route::post('check/bind', 'admin/Role/checkBind');
    Route::post('verify', 'admin/Role/verify');

    // 角色用户列表
    Route::get('user', 'admin/Role/user');

    // 角色分组管理
    Route::get('group', 'admin/Role/group');
    Route::post('group/edit', 'admin/Role/groupEdit');
    Route::post('group/status', 'admin/Role/groupStatus');
    Route::post('group/delete', 'admin/Role/groupDel');
});

Route::group('score', function () {
    // 积分日志
    Route::get('log', 'admin/Score/log');
    Route::post('clear', 'admin/Score/clear');
    
    // 积分类型管理
    Route::get('type', 'admin/Score/type');
    Route::post('type/edit', 'admin/Score/typeEdit');
    Route::post('type/status', 'admin/Score/typeStatus');
    Route::post('type/delete', 'admin/Score/typeDel');
});

Route::group('seo', function () {
    // SEO规则列表
    Route::get('list', 'admin/Seo/list');
    // 编辑SEO规则
    Route::any('edit', 'admin/Seo/edit');
    // SEO规则状态
    Route::post('status', 'admin/Seo/status');
});

Route::group('tominiprogram', function () {
    // 跳转小程序列表
    Route::get('list', 'admin/Tominiprogram/list');
    
    // 添加、编辑跳转小程序
    Route::any('edit', 'admin/Tominiprogram/edit');
    
    // 更新跳转小程序状态
    Route::post('status', 'admin/Tominiprogram/status');
    
    // 删除跳转小程序
    Route::post('delete', 'admin/Tominiprogram/del');
});

Route::group('wechat_mp', function () {
    // 微信小程序配置
    Route::any('config', 'admin/WechatMiniProgram/config');
    
    // 模板消息通知
    Route::any('message', 'admin/WechatMiniProgram/templateMessage');
});

Route::group('wechat', function () {
    // 公众号配置
    Route::any('config', 'admin/WechatOfficial/config');
    
    // 菜单管理
    Route::any('menu', 'admin/WechatOfficial/menu');
    
    // 自动回复列表
    Route::get('autoreply', 'admin/WechatOfficial/autoReply');
    
    // 添加、更新自动回复
    Route::any('autoreply/edit', 'admin/WechatOfficial/editAutoReply');
    
    // 修改自动回复状态
    Route::post('autoreply/status', 'admin/WechatOfficial/autoReplyStatus');
    
    // 素材列表
    Route::any('material/list', 'admin/WechatOfficial/material');
    
    // 获取单个素材详情
    Route::any('material/detail', 'admin/WechatOfficial/materialDetail');
    
    // 模板消息通知
    Route::any('message', 'admin/WechatOfficial/templateMessage');
});

Route::group('wechat_work', function () {
    // 企业微信配置
    Route::any('config', 'admin/WechatWork/config');

    // 企业微信回调
    Route::any('callback', 'admin/WechatWork/callback');

    // 网页授权
    Route::get('oauth', 'admin/WechatWork/oauth');

    // 网页授权回调
    Route::get('oauth_callback', 'admin/WechatWork/oauthCallback');

    // 生成微信SDK
    Route::post('jssdk', 'admin/WechatWork/jssdk');
});

Route::group('withdraw', function () {
    // 提现列表
    Route::get('list', 'admin/Withdraw/list');
    
    // 提现详情
    Route::get('detail', 'admin/Withdraw/detail');
    
    // 手动处理提现
    Route::post('action', 'admin/Withdraw/action');
    
    // 取消提现申请
    Route::post('cancel', 'admin/Withdraw/cancel');
});

// 智能体管理路由
Route::group('muu_agent', function () {
    // 基础信息
    Route::get('index', 'admin/muuAgent.Index/index');

    // 智能体管理
    Route::get('agent/list', 'admin/muuAgent.Agent/lists');
    Route::get('agent/detail', 'admin/muuAgent.Agent/detail');
    Route::post('agent/create', 'admin/muuAgent.Agent/create');
    Route::post('agent/edit', 'admin/muuAgent.Agent/edit');
    Route::post('agent/del', 'admin/muuAgent.Agent/del');
    Route::post('agent/publish', 'admin/muuAgent.Agent/publish');

    // 对话管理
    Route::post('chat/send', 'admin/muuAgent.Chat/send');
    Route::get('chat/conversation', 'admin/muuAgent.Chat/conversation');
    Route::get('chat/history', 'admin/muuAgent.Chat/history');
    Route::post('chat/del', 'admin/muuAgent.Chat/del');

    // 知识库管理
    Route::get('kb/list', 'admin/muuAgent.Kb/lists');
    Route::get('kb/detail', 'admin/muuAgent.Kb/detail');
    Route::post('kb/create', 'admin/muuAgent.Kb/create');
    Route::post('kb/edit', 'admin/muuAgent.Kb/edit');
    Route::post('kb/del', 'admin/muuAgent.Kb/del');
    Route::post('kb/upload', 'admin/muuAgent.Kb/upload');
    Route::get('kb/documents', 'admin/muuAgent.Kb/documents');

    // 提示词模板管理
    Route::get('prompt/list', 'admin/muuAgent.Prompt/lists');
    Route::get('prompt/detail', 'admin/muuAgent.Prompt/detail');
    Route::post('prompt/create', 'admin/muuAgent.Prompt/create');
    Route::post('prompt/edit', 'admin/muuAgent.Prompt/edit');
    Route::post('prompt/del', 'admin/muuAgent.Prompt/del');
    Route::get('prompt/categories', 'admin/muuAgent.Prompt/categories');

    // 会话管理
    Route::get('conversation/list', 'admin/muuAgent.Conversation/lists');
    Route::get('conversation/detail', 'admin/muuAgent.Conversation/detail');
    Route::post('conversation/create', 'admin/muuAgent.Conversation/create');
    Route::post('conversation/edit', 'admin/muuAgent.Conversation/edit');
    Route::post('conversation/del', 'admin/muuAgent.Conversation/del');
    Route::get('conversation/messages', 'admin/muuAgent.Conversation/messages');
    Route::post('conversation/generate_title', 'admin/muuAgent.Conversation/generateTitle');

    // 日志管理
    Route::get('log/ai', 'admin/muuAgent.Log/ai');
    Route::get('log/ai_detail', 'admin/muuAgent.Log/aiDetail');
    Route::get('log/skill', 'admin/muuAgent.Log/skill');
    Route::get('log/skill_detail', 'admin/muuAgent.Log/skillDetail');
    Route::get('log/agent', 'admin/muuAgent.Log/agent');
    Route::get('log/agent_detail', 'admin/muuAgent.Log/agentDetail');
    Route::get('log/agent_reasoning', 'admin/muuAgent.Log/agentReasoning');
    Route::get('log/retrieval', 'admin/muuAgent.Log/retrieval');
    Route::get('log/retrieval_detail', 'admin/muuAgent.Log/retrievalDetail');
    Route::get('log/retrieval_statistics', 'admin/muuAgent.Log/retrievalStatistics');
    Route::get('log/statistics', 'admin/muuAgent.Log/statistics');

    // MCP Server管理
    Route::get('mcp_server/list', 'admin/muuAgent.McpServer/lists');
    Route::get('mcp_server/detail', 'admin/muuAgent.McpServer/detail');
    Route::post('mcp_server/create', 'admin/muuAgent.McpServer/create');
    Route::post('mcp_server/edit', 'admin/muuAgent.McpServer/edit');
    Route::post('mcp_server/del', 'admin/muuAgent.McpServer/del');
    Route::post('mcp_server/import', 'admin/muuAgent.McpServer/import');
    Route::post('mcp_server/discover_tools', 'admin/muuAgent.McpServer/discoverTools');
    Route::post('mcp_server/sync_tools', 'admin/muuAgent.McpServer/syncTools');
    Route::post('mcp_server/test_connection', 'admin/muuAgent.McpServer/testConnection');
    Route::post('mcp_server/test_connection_by_id', 'admin/muuAgent.McpServer/testConnectionById');
    Route::post('mcp_server/health_check', 'admin/muuAgent.McpServer/healthCheck');
    Route::post('mcp_server/refresh_cache', 'admin/muuAgent.McpServer/refreshCache');

    // 技能管理
    Route::get('skill/list', 'admin/muuAgent.Skill/lists');
    Route::post('skill/scan', 'admin/muuAgent.Skill/scan');
    Route::get('skill/detail', 'admin/muuAgent.Skill/detail');
    Route::post('skill/import', 'admin/muuAgent.Skill/import');
    Route::post('skill/validate', 'admin/muuAgent.Skill/validate');
    Route::post('skill/refresh', 'admin/muuAgent.Skill/refresh');
    Route::post('skill/clear_cache', 'admin/muuAgent.Skill/clearCache');
    Route::post('skill/clear_all_cache', 'admin/muuAgent.Skill/clearAllCache');
    Route::post('skill/sync', 'admin/muuAgent.Skill/sync');
    Route::get('skill/stats', 'admin/muuAgent.Skill/stats');
});
