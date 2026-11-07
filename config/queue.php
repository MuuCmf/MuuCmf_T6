<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    'default'     => 'redis',
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => env('queue.name', 'default'),
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => env('queue.name', 'default'),
            // 服务器地址
            'host'       => env('redis.host', '127.0.0.1'),
            // 端口
            'port'       => env('redis.port', '6379'),
            // 密码
            'password'   => env('redis.password', ''),
            'select'     => env('redis.select', 0),
            'timeout'    => 0,
            'persistent' => false,
        ],
    ],
    'failed'      => [
        // 失败任务落库，避免失败即静默丢弃（需 failed_jobs 表，见 data/install.sql）
        'type'  => 'database',
        'table' => 'failed_jobs',
    ],
];
