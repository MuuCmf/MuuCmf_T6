<?php

namespace app\common\service\trade;

use think\facade\Db;
use think\facade\Log;

/**
 * 交易领域事件分发
 *
 * 框架只广播事实（订单支付成功 / 退款完成），不感知任何具体业务应用。
 * 按约定扫描已安装应用下的 app\{app}\service\trade\TradeEventSubscriber，
 * 实现 TradeEventSubscriberInterface 的实例即为订阅者；与 CancelService::stockRollback、
 * PolicyRegistry、Sitemap::installedApps 的软依赖惯例一致，卸载应用后只是扫描不到订阅者。
 *
 * 触发点（均在事务提交之后、异常不影响主流程）：
 * - api/controller/Pay.php            支付回调
 * - api/controller/Orders.php         免费 / 0 元订单（不走支付回调）
 * - common/service/aftersale/executor/RefundExecutor.php  退款完成与已完成流水收尾
 */
class TradeEventDispatcher
{
    public const ORDER_PAID = 'order_paid';
    public const ORDER_REFUNDED = 'order_refunded';

    /** 框架核心应用不参与订阅扫描 */
    private const CORE_MODULES = ['admin', 'api', 'common', 'index', 'ucenter', 'channel'];

    /** @var array<string,TradeEventSubscriberInterface>|null 请求级缓存 */
    private static $subscribers = null;

    /**
     * 订单支付成功
     *
     * 注意：调用方必须传数据库原始订单（paid_fee 单位为分），
     * 不能传 OrdersLogic::formatData() 处理后的元字符串。
     *
     * @param array $order 订单原始数据
     */
    public static function paid(array $order): void
    {
        $orderNo = trim((string)($order['order_no'] ?? ''));
        if ($orderNo === '') {
            return;
        }

        self::dispatch(self::ORDER_PAID, [
            'shopid'    => (int)($order['shopid'] ?? 0),
            'order_no'  => $orderNo,
            'uid'       => (int)($order['uid'] ?? 0),
            'app'       => (string)($order['app'] ?? ''),
            'paid_fee'  => (int)($order['paid_fee'] ?? 0),
            'unionid'   => (string)($order['unionid'] ?? ''),
            'paid_time' => (int)($order['paid_time'] ?? 0),
        ]);
    }

    /**
     * 订单退款完成
     *
     * @param array $order  订单原始数据（提供 shopid / uid / app / unionid）
     * @param array $refund 退款数据（refund_no / refund_fee 分 / order_no / refund_time）
     */
    public static function refunded(array $order, array $refund): void
    {
        $orderNo = trim((string)($order['order_no'] ?? $refund['order_no'] ?? ''));
        if ($orderNo === '') {
            return;
        }

        self::dispatch(self::ORDER_REFUNDED, [
            'shopid'      => (int)($order['shopid'] ?? $refund['shopid'] ?? 0),
            'order_no'    => $orderNo,
            'uid'         => (int)($order['uid'] ?? $refund['uid'] ?? 0),
            'app'         => (string)($order['app'] ?? $refund['app'] ?? ''),
            'unionid'     => (string)($order['unionid'] ?? ''),
            'refund_no'   => (string)($refund['refund_no'] ?? ''),
            'refund_fee'  => (int)($refund['refund_fee'] ?? 0),
            'refund_time' => (int)($refund['refund_time'] ?? 0),
        ]);
    }

    /**
     * 广播事件；单个订阅者异常不影响其它订阅者与交易主流程
     */
    private static function dispatch(string $type, array $payload): void
    {
        foreach (self::resolveSubscribers() as $app => $subscriber) {
            try {
                if ($type === self::ORDER_PAID) {
                    $subscriber->onOrderPaid($payload);
                } else {
                    $subscriber->onOrderRefunded($payload);
                }
            } catch (\Throwable $e) {
                Log::warning('[trade] ' . $app . ' 处理 ' . $type . ' 失败，order_no=' . $payload['order_no'] . '：' . $e->getMessage());
            }
        }
    }

    /**
     * 已安装应用中的订阅者列表
     *
     * @return array<string,TradeEventSubscriberInterface>
     */
    private static function resolveSubscribers(): array
    {
        static $resolvedAt = 0;
        // 常驻进程（queue:work --daemon）需定期刷新已安装模块列表，
        // 否则运行期新装/启用模块后订阅者恒空且不会有任何告警
        if (self::$subscribers !== null && time() - $resolvedAt < 30) {
            return self::$subscribers;
        }
        $resolvedAt = time();
        self::$subscribers = [];

        try {
            $rows = Db::name('module')->where('is_setup', 1)->field('name')->select()->toArray();
        } catch (\Throwable $e) {
            // 模块表不可用（安装中 / 数据库异常）时静默降级，绝不阻断交易
            Log::warning('[trade] 读取已安装应用失败：' . $e->getMessage());
            return self::$subscribers;
        }

        foreach ($rows as $row) {
            $app = strtolower(trim((string)($row['name'] ?? '')));
            if ($app === '' || in_array($app, self::CORE_MODULES, true)) {
                continue;
            }
            // 应用名会拼进类名，仅接受合法标识符，避免路径穿越与任意类加载
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $app)) {
                continue;
            }
            $class = 'app\\' . $app . '\\service\\trade\\TradeEventSubscriber';
            if (!class_exists($class)) {
                continue;
            }
            try {
                $instance = new $class();
                if ($instance instanceof TradeEventSubscriberInterface) {
                    self::$subscribers[$app] = $instance;
                }
            } catch (\Throwable $e) {
                Log::warning('[trade] 实例化 ' . $app . ' 交易事件订阅者失败：' . $e->getMessage());
            }
        }

        return self::$subscribers;
    }

    /** 供安装/卸载应用或测试时清除请求级缓存 */
    public static function reset(): void
    {
        self::$subscribers = null;
    }
}
