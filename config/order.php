<?php

/**
 * 订单相关静态配置
 *
 * 仅存放不随店铺变化的默认值；后台可运营的动态配置请走 muucmf_extend_config。
 */
return [
    // 未支付订单自动取消超时（秒），默认 24 小时
    'unpaid_cancel_timeout' => 86400,

    // 定时任务单轮处理上限（有效范围 1~500）
    'unpaid_cancel_limit' => 100,
];
