<?php

namespace app\common\service\message;

use think\facade\Db;

/**
 * 店铺默认数据初始化
 *
 * message_type 与 message_template 都是店铺级数据（平台种子 shopid=0 不会被店铺用户看到），
 * 因此在新店铺首次进入消息中心/首次发送系统模板时按店铺补齐，保证安装后即可用。
 */
class MessageSeed
{
    /** @var array 已初始化过的店铺 */
    protected static $seeded = [];

    /** 验证码邮件默认标题 */
    protected const VERIFY_EMAIL_TITLE = '【{{site_name}}】验证码';

    /** 验证码邮件默认正文 */
    protected const VERIFY_EMAIL_CONTENT = '<p>{{site_name}} 验证码：<strong>{{code}}</strong></p><p>该验证码 {{expire_minutes}} 分钟内有效，请勿泄露给他人。如非本人操作，请忽略本邮件。</p>';

    /** 验证码邮件旧版默认正文（仅用于识别未修改过的存量模板） */
    protected const VERIFY_EMAIL_CONTENT_LEGACY = '<p>您的验证码为 <strong>{{code}}</strong>，15 分钟内有效。</p>';

    /**
     * 补齐店铺默认消息类型与系统模板（幂等）
     *
     * @param int $shopid
     * @return void
     */
    public function ensure(int $shopid): void
    {
        if ($shopid < 0 || isset(self::$seeded[$shopid])) {
            return;
        }

        try {
            $this->ensureTypes($shopid);
            $this->ensureTemplates($shopid);
            // 成功后才置位：首次因表不存在等原因失败时，后续请求仍可重试补齐
            self::$seeded[$shopid] = true;
        } catch (\Throwable $e) {
            // 表不存在（未执行升级脚本）时静默降级，不影响主流程
            trace('[message] seed failed: ' . $e->getMessage(), 'warning');
        }
    }

    /**
     * 默认消息类型
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureTypes(int $shopid): void
    {
        $types = [
            ['code' => 'system', 'title' => '系统通知', 'description' => '系统公告与账号相关通知', 'sort' => 1],
            ['code' => 'order', 'title' => '订单通知', 'description' => '订单、支付、退款等交易通知', 'sort' => 2],
            ['code' => 'marketing', 'title' => '营销活动', 'description' => '活动、优惠券与会员提醒', 'sort' => 3],
        ];

        // 一次取回已有 code，避免后台每次请求都逐条查询
        $existing = Db::name('message_type')
            ->where('shopid', $shopid)
            ->column('code');
        $existing = array_flip(array_map('strval', $existing));

        foreach ($types as $type) {
            if (isset($existing[$type['code']])) {
                continue;
            }
            // 兼容历史数据：可能已存在同名但无 code 的类型，直接补 code
            $legacy = Db::name('message_type')
                ->where('shopid', $shopid)
                ->where('code', '')
                ->where('title', $type['title'])
                ->value('id');
            if (!empty($legacy)) {
                Db::name('message_type')->where('id', $legacy)->update([
                    'code'        => $type['code'],
                    'sort'        => $type['sort'],
                    'update_time' => time(),
                ]);
                continue;
            }

            Db::name('message_type')->insert([
                'shopid'      => $shopid,
                'title'       => $type['title'],
                'description' => $type['description'],
                'icon'        => '',
                'code'        => $type['code'],
                'sort'        => $type['sort'],
                'status'      => 1,
                'create_time' => time(),
                'update_time' => time(),
            ]);
        }
    }

    /**
     * 默认模板（5 个 P0 场景）
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureTemplates(int $shopid): void
    {
        // 短信签名从扩展配置读取；服务商模板 ID 以模板级配置为准，
        // 升级时优先沿用旧全局模板 ID，避免存量店铺验证码短信直接不可用
        $smsSign = (string)config('extend.SMS_ALIYUN_SIGN', '') ?: (string)config('extend.SMS_TENCENT_SIGN', '');
        $legacyAliyunTemplate = $this->legacySmsTemplateId('SMS_ALIYUN_TEMPLATEID');
        $legacyTencentTemplate = $this->legacySmsTemplateId('SMS_TENCENT_TEMPLATEID');

        $templates = [
            [
                // 后台新建发送任务：自由标题 + 富文本正文
                'code'         => 'common.custom_message',
                'app'          => 'common',
                'scene'        => 'custom_message',
                'name'         => '自定义消息',
                'description'  => '后台自由编辑标题与正文的通用消息，供自定义发送任务复用',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['title', 'description', 'content'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => [
                    'inbox' => [
                        'title'   => '{{title}}',
                        'content' => '{{content}}',
                        'extra'   => ['type_code' => 'system'],
                    ],
                    'email' => [
                        'title'   => '{{title}}',
                        'content' => '{{content}}',
                        'extra'   => [],
                    ],
                    'sms' => [
                        'title'    => '',
                        'content'  => '{{content}}',
                        'extra'    => [],
                        'var_map'  => ['content' => 'content'],
                    ],
                ],
            ],
            [
                'code'         => 'common.verify_code',
                'app'          => 'common',
                'scene'        => 'verify_code',
                'name'         => '验证码',
                'description'  => '登录、注册、改密、绑定手机号/邮箱验证码，安全级别，不参与免打扰与频控',
                'level'        => MessageLevel::SECURITY,
                'variables'    => ['code', 'site_name', 'expire_minutes'],
                'default_vars' => [
                    'site_name'      => $this->defaultSiteName(),
                    'expire_minutes' => '15',
                ],
                'fallback'     => ['sms'],
                'always_inbox' => 0,
                'frequency'    => null,
                'channels'     => [
                    'sms' => [
                        'title'    => '',
                        'content'  => '验证码：{{code}}',
                        'extra'    => [
                            'provider_template_id' => [
                                'aliyun'  => $legacyAliyunTemplate,
                                'tencent' => $legacyTencentTemplate,
                            ],
                            'sign_name'            => $smsSign,
                        ],
                        'var_map'  => ['code' => 'code'],
                    ],
                    'email' => [
                        'title'   => self::VERIFY_EMAIL_TITLE,
                        'content' => self::VERIFY_EMAIL_CONTENT,
                        'extra'   => [],
                    ],
                ],
            ],
            [
                'code'         => 'order.pay_success',
                'app'          => 'order',
                'scene'        => 'pay_success',
                'name'         => '支付成功通知',
                'description'  => '订单支付成功站内信',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'amount', 'product_name', 'paid_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => array_merge([
                    'inbox' => [
                        'title'   => '支付成功',
                        'content' => '<p>您的订单 {{order_no}} 已支付成功。</p><p>商品：{{product_name}}</p><p>金额：{{amount}} 元</p><p>支付时间：{{paid_time}}</p>',
                        'extra'   => ['type_code' => 'order', 'description' => '订单 {{order_no}} 支付成功'],
                    ],
                ], self::wechatChannelDefinitions()),
            ],
            [
                'code'         => 'order.pay_success_admin',
                'app'          => 'order',
                'scene'        => 'pay_success_admin',
                'name'         => '支付成功运营通知',
                'description'  => '支付成功后通知店铺运营',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'amount', 'paid_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                // 运营通知默认发给平台管理员，可在模板编辑页改为运营用户组
                'targets'      => ['type' => 'uids', 'value' => [1]],
                'channels'     => [
                    'inbox' => [
                        'title'   => '有新的支付订单',
                        'content' => '<p>订单 {{order_no}} 支付成功，金额 {{amount}} 元，时间 {{paid_time}}。</p>',
                        'extra'   => ['type_code' => 'order'],
                    ],
                ],
            ],
            [
                'code'         => 'order.refund_success',
                'app'          => 'order',
                'scene'        => 'refund_success',
                'name'         => '退款到账通知',
                'description'  => '售后退款成功后通知用户',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'amount'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => [
                    'inbox' => [
                        'title'   => '退款到账',
                        'content' => '<p>订单 {{order_no}} 的退款 {{amount}} 元已到账，请注意查收。</p>',
                        'extra'   => ['type_code' => 'order'],
                    ],
                ],
            ],
            [
                'code'         => 'minishop.erp_alert',
                'app'          => 'minishop',
                'scene'        => 'erp_alert',
                'name'         => '快麦ERP告警',
                'description'  => 'ERP 凭证或会话异常时通知店铺运营',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['title', 'content'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                // 运营告警默认发给平台管理员，可在模板编辑页改为运营用户组
                'targets'      => ['type' => 'uids', 'value' => [1]],
                'channels'     => [
                    'inbox' => [
                        'title'   => '{{title}}',
                        'content' => '<p>{{title}}</p><p>{{content}}</p>',
                        'extra'   => ['type_code' => 'system'],
                    ],
                ],
            ],
            [
                'code'         => 'scoreshop.exchange_success',
                'app'          => 'scoreshop',
                'scene'        => 'exchange_success',
                'name'         => '积分兑换成功通知',
                'description'  => '积分商城兑换成功后通知用户',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'product_name', 'quantity', 'points', 'exchange_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => [
                    'inbox' => [
                        'title'   => '兑换成功',
                        'content' => '<p>您的兑换订单 {{order_no}} 已成功提交。</p><p>商品：{{product_name}} × {{quantity}}</p><p>消耗积分：{{points}}</p><p>兑换时间：{{exchange_time}}</p>',
                        'extra'   => ['type_code' => 'order', 'description' => '订单 {{order_no}} 兑换成功'],
                    ],
                ],
            ],
            [
                'code'         => 'scoreshop.exchange_success_admin',
                'app'          => 'scoreshop',
                'scene'        => 'exchange_success_admin',
                'name'         => '积分兑换运营通知',
                'description'  => '积分商城兑换成功后通知店铺运营',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'nickname', 'uid', 'product_name', 'quantity', 'points', 'exchange_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                // 运营通知默认发给平台管理员，可在模板编辑页改为运营用户组
                'targets'      => ['type' => 'uids', 'value' => [1]],
                'channels'     => [
                    'inbox' => [
                        'title'   => '有新的积分兑换订单',
                        'content' => '<p>{{nickname}}（UID {{uid}}）兑换了 {{product_name}} × {{quantity}}，消耗 {{points}} 积分。</p><p>订单号：{{order_no}}</p><p>时间：{{exchange_time}}</p>',
                        'extra'   => ['type_code' => 'order'],
                    ],
                ],
            ],
            [
                'code'         => 'scoreshop.order_shipped',
                'app'          => 'scoreshop',
                'scene'        => 'order_shipped',
                'name'         => '积分兑换发货通知',
                'description'  => '积分商城订单发货后通知用户',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['order_no', 'product_name', 'quantity', 'delivery_info', 'delivery_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => [
                    'inbox' => [
                        'title'   => '您的兑换商品已发货',
                        'content' => '<p>订单 {{order_no}} 已发货。</p><p>商品：{{product_name}} × {{quantity}}</p><p>发货信息：{{delivery_info}}</p><p>发货时间：{{delivery_time}}</p>',
                        'extra'   => ['type_code' => 'order', 'description' => '订单 {{order_no}} 已发货'],
                    ],
                ],
            ],
            [
                'code'         => 'svip.expire_remind',
                'app'          => 'svip',
                'scene'        => 'expire_remind',
                'name'         => '会员到期提醒',
                'description'  => '会员到期前 7/3/1 天提醒',
                'level'        => MessageLevel::MARKETING,
                'variables'    => ['nickname', 'expire_time'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => ['max_times' => 2, 'window_hours' => 168],
                'channels'     => [
                    'inbox' => [
                        'title'   => '会员即将到期',
                        'content' => '<p>{{nickname}}，您的会员将于 {{expire_time}} 到期，续费可继续享受会员权益。</p>',
                        'extra'   => ['type_code' => 'marketing'],
                    ],
                ],
            ],
            [
                'code'         => 'appstore.license_expire',
                'app'          => 'appstore',
                'scene'        => 'license_expire',
                'name'         => '应用授权到期提醒',
                'description'  => '应用商店升级服务到期前 7/3/1 天提醒用户续费',
                'level'        => MessageLevel::TRANSACTION,
                'variables'    => ['app_name', 'domain', 'end_time', 'days_left'],
                'fallback'     => ['inbox'],
                'always_inbox' => 1,
                'frequency'    => null,
                'channels'     => [
                    'inbox' => [
                        'title'   => '应用授权即将到期',
                        'content' => '<p>您的应用 {{app_name}}（域名 {{domain}}）升级服务将于 {{end_time}} 到期，剩余 {{days_left}} 天。</p><p>请及时续费，以免影响应用正常使用。</p>',
                        'extra'   => ['type_code' => 'order'],
                    ],
                    // 微信模板 ID 由后台模板编辑页填写；field_map 为默认字段映射，可按实际模板调整
                    'wechat_oa' => [
                        'title'   => '',
                        'content' => '',
                        'extra'   => [
                            'template_id' => '',
                            'field_map'   => [
                                'thing1'  => 'app_name',
                                'thing2'  => 'domain',
                                'time3'   => 'end_time',
                                'number4' => 'days_left',
                            ],
                            'url'         => '',
                        ],
                        'var_map' => [],
                    ],
                    'wechat_mp' => [
                        'title'   => '',
                        'content' => '',
                        'extra'   => [
                            'template_id' => '',
                            'field_map'   => [
                                'thing1'  => 'app_name',
                                'thing2'  => 'domain',
                                'time3'   => 'end_time',
                                'number4' => 'days_left',
                            ],
                            'page'        => '',
                        ],
                        'var_map' => [],
                    ],
                ],
            ],
        ];

        // 一次取回已有模板（含默认接收人），避免后台每次请求都逐条查询
        $existing = [];
        $existingRows = Db::name('message_template')
            ->where('shopid', $shopid)
            ->field('code,targets')
            ->select()->toArray();
        foreach ($existingRows as $row) {
            $existing[(string)$row['code']] = $row;
        }

        foreach ($templates as $definition) {
            if (isset($existing[$definition['code']])) {
                // 历史种子（旧 upgrade.sql）可能未写入默认接收人，按定义补齐；不覆盖后台已配置值
                if (!empty($definition['targets']) && empty($existing[$definition['code']]['targets'])) {
                    Db::name('message_template')
                        ->where('shopid', $shopid)
                        ->where('code', $definition['code'])
                        ->update([
                            'targets'     => json_encode($definition['targets'], JSON_UNESCAPED_UNICODE),
                            'update_time' => time(),
                        ]);
                }
                continue;
            }

            $now = time();
            $templateId = Db::name('message_template')->insertGetId([
                'shopid'         => $shopid,
                'code'           => $definition['code'],
                'app'            => $definition['app'],
                'scene'          => $definition['scene'],
                'name'           => $definition['name'],
                'description'    => $definition['description'],
                'level'          => $definition['level'],
                'variables'      => json_encode($definition['variables'], JSON_UNESCAPED_UNICODE),
                'default_vars'   => json_encode($definition['default_vars'] ?? [], JSON_UNESCAPED_UNICODE),
                'fallback'       => json_encode($definition['fallback'], JSON_UNESCAPED_UNICODE),
                'always_inbox'   => (int)$definition['always_inbox'],
                'frequency_rule' => $definition['frequency'] ? json_encode($definition['frequency'], JSON_UNESCAPED_UNICODE) : '',
                'targets'        => empty($definition['targets']) ? '' : json_encode($definition['targets'], JSON_UNESCAPED_UNICODE),
                'is_system'      => 1,
                'version'        => 1,
                'status'         => 1,
                'sort'           => 0,
                'create_time'    => $now,
                'update_time'    => $now,
            ]);

            foreach ($definition['channels'] as $channel => $content) {
                Db::name('message_template_channel')->insert([
                    'shopid'            => $shopid,
                    'template_id'       => $templateId,
                    'template_code'     => $definition['code'],
                    'channel'           => $channel,
                    'title'             => $content['title'],
                    'content'           => $content['content'],
                    'extra'             => json_encode($content['extra'] ?? [], JSON_UNESCAPED_UNICODE),
                    'third_template_id' => '',
                    'var_map'           => json_encode($content['var_map'] ?? [], JSON_UNESCAPED_UNICODE),
                    'status'            => 1,
                    'create_time'       => $now,
                    'update_time'       => $now,
                ]);
            }
        }

        // 存量店铺的验证码模板缺少变量声明 / 邮件渠道 / 短信模板ID，按需幂等补齐
        // 变量声明先补，避免邮件文案先升级为带新变量时出现「变量未声明」的中间态
        $this->ensureVerifyCodeVariables($shopid);
        $this->ensureVerifyCodeEmailChannel($shopid);
        $this->ensureVerifyCodeSmsTemplateId($shopid);
        // 存量店铺补齐微信渠道行（幂等，不覆盖管理员自定义内容）
        $this->ensureWechatChannels($shopid);
    }

    /**
     * 支付成功模板的微信渠道行定义
     *
     * 字段名与历史 Pay.php 模板消息保持一致；template_id 留空，
     * 运行时由渠道按 scene=pay_success 回退 wechat_config / wechat_mp_config.tmplmsg。
     *
     * @return array
     */
    protected static function wechatChannelDefinitions(): array
    {
        return [
            'wechat_oa' => [
                'title'   => '',
                'content' => '',
                'extra'   => [
                    'template_id' => '',
                    'field_map'   => [
                        'character_string3' => 'order_no',
                        'amount4'           => 'amount',
                        'thing11'           => 'product_name',
                        'time7'             => 'paid_time',
                    ],
                    'url'         => '',
                ],
                'var_map' => [],
            ],
            'wechat_mp' => [
                'title'   => '',
                'content' => '',
                'extra'   => [
                    'template_id' => '',
                    'field_map'   => [
                        'thing1'            => 'product_name',
                        'character_string3' => 'order_no',
                        'amount4'           => 'amount',
                        'time7'             => 'paid_time',
                    ],
                    'page'        => 'micro/pages/index',
                ],
                'var_map' => [],
            ],
        ];
    }

    /**
     * 存量店铺补齐微信渠道行（只补缺失，不覆盖已有配置）
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureWechatChannels(int $shopid): void
    {
        $template = Db::name('message_template')
            ->where('shopid', $shopid)
            ->where('code', 'order.pay_success')
            ->find();
        if (empty($template)) {
            return;
        }

        $templateId = (int)$template['id'];
        $existing = array_flip(Db::name('message_template_channel')
            ->where('shopid', $shopid)
            ->where('template_id', $templateId)
            ->whereIn('channel', ['wechat_oa', 'wechat_mp'])
            ->column('channel'));

        $now = time();
        foreach (self::wechatChannelDefinitions() as $channel => $definition) {
            if (isset($existing[$channel])) {
                continue;
            }
            Db::name('message_template_channel')->insert([
                'shopid'            => $shopid,
                'template_id'       => $templateId,
                'template_code'     => 'order.pay_success',
                'channel'           => $channel,
                'title'             => (string)$definition['title'],
                'content'           => (string)$definition['content'],
                'extra'             => json_encode($definition['extra'], JSON_UNESCAPED_UNICODE),
                'third_template_id' => '',
                'var_map'           => json_encode($definition['var_map'], JSON_UNESCAPED_UNICODE),
                'status'            => 1,
                'create_time'       => $now,
                'update_time'       => $now,
            ]);
        }
    }

    /**
     * 补齐验证码模板的邮件渠道（存量店铺首次种子/发送时）
     *
     * 只补缺失的 email 渠道行；存量行仅在正文仍是旧默认文案时升级，不覆盖管理员自定义内容。
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureVerifyCodeEmailChannel(int $shopid): void
    {
        $template = Db::name('message_template')
            ->where('shopid', $shopid)
            ->where('code', 'common.verify_code')
            ->find();
        if (empty($template)) {
            return;
        }

        $channel = Db::name('message_template_channel')
            ->where('shopid', $shopid)
            ->where('template_id', (int)$template['id'])
            ->where('channel', 'email')
            ->find();

        if (!empty($channel)) {
            if (trim((string)($channel['content'] ?? '')) === self::VERIFY_EMAIL_CONTENT_LEGACY) {
                Db::name('message_template_channel')->where('id', (int)$channel['id'])->update([
                    'title'       => self::VERIFY_EMAIL_TITLE,
                    'content'     => self::VERIFY_EMAIL_CONTENT,
                    'update_time' => time(),
                ]);
            }
            return;
        }

        $now = time();
        Db::name('message_template_channel')->insert([
            'shopid'            => $shopid,
            'template_id'       => (int)$template['id'],
            'template_code'     => 'common.verify_code',
            'channel'           => 'email',
            'title'             => self::VERIFY_EMAIL_TITLE,
            'content'           => self::VERIFY_EMAIL_CONTENT,
            'extra'             => json_encode([], JSON_UNESCAPED_UNICODE),
            'third_template_id' => '',
            'var_map'           => json_encode([], JSON_UNESCAPED_UNICODE),
            'status'            => 1,
            'create_time'       => $now,
            'update_time'       => $now,
        ]);
    }

    /**
     * 补齐验证码短信渠道的服务商模板 ID（存量店铺升级兼容）
     *
     * 旧全局短信模板配置已软下线，这里按名称直读原值，仅在模板未配置任何服务商模板 ID 时填充，
     * 避免升级后验证码短信因「模板未配置短信模板ID」而全部失败。
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureVerifyCodeSmsTemplateId(int $shopid): void
    {
        $template = Db::name('message_template')
            ->where('shopid', $shopid)
            ->where('code', 'common.verify_code')
            ->find();
        if (empty($template)) {
            return;
        }

        $channel = Db::name('message_template_channel')
            ->where('shopid', $shopid)
            ->where('template_id', (int)$template['id'])
            ->where('channel', 'sms')
            ->find();
        if (empty($channel)) {
            return;
        }

        $extra = json_decode((string)($channel['extra'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];
        $provider = $extra['provider_template_id'] ?? [];
        $provider = is_array($provider) ? $provider : [];

        // 已配置任一服务商模板 ID，视为管理员已维护，不覆盖
        if (self::hasProviderTemplateId($provider)) {
            return;
        }

        $aliyun = $this->legacySmsTemplateId('SMS_ALIYUN_TEMPLATEID');
        $tencent = $this->legacySmsTemplateId('SMS_TENCENT_TEMPLATEID');
        if ($aliyun === '' && $tencent === '') {
            return;
        }

        $provider['aliyun'] = $aliyun;
        $provider['tencent'] = $tencent;
        $extra['provider_template_id'] = $provider;

        Db::name('message_template_channel')->where('id', (int)$channel['id'])->update([
            'extra'       => json_encode($extra, JSON_UNESCAPED_UNICODE),
            'update_time' => time(),
        ]);
    }

    /**
     * 补齐验证码模板的变量声明与默认值（存量店铺幂等）
     *
     * 只补缺失项，不覆盖管理员已设置的值，避免后台改过文案后渲染报「模板变量未声明」。
     *
     * @param int $shopid
     * @return void
     */
    protected function ensureVerifyCodeVariables(int $shopid): void
    {
        $template = Db::name('message_template')
            ->where('shopid', $shopid)
            ->where('code', 'common.verify_code')
            ->find();
        if (empty($template)) {
            return;
        }

        $variables = json_decode((string)($template['variables'] ?? ''), true);
        $variables = is_array($variables) ? array_values($variables) : [];
        $defaultVars = json_decode((string)($template['default_vars'] ?? ''), true);
        $defaultVars = is_array($defaultVars) ? $defaultVars : [];

        $changed = false;
        foreach (['code', 'site_name', 'expire_minutes'] as $variable) {
            if (!in_array($variable, $variables, true)) {
                $variables[] = $variable;
                $changed = true;
            }
        }

        $defaults = [
            'site_name'      => $this->defaultSiteName(),
            'expire_minutes' => '15',
        ];
        foreach ($defaults as $name => $value) {
            if (trim((string)($defaultVars[$name] ?? '')) === '') {
                $defaultVars[$name] = $value;
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        Db::name('message_template')->where('id', (int)$template['id'])->update([
            'variables'    => json_encode($variables, JSON_UNESCAPED_UNICODE),
            'default_vars' => json_encode($defaultVars, JSON_UNESCAPED_UNICODE),
            'update_time'  => time(),
        ]);
    }

    /**
     * 短信服务商模板 ID 是否已配置
     *
     * 任一服务商填了非空值即视为管理员已维护，补齐逻辑与发送前校验共用同一口径。
     *
     * @param mixed $provider 服务商模板 ID 集合（通常为 ['aliyun' => '', 'tencent' => '']）
     * @return bool
     */
    public static function hasProviderTemplateId($provider): bool
    {
        if (!is_array($provider)) {
            return false;
        }
        foreach ($provider as $value) {
            if (is_array($value)) {
                continue;
            }
            if (trim((string)$value) !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * 读取旧全局短信模板 ID
     *
     * 旧配置在升级脚本中被置为 status=0，config('extend.*') 读不到，这里按名称直读原值。
     *
     * @param string $name
     * @return string
     */
    protected function legacySmsTemplateId(string $name): string
    {
        try {
            return (string)Db::name('extend_config')->where('name', $name)->value('value');
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * 验证码邮件默认站点名称
     *
     * @return string
     */
    protected function defaultSiteName(): string
    {
        $name = trim((string)config('system.WEB_SITE_NAME', ''));
        return $name !== '' ? $name : '本站';
    }
}
