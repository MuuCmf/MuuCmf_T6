<?php

namespace app\common\service\message;

use think\facade\Db;

/**
 * 目标解析：指定用户 / 用户组
 *
 * 首版只支持 uids / groups，条件人群由业务方先解析成 uid 再调用。
 * 服务层内部按批滚动推进，调用方不需要自己分片。
 */
class AudienceResolver
{
    /**
     * 目标用户总数（去重）
     *
     * @param array $targets ['type'=>'uids|groups','value'=>mixed]
     * @param int   $shopid
     * @return int
     * @throws MessageException
     */
    public function count(array $targets, int $shopid): int
    {
        return count($this->all($targets, $shopid));
    }

    /**
     * 解析全部目标 uid（内部有上限保护，避免超大群一次性载入内存）
     *
     * @param array $targets
     * @param int   $shopid
     * @param int   $max
     * @return array
     * @throws MessageException
     */
    public function all(array $targets, int $shopid, int $max = 200000): array
    {
        $type = (string)($targets['type'] ?? 'uids');
        $value = $targets['value'] ?? [];

        if ($type === 'uids') {
            $uids = is_array($value) ? $value : explode(',', (string)$value);
            $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($uid) {
                return $uid > 0;
            })));
            return array_slice($uids, 0, $max);
        }

        if ($type === 'groups') {
            $groupIds = is_array($value) ? $value : explode(',', (string)$value);
            $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
            if (empty($groupIds)) {
                return [];
            }
            // 必须显式排序：分批是「整表重查 + slice」，无序结果在两次查询间可能变化，
            // 会导致同一用户被重复发送或漏发
            $rows = Db::name('auth_group_access')->alias('a')
                ->join('member m', 'm.uid=a.uid')
                ->where('a.group_id', 'in', $groupIds)
                ->where('m.status', '>=', 0)
                ->distinct(true)
                ->order('a.uid asc')
                ->limit($max)
                ->column('a.uid');

            return array_values(array_unique(array_map('intval', $rows)));
        }

        throw new MessageException('target_invalid', '不支持的发送目标类型：' . $type);
    }

    /**
     * 取一批目标 uid
     *
     * @param array $targets
     * @param int   $shopid
     * @param int   $offset
     * @param int   $limit
     * @return array
     * @throws MessageException
     */
    public function fetch(array $targets, int $shopid, int $offset, int $limit): array
    {
        $all = $this->all($targets, $shopid);
        return array_slice($all, max(0, $offset), max(1, $limit));
    }

    /**
     * 目标摘要（审计与去重）
     *
     * @param array $targets
     * @return string
     */
    public function hash(array $targets): string
    {
        return md5(json_encode([
            'type'  => $targets['type'] ?? '',
            'value' => $targets['value'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }
}
