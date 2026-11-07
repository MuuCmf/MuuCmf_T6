<?php

namespace app\common\model;

use think\Model;

class Verify extends Model
{
    protected $tableName = 'verify';
    protected $autoWriteTimestamp = true;
    // 关闭自动写入update_time字段
    protected $updateTime = false;

    /**
     * 写入验证码随机数
     */
    public function addVerify($account, $type, $uid = 0)
    {
        $uid = $uid ? $uid : is_login();
        if ($type == 'mobile' || $type == 'email') {
            $verify = create_rand(6, 'num');
        } else {
            $verify = create_rand(32);
        }

        $this->where(['account' => $account, 'type' => $type])->delete();
        $data['verify'] = $verify;
        $data['account'] = $account;
        $data['type'] = $type;
        $data['uid'] = $uid;

        $res = $this->save($data);
        if (!$res) {
            return false;
        }
        return $verify;
    }


    /**
     * 检测验证码
     *
     * @param      <type>   $account  The account
     * @param      <type>   $type     The type
     * @param      <type>   $verify   The verify
     * @param      <type>   $uid      The uid
     *
     * @return     boolean  ( description_of_the_return_value )
     */
    public function checkVerify($account, $type, $verify)
    {
        // 验证码有效期为15分钟
        $expire_time = time() - 900;
        
        $data = $this->where(['account' => $account, 'type' => $type, 'verify' => $verify])->find();

        if (!$data) {
            return false;
        }
        
        // 检查验证码是否过期
        if ($data['create_time'] < $expire_time) {
            $this->where(['account' => $account, 'type' => $type])->delete();
            return false;
        }
        
        $this->where(['account' => $account, 'type' => $type])->delete();
        $this->where('create_time', '<=', get_some_day(1))->delete();

        return true;
    }

    /**
     * 发送短信
     *
     * 统一转调消息中心（模板 common.verify_code，安全级别）：
     * 走同一套模板渲染、服务商适配与发送记录。
     *
     * 服务商模板 ID 只来自消息模板的短信渠道配置，不再读取扩展配置里的全局「短信模板」。
     * 返回值保持历史结构（Message / SendStatusSet），兼容既有调用方。
     *
     * @param string $PhoneNumbers 手机号
     * @param string $code         验证码
     * @return array
     */
    public function sendSms($PhoneNumbers, $code)
    {
        try {
            $res = \app\common\service\message\MessageCenter::sendNowToMobile(
                'common.verify_code',
                (string)$PhoneNumbers,
                ['code' => (string)$code],
                ['channels' => ['sms'], 'is_test' => 0]
            );
        } catch (\Throwable $e) {
            trace('[verify] sendSms failed: ' . $e->getMessage(), 'error');
            $res = [
                'success'    => false,
                'error_code' => 'send_failed',
                'error_msg'  => $e->getMessage(),
            ];
        }

        if (!is_array($res)) {
            $res = [
                'success'    => false,
                'error_code' => 'send_failed',
                'error_msg'  => '短信发送失败',
            ];
        }

        // 兼容历史调用方：按服务商响应结构补齐 Message / SendStatusSet
        if (!array_key_exists('Message', $res)) {
            $res['Message'] = !empty($res['success'])
                ? 'OK'
                : ((string)($res['error_msg'] ?? '') ?: '短信发送失败');
        }
        if (!array_key_exists('SendStatusSet', $res)) {
            $res['SendStatusSet'] = [[
                'Code'     => !empty($res['success']) ? 'Ok' : ((string)($res['error_code'] ?? '') ?: 'Failed'),
                'Message'  => $res['Message'],
                'SerialNo' => '',
            ]];
        }

        return $res;
    }

    /**
     * 发送邮箱验证码
     *
     * 统一转调消息中心（模板 common.verify_code，邮件渠道）：
     * 与短信验证码共用模板渲染、发送记录与失败口径。
     *
     * @param string $email 收件邮箱
     * @param string $code  验证码
     * @return array
     */
    public function sendEmail($email, $code)
    {
        try {
            return \app\common\service\message\MessageCenter::sendNowToEmail(
                'common.verify_code',
                (string)$email,
                ['code' => (string)$code],
                ['channels' => ['email'], 'is_test' => 0]
            );
        } catch (\Throwable $e) {
            trace('[verify] sendEmail failed: ' . $e->getMessage(), 'error');
            return [
                'success'    => false,
                'error_code' => 'send_failed',
                'error_msg'  => $e->getMessage(),
            ];
        }
    }
}
