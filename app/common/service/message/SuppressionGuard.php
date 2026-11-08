<?php

namespace app\common\service\message;

use think\facade\Cache;
use think\facade\Log;
use app\common\model\MessageSuppression;
use app\common\model\MessageSubscription;

/**
 * 触达策略：黑名单 / 退订 / 免打扰 / 频控
 *
 * 判定顺序（每个用户每次发送只判定一次，位置固定在解析渠道链之前）：
 * 1. 黑名单：marketing + transaction 拦截，security 豁免
 * 2. 退订：仅 marketing 生效
 * 3. 免打扰：仅 marketing 且仅作用于外部渠道，顺延到「下一个 quiet_end」
 * 4. 频控：仅 marketing，Redis Lua 先预留、失败回退
 */
class SuppressionGuard
{
    /**
     * 频控 Lua：读计数 → 判断 → 预留 +1 → 首次设置 TTL
     */
    protected const LUA_RESERVE = <<<'LUA'
local c = tonumber(redis.call('GET', KEYS[1]) or '0')
if c < 0 then redis.call('DEL', KEYS[1]); c = 0 end
if c >= tonumber(ARGV[2]) then return 0 end
redis.call('INCR', KEYS[1])
if c == 0 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
return 1
LUA;

    /**
     * 频控回退 Lua：原子 DECR，绝不创建「负值且无 TTL」的 key
     */
    protected const LUA_RELEASE = <<<'LUA'
local c = tonumber(redis.call('GET', KEYS[1]) or '0')
if c <= 0 then
    if redis.call('EXISTS', KEYS[1]) == 1 then redis.call('DEL', KEYS[1]) end
    return 0
end
local n = redis.call('DECR', KEYS[1])
if n <= 0 then redis.call('DEL', KEYS[1]) end
return n
LUA;

    /**
     * 触达判定
     *
     * @param int   $shopid
     * @param int   $uid
     * @param array $template 模板
     * @param array $chain    渠道链
     * @param array $config   渠道配置
     * @param array $pre      批量预取结果：blacklisted / unsubscribed
     * @param bool  $reserveFrequency 是否参与频控扣减（同一次发送的后续重试必须传 false）
     * @param bool  $skipQuiet 顺延记录到期重投时跳过二次免打扰判定，避免无限顺延
     * @return array{action:string,reason:string,delay_to:int,reserved:array}
     */
    public function check(int $shopid, int $uid, array $template, array $chain, array $config, array $pre = [], bool $reserveFrequency = true, bool $skipQuiet = false): array
    {
        $level = (string)($template['level'] ?? MessageLevel::MARKETING);
        $result = ['action' => 'allow', 'reason' => '', 'delay_to' => 0, 'reserved' => []];

        // 1. 黑名单（安全消息豁免）
        if (!MessageLevel::exemptBlacklist($level)) {
            $blacklisted = $pre['blacklisted'] ?? null;
            if ($blacklisted === null) {
                $blacklisted = MessageSuppression::isActive($shopid, $uid);
            }
            if ($blacklisted) {
                $result['action'] = 'block';
                $result['reason'] = 'blacklisted';
                return $result;
            }
        }

        // 2. 退订（仅营销）
        if (MessageLevel::isMarketing($level)) {
            $unsubscribed = $pre['unsubscribed'] ?? null;
            if ($unsubscribed === null) {
                $unsubscribed = MessageSubscription::isUnsubscribed($shopid, $uid, (string)($template['app'] ?? 'common'));
            }
            if ($unsubscribed) {
                $result['action'] = 'block';
                $result['reason'] = 'unsubscribed';
                return $result;
            }
        }

        // 3. 免打扰（仅营销，且只作用于外部渠道）；顺延到期重投不再二次判定
        if (!$skipQuiet && MessageLevel::isMarketing($level) && $this->hasExternalChannel($chain)) {
            $delayTo = $this->quietDelayTo($config['quiet'] ?? []);
            if ($delayTo > 0) {
                $result['action'] = 'delay';
                $result['reason'] = 'quiet';
                $result['delay_to'] = $delayTo;
                return $result;
            }
        }

        // 4. 频控（仅营销，先预留）；重试路径不重复扣减，否则一条消息会吃掉多次配额
        if ($reserveFrequency && MessageLevel::isMarketing($level)) {
            $rule = !empty($template['frequency_rule'])
                ? $template['frequency_rule']
                : ($config['frequency'] ?? ['max_times' => 3, 'window_hours' => 24]);
            $reserved = $this->reserveFrequency($shopid, $uid, (string)$template['code'], $rule);
            if ($reserved === null) {
                $result['action'] = 'block';
                $result['reason'] = 'freq';
                return $result;
            }
            $result['reserved'] = $reserved;
        }

        return $result;
    }

    /**
     * 失败回退预留计数
     *
     * @param array $keys
     * @return void
     */
    public function release(array $keys): void
    {
        if (empty($keys)) {
            return;
        }
        try {
            $redis = Cache::store('redis')->handler();
            foreach ($keys as $key) {
                // get + decr 非原子：key 恰好过期时 decr 会写出 -1 且永不过期，导致该用户被永久拦截
                $redis->eval(self::LUA_RELEASE, [$key], 1);
            }
        } catch (\Throwable $e) {
            Log::warning('[message] frequency release failed: ' . $e->getMessage());
        }
    }

    /**
     * 重试成功时补扣一次频控配额
     *
     * 首次发送失败会回退配额、失败记录重试又豁免预留；若重试最终成功，
     * 这条真实触达必须占一次配额，否则「渠道抖动 + 自动重试」可绕过频控。
     *
     * @param int   $shopid
     * @param int   $uid
     * @param array $template
     * @param array $config
     * @return void
     */
    public function consumeFrequency(int $shopid, int $uid, array $template, array $config): void
    {
        if (!MessageLevel::isMarketing((string)($template['level'] ?? MessageLevel::MARKETING))) {
            return;
        }
        $rule = !empty($template['frequency_rule'])
            ? $template['frequency_rule']
            : ($config['frequency'] ?? ['max_times' => 3, 'window_hours' => 24]);
        if ($this->reserveFrequency($shopid, $uid, (string)$template['code'], $rule) === null) {
            Log::warning('[message] frequency quota exhausted on retry success', [
                'shopid' => $shopid,
                'uid'    => $uid,
                'code'   => (string)$template['code'],
            ]);
        }
    }

    /**
     * 频控预留
     *
     * @param int    $shopid
     * @param int    $uid
     * @param string $code
     * @param array  $rule
     * @return array|null 成功返回预留键集合，超限返回 null
     */
    protected function reserveFrequency(int $shopid, int $uid, string $code, array $rule): ?array
    {
        if ($code === '') {
            // 模板编码为空属配置错误，保存阶段已拦截，这里直接拦截
            return null;
        }

        $maxTimes = max(1, (int)($rule['max_times'] ?? 3));
        $windowHours = max(1, (int)($rule['window_hours'] ?? 24));
        $key = 'msg:freq:' . $shopid . ':' . $uid . ':' . $code;

        try {
            $redis = Cache::store('redis')->handler();
            $ok = $redis->eval(self::LUA_RESERVE, [$key, $windowHours * 3600, $maxTimes], 1);
            if ((int)$ok !== 1) {
                return null;
            }
            return [$key];
        } catch (\Throwable $e) {
            // Redis 不可用时放行，不阻塞发送
            Log::warning('[message] frequency check degraded (redis unavailable): ' . $e->getMessage(), [
                'shopid' => $shopid,
                'uid'    => $uid,
                'code'   => $code,
            ]);
            return [];
        }
    }

    /**
     * 免打扰顺延时间
     *
     * 返回 0 表示不在免打扰时段；否则返回「下一个 quiet_end」+ 0~30 分钟抖动。
     *
     * @param array $quiet
     * @return int
     */
    public function quietDelayTo(array $quiet): int
    {
        if (empty($quiet['enabled'])) {
            return 0;
        }
        $start = (string)($quiet['start'] ?? '');
        $end = (string)($quiet['end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) {
            return 0;
        }

        $now = time();
        $nowMinute = (int)date('G', $now) * 60 + (int)date('i', $now);
        $startMinute = $this->toMinute($start);
        $endMinute = $this->toMinute($end);
        if ($startMinute < 0 || $endMinute < 0) {
            return 0;
        }

        $inQuiet = $startMinute < $endMinute
            ? ($nowMinute >= $startMinute && $nowMinute < $endMinute)
            : ($nowMinute >= $startMinute || $nowMinute < $endMinute);

        if (!$inQuiet) {
            return 0;
        }

        // 当天 quiet_end 未过就用当天，已过才顺延到次日
        $todayEnd = strtotime(date('Y-m-d', $now) . ' ' . $end);
        if ($todayEnd === false) {
            return 0;
        }
        if ($todayEnd <= $now) {
            $todayEnd += 86400;
        }

        return $todayEnd + mt_rand(0, 1800);
    }

    /**
     * 是否包含外部渠道
     *
     * @param array $chain
     * @return bool
     */
    protected function hasExternalChannel(array $chain): bool
    {
        foreach ($chain as $channel) {
            if ($channel !== 'inbox') {
                return true;
            }
        }
        return false;
    }

    /**
     * HH:MM 转分钟
     *
     * @param string $time
     * @return int
     */
    protected function toMinute(string $time): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{1,2})$/', trim($time), $m)) {
            return -1;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }
}
