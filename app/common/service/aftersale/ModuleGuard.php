<?php

namespace app\common\service\aftersale;

use app\common\model\Module as ModuleModel;

/**
 * 模块能力守卫
 * 统一外部模块安装状态判断，避免各控制器/逻辑层重复实例化 Module 模型
 */
class ModuleGuard
{
    /**
     * 判断指定模块是否已安装
     * @param string $module 模块标识，如 distribution、creator、micro、convert、ask
     * @return bool
     */
    public static function installed(string $module): bool
    {
        if ($module === '') {
            return false;
        }
        try {
            return (bool)(new ModuleModel())->checkInstalled($module);
        } catch (\Throwable $e) {
            // 模块状态读取异常时按未安装处理，避免阻断主流程
            return false;
        }
    }
}
