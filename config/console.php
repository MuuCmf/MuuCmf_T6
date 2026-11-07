<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'crontab' => 'app\common\command\Crontab',
        'gateway:im' => 'app\im\command\GatewayWorker',
        'refund:reconcile' => 'app\common\command\RefundReconcile',
    ],
];
