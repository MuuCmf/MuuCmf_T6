<?php

namespace app\common\service\message;

use app\common\service\message\channel\ChannelInterface;
use app\common\service\message\channel\EmailChannel;
use app\common\service\message\channel\InboxChannel;
use app\common\service\message\channel\SmsChannel;
use app\common\service\message\channel\WechatOfficialChannel;
use app\common\service\message\channel\WechatMiniProgramChannel;

/**
 * 渠道路由与兜底
 *
 * 已注册 inbox / email / sms / wechat_oa / wechat_mp；
 * 微信渠道由 MESSAGE_CHANNEL_WECHAT_OA / WECHAT_MP 开关控制，默认关闭。
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
            'inbox'     => new InboxChannel(),
            'email'     => new EmailChannel($this->configProvider),
            'sms'       => new SmsChannel($this->configProvider),
            'wechat_oa' => new WechatOfficialChannel($this->configProvider),
            'wechat_mp' => new WechatMiniProgramChannel($this->configProvider),
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
     * 渠道是否实际可用（凭证齐备 AND 全局开关已打开）
     *
     * @param int    $shopid
     * @param string $channel
     * @return bool
     */
    public function enabled(int $shopid, string $channel): bool
    {
        return $this->configProvider->enabled($shopid, $channel);
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

        // 与 buildChain / 预览统一口径：开关关闭的渠道视为不可达
        if (!$this->enabled($shopid, $channel)) {
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
