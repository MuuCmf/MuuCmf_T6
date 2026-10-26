<?php
namespace app\api\controller;

use app\common\controller\Api;

class Qrcode extends Api
{
    /**
     * 生成二维码 输出图片
     *
     * 注意：PHP 在解析 query string（$_GET / ThinkPHP param）时已经对参数自动 urldecode 一次，
     * 此处【不可再次 urldecode】。否则形如 name=%E6%9D%A8（中文姓名的百分号编码）的内容会被二次
     * 解码还原成原始 UTF-8 字节，URL 中出现非 ASCII 字符，FILTER_VALIDATE_URL 校验必然失败，
     * 导致接口返回错误页而非二维码图片（证书查询二维码加载失败即由此引起）。
     */
    public function create($url){
        $url = trim((string)$url);

        // 检查URL是否为有效格式
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return $this->error('无效的URL格式');
        }

        // 解析URL检查协议和域名（可选：添加白名单验证）
        // $parsedUrl = parse_url($url);

        // // 确保URL有协议且是http或https
        // if (!isset($parsedUrl['scheme']) || !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'])) {
        //     return $this->error('URL必须使用http或https协议');
        // }

        // 可选：添加域名白名单验证
        // $allowedDomains = ['example.com', 'your-domain.com'];
        // if (!isset($parsedUrl['host']) || !in_array($parsedUrl['host'], $allowedDomains)) {
        //     return $this->error('不允许的域名');
        // }

        ob_clean();//这个一定要加上，清除缓冲区
        // 图片输出接口兜底：生成期间仅保留致命错误，抑制 Warning/Deprecated/Notice 文本，
        // 防止 display_errors=On 环境下任何错误文本混入 PNG 二进制流导致图片损坏
        $errLevel = error_reporting(E_ERROR | E_PARSE);
        $qrcode = qrcode($url,false,false,false,'8','L',2,false);
        error_reporting($errLevel);
        echo $qrcode;exit();
    }
}
