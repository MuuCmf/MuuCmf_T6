<?php

namespace app\common\service\message;

/**
 * 消息中心业务异常
 *
 * 统一携带错误码（见需求文档 §8.4）与上下文，便于落 message_record 与前端定位。
 */
class MessageException extends \Exception
{
    /** @var string 业务错误码 */
    protected $errorCode = 'provider_error';

    /** @var array 上下文 */
    protected $context = [];

    public function __construct(string $errorCode, string $message = '', array $context = [])
    {
        $this->errorCode = $errorCode;
        $this->context = $context;
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * 错误码文案
     *
     * @return array
     */
    public static function codes(): array
    {
        return [
            'template_not_found'       => '模板不存在',
            'template_disabled'        => '模板已停用',
            'template_var_missing'     => '模板变量缺失',
            'var_too_long'             => '变量超长',
            'invalid_link'             => '链接不合法',
            'channel_not_configured'   => '渠道未配置',
            'no_binding'               => '用户无手机号或邮箱',
            'already_reached'          => '已通过其他渠道送达',
            'freq_limited'             => '频控拦截',
            'quiet'                    => '免打扰顺延',
            'unsubscribed'             => '用户已退订',
            'blacklisted'              => '黑名单',
            'user_canceled'            => '任务已取消',
            'provider_error'           => '服务商返回错误',
            'provider_rate_limit'      => '服务商限流',
            'biz_key_required'         => '事务/安全消息缺少 biz_key',
            'biz_key_too_long'         => 'biz_key 超长',
            'target_invalid'           => '发送目标不合法',
            'shopid_forbidden'         => '无权操作该店铺',
            'template_exists'          => '模板编码已存在',
            'template_invalid'         => '模板配置不合法',
        ];
    }

    /**
     * 错误码中文说明
     *
     * @param string $code
     * @return string
     */
    public static function text(string $code): string
    {
        return self::codes()[$code] ?? $code;
    }
}
