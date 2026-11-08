<?php

namespace app\common\service\message\channel;

/**
 * 渠道发送结果
 */
class ChannelResult
{
    /** @var bool 是否成功 */
    public $success = false;

    /** @var string 渠道标识 */
    public $channel = '';

    /** @var string 错误码 */
    public $errorCode = '';

    /** @var string 错误信息 */
    public $errorMsg = '';

    /** @var array|string 服务商原始响应（验证码调用方需要兼容判断） */
    public $raw = [];

    /** @var int 站内信 ID */
    public $inboxId = 0;

    /** @var string 第三方消息 ID */
    public $thirdMsgId = '';

    /** @var string 渲染后标题 */
    public $title = '';

    /** @var string 内容摘要（只存摘要，不落全文） */
    public $contentDigest = '';

    /** @var bool 是否可重试 */
    public $retryable = true;

    /** @var bool 是否因幂等命中而跳过真实发送（调用方不应再走兜底链或计入记录数） */
    public $skipped = false;

    /**
     * 成功结果
     *
     * @param string $channel
     * @param array  $extra
     * @return static
     */
    public static function success(string $channel, array $extra = []): self
    {
        $result = new self();
        $result->success = true;
        $result->channel = $channel;
        foreach ($extra as $key => $value) {
            if (property_exists($result, $key)) {
                $result->$key = $value;
            }
        }
        return $result;
    }

    /**
     * 失败结果
     *
     * @param string $channel
     * @param string $errorCode
     * @param string $errorMsg
     * @param array  $extra
     * @return static
     */
    public static function failure(string $channel, string $errorCode = 'provider_error', string $errorMsg = '', array $extra = []): self
    {
        $result = new self();
        $result->success = false;
        $result->channel = $channel;
        $result->errorCode = $errorCode;
        $result->errorMsg = mb_substr($errorMsg, 0, 500);
        foreach ($extra as $key => $value) {
            if (property_exists($result, $key)) {
                $result->$key = $value;
            }
        }
        return $result;
    }

    /**
     * 转数组
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success'        => $this->success,
            'channel'        => $this->channel,
            'error_code'     => $this->errorCode,
            'error_msg'      => $this->errorMsg,
            'raw'            => $this->raw,
            'inbox_id'       => $this->inboxId,
            'third_msg_id'   => $this->thirdMsgId,
            'title'          => $this->title,
            'content_digest' => $this->contentDigest,
            'retryable'      => $this->retryable,
        ];
    }
}
