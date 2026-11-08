<?php

namespace app\common\service\message\channel;

use think\facade\Db;
use think\facade\Log;

/**
 * 微信渠道公共实现
 *
 * 公众号（wechat_oa）与小程序订阅消息（wechat_mp）共用：
 *  - 可达性：member_sync 一次 IN 批量取 openid，禁止在用户循环内逐条查库；
 *  - 模板 ID：message_template_channel.extra.template_id / third_template_id，
 *    缺省时按模板 scene 回退历史 wechat_config / wechat_mp_config.tmplmsg；
 *  - 字段：extra.field_map（微信模板字段名 => 消息模板变量名）。
 *
 * 渠道不直接依赖请求上下文中的 shopid，队列 / CLI 场景由 context 显式传入。
 */
abstract class WechatChannel implements ChannelInterface
{
    /** @var \app\common\service\message\ChannelConfigProvider */
    protected $configProvider;

    public function __construct($configProvider)
    {
        $this->configProvider = $configProvider;
    }

    /** @return string 渠道标识 */
    abstract protected function channelKey(): string;

    /** @return array member_sync.type 取值（可多个，兼容历史登录渠道） */
    abstract protected function syncTypes(): array;

    /** @return array 服务商原始响应 */
    abstract protected function dispatch(int $shopid, array $message): array;

    /** @return string 历史微信配置里的模板 ID */
    abstract protected function legacyTemplateId(int $shopid, string $scene): string;

    public function key(): string
    {
        return $this->channelKey();
    }

    public function configured(int $shopid): bool
    {
        return $this->configProvider->configured($shopid, $this->channelKey());
    }

    public function available(int $shopid, int $uid): bool
    {
        return $this->openidOf($shopid, $uid) !== '';
    }

    public function availableBatch(int $shopid, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        $map = array_fill_keys($uids, false);
        if (empty($uids)) {
            return $map;
        }

        $rows = Db::name('member_sync')
            ->where('shopid', $shopid)
            ->whereIn('type', $this->syncTypes())
            ->whereIn('uid', $uids)
            ->field('uid,openid')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = trim((string)$row['openid']) !== '';
        }
        return $map;
    }

    public function send(array $rendered, array $context): ChannelResult
    {
        $channel = $this->channelKey();
        $shopid = (int)($context['shopid'] ?? 0);
        $uid = (int)($context['uid'] ?? 0);

        if (!$this->configured($shopid)) {
            return ChannelResult::failure($channel, 'channel_not_configured',
                '微信渠道未配置 AppID/Secret', ['retryable' => false]);
        }

        $openid = trim((string)($context['openid'] ?? '')) ?: $this->openidOf($shopid, $uid);
        if ($openid === '') {
            return ChannelResult::failure($channel, 'no_binding',
                '用户未绑定微信 openid', ['retryable' => false]);
        }

        $templateId = $this->resolveTemplateId($rendered, $shopid);
        if ($templateId === '') {
            return ChannelResult::failure($channel, 'channel_not_configured',
                '模板未配置微信模板ID', ['retryable' => false]);
        }

        $message = $this->buildMessage($rendered, $openid, $templateId);
        if (isset($message['__error'])) {
            return ChannelResult::failure($channel, (string)$message['__error'],
                (string)($message['__message'] ?? ''), ['retryable' => false]);
        }

        try {
            $raw = $this->dispatch($shopid, $message);
        } catch (\Throwable $e) {
            Log::warning('[message] ' . $channel . ' send failed: ' . $e->getMessage());
            return ChannelResult::failure($channel, 'provider_error', $e->getMessage(), ['raw' => []]);
        }

        if (!is_array($raw) || empty($raw)) {
            return ChannelResult::failure($channel, 'provider_error', '微信接口无响应');
        }

        $errcode = (int)($raw['errcode'] ?? 0);
        if ($errcode !== 0) {
            [$code, $retryable] = $this->classify($errcode);
            return ChannelResult::failure($channel, $code,
                (string)($raw['errmsg'] ?? '微信返回错误'), ['retryable' => $retryable, 'raw' => $raw]);
        }

        return ChannelResult::success($channel, [
            'thirdMsgId'    => (string)($raw['msgid'] ?? ''),
            'raw'           => $raw,
            'title'         => (string)($rendered['title'] ?? ''),
            'contentDigest' => md5(json_encode($message['data'], JSON_UNESCAPED_UNICODE) ?: ''),
        ]);
    }

    /**
     * 组装微信消息体（public 便于离线回归验证，不触发网络）
     *
     * @param array  $rendered
     * @param string $openid
     * @param string $templateId
     * @return array 出错时返回 __error / __message
     */
    public function buildMessage(array $rendered, string $openid, string $templateId): array
    {
        $extra = is_array($rendered['extra'] ?? null) ? $rendered['extra'] : [];
        $fieldMap = $extra['field_map'] ?? [];
        if (!is_array($fieldMap) || empty($fieldMap)) {
            // 兼容把映射放在 var_map 的早期数据
            $fieldMap = is_array($rendered['var_map'] ?? null) ? $rendered['var_map'] : [];
        }
        if (empty($fieldMap)) {
            return ['__error' => 'channel_not_configured', '__message' => '模板未配置微信字段映射 field_map'];
        }

        $vars = is_array($rendered['variables'] ?? null) ? $rendered['variables'] : [];
        $data = [];
        foreach ($fieldMap as $field => $tplVar) {
            $field = trim((string)$field);
            $value = (string)($vars[(string)$tplVar] ?? '');
            if ($field === '' || $value === '') {
                return [
                    '__error'   => 'template_var_missing',
                    '__message' => '微信模板变量为空或字段名非法：' . $field,
                ];
            }
            // 微信 thing / character_string 类字段限制 20 字符，超长收敛而非静默丢弃
            if (mb_strlen($value, 'utf-8') > 20) {
                $value = mb_substr($value, 0, 20, 'utf-8');
            }
            $data[$field] = ['value' => $value];
        }

        $message = [
            'touser'      => $openid,
            'template_id' => $templateId,
            'data'        => $data,
        ];
        return $this->decorateMessage($message, $extra);
    }

    /**
     * 渠道特有的跳转 / 版本参数
     */
    protected function decorateMessage(array $message, array $extra): array
    {
        return $message;
    }

    /**
     * 模板 ID：模板配置优先，缺省按 scene 回退历史微信配置
     */
    protected function resolveTemplateId(array $rendered, int $shopid): string
    {
        $extra = is_array($rendered['extra'] ?? null) ? $rendered['extra'] : [];
        $templateId = trim((string)($extra['template_id'] ?? ''));
        if ($templateId === '') {
            $templateId = trim((string)($rendered['third_template_id'] ?? ''));
        }
        if ($templateId !== '') {
            return $templateId;
        }

        $code = (string)($rendered['template_code'] ?? '');
        $pos = strrpos($code, '.');
        $scene = $pos === false ? $code : substr($code, $pos + 1);
        if ($scene === '') {
            return '';
        }
        return $this->legacyTemplateId($shopid, $scene);
    }

    protected function openidOf(int $shopid, int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        return trim((string)Db::name('member_sync')
            ->where('shopid', $shopid)
            ->whereIn('type', $this->syncTypes())
            ->where('uid', $uid)
            ->value('openid'));
    }

    /**
     * 微信错误码归一化
     *
     * @return array [错误码, 是否可重试]
     */
    protected function classify(int $errcode): array
    {
        // 40003 openid 无效 / 43101 用户未订阅或额度用尽 / 43103 订阅额度不足：走兜底链，不重试
        if (in_array($errcode, [40003, 43101, 43103], true)) {
            return ['no_binding', false];
        }
        if ($errcode === 40037) {
            return ['channel_not_configured', false];
        }
        if ($errcode === 47003) {
            return ['template_var_missing', false];
        }
        if ($errcode === 45009) {
            return ['provider_rate_limit', true];
        }
        return ['provider_error', true];
    }
}
