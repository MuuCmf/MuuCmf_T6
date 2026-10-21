<?php
namespace app\common\service;

use think\facade\Log;
use app\common\service\Concerns\BusinessEngineProxy;

/**
 * 拼团门面（common 侧）
 *
 * 结算与价格展示代码统一通过本门面调用拼团引擎，与 business 模块解耦：
 * business 未启用或引擎缺失时返回 null，全链路安全降级为原价。
 *
 * 读路径（resolve）要求 business 模块已安装，未就绪即降级为原价；
 * 写路径（createGroup/joinGroup/settle/release/refundRollback/cancelGroup/expireGroups）
 * 为订单生命周期内的数据补偿操作，仅要求引擎类存在即可执行。
 */
class Groupbuy
{
    use BusinessEngineProxy;

    /** 引擎类全限定名 */
    private const ENGINECLASS = 'app\\business\\service\\Groupbuy';

    /**
     * 解析某内容命中的最优拼团活动
     *
     * @param int    $shopid   平台/店铺 ID
     * @param string $app      所属模块
     * @param int    $uid      用户 ID
     * @param int    $infoId   内容 ID
     * @param string $infoType 内容类型
     * @param int    $price    内容原价（分）
     * @param string $sku      SKU 标识（预留）
     * @return array|null 命中返回拼团信息数组，未命中或 business 缺失返回 null
     */
    public static function resolve(int $shopid, string $app, int $uid, int $infoId, string $infoType, int $price, string $sku = ''): ?array
    {
        $engine = self::engineForRead(self::ENGINECLASS);
        if ($engine === null) {
            return null;
        }

        try {
            return (new $engine())->resolve($shopid, $app, $uid, $infoId, $infoType, $price, $sku);
        } catch (\Throwable $e) {
            Log::warning('[groupbuy] resolve 降级为原价：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 按指定的参与内容精确解析拼团信息（开团算价/展示专用）
     *
     * 与 resolve 的区别：严格以传入的参与内容计算价格，避免同商品多活动时
     * 「页面展示 / 下单」被最优活动覆盖。business 未启用时返回 null。
     *
     * @param int    $shopid   平台/店铺 ID
     * @param int    $itemId   参与内容 ID
     * @param int    $uid      用户 ID
     * @param int    $price    内容原价（分）
     * @param string $app      所属模块（传值时校验内容归属）
     * @param string $infoType 内容类型（传值时校验内容归属）
     * @param int    $infoId   内容 ID（传值时校验内容归属）
     * @param int    $quantity 本次购买件数（默认 1，用于限购展示口径）
     * @return array|null 命中返回拼团信息数组，未命中或 business 缺失返回 null
     */
    public static function resolveByItem(int $shopid, int $itemId, int $uid, int $price, string $app = '', string $infoType = '', int $infoId = 0, int $quantity = 1): ?array
    {
        $engine = self::engineForRead(self::ENGINECLASS);
        if ($engine === null) {
            return null;
        }

        try {
            return (new $engine())->resolveByItem($shopid, $itemId, $uid, $price, $app, $infoType, $infoId, $quantity);
        } catch (\Throwable $e) {
            Log::warning('[groupbuy] resolveByItem 降级为原价：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 按参团 token 精确解析团的拼团信息（参团算价/展示专用）
     *
     * 与 resolve 的区别：严格以团绑定的参与内容计算价格，避免同商品多活动时
     * 按「最优活动」算价却加入价格不同的团。business 未启用时返回 null。
     *
     * @param int    $shopid   平台/店铺 ID
     * @param string $token    参团 token
     * @param int    $uid      用户 ID
     * @param int    $price    商品原价（分）
     * @param int    $quantity 购买数量
     * @param string $app      所属模块（传值时校验团内容归属）
     * @param string $infoType 内容类型（传值时校验团内容归属）
     * @param int    $infoId   内容 ID（传值时校验团内容归属）
     * @return array|null 命中返回拼团信息数组，未命中返回 null
     */
    public static function resolveByToken(int $shopid, string $token, int $uid, int $price, int $quantity, string $app = '', string $infoType = '', int $infoId = 0): ?array
    {
        $engine = self::engineForRead(self::ENGINECLASS);
        if ($engine === null) {
            return null;
        }

        try {
            return (new $engine())->resolveByToken($shopid, $token, $uid, $price, $quantity, $app, $infoType, $infoId);
        } catch (\Throwable $e) {
            Log::warning('[groupbuy] resolveByToken 降级为原价：' . $e->getMessage());
            return null;
        }
    }

    /**
     * 获取某参与内容进行中的团（详情页/移动端选团参团共用）
     *
     * business 未启用或引擎缺失时返回空数组，调用方按「暂无进行中的团」降级展示。
     *
     * @param int $shopid 平台/店铺 ID
     * @param int $itemId 参与内容 ID
     * @param int $limit  返回数量（默认 5，最大 20）
     * @return array 已格式化的团列表
     */
    public static function groups(int $shopid, int $itemId, int $limit = 5): array
    {
        $engine = self::engineForRead(self::ENGINECLASS);
        if ($engine === null) {
            return [];
        }

        try {
            return (new $engine())->groups($shopid, $itemId, $limit);
        } catch (\Throwable $e) {
            Log::warning('[groupbuy] groups 降级为空列表：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 参团资格状态（库存/限购）
     *
     * business 未启用或引擎缺失时返回不可参团，调用方按「入口不可用」降级。
     *
     * @param int $shopid 平台/店铺 ID
     * @param int $itemId 参与内容 ID
     * @param int $uid    用户 ID（0 表示游客）
     * @return array ['can_join'=>bool,'stock_full'=>bool,'limit_buy'=>int,'bought'=>int,'need_login'=>bool]
     */
    public static function joinStatus(int $shopid, int $itemId, int $uid): array
    {
        $default = [
            'can_join'   => false,
            'stock_full' => false,
            'limit_buy'  => 0,
            'bought'     => 0,
            'need_login' => $uid <= 0,
        ];
        $engine = self::engineForRead(self::ENGINECLASS);
        if ($engine === null) {
            return $default;
        }

        try {
            return (new $engine())->joinStatus($shopid, $itemId, $uid);
        } catch (\Throwable $e) {
            Log::warning('[groupbuy] joinStatus 降级为不可参团：' . $e->getMessage());
            return $default;
        }
    }

    /**
     * 开团（统一下单事务内调用）
     *
     * @param int    $shopid   平台/店铺 ID
     * @param int    $uid      团长 UID
     * @param int    $itemId   参与内容 ID
     * @param string $orderNo  团长订单号
     * @param int    $payFee   拼团金额（分）
     * @param string $app      所属模块
     * @param string $infoType 内容类型
     * @param int    $infoId   内容 ID
     * @param int    $quantity 购买件数（默认 1）
     * @return array ['group_id' => int, 'token' => string, 'group_no' => string]
     */
    public static function createGroup(int $shopid, int $uid, int $itemId, string $orderNo, int $payFee, string $app, string $infoType, int $infoId, int $quantity = 1): array
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            throw new \Exception('拼团功能未启用');
        }

        try {
            return (new $engine())->createGroup($shopid, $uid, $itemId, $orderNo, $payFee, $app, $infoType, $infoId, $quantity);
        } catch (\Throwable $e) {
            if ($e instanceof \Exception) {
                throw $e;
            }
            Log::error('[groupbuy] createGroup 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            throw new \Exception('开团失败，请重试');
        }
    }

    /**
     * 参团（统一下单事务内调用）
     *
     * @param int    $shopid   平台/店铺 ID
     * @param int    $uid      团员 UID
     * @param string $token    参团 token
     * @param string $orderNo  团员订单号
     * @param int    $payFee   拼团金额（分）
     * @param string $app      所属模块
     * @param string $infoType 内容类型
     * @param int    $infoId   内容 ID
     * @param int    $quantity 购买件数（默认 1）
     * @return array ['group_id' => int, 'status' => int]
     */
    public static function joinGroup(int $shopid, int $uid, string $token, string $orderNo, int $payFee, string $app, string $infoType, int $infoId, int $quantity = 1): array
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            throw new \Exception('拼团功能未启用');
        }

        try {
            return (new $engine())->joinGroup($shopid, $uid, $token, $orderNo, $payFee, $app, $infoType, $infoId, $quantity);
        } catch (\Throwable $e) {
            if ($e instanceof \Exception) {
                throw $e;
            }
            Log::error('[groupbuy] joinGroup 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            throw new \Exception('参团失败，请重试');
        }
    }

    /**
     * 支付成功：成员记录置为已支付
     *
     * @param int    $shopid  平台/店铺 ID
     * @param string $orderNo 订单号
     * @return bool
     */
    public static function settle(int $shopid, string $orderNo): bool
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->settle($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[groupbuy] settle 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 订单取消/删除：成员置为已取消，未成团回退人数
     *
     * @param int    $shopid  平台/店铺 ID
     * @param string $orderNo 订单号
     * @return bool
     */
    public static function release(int $shopid, string $orderNo): bool
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->release($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[groupbuy] release 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 退款：成员置为已退款，未成团回退人数
     *
     * @param int    $shopid  平台/店铺 ID
     * @param string $orderNo 订单号
     * @return bool
     */
    public static function refundRollback(int $shopid, string $orderNo): bool
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            return false;
        }

        try {
            return (new $engine())->refundRollback($shopid, $orderNo);
        } catch (\Throwable $e) {
            Log::error('[groupbuy] refundRollback 失败，order_no=' . $orderNo . '：' . $e->getMessage());
            return false;
        }
    }

    /**
     * 团长取消未成团的团（成员标记退款，实际退款由调用方处理）
     *
     * @param int $shopid  平台/店铺 ID
     * @param int $uid     团长 UID
     * @param int $groupId 团实例 ID
     * @return array ['group_id' => int, 'member_orders' => string[]]
     */
    public static function cancelGroup(int $shopid, int $uid, int $groupId): array
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            throw new \Exception('拼团功能未启用');
        }

        try {
            return (new $engine())->cancelGroup($shopid, $uid, $groupId);
        } catch (\Throwable $e) {
            if ($e instanceof \Exception) {
                throw $e;
            }
            Log::error('[groupbuy] cancelGroup 失败，group_id=' . $groupId . '：' . $e->getMessage());
            throw new \Exception('取消拼团失败，请重试');
        }
    }

    /**
     * 超时未成团的团自动失败（惰性扫描 + 定时任务共用）
     *
     * @param int $shopid 平台/店铺 ID，0 表示全部
     * @return string[] 受影响成员订单号列表
     */
    public static function expireGroups(int $shopid = 0): array
    {
        $engine = self::engineForWrite(self::ENGINECLASS);
        if ($engine === null) {
            return [];
        }

        try {
            return (new $engine())->expireGroups($shopid);
        } catch (\Throwable $e) {
            Log::error('[groupbuy] expireGroups 失败：' . $e->getMessage());
            return [];
        }
    }

    /**
     * 支付/超时竞态补偿：订单已支付但所属团已失败/已取消时立即自动退款
     *
     * 供订单支付回调在结算后调用：成员已支付却因超时扫描/团长取消而不属任何有效团时，
     * 立即退还该订单，避免「订单已收款却既不属有效团也无退款」的资金不一致。
     * business 模块缺失时返回 false（安全降级，不影响订单主流程）。
     *
     * @param array $order 订单数据（需含 group_id / order_no / paid / refund / app）
     * @return bool 是否已退款
     */
    public static function refundIfGroupFailed(array $order): bool
    {
        $service = 'app\\business\\service\\GroupbuyRefund';
        if (!class_exists($service) || !method_exists($service, 'refundIfGroupFailed')) {
            return false;
        }

        try {
            return (bool)$service::refundIfGroupFailed($order);
        } catch (\Throwable $e) {
            Log::error('[groupbuy] refundIfGroupFailed 失败，order_no=' . (string)($order['order_no'] ?? '') . '：' . $e->getMessage());
            return false;
        }
    }
}
