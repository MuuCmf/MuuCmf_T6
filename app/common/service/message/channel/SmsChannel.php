<?php

namespace app\common\service\message\channel;

use think\facade\Db;
use AlibabaCloud\Client\AlibabaCloud;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Sms\V20210111\Models\SendSmsRequest;
use TencentCloud\Sms\V20210111\SmsClient;

/**
 * 短信渠道
 *
 * 模板 ID 按服务商从 message_template_channel.extra.provider_template_id 取，
 * 切换服务商不需要改代码；变量映射来自 var_map（服务商变量 => 模板变量）。
 */
class SmsChannel implements ChannelInterface
{
    /** @var ChannelConfigProvider */
    protected $configProvider;

    public function __construct($configProvider)
    {
        $this->configProvider = $configProvider;
    }

    public function key(): string
    {
        return 'sms';
    }

    public function configured(int $shopid): bool
    {
        return $this->configProvider->configured($shopid, 'sms');
    }

    public function available(int $shopid, int $uid): bool
    {
        return $this->mobileOf($uid) !== '';
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
            ->field('uid,mobile')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = !empty($row['mobile']);
        }
        return $map;
    }

    /**
     * 发送短信
     *
     * @param array $rendered
     * @param array $context
     * @return ChannelResult
     */
    public function send(array $rendered, array $context): ChannelResult
    {
        $shopid = (int)($context['shopid'] ?? 0);
        $uid = (int)($context['uid'] ?? 0);
        $mobile = (string)($context['mobile'] ?? '') ?: $this->mobileOf($uid);
        if ($mobile === '') {
            return ChannelResult::failure('sms', 'no_binding', '用户未绑定手机号');
        }

        $config = $this->configProvider->get($shopid);
        $driver = (string)($config['sms']['driver'] ?? 'aliyun');
        $extra = $rendered['extra'] ?? [];

        $templateIds = $extra['provider_template_id'] ?? [];
        if (!is_array($templateIds)) {
            $templateIds = [];
        }
        $templateId = (string)($templateIds[$driver] ?? '');
        if ($templateId === '') {
            $templateId = (string)($rendered['third_template_id'] ?? '');
        }
        // 模板 ID 只认模板级配置，不再回退扩展配置里的全局「短信模板」
        if ($templateId === '') {
            return ChannelResult::failure('sms', 'channel_not_configured', '模板未配置短信模板ID');
        }

        $sign = (string)($extra['sign_name'] ?? '');
        if ($sign === '') {
            $sign = (string)($config['sms'][$driver]['sign'] ?? '');
        }

        $params = $this->buildParams($rendered);
        if (isset($params['__error'])) {
            return ChannelResult::failure('sms', $params['__error'], $params['__message'] ?? '');
        }

        if ($driver === 'tencent') {
            return $this->sendByTencent($config['sms']['tencent'], $mobile, $templateId, $sign, $params);
        }

        return $this->sendByAliyun($config['sms']['aliyun'], $mobile, $templateId, $sign, $params);
    }

    /**
     * 构建服务商变量
     *
     * @param array $rendered
     * @return array
     */
    protected function buildParams(array $rendered): array
    {
        $vars = $rendered['variables'] ?? [];
        $varMap = $rendered['var_map'] ?? [];
        if (!is_array($varMap) || empty($varMap)) {
            $varMap = [];
            foreach (array_keys((array)$vars) as $name) {
                $varMap[$name] = $name;
            }
        }

        $params = [];
        foreach ($varMap as $providerVar => $tplVar) {
            $value = (string)($vars[$tplVar] ?? '');
            if ($value === '') {
                return ['__error' => 'template_var_missing', '__message' => '短信变量为空：' . $tplVar];
            }
            if (mb_strlen($value) > 20) {
                return ['__error' => 'var_too_long', '__message' => '短信变量超长：' . $tplVar];
            }
            $params[(string)$providerVar] = $value;
        }

        return $params;
    }

    /**
     * 阿里云发送
     *
     * @param array  $config
     * @param string $mobile
     * @param string $templateId
     * @param string $sign
     * @param array  $params
     * @return ChannelResult
     */
    protected function sendByAliyun(array $config, string $mobile, string $templateId, string $sign, array $params): ChannelResult
    {
        if (empty($config['access_key_id']) || empty($config['access_key_secret'])) {
            return ChannelResult::failure('sms', 'channel_not_configured', '未配置阿里云短信凭证');
        }

        try {
            AlibabaCloud::accessKeyClient($config['access_key_id'], $config['access_key_secret'])
                ->regionId($config['region'] ?: 'cn-beijing')
                ->asDefaultClient();

            $response = AlibabaCloud::rpc()
                ->product('Dysmsapi')
                ->version('2017-05-25')
                ->action('SendSms')
                ->method('POST')
                ->host('dysmsapi.aliyuncs.com')
                ->options([
                    'query' => [
                        'PhoneNumbers'  => $mobile,
                        'SignName'      => $sign,
                        'TemplateParam' => json_encode($params, JSON_UNESCAPED_UNICODE),
                        'TemplateCode'  => $templateId,
                    ],
                ])
                ->request();

            $raw = $response->toArray();
        } catch (\Throwable $e) {
            return ChannelResult::failure('sms', $this->classifyError($e->getMessage()), $e->getMessage(), [
                'raw' => $e->getMessage(),
            ]);
        }

        $code = (string)($raw['Code'] ?? '');
        if ($code === 'OK') {
            return ChannelResult::success('sms', [
                'raw'          => $raw,
                'thirdMsgId'   => (string)($raw['RequestId'] ?? ''),
                'contentDigest' => 'md5:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE)),
            ]);
        }

        $message = (string)($raw['Message'] ?? '短信发送失败');

        return ChannelResult::failure('sms', $this->classifyError($code . ' ' . $message), $message, [
            'raw' => $raw,
        ]);
    }

    /**
     * 腾讯云发送
     *
     * @param array  $config
     * @param string $mobile
     * @param string $templateId
     * @param string $sign
     * @param array  $params
     * @return ChannelResult
     */
    protected function sendByTencent(array $config, string $mobile, string $templateId, string $sign, array $params): ChannelResult
    {
        if (empty($config['secret_id']) || empty($config['secret_key']) || empty($config['sdk_app_id'])) {
            return ChannelResult::failure('sms', 'channel_not_configured', '未配置腾讯云短信凭证');
        }

        try {
            $cred = new Credential($config['secret_id'], $config['secret_key']);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint('sms.tencentcloudapi.com');
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new SmsClient($cred, $config['region'] ?: 'ap-beijing', $clientProfile);

            $req = new SendSmsRequest();
            $req->fromJsonString(json_encode([
                'PhoneNumberSet'   => [$mobile],
                'SmsSdkAppId'      => $config['sdk_app_id'],
                'SignName'         => $sign,
                'TemplateId'       => $templateId,
                'TemplateParamSet' => array_values($params),
            ], JSON_UNESCAPED_UNICODE));

            $resp = json_decode($client->SendSms($req)->toJsonString(), true);
        } catch (\Throwable $e) {
            return ChannelResult::failure('sms', $this->classifyError($e->getMessage()), $e->getMessage(), [
                'raw' => $e->getMessage(),
            ]);
        }

        $status = $resp['SendStatusSet'][0] ?? [];
        $code = (string)($status['Code'] ?? '');
        if ($code === 'Ok') {
            return ChannelResult::success('sms', [
                'raw'          => $resp,
                'thirdMsgId'   => (string)($status['SerialNo'] ?? ''),
                'contentDigest' => 'md5:' . md5(json_encode($params, JSON_UNESCAPED_UNICODE)),
            ]);
        }

        $message = (string)($status['Message'] ?? ($resp['Response']['Error']['Message'] ?? '短信发送失败'));

        return ChannelResult::failure('sms', $this->classifyError($code . ' ' . $message), $message, [
            'raw' => $resp,
        ]);
    }

    /**
     * 错误码归一
     *
     * @param string $message
     * @return string
     */
    protected function classifyError(string $message): string
    {
        $message = strtolower($message);
        if (strpos($message, 'business_limit_control') !== false || strpos($message, 'limitexceeded') !== false) {
            return 'provider_rate_limit';
        }
        return 'provider_error';
    }

    /**
     * 读取用户手机号
     *
     * @param int $uid
     * @return string
     */
    protected function mobileOf(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        $mobile = Db::name('member')->where('uid', $uid)->value('mobile');
        return empty($mobile) ? '' : (string)$mobile;
    }
}
