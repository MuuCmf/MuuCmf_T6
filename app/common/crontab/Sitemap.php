<?php

declare(strict_types=1);

namespace app\common\crontab;

use app\common\model\CrontabLog;
use app\common\service\Sitemap as SitemapService;

/**
 * @title Sitemap 自动生成 计划任务
 * @description 汇总框架核心页面与已安装应用页面，生成 public/sitemap.xml，
 *              并同步更新 robots.txt 的 Sitemap 声明；执行摘要写入
 *              muucmf_crontab_log 便于后台查看。
 *              站点域名取系统配置 WEB_SITE_URL（CLI 下无请求域名，必须配置该项）。
 * @package app\common\crontab
 */
class Sitemap
{
    /**
     * 业务处理
     * @param int $shopid 店铺ID（平台级任务恒为 0）
     * @param int $task_id 计划任务ID（muucmf_crontab.id）
     * @return bool
     */
    public function handle(int $shopid, int $task_id)
    {
        try {
            $result = (new SitemapService())->publish();

            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => $result['msg'],
                'status'      => $result['ok'] ? 1 : 0,
            ]);

            return (bool)$result['ok'];
        } catch (\Throwable $e) {
            CrontabLog::addLog([
                'shopid'      => $shopid,
                'cid'         => $task_id,
                'description' => 'Sitemap 生成异常：' . $e->getMessage(),
                'status'      => 0,
            ]);
            return false;
        }
    }
}