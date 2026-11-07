<?php

namespace app\common\service\message\channel;

use think\facade\Db;

/**
 * 站内信渠道
 *
 * message + message_content 与调用方（Dispatcher）的 message_record 在同一事务内提交。
 */
class InboxChannel implements ChannelInterface
{
    /** @var array type_code 缓存 */
    protected static $typeCache = [];

    public function key(): string
    {
        return 'inbox';
    }

    public function configured(int $shopid): bool
    {
        return true;
    }

    public function available(int $shopid, int $uid): bool
    {
        if ($shopid < 0 || $uid <= 0) {
            return false;
        }
        $status = Db::name('member')->where('uid', $uid)->value('status');
        return $status !== null && (int)$status === 1;
    }

    public function availableBatch(int $shopid, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        $map = array_fill_keys($uids, false);
        if (empty($uids)) {
            return $map;
        }
        $rows = Db::name('member')->where('uid', 'in', $uids)->where('status', 1)->column('uid');
        foreach ($rows as $uid) {
            $map[(int)$uid] = true;
        }
        return $map;
    }

    /**
     * 写入站内信
     *
     * @param array $rendered
     * @param array $context
     * @return ChannelResult
     */
    public function send(array $rendered, array $context): ChannelResult
    {
        $shopid = (int)($context['shopid'] ?? 0);
        $uid = (int)($context['uid'] ?? 0);
        if ($uid <= 0) {
            return ChannelResult::failure('inbox', 'no_binding', '接收用户为空');
        }

        // 会员状态校验：存在 member 行但已被禁用/删除时拒发；
        // 无 member 行的内部接收人（如平台管理员）保持原有可发行为。
        $memberStatus = Db::name('member')->where('uid', $uid)->value('status');
        if ($memberStatus !== null && (int)$memberStatus !== 1) {
            return ChannelResult::failure('inbox', 'no_binding', '接收用户状态异常', ['retryable' => false]);
        }

        $extra = $rendered['extra'] ?? [];
        $typeCode = (string)($extra['type_code'] ?? 'system');
        // 兼容历史入口：允许调用方直接指定 message_type.id
        $explicitTypeId = (int)($context['type_id'] ?? 0);
        $typeId = $explicitTypeId > 0 ? $explicitTypeId : $this->resolveTypeId($shopid, $typeCode);
        $title = (string)($rendered['title'] ?? '');
        $content = (string)($rendered['content'] ?? '');
        $link = (string)($extra['link'] ?? '');
        $description = mb_substr((string)($extra['description'] ?? ($rendered['variables']['description'] ?? strip_tags($content))), 0, 128);

        try {
            $ids = Db::transaction(function () use ($shopid, $uid, $typeId, $title, $description, $content, $link, $rendered) {
                $now = time();
                $contentId = Db::name('message_content')->insertGetId([
                    'shopid'        => $shopid,
                    'title'         => mb_substr($title, 0, 500),
                    'description'   => $description,
                    'content'       => $content,
                    'args'          => json_encode($rendered['variables'] ?? [], JSON_UNESCAPED_UNICODE),
                    'link'          => $link,
                    'template_code' => (string)($rendered['template_code'] ?? ''),
                    'variables'     => json_encode($rendered['variables'] ?? [], JSON_UNESCAPED_UNICODE),
                    'status'        => 1,
                    'create_time'   => $now,
                    'update_time'   => $now,
                ]);

                $messageId = Db::name('message')->insertGetId([
                    'shopid'      => $shopid,
                    'uid'         => 0,
                    'to_uid'      => $uid,
                    'type_id'     => $typeId,
                    'content_id'  => $contentId,
                    'is_read'     => 0,
                    'status'      => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                return [$contentId, $messageId];
            });
        } catch (\Throwable $e) {
            return ChannelResult::failure('inbox', 'provider_error', '站内信写入失败：' . $e->getMessage());
        }

        return ChannelResult::success('inbox', [
            'inboxId'       => (int)$ids[1],
            'title'         => $title,
            'contentDigest' => 'md5:' . md5($content),
        ]);
    }

    /**
     * type_code 映射到当前店铺的 message_type.id
     *
     * @param int    $shopid
     * @param string $typeCode
     * @return int
     */
    protected function resolveTypeId(int $shopid, string $typeCode): int
    {
        $cacheKey = $shopid . ':' . $typeCode;
        if (isset(self::$typeCache[$cacheKey])) {
            return self::$typeCache[$cacheKey];
        }

        $typeId = 0;
        if ($typeCode !== '') {
            $typeId = (int)Db::name('message_type')
                ->where('shopid', $shopid)
                ->where('code', $typeCode)
                ->where('status', 1)
                ->value('id');
        }
        if ($typeId <= 0) {
            // 查不到则回落到该店铺的默认类型，避免消息聚合不到任何 Tab
            $typeId = (int)Db::name('message_type')
                ->where('shopid', $shopid)
                ->where('status', 1)
                ->order('sort asc,id asc')
                ->value('id');
        }

        self::$typeCache[$cacheKey] = $typeId;
        return $typeId;
    }
}
