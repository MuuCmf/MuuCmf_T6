<?php

namespace app\common\model;

use app\common\service\message\ShopContext;

/**
 * 消息中心模型基类
 *
 * 统一约定：
 * 1. 所有消息中心业务表都带 shopid，查询必须显式带上店铺条件，禁止全表跨店查询；
 * 2. 写入时若未显式传 shopid，则从当前请求上下文补全（后台/接口）；
 * 3. 时间字段统一秒级 int，由 autoWriteTimestamp 维护。
 */
abstract class MessageBaseModel extends Base
{
    //自动写入创建和更新的时间戳字段
    protected $autoWriteTimestamp = true;

    /**
     * 追加店铺条件（模型查询入口统一走这里，避免散落的 where('shopid', ...)）
     *
     * @param int $shopid
     * @return static
     */
    public function forShop(int $shopid)
    {
        return $this->where($this->getTable() . '.shopid', $shopid);
    }

    /**
     * 校验并返回可操作的店铺 ID
     *
     * @param int $shopid
     * @return int
     */
    public static function assertShop(int $shopid): int
    {
        return ShopContext::assert($shopid);
    }

    /**
     * 写入前补全 shopid（仅当当前请求有明确店铺上下文时）
     *
     * @param MessageBaseModel $model
     * @return void
     */
    protected static function onBeforeInsert($model)
    {
        $shopid = (int)$model->getData('shopid');
        if ($shopid <= 0) {
            $current = ShopContext::currentShopid();
            if ($current > 0) {
                $model->shopid = $current;
            }
        }
    }

    /**
     * 解析 JSON 字段，异常时返回默认值
     *
     * @param mixed $value
     * @param mixed $default
     * @return mixed
     */
    protected static function decodeJson($value, $default = [])
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
