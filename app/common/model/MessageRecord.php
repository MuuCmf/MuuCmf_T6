<?php

namespace app\common\model;

/**
 * 消息发送记录表（一个用户 × 一个渠道一行）
 */
class MessageRecord extends MessageBaseModel
{
    protected $name = 'message_record';

    // 记录状态
    public const STATUS_PENDING  = 'pending';
    public const STATUS_DELAYED  = 'delayed';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_BLOCKED  = 'blocked';
    public const STATUS_CANCELED = 'canceled';

    // 终态（delayed 会继续被 MessageRetry 捞取）
    public const TERMINAL_STATUS = [
        self::STATUS_SUCCESS,
        self::STATUS_FAILED,
        self::STATUS_BLOCKED,
        self::STATUS_CANCELED,
    ];
}
