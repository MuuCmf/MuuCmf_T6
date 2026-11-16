<?php
// +----------------------------------------------------------------------
// | muucmf cloud配置
// +----------------------------------------------------------------------
return [
    //云端api
    'api' => "https://www.muucmf.cc/cloud/",//云端配置
    // 升级下载兜底采集：开启后授权应用成功下载升级包时写入升级记录（source=2）
    'record_download' => false,
    // 域名授权观测层：仅记录通道分布，不改变任何请求的判定结果
    'auth_observe' => env('cloud.auth_observe', true),
    // 断网宽限小时数；0 为严格模式（断网即拒），仅对要求强合规的私有化客户按需开启
    'grace_hours' => env('cloud.grace_hours', 72),
    // 签名时间戳允许偏差（秒），NTP 覆盖不足时可放宽到 600
    'sig_window' => env('cloud.sig_window', 600),
    // 密钥轮换时旧密钥并存天数
    'secret_rotate_days' => env('cloud.secret_rotate_days', 7),
];
