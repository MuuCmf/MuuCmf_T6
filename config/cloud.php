<?php
// +----------------------------------------------------------------------
// | muucmf cloud配置
// +----------------------------------------------------------------------
return [
    //云端api
    'api' => "https://www.muucmf.cc/cloud/",//云端配置
    // 升级下载兜底采集：开启后授权应用成功下载升级包时写入升级记录（source=2）
    'record_download' => false,
    // 域名授权观测层（T-A）：仅记录通道分布，不改变任何请求的判定结果
    'auth_observe' => env('cloud.auth_observe', true),
    // 域名授权强制校验总开关（S0-B 起按域名启用，T-A 阶段必须保持 false）
    'auth_enforce' => env('cloud.auth_enforce', false),
    // 观测数据聚合周期预留（秒）
    'metric_flush_seconds' => 60,
];
