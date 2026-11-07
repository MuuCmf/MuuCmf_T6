<?php

namespace app\common\model;

/**
 * 消息黑名单（解黑置 status=0，不物理删除，保留审计痕迹）
 */
class MessageSuppression extends MessageBaseModel
{
    protected $name = 'message_suppression';

    public const STATUS_ACTIVE  = 1;
    public const STATUS_RELEASED = 0;

    /**
     * 批量查询生效中的黑名单
     *
     * @param int   $shopid
     * @param array $uids
     * @return array [uid => true]
     */
    public static function activeMap(int $shopid, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        // shopid=0 是默认店铺的合法取值，不能短路，否则默认店铺的黑名单完全不生效
        if ($shopid < 0 || empty($uids)) {
            return [];
        }
        $now = time();
        $rows = self::where('shopid', $shopid)
            ->where('uid', 'in', $uids)
            ->where('status', self::STATUS_ACTIVE)
            ->where(function ($query) use ($now) {
                $query->where('expire_time', 0)->whereOr('expire_time', '>', $now);
            })
            ->column('uid');

        $map = [];
        foreach ($rows as $uid) {
            $map[(int)$uid] = true;
        }
        return $map;
    }

    /**
     * 单个用户是否在黑名单中
     *
     * @param int $shopid
     * @param int $uid
     * @return bool
     */
    public static function isActive(int $shopid, int $uid): bool
    {
        return isset(self::activeMap($shopid, [$uid])[$uid]);
    }
}
