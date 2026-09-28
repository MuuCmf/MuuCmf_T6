<?php

namespace app\common\model;

/**
 * 计划任务日志
 */
class CrontabLog extends Base
{
    protected $autoWriteTimestamp = true;

    public static function addLog($params)
    {
        $data = [
            'shopid'    =>  isset($params['shopid']) ? intval($params['shopid']) : 0,
            'cid'       =>  isset($params['cid']) ? intval($params['cid']) : 0,
            'description'   =>  mb_substr((string)($params['description'] ?? ''), 0, 500),
            'status'    =>  isset($params['status']) ? intval($params['status']) : 1,
        ];
        return (new self())->edit($data);
    }

    /**
     * 日志轮转：清理指定天数前的历史日志
     * 由独立的清理入口（URL 接口/CLI 命令）显式调用，避免写日志时产生删除副作用
     *
     * @param int $days 保留天数（默认 180）
     * @return int 删除条数
     */
    public static function rotateLogs($days = 180)
    {
        $days = max(1, intval($days));
        return (new self())->where('create_time', '<', time() - ($days * 24 * 60 * 60))->delete();
    }
}