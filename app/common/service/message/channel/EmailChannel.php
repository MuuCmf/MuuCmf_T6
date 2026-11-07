<?php

namespace app\common\service\message\channel;

use think\facade\Db;
use app\common\service\Mail;

/**
 * 邮件渠道
 *
 * 包装 Mail::sendMailLocal：该方法失败时返回错误字符串而不是抛异常，
 * 必须统一判定为失败并落 error_msg。
 */
class EmailChannel implements ChannelInterface
{
    /** @var ChannelConfigProvider 由 ChannelManager 注入 */
    protected $configProvider;

    public function __construct($configProvider)
    {
        $this->configProvider = $configProvider;
    }

    public function key(): string
    {
        return 'email';
    }

    public function configured(int $shopid): bool
    {
        return $this->configProvider->configured($shopid, 'email');
    }

    public function available(int $shopid, int $uid): bool
    {
        $email = $this->emailOf($uid);
        return $email !== '';
    }

    public function availableBatch(int $shopid, array $uids): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        $map = array_fill_keys($uids, false);
        if (empty($uids)) {
            return $map;
        }
        $rows = Db::name('member')
            ->where('uid', 'in', $uids)
            ->where('status', 1)
            ->field('uid,email')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = !empty($row['email']);
        }
        return $map;
    }

    /**
     * 发送邮件
     *
     * @param array $rendered
     * @param array $context
     * @return ChannelResult
     */
    public function send(array $rendered, array $context): ChannelResult
    {
        $uid = (int)($context['uid'] ?? 0);
        // 邮箱验证码等场景由调用方直接指定收件邮箱，未注册邮箱无需反查用户
        $email = trim((string)($context['email'] ?? ''));
        if ($email === '') {
            $email = $this->emailOf($uid);
        }
        if ($email === '') {
            return ChannelResult::failure('email', 'no_binding', '用户未绑定邮箱');
        }

        $subject = (string)($rendered['title'] ?? '');
        $html = (string)($rendered['content'] ?? '');
        $extra = $rendered['extra'] ?? [];
        $text = (string)($extra['text'] ?? '');

        // 模板未配置邮件内容时不得发出空主题空正文的邮件
        if (trim($subject) === '' && trim($html) === '') {
            return ChannelResult::failure('email', 'template_channel_missing', '邮件模板未配置标题与正文');
        }

        $mailer = new Mail();
        try {
            $result = $mailer->sendMailLocal($email, $subject, $html);
        } catch (\Throwable $e) {
            return ChannelResult::failure('email', 'provider_error', $e->getMessage());
        }

        if ($result === true) {
            // 留存 SMTP 队列 ID（如 queued as xxx），投递异常时可据此在服务商后台追查
            $reply = $mailer->getLastReply();
            $thirdMsgId = preg_match('/queued as ([^\s,]+)/i', $reply, $m) ? $m[1] : $reply;

            return ChannelResult::success('email', [
                'title'         => $subject,
                'contentDigest' => 'md5:' . md5($html . $text),
                'thirdMsgId'    => $thirdMsgId,
            ]);
        }

        return ChannelResult::failure('email', 'provider_error', is_string($result) ? $result : '邮件发送失败');
    }

    /**
     * 读取用户邮箱
     *
     * @param int $uid
     * @return string
     */
    protected function emailOf(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        $email = Db::name('member')->where('uid', $uid)->value('email');
        return empty($email) ? '' : (string)$email;
    }
}
