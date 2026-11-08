<?php

namespace app\common\service\message;

use think\facade\Cache;
use think\facade\Db;

/**
 * 模板解析与变量渲染
 *
 * - 占位符统一 {{变量名}}，变量名只允许 [a-z0-9_]；
 * - 转义按渠道区分：inbox/email 白名单净化，sms 纯文本转义；
 * - 缺失变量：站内信/邮件兜底为空串，短信直接失败（不允许把 {{xxx}} 发给用户）。
 */
class TemplateResolver
{
    /** 缓存有效期（秒） */
    protected const CACHE_TTL = 300;

    /**
     * 读取模板（含渠道内容）
     *
     * @param int    $shopid
     * @param string $code
     * @param bool   $throw
     * @return array|null
     * @throws MessageException
     */
    public function get(int $shopid, string $code, bool $throw = true): ?array
    {
        $key = $this->cacheKey($shopid, $code);
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                if (empty($cached['status'])) {
                    if ($throw) {
                        throw new MessageException('template_disabled', '模板已停用：' . $code);
                    }
                    return $cached;
                }
                return $cached;
            }
        } catch (MessageException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 缓存不可用时回源
        }

        $row = Db::name('message_template')
            ->where('shopid', $shopid)
            ->where('code', $code)
            ->find();

        if (empty($row)) {
            if ($throw) {
                throw new MessageException('template_not_found', '模板不存在：' . $code);
            }
            return null;
        }

        $template = $this->formatTemplate($row);
        $channels = Db::name('message_template_channel')
            ->where('shopid', $shopid)
            ->where('template_id', (int)$row['id'])
            ->select()
            ->toArray();
        foreach ($channels as $channel) {
            $template['channels'][$channel['channel']] = $this->formatChannel($channel);
        }

        try {
            Cache::set($key, $template, self::CACHE_TTL);
        } catch (\Throwable $e) {
            // 忽略缓存写入失败
        }

        return $template;
    }

    /**
     * 清理模板缓存（按店铺递增版本号，避免遍历删除）
     *
     * @param int    $shopid
     * @param string $code 兼容参数，版本号方案下无需精确匹配
     * @return void
     */
    public function clearCache(int $shopid, string $code = '*'): void
    {
        try {
            $key = 'msg:tpl:ver:' . $shopid;
            // 必须先读后写：版本键不存在时 inc 在部分缓存驱动下无效，导致首次失效不生效
            $version = (int)Cache::get($key, 1);
            Cache::set($key, $version + 1, 0);
        } catch (\Throwable $e) {
            // 忽略
        }
    }

    /**
     * 解析渠道链
     *
     * @param array      $template 模板
     * @param array      $options  调用参数
     * @param array      $config   渠道配置
     * @param array|null $skipped  被剔除的渠道及原因（引用返回，供发送侧落 blocked 记录）
     * @return array
     */
    public function buildChain(array $template, array $options, array $config, ?array &$skipped = null): array
    {
        $skipped = [];
        $chain = [];
        if (!empty($options['channels']) && is_array($options['channels'])) {
            $chain = $options['channels'];
        } elseif (!empty($template['fallback'])) {
            $chain = $template['fallback'];
        } else {
            $chain = $config['default_chain'] ?? ['sms'];
        }

        $chain = array_values(array_unique(array_filter(array_map('strval', $chain))));

        // 关闭的渠道不再进入链路（仍会在发送时落 blocked，保证统计可对账）
        $external = [];
        foreach ($chain as $channel) {
            if ($channel === 'inbox') {
                continue;
            }
            if (in_array($channel, ['sms', 'email', 'wechat_oa', 'wechat_mp'], true)) {
                if (empty($config['enabled'][$channel])) {
                    $skipped[] = ['channel' => $channel, 'reason' => 'channel_disabled'];
                    continue;
                }
                // 模板渠道行被软停用：内容保留但不进发送链路
                if (isset($template['channels'][$channel]['status'])
                    && (int)$template['channels'][$channel]['status'] === 0) {
                    $skipped[] = ['channel' => $channel, 'reason' => 'channel_disabled'];
                    continue;
                }
                if ($channel === 'sms' || $channel === 'email') {
                    // 模板未配置该渠道内容时跳过，避免发送空短信/空邮件（如仅需站内信的事务模板）
                    if (empty($template['channels'][$channel]['content'])) {
                        $skipped[] = ['channel' => $channel, 'reason' => 'channel_not_configured'];
                        continue;
                    }
                } else {
                    // 微信渠道用 field_map 组装 data，不依赖富文本正文
                    $wechat = $template['channels'][$channel] ?? [];
                    $wechatExtra = is_array($wechat['extra'] ?? null) ? $wechat['extra'] : [];
                    $fieldMap = $wechatExtra['field_map'] ?? ($wechat['var_map'] ?? []);
                    if (!is_array($fieldMap) || empty($fieldMap)) {
                        $skipped[] = ['channel' => $channel, 'reason' => 'channel_not_configured'];
                        continue;
                    }
                }
                if (count($external) >= 3) {
                    $skipped[] = ['channel' => $channel, 'reason' => 'too_many_channels'];
                    continue;
                }
                $external[] = $channel;
                continue;
            }
            // 未实现/未知渠道：不进链路，但必须落 blocked 记录
            $skipped[] = ['channel' => $channel, 'reason' => 'channel_not_configured'];
        }

        if (!empty($template['always_inbox'])) {
            // 站内信必发：链首额外发送，链尾不再重复
            return array_merge(['inbox'], $external);
        }

        $external[] = 'inbox';
        return $external;
    }

    /**
     * 渲染单个渠道内容
     *
     * @param array  $channel    渠道内容
     * @param array  $vars       调用方变量
     * @param string $channelKey 渠道标识
     * @param array  $template   模板
     * @return array
     * @throws MessageException
     */
    public function render(array $channel, array $vars, string $channelKey, array $template): array
    {
        $declared = $template['variables'] ?? [];
        $defaults = $template['default_vars'] ?? [];
        $vars = array_merge($defaults, $vars);

        $title = $this->renderString((string)($channel['title'] ?? ''), $vars, $channelKey, $declared);
        $content = $this->renderString((string)($channel['content'] ?? ''), $vars, $channelKey, $declared);

        $extra = $channel['extra'] ?? [];
        $link = (string)($extra['link'] ?? '');
        if ($link !== '' && !$this->isValidLink($link)) {
            throw new MessageException('invalid_link', '跳转链接不合法：' . $link);
        }

        return [
            'title'         => $title,
            'content'       => $content,
            'extra'         => $extra,
            'var_map'       => $channel['var_map'] ?? [],
            'third_template_id' => (string)($channel['third_template_id'] ?? ''),
            'template_code' => (string)($template['code'] ?? ''),
            'variables'     => $vars,
        ];
    }

    /**
     * 渲染字符串
     *
     * @param string $text
     * @param array  $vars
     * @param string $channelKey
     * @param array  $declared
     * @return string
     * @throws MessageException
     */
    protected function renderString(string $text, array $vars, string $channelKey, array $declared): string
    {
        if ($text === '') {
            return '';
        }

        $placeholders = $this->extractVariables($text);
        $external = in_array($channelKey, ['sms', 'wechat_oa', 'wechat_mp'], true);

        foreach ($placeholders as $placeholder) {
            if (!in_array($placeholder, $declared, true)) {
                throw new MessageException('template_var_missing', '模板变量未声明：' . $placeholder, [
                    'placeholder' => $placeholder,
                    'channel'     => $channelKey,
                ]);
            }

            $value = $vars[$placeholder] ?? '';
            $value = is_scalar($value) ? (string)$value : '';

            if ($value === '' && $external) {
                throw new MessageException('template_var_missing', '模板变量缺失：' . $placeholder, [
                    'placeholder' => $placeholder,
                    'channel'     => $channelKey,
                ]);
            }

            if ($external && mb_strlen($value) > 20) {
                throw new MessageException('var_too_long', '短信变量超长：' . $placeholder, [
                    'placeholder' => $placeholder,
                    'limit'       => 20,
                ]);
            }

            $text = str_replace('{{' . $placeholder . '}}', $this->encode($value, $channelKey), $text);
        }

        // 兼容带空格的占位符
        $text = preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $text);

        // 模板静态正文同样必须净化：只转义变量值挡不住模板里的 <script>/事件属性
        if (!$external) {
            $text = $this->purifyHtml($text);
        }

        return $text;
    }

    /**
     * 提取占位符
     *
     * @param string $text
     * @return array
     */
    public function extractVariables(string $text): array
    {
        if ($text === '') {
            return [];
        }
        if (!preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $matches)) {
            return [];
        }
        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * 按渠道转义
     *
     * @param string $value
     * @param string $channel
     * @return string
     */
    public function encode(string $value, string $channel): string
    {
        if (in_array($channel, ['inbox', 'email'], true)) {
            // HTML 渠道做白名单净化，不整体转义，否则邮件排版与站内信富文本会丢失
            return $this->purifyHtml($value);
        }
        return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * HTML 白名单净化（轻量实现，移除脚本与事件属性）
     *
     * @param string $html
     * @return string
     */
    public function purifyHtml(string $html): string
    {
        if ($html === '') {
            return '';
        }
        // 移除危险标签
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|link|meta)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|style|iframe|object|embed|form|input|link|meta)[^>]*/?>#is', '', $html);
        // 移除 on* 事件属性与 javascript: 协议
        $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#is', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*("|\')#is', '$1="#"', $html);

        return $html;
    }

    /**
     * 链接白名单校验
     *
     * @param string $link
     * @return bool
     */
    public function isValidLink(string $link): bool
    {
        $link = trim($link);
        if ($link === '') {
            return true;
        }
        if (strpos($link, '//') === 0) {
            return false;
        }
        if (strpos($link, '/') === 0) {
            return true;
        }
        return (bool)preg_match('#^https?://[^\s]+$#i', $link);
    }

    /**
     * 是否包含用户级变量（禁止复用 message_content）
     *
     * @param array $variables 模板声明
     * @param array $vars      本次变量
     * @return bool
     */
    public function hasUserScopedVars(array $variables, array $vars): bool
    {
        $scoped = MessageLevel::userScopedVars();
        foreach ($scoped as $name) {
            if (in_array($name, $variables, true) && array_key_exists($name, $vars)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 校验模板与渠道内容（保存阶段）
     *
     * @param array $template 模板字段
     * @param array $channels 渠道内容 [channel => row]
     * @return array 结构化错误 [{channel,field,placeholder,type}]
     */
    public function validate(array $template, array $channels): array
    {
        $errors = [];
        $declared = array_map('strtolower', (array)($template['variables'] ?? []));
        $seen = [];

        foreach ($channels as $channelKey => $channel) {
            if (isset($seen[$channelKey])) {
                $errors[] = ['channel' => $channelKey, 'field' => 'channel', 'type' => 'duplicate_channel'];
            }
            $seen[$channelKey] = true;

            if (!in_array($channelKey, ['inbox', 'email', 'sms', 'wechat_oa', 'wechat_mp'], true)) {
                continue;
            }
            // 停用渠道仅保留内容，不参与保存期校验（如空短信行不应触发模板ID必填）
            if (isset($channel['status']) && (int)$channel['status'] === 0) {
                continue;
            }

            $title = (string)($channel['title'] ?? '');
            $content = (string)($channel['content'] ?? '');
            $extra = $channel['extra'] ?? [];

            // 保存期校验字段长度，避免 varchar 截断或超长短信变量到发送期才暴露
            if (mb_strlen($title, 'utf-8') > 500 || mb_strlen($content, 'utf-8') > 5000) {
                $errors[] = [
                    'channel' => $channelKey,
                    'field'   => mb_strlen($title, 'utf-8') > 500 ? 'title' : 'content',
                    'type'    => 'too_long',
                ];
            }

            if ($channelKey === 'inbox' || $channelKey === 'email') {
                if (trim($title) === '') {
                    $errors[] = ['channel' => $channelKey, 'field' => 'title', 'type' => 'missing_value'];
                }
                if (trim($content) === '') {
                    $errors[] = ['channel' => $channelKey, 'field' => 'content', 'type' => 'missing_value'];
                }
            }

            if ($channelKey === 'inbox' && trim((string)($extra['type_code'] ?? '')) === '') {
                $errors[] = ['channel' => 'inbox', 'field' => 'extra.type_code', 'type' => 'missing_value'];
            }

            if ($channelKey === 'wechat_oa' || $channelKey === 'wechat_mp') {
                // 模板 ID 可留空（运行时按 scene 回退历史微信配置），字段映射必须声明
                $fieldMap = $extra['field_map'] ?? ($channel['var_map'] ?? []);
                if (!is_array($fieldMap) || empty($fieldMap)) {
                    $errors[] = [
                        'channel' => $channelKey,
                        'field'   => 'extra.field_map',
                        'type'    => 'missing_value',
                    ];
                } else {
                    foreach ($fieldMap as $field => $tplVar) {
                        $field = trim((string)$field);
                        $tplVar = (string)$tplVar;
                        if ($field === '' || !in_array($tplVar, $declared, true)) {
                            $errors[] = [
                                'channel'     => $channelKey,
                                'field'       => 'extra.field_map',
                                'placeholder' => $field !== '' ? $field : $tplVar,
                                'type'        => 'invalid_value',
                            ];
                        }
                    }
                }
            }

            if ($channelKey === 'sms') {
                // 模板 ID 只认模板级配置：至少填写一个服务商的模板 ID（空字符串不算）
                $providerTemplate = $extra['provider_template_id'] ?? [];
                if (!is_array($providerTemplate)) {
                    $providerTemplate = [];
                }
                $filledTemplateIds = array_filter(array_map(function ($value) {
                    return trim((string)$value);
                }, $providerTemplate), function ($value) {
                    return $value !== '';
                });
                if (empty($filledTemplateIds)) {
                    $errors[] = ['channel' => 'sms', 'field' => 'extra.provider_template_id', 'type' => 'missing_value'];
                }
                if (trim((string)($extra['sign_name'] ?? '')) === '') {
                    $errors[] = ['channel' => 'sms', 'field' => 'extra.sign_name', 'type' => 'missing_value'];
                }
                // 仅当短信正文含变量时才要求映射（纯静态短信模板可留空）
                if (empty($channel['var_map']) && !empty($this->extractVariables($content))) {
                    $errors[] = ['channel' => 'sms', 'field' => 'var_map', 'type' => 'missing_value'];
                }
            }

            $link = (string)($extra['link'] ?? '');
            if ($link !== '' && !$this->isValidLink($link)) {
                $errors[] = ['channel' => $channelKey, 'field' => 'extra.link', 'type' => 'invalid_link'];
            }

            foreach ($this->extractVariables($title . "\n" . $content) as $placeholder) {
                if (!in_array($placeholder, $declared, true)) {
                    $errors[] = [
                        'channel'     => $channelKey,
                        'field'       => 'content',
                        'placeholder' => $placeholder,
                        'type'        => 'missing_declare',
                    ];
                }
            }
        }

        return $errors;
    }

    /**
     * 格式化模板行
     *
     * @param array $row
     * @return array
     */
    protected function formatTemplate(array $row): array
    {
        $variables = json_decode((string)($row['variables'] ?? ''), true);
        $defaults = json_decode((string)($row['default_vars'] ?? ''), true);
        $fallback = json_decode((string)($row['fallback'] ?? ''), true);
        if (!is_array($fallback)) {
            $fallback = array_filter(array_map('trim', explode(',', (string)($row['fallback'] ?? ''))));
        }
        $frequency = json_decode((string)($row['frequency_rule'] ?? ''), true);
        $targets = json_decode((string)($row['targets'] ?? ''), true);

        return [
            'id'             => (int)$row['id'],
            'shopid'         => (int)$row['shopid'],
            'code'           => (string)$row['code'],
            'app'            => (string)$row['app'],
            'scene'          => (string)$row['scene'],
            'name'           => (string)$row['name'],
            'description'    => (string)$row['description'],
            'level'          => (string)$row['level'],
            'variables'      => is_array($variables) ? array_values($variables) : [],
            'default_vars'   => is_array($defaults) ? $defaults : [],
            'fallback'       => is_array($fallback) ? array_values($fallback) : [],
            'always_inbox'   => (int)$row['always_inbox'],
            'frequency_rule' => is_array($frequency) ? $frequency : [],
            'targets'        => is_array($targets) ? $targets : [],
            'is_system'      => (int)$row['is_system'],
            'version'        => (int)$row['version'],
            'status'         => (int)$row['status'],
            'channels'       => [],
        ];
    }

    /**
     * 格式化渠道行
     *
     * @param array $row
     * @return array
     */
    protected function formatChannel(array $row): array
    {
        $extra = json_decode((string)($row['extra'] ?? ''), true);
        $varMap = json_decode((string)($row['var_map'] ?? ''), true);

        return [
            'id'                => (int)$row['id'],
            'title'             => (string)($row['title'] ?? ''),
            'content'           => (string)($row['content'] ?? ''),
            'extra'             => is_array($extra) ? $extra : [],
            'var_map'           => is_array($varMap) ? $varMap : [],
            'third_template_id' => (string)($row['third_template_id'] ?? ''),
            'status'            => (int)$row['status'],
        ];
    }

    /**
     * 缓存键（含版本号）
     *
     * @param int    $shopid
     * @param string $code
     * @return string
     */
    protected function cacheKey(int $shopid, string $code): string
    {
        $version = 1;
        try {
            $version = (int)Cache::get('msg:tpl:ver:' . $shopid, 1);
        } catch (\Throwable $e) {
            $version = 1;
        }
        return 'msg:tpl:' . $shopid . ':' . $version . ':' . $code;
    }
}
