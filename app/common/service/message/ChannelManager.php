<?php

namespace app\common\service\message;

use app\common\service\message\channel\ChannelInterface;
use app\common\service\message\channel\EmailChannel;
use app\common\service\message\channel\InboxChannel;
use app\common\service\message\channel\SmsChannel;

/**
 * 渠道路由与兜底
 *
 * 首版只注册 inbox / email / sms；模板里若配置了微信渠道，
 * 由调用方按 channel_not_configured 落 blocked，不影响兜底链。
 */
class ChannelManager
{
    /** @var ChannelConfigProvider */
    protected $configProvider;

    /** @var array<string,ChannelInterface> */
    protected $channels = [];

    public function __construct(?ChannelConfigProvider $configProvider = null)
    {
        $this->configProvider = $configProvider ?: new ChannelConfigProvider();
        $this->channels = [
            'inbox' => new InboxChannel(),
            'email' => new EmailChannel($this->configProvider),
            'sms'   => new SmsChannel($this->configProvider),
        ];
    }

    /**
     * 获取渠道实例
     *
     * @param string $channel
     * @return ChannelInterface|null
     */
    public function get(string $channel): ?ChannelInterface
    {
        return $this->channels[$channel] ?? null;
    }

    /**
     * 已实现的渠道
     *
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->channels);
    }

    /**
     * 渠道是否已配置
     *
     * @param int    $shopid
     * @param string $channel
     * @return bool
     */
    public function configured(int $shopid, string $channel): bool
    {
        $handler = $this->get($channel);
        return $handler ? $handler->configured($shopid) : false;
    }

    /**
     * 批量可达性
     *
     * @param int    $shopid
     * @param array  $uids
     * @param string $channel
     * @return array [uid => bool]
     */
    public function availableBatch(int $shopid, array $uids, string $channel): array
    {
        if (empty($uids)) {
            return [];
        }
        $handler = $this->get($channel);
        if (!$handler) {
            return array_fill_keys(array_map('intval', $uids), false);
        }

        if (!$this->configured($shopid, $channel)) {
            return array_fill_keys(array_map('intval', $uids), false);
        }

        return $handler->availableBatch($shopid, $uids);
    }

    /**
     * 渠道配置
     *
     * @return ChannelConfigProvider
     */
    public function configProvider(): ChannelConfigProvider
    {
        return $this->configProvider;
    }
}
