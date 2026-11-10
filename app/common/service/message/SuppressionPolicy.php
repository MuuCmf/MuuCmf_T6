<?php

namespace app\common\service\message;

/**
 * 触达抑制纯策略
 *
 * TouchGuard（按客户/群）与 SuppressionGuard（按会员）各自维护配置来源与配额存储，
 * 但「免打扰时段」「频控默认值」的口径必须一致，否则同一次发送会出现
 * 「站内信被拦、企微却放行」。本类只做纯计算，不读写数据库、不发请求。
 */
class SuppressionPolicy
{
    /** 免打扰顺延抖动上限（秒）：避免免打扰结束瞬间全量节点集中发送 */
    protected const JITTER_MAX = 1800;

    /**
     * 归一化 TouchGuard 的配置（quiet_* 前缀）
     *
     * @param array $config 店铺配置
     * @return array{enabled:bool,start:string,end:string}
     */
    public static function fromTouchConfig(array $config): array
    {
        return [
            'enabled' => (int)($config['quiet_enabled'] ?? 0) === 1,
            'start'   => (string)($config['quiet_start'] ?? ''),
            'end'     => (string)($config['quiet_end'] ?? ''),
        ];
    }

    /**
     * 归一化消息中心的免打扰配置（enabled/start/end）
     *
     * @param array $quiet 原始配置
     * @return array{enabled:bool,start:string,end:string}
     */
    public static function fromMessageConfig(array $quiet): array
    {
        return [
            'enabled' => !empty($quiet['enabled']),
            'start'   => (string)($quiet['start'] ?? ''),
            'end'     => (string)($quiet['end'] ?? ''),
        ];
    }

    /**
     * 是否处于免打扰时段
     *
     * @param array $quiet 已归一化的配置
     * @param int   $now   时间戳，0 表示当前
     * @return bool
     */
    public function inQuiet(array $quiet, int $now = 0): bool
    {
        $range = $this->range($quiet);
        if ($range === null) {
            return false;
        }
        [$start, $end] = $range;

        $now = $now > 0 ? $now : time();
        $minute = (int)date('G', $now) * 60 + (int)date('i', $now);

        if ($start < $end) {
            return $minute >= $start && $minute < $end;
        }

        // 跨天时段，例如 21:00-09:00
        return $minute >= $start || $minute < $end;
    }

    /**
     * 免打扰顺延时间点
     *
     * @param array $quiet 已归一化的配置
     * @param int   $now   时间戳，0 表示当前
     * @return int 下一个 quiet_end + 抖动；未配置或不在免打扰时段返回 0
     */
    public function quietDelayTo(array $quiet, int $now = 0): int
    {
        if (!$this->inQuiet($quiet, $now)) {
            return 0;
        }

        return $this->nextQuietEnd($quiet, $now);
    }

    /**
     * 下一个 quiet_end 时间点
     *
     * 与 quietDelayTo 的区别：不判断当前是否在免打扰时段，
     * 供调用方自行决定何时使用（TouchGuard 先拦后顺延的既有流程）。
     *
     * @param array $quiet 已归一化的配置
     * @param int   $now   时间戳，0 表示当前
     * @return int 配置非法时返回 $now
     */
    public function nextQuietEnd(array $quiet, int $now = 0): int
    {
        $now = $now > 0 ? $now : time();
        $range = $this->range($quiet);
        if ($range === null) {
            return $now;
        }
        [, $end] = $range;

        $hour = intdiv($end, 60);
        $minute = $end % 60;
        $target = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:%02d:00', $hour, $minute));
        if ($target === false) {
            return $now;
        }
        if ($target <= $now) {
            $target += 86400;
        }

        return $target + mt_rand(0, self::JITTER_MAX);
    }

    /**
     * 频控规则归一化
     *
     * @param array $config 店铺配置
     * @return array{enabled:bool,window_hours:int,max:int}
     */
    public function freqRule(array $config): array
    {
        return [
            'enabled'      => (int)($config['freq_enabled'] ?? 0) === 1,
            'window_hours' => max(1, (int)($config['freq_window_hours'] ?? 24)),
            'max'          => max(0, (int)($config['freq_max_per_window'] ?? 0)),
        ];
    }

    /**
     * 解析免打扰区间
     *
     * @param array $quiet 已归一化的配置
     * @return array{0:int,1:int}|null 未启用或格式非法返回 null
     */
    protected function range(array $quiet): ?array
    {
        if (empty($quiet['enabled'])) {
            return null;
        }

        $start = $this->toMinute((string)($quiet['start'] ?? ''));
        $end = $this->toMinute((string)($quiet['end'] ?? ''));
        if ($start < 0 || $end < 0 || $start === $end) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * HH:MM 转分钟
     *
     * @param string $time 时间文本
     * @return int 格式非法返回 -1
     */
    public function toMinute(string $time): int
    {
        if (!preg_match('/^(\d{1,2}):(\d{1,2})$/', trim($time), $match)) {
            return -1;
        }

        $hour = (int)$match[1];
        $minute = (int)$match[2];
        if ($hour > 23 || $minute > 59) {
            return -1;
        }

        return $hour * 60 + $minute;
    }
}
