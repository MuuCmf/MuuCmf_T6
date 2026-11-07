<?php

namespace app\common\model;

/**
 * 消息发送任务表
 */
class MessageTask extends MessageBaseModel
{
    protected $name = 'message_task';

    // 任务状态
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_FINISHED  = 'finished';
    public const STATUS_PARTIAL   = 'partial';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELED  = 'canceled';

    // 终态
    public const TERMINAL_STATUS = [
        self::STATUS_FINISHED,
        self::STATUS_PARTIAL,
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
    ];

    /**
     * 目标定义
     *
     * @return array
     */
    public function getTargetPayloadArray(): array
    {
        return self::decodeJson($this->getData('target_payload'), []);
    }

    /**
     * 渠道集合
     *
     * @return array
     */
    public function getChannelsArray(): array
    {
        $channels = self::decodeJson($this->getData('channels'), null);
        if (!is_array($channels)) {
            $channels = array_filter(array_map('trim', explode(',', (string)$this->getData('channels'))));
        }
        return array_values(array_unique(array_filter(array_map('strval', $channels))));
    }

    /**
     * 是否终态
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return in_array((string)$this->getData('status'), self::TERMINAL_STATUS, true);
    }
}
