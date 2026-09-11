<?php

namespace app\admin\controller;

/**
 * 消息队列控制器
 */
class Queue extends Admin
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 获取队列列表
     * 
     * 从Redis队列中获取分页数据,包含以下功能:
     * - 连接Redis服务器
     * - 根据页码获取指定范围的队列数据
     * - 对队列数据进行JSON解码
     * - 组装分页数据结构
     * - 生成分页导航
     * 
     * @return mixed 返回渲染后的视图
     */
    public function list()
    {
        // 连接redis
        $redis = $this->connRedis();

        $key = '{queues:' . config('queue.connections.redis.queue') . '}';
        $page = input('page', 1, 'intval');
        $rows = input('rows', 20, 'intval');
        $page_size = $rows;

        // 起始索引
        $limit_s = ($page - 1) * $page_size;
        // 结束索引
        $limit_e = ($limit_s + $page_size) - 1;
        $page_list = $redis->lrange($key, $limit_s, $limit_e); //指定区间内列表。
        $count = $redis->llen($key);
        $page_count = intval(ceil($count / $page_size)); //总共多少页
        foreach ($page_list as &$v) {
            $v = json_decode($v, true);
            $v['json'] = json_encode($v);
        }
        unset($v);

        // 组装数据
        $lists = [
            'current_page' => $page,
            'last_page' => $page_count,
            'per_page' => $page_size,
            'total' => $count,
            'data' => $page_list
        ];

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 连接redis
     */
    private function connRedis()
    {
        // 连接redis
        $config = config('queue.connections.redis');
        $func   = $config['persistent'] ? 'pconnect' : 'connect';

        // 判断redis是否支持连接池
        if (!class_exists('Redis')) {
            throw new \Exception('Redis扩展未加载');
        }

        $client = new \Redis;
        $client->$func($config['host'], $config['port'], $config['timeout']);

        if ('' != $config['password']) {
            $client->auth($config['password']);
        }

        if (0 != $config['select']) {
            $client->select($config['select']);
        }

        return $client;
    }

    public function status()
    {
        try {
            $redis = $this->connRedis();
            $queueName = config('queue.connections.redis.queue');
            $key = '{queues:' . $queueName . '}';
            $queueLength = $redis->llen($key);

            // 使用心跳机制检查队列是否在运行
            $heartbeatKey = 'queue:heartbeat:' . $queueName;
            $lastHeartbeat = $redis->get($heartbeatKey);

            // 如果5分钟内有心跳，说明队列在运行
            $isRunning = $lastHeartbeat && (time() - $lastHeartbeat) < 300;

            return $this->success('success', [
                'queue_length' => $queueLength,
                'is_running' => $isRunning,
                'last_heartbeat' => $lastHeartbeat ? date('Y-m-d H:i:s', $lastHeartbeat) : null,
                'heartbeat_ago' => $lastHeartbeat ? time() - $lastHeartbeat : null,
                'time' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            return $this->error('检查失败：' . $e->getMessage());
        }
    }

    /**
     * 手动更新心跳（用于测试）
     */
    public function heartbeat()
    {
        try {
            $redis = $this->connRedis();
            $queueName = config('queue.connections.redis.queue');
            $heartbeatKey = 'queue:heartbeat:' . $queueName;

            $redis->set($heartbeatKey, time());
            $redis->expire($heartbeatKey, 600);

            return $this->success('心跳已更新', [
                'time' => date('Y-m-d H:i:s'),
                'heartbeat_key' => $heartbeatKey
            ]);
        } catch (\Exception $e) {
            return $this->error('更新失败：' . $e->getMessage());
        }
    }
}
