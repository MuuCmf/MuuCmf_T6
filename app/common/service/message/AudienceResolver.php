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
        $type = (string)($targets['type'] ?? 'uids');
        $value = $targets['value'] ?? [];

        if ($type === 'uids') {
            $uids = is_array($value) ? $value : explode(',', (string)$value);
            $uids = array_values(array_unique(array_filter(array_map('intval', $uids), function ($uid) {
                return $uid > 0;
            })));
            return count(array_slice($uids, 0, 200000));
        }

        if ($type === 'groups') {
            $groupIds = is_array($value) ? $value : explode(',', (string)$value);
            $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
            if (empty($groupIds)) {
                return 0;
            }
            // 与 fetch() 用同一套条件，避免 user_total 与实际目标集合不一致
            $query = $this->groupsQuery($groupIds, $shopid);
            return min(200000, (int)$query->count('DISTINCT a.uid'));
        }

        throw new MessageException('target_invalid', '不支持的发送目标类型：' . $type);
    }

    /**
     * 用户组目标查询（带店铺隔离）
     *
     * @param array $groupIds
     * @param int   $shopid
     * @return \think\db\Query
     */
    protected function groupsQuery(array $groupIds, int $shopid)
    {
        $query = Db::name('auth_group_access')->alias('a')
            ->join('member m', 'm.uid=a.uid')
            ->where('a.group_id', 'in', $groupIds)
            ->where('m.status', '>=', 0);
        // shopid=0 是平台级视图，保持历史口径；非 0 店铺只能选本店会员
        if ($shopid > 0) {
            $query->where('m.shopid', $shopid);
        }
        return $query;
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
            // 显式排序并与 fetch / count 共用条件，避免跨店群发和口径不一致
            $rows = $this->groupsQuery($groupIds, $shopid)
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
        $type = (string)($targets['type'] ?? 'uids');
        $value = $targets['value'] ?? [];

        if ($type === 'uids') {
            $all = $this->all($targets, $shopid);
            return array_slice($all, max(0, $offset), max(1, $limit));
        }

        if ($type === 'groups') {
            $groupIds = is_array($value) ? $value : explode(',', (string)$value);
            $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
            if (empty($groupIds)) {
                return [];
            }
            // 直接用 SQL 分页，避免每批把全量目标重新载入内存（1 万用户时是 O(N²)）
            $rows = $this->groupsQuery($groupIds, $shopid)
                ->distinct(true)
                ->order('a.uid asc')
                ->limit(max(0, $offset), max(1, $limit))
                ->column('a.uid');
            return array_values(array_unique(array_map('intval', $rows)));
        }

        throw new MessageException('target_invalid', '不支持的发送目标类型：' . $type);
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
