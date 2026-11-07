<?php

namespace app\api\controller;

use app\common\model\Member as MemberModel;
use app\common\model\Verify as VerifyModel;
use app\common\controller\Common;
use think\facade\Log;

/**
 * 验证码接口
 */
class Verify extends Common
{
    protected $MemberModel;
    protected $VerifyModel;

    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->MemberModel = new MemberModel();
        $this->VerifyModel = new VerifyModel();
    }
    
    /**
     * 处理验证码发送成功后的逻辑
     * @param string $account 账号
     * @param string $type 类型
     * @param string $ip IP地址
     * @param string $time 当前时间
     * @param string $log_msg 日志消息
     * @return array 成功响应
     */
    private function handleVerifySuccess($account, $type, $ip, $time, $log_msg)
    {
        // 更新账号频率限制计数
        $account_key = 'verify_account_' . md5($account . $type);
        $account_count = cache($account_key);
        if (!$account_count) {
            cache($account_key, 1, 3600); // 初始化为1，有效期1小时
        } else {
            cache($account_key, $account_count + 1, 3600); // 增加计数，重置有效期
        }
        // 使用更安全的session键名
        $session_key = 'verify_time_' . md5($ip . $account . $type);
        session($session_key, $time);
        // 记录日志
        Log::record($log_msg, 'info');
        return $this->success('验证码发送成功');
    }

    /**
     * sendVerify 发送验证码
     */
    public function send()
    {
        $account = input('post.account', '', 'text');
        $type = input('post.type', '', 'text');
        if(empty($type)){
            return $this->error('缺少参数');
        }
        $type = $type == 'mobile' ? 'mobile' : 'email';
        $type_str = $type == 'mobile' ? '手机号' : 'email';
        if (empty($account)) {
            return $this->error($type_str . '不能为空');
        }
        // 判断格式类型
        $check_email = preg_match("/[a-z0-9_\-\.]+@([a-z0-9_\-]+?\.)+[a-z]{2,3}/i", $account);
        $check_mobile = preg_match("/^\d{6,15}$/", $account);
        if ($type == 'email' && !$check_email) {
            return $this->error('邮箱格式错误');
        }
        if ($type == 'mobile' && !$check_mobile) {
            return $this->error('手机格式错误');
        }

        $time = time();
        
        // 基于IP地址的频率限制
        $ip = request()->ip();
        $ip_key = 'verify_ip_' . $ip;
        $ip_count = cache($ip_key);
        if ($ip_count && $ip_count >= 5) { // 同一IP每分钟最多5次请求
            // 当请求频率较高时，要求验证图形验证码
            $captcha = input('post.send_verify_captcha', '', 'trim');
            if (empty($captcha)) {
                return $this->error('请输入图形验证码');
            }
            if (!captcha_check($captcha)) {
                return $this->error('图形验证码错误');
            }
        }
        if ($ip_count && $ip_count >= 10) { // 同一IP每分钟最多10次请求
            Log::record("Verify API: Too many requests from IP - IP: {$ip}, Count: {$ip_count}, Account: {$account}, Type: {$type}", 'warning');
            return $this->error('请求过于频繁，请稍后再试');
        }
        if (!$ip_count) {
            cache($ip_key, 1, 60); // 初始化为1，有效期60秒
        } else {
            cache($ip_key, $ip_count + 1, 60); // 增加计数，重置有效期
        }
        
        // 基于手机号/邮箱的频率限制
        $account_key = 'verify_account_' . md5($account . $type);
        $account_count = cache($account_key);
        if ($account_count && $account_count >= 5) { // 同一账号每小时最多5次请求
            Log::record("Verify API: Too many requests for account - Account: {$account}, Type: {$type}, Count: {$account_count}, IP: {$ip}", 'warning');
            return $this->error('该账号请求过于频繁，请稍后再试');
        }
        
        //验证码再次发送的时间限制，默认60秒
        $resend_time =  config('extend.SMS_RESEND');
        // 使用更安全的session键名，并加入IP地址验证
        $session_key = 'verify_time_' . md5($ip . $account . $type);
        if ($time <= session($session_key) + $resend_time) {
            Log::record("Verify API: Resend too soon - Account: {$account}, Type: {$type}, IP: {$ip}", 'info');
            return $this->error('请' . ($resend_time - ($time - session($session_key))) . '秒后再发');
        }
        
        // 写入验证码
        $verify = $this->VerifyModel->addVerify($account, $type);
        if (!$verify) {
            return $this->error('验证码写入失败');
        }

        // 发送验证码
        switch ($type) {
            case 'mobile':
                $res = $this->VerifyModel->sendSms($account, $verify);
                // 消息中心已抹平服务商差异，统一按 Message 判断，无需再区分阿里云/腾讯云
                if (is_array($res) && ($res['Message'] ?? '') === 'OK') {
                    $log_msg = "Verify API: SMS sent successfully - Account: {$account}, Type: {$type}, IP: {$ip}";
                    return $this->handleVerifySuccess($account, $type, $ip, $time, $log_msg);
                }
                $error_msg = is_array($res) ? ((string)($res['Message'] ?? '') ?: '短信发送失败') : (string)$res;
                Log::record("Verify API: SMS sent failed - Account: {$account}, Type: {$type}, IP: {$ip}, Error: {$error_msg}", 'error');
                return $this->error($error_msg);
            case 'email':
                $res = $this->VerifyModel->sendEmail($account, $verify);
                if (is_array($res) && !empty($res['success'])) {
                    $log_msg = "Verify API: Email sent successfully - Account: {$account}, Type: {$type}, IP: {$ip}";
                    return $this->handleVerifySuccess($account, $type, $ip, $time, $log_msg);
                }
                $error_msg = is_array($res) ? ((string)($res['error_msg'] ?? '') ?: '邮件发送失败') : '邮件发送失败';
                Log::record("Verify API: Email sent failed - Account: {$account}, Type: {$type}, IP: {$ip}, Error: {$error_msg}", 'error');
                return $this->error($error_msg);
        }

        return $this->error('不支持的验证码类型');
    }
}
