<?php

namespace app\common\service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use app\common\service\message\ChannelConfigProvider;

class Mail
{
    /** @var string 上一次发送的 SMTP 响应（含队列 ID，用于投递排查） */
    protected $lastReply = '';

    /**
     * 用常规方式发送邮件。
     */
    public function sendMailLocal($to = '', $subject = '', $body = '', $name = '', $attachment = null)
    {
        $sys_config = $this->getSystmeConfig();
        $from_email = $sys_config['MAIL_SMTP_USER'];
        $from_name = $sys_config['WEB_SITE_NAME'];

        if($sys_config['MAIL_SMTP_SSL']){
            $ssl_value = 'ssl';
        }else{
            $ssl_value = '';
        }
        
        $mail = new PHPMailer(true); //实例化PHPMailer

        try {
            $mail->isSMTP(); // 设定使用SMTP服务
            $mail->SMTPDebug = 0; // 关闭SMTP调试功能// 1 = errors and messages// 2 = messages only
            $mail->SMTPAuth = true; // 启用 SMTP 验证功能
            $mail->SMTPSecure = $ssl_value; // 使用安全协议
            $mail->Host = $sys_config['MAIL_SMTP_HOST']; // SMTP 服务器
            $mail->Port = $sys_config['MAIL_SMTP_PORT']; // SMTP服务器的端口号
            $mail->Username = $sys_config['MAIL_SMTP_USER']; // SMTP服务器用户名
            $mail->Password = $sys_config['MAIL_SMTP_PASS']; // SMTP服务器密码
            $mail->CharSet = 'UTF-8';// 设置发送的邮件的编码
            $mail->From = $from_email;// 设置发件人邮箱地址 同登录账号
            $mail->FromName = $from_name;

            if ($to == '') {
                $to = $sys_config['MAIL_SMTP_CE']; //邮件地址为空时，默认使用后台默认邮件测试地址
            }
            if ($name == '') {
                $name = $sys_config['WEB_SITE_NAME']; //发送者名称为空时，默认使用网站名称
            }
            if ($subject == '') {
                $subject = $sys_config['WEB_SITE_NAME']; //邮件主题为空时，默认使用网站标题
            }
            if ($body == '') {
                $body = $sys_config['WEB_SITE_NAME']; //邮件内容为空时，默认使用网站描述
            }
            $mail->isHTML(true);// 邮件正文是否为html编码 注意此处是一个方法
            $mail->CharSet = 'UTF-8'; //设定邮件编码，默认ISO-8859-1，如果发中文此项必须设置，否则乱码
            $mail->Subject = $subject;
            $mail->MsgHTML($body);    //发送的邮件内容主体
            $mail->addAddress($to, $name);
            if (is_array($attachment)) { // 添加附件
                foreach ($attachment as $file) {
                    is_file($file) && $mail->addAttachment($file);
                }
            }

            $status = $mail->send(); //? true : $mail->ErrorInfo; //返回错误信息

            // 保留 SMTP 最后一次响应（如 250 Data Ok: queued as xxx），供发送记录留存
            $this->lastReply = (string)$mail->getSMTPInstance()->getLastReply();

            if($status) {
                return true;
            }else{
                return $mail->ErrorInfo;
            }
        } catch (Exception $e) {
            return "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    }

    /**
     * 获取上一次发送的 SMTP 响应
     *
     * @return string
     */
    public function getLastReply(): string
    {
        return $this->lastReply;
    }

    /**
     * 获取邮件相关系统配置
     *
     * 复用 ChannelConfigProvider 的系统配置进程内缓存，
     * 避免每封邮件都把 muucmf_config 全表重读一遍。
     *
     * @return array
     */
    public function getSystmeConfig()
    {
        $names = [
            'MAIL_SMTP_HOST',
            'MAIL_SMTP_PORT',
            'MAIL_SMTP_USER',
            'MAIL_SMTP_PASS',
            'MAIL_SMTP_SSL',
            'MAIL_SMTP_CE',
            'WEB_SITE_NAME',
        ];

        $config = [];
        foreach ($names as $name) {
            $config[$name] = ChannelConfigProvider::systemConfig($name, '');
        }

        return $config;
    }
}