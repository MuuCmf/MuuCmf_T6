<?php

namespace app\common\service\aftersale\policy;

/**
 * 退款策略注册表
 * 按订单所属应用解析策略实现，约定路径：app\{app}\service\aftersale\Policy
 */
class PolicyRegistry
{
    /**
     * 指定应用是否已接入售后策略
     * @param string $app 应用标识
     * @return bool
     */
    public static function supports(string $app): bool
    {
        $app = preg_replace('/[^a-z0-9_]/i', '', $app);
        if ($app === '') {
            return false;
        }
        return class_exists('app\\' . $app . '\\service\\aftersale\\Policy');
    }

    /**
     * 解析指定应用的退款策略
     * @param string $app 应用标识
     * @return RefundPolicyInterface
     * @throws \Exception
     */
    public static function resolve(string $app): RefundPolicyInterface
    {
        // 应用标识只允许字母、数字与下划线，避免拼接出非预期类名
        $app = preg_replace('/[^a-z0-9_]/i', '', $app);
        if ($app === '') {
            throw new \Exception('退款策略未指定应用');
        }
        $class = 'app\\' . $app . '\\service\\aftersale\\Policy';
        if (!class_exists($class)) {
            throw new \Exception('该应用未接入售后策略：' . $app);
        }
        $policy = new $class();
        if (!$policy instanceof RefundPolicyInterface) {
            throw new \Exception('售后策略实现不合法：' . $class);
        }
        return $policy;
    }
}
