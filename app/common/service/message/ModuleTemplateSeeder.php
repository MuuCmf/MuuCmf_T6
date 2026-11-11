<?php

namespace app\common\service\message;

use think\facade\Db;

/**
 * 模块级消息模板种子装配器
 *
 * 模块专属模板的定义全部由各模块自行提供，统一放在：
 *   app/{app}/info/message_templates.sql
 * 文件内为幂等 INSERT（主表 + 渠道行），固定 shopid=0（当前系统只使用平台店铺）。
 *
 * MessageSeed 只负责平台通用模板（common.* / order.*）的逐店补齐；本类负责：
 *   1. 模块安装 / 升级时重放对应模块的模板 SQL；
 *   2. 发送侧 template_not_found 时按模板 code 的 app 前缀重放，补齐缺失模板；
 *   3. 卸载模块（删除数据）时按 app 清理模板与渠道行。
 *
 * 重放依赖 SQL 自身的 INSERT ... WHERE NOT EXISTS，不覆盖后台已编辑的模板内容。
 */
class ModuleTemplateSeeder
{
    /** 模块模板种子文件相对模块根目录的路径 */
    public const SEED_FILE = 'info/message_templates.sql';

    /** 当前系统只使用平台店铺 */
    public const SHOPID = 0;

    /**
     * 按模板 code 重放其所属模块的种子（供发送侧兜底）
     */
    public static function replay(string $code): bool
    {
        $app = self::appOf($code);
        if ($app === '') {
            return false;
        }
        return self::replayApp($app);
    }

    /**
     * 重放指定模块的模板种子
     */
    public static function replayApp(string $app): bool
    {
        if (!preg_match('/^[a-z0-9_]+$/', $app)) {
            return false;
        }
        $path = self::path($app);
        if (!is_file($path)) {
            return false;
        }
        return self::executeFile($path);
    }

    /**
     * 重放全部模块的模板种子（供系统升级与安装后兜底）
     */
    public static function replayAll(): void
    {
        foreach ((array)glob(self::basePath() . '/*/' . self::SEED_FILE) as $path) {
            self::executeFile($path);
        }
    }

    /**
     * 卸载清理：按 app 删除该模块的模板主表与渠道行
     *
     * message_template_channel 无 app 列，需先取主表 id 再按 template_id 关联删除。
     */
    public static function purge(string $app): void
    {
        if (!preg_match('/^[a-z0-9_]+$/', $app)) {
            return;
        }
        try {
            $ids = Db::name('message_template')
                ->where('shopid', self::SHOPID)
                ->where('app', $app)
                ->column('id');
            if (empty($ids)) {
                return;
            }
            Db::name('message_template_channel')
                ->where('shopid', self::SHOPID)
                ->whereIn('template_id', $ids)
                ->delete();
            Db::name('message_template')
                ->where('shopid', self::SHOPID)
                ->whereIn('id', $ids)
                ->delete();
        } catch (\Throwable $e) {
            // 表不存在（未执行升级脚本）或清理失败不影响卸载主流程
            trace('[message] module template purge failed: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * 模板 code 的 app 前缀，如 appstore.license_expire → appstore
     */
    public static function appOf(string $code): string
    {
        $pos = strpos($code, '.');
        if ($pos === false) {
            return '';
        }
        return substr($code, 0, $pos);
    }

    public static function path(string $app): string
    {
        return self::basePath() . '/' . $app . '/' . self::SEED_FILE;
    }

    /**
     * 应用根目录：Web 环境由框架定义 APP_PATH，CLI / 测试环境回退到容器 basePath
     */
    protected static function basePath(): string
    {
        if (defined('APP_PATH')) {
            return rtrim((string)constant('APP_PATH'), '/\\');
        }
        return rtrim((string)app()->getBasePath(), '/\\');
    }

    /**
     * 执行模块种子 SQL：按 ;\n 分割，逐条幂等执行；单条失败不影响后续
     *
     * 只执行 INSERT 语句，避免模块 SQL 里混入建表 / 删表等重复执行有副作用的语句。
     */
    protected static function executeFile(string $path): bool
    {
        $sql = (string)file_get_contents($path);
        $sql = str_replace("\r", "\n", $sql);
        $prefix = (string)config('database.connections.mysql.prefix');
        $executed = false;
        foreach (explode(";\n", $sql) as $statement) {
            $lines = [];
            foreach (explode("\n", $statement) as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '#') === 0) {
                    continue;
                }
                $lines[] = $line;
            }
            $statement = trim(implode("\n", $lines));
            if ($statement === '' || stripos($statement, 'INSERT') !== 0) {
                continue;
            }
            $statement = str_replace('`muucmf_', '`' . $prefix, $statement);
            try {
                Db::execute($statement);
                $executed = true;
            } catch (\Throwable $e) {
                trace('[message] module template seed failed: ' . $e->getMessage(), 'warning');
            }
        }
        return $executed;
    }
}
