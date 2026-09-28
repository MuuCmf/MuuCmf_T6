<?php
namespace app\api\controller;

use app\common\controller\Api;
use think\facade\Cache;

/**
 * API通用控制器
 * 用于提供通用的API接口
 */
class Proxy extends Api
{
    /** 图片代理最大响应字节数（5MB），防止拉取超大文件造成内存压力 */
    const MAX_IMAGE_SIZE = 5242880;
    /** 重定向最大跟随次数 */
    const MAX_REDIRECTS = 3;

    /**
     * 图片代理接口
     * 接收外部图片URL，由服务器获取并返回base64数据
     * 用于解决前端H5跨域访问图片资源的问题
     *
     * @return \think\Response
     */
    public function image()
    {
        try {
            $imageUrl = input('imageUrl', '', 'text');

            // 参数校验
            if (empty($imageUrl)) {
                return $this->error('图片URL不能为空');
            }

            // 安全校验：只允许http/https协议
            if (!preg_match('#^https?://#i', (string)$imageUrl)) {
                return $this->error('不支持的URL协议');
            }

            // SSRF防护：目标主机必须是公网可信地址（含内网段、保留段、数字编码IP拦截）
            if (!$this->isSafeUrl($imageUrl)) {
                return $this->error('该地址不在允许访问范围内');
            }

            // 检查缓存
            $cacheKey = 'proxy_image_' . md5((string)$imageUrl);
            $cachedData = Cache::get($cacheKey);

            if ($cachedData) {
                return $this->success('success', $cachedData);
            }

            // 使用curl获取图片数据（逐跳校验重定向目标）
            $imageData = $this->fetchImageData($imageUrl);

            if (!$imageData) {
                return $this->error('获取图片失败');
            }

            // 转换为base64
            $mimeType = $this->detectMimeType($imageData);
            $base64Data = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

            // 尝试缓存24小时
            Cache::set($cacheKey, $base64Data, 86400);

            return $this->success('success', $base64Data);
        } catch (\Exception $e) {
            // 异常错误
            $this->error('图片代理失败: ' . $e->getMessage());
        }
    }

    /**
     * 使用curl获取图片数据
     * 关闭自动跟随重定向，改为手动逐跳跟随并对每一跳目标做 SSRF 校验
     *
     * @param string $url
     * @return string|false
     */
    private function fetchImageData($url)
    {
        $current = $url;

        for ($i = 0; $i < self::MAX_REDIRECTS; $i++) {
            // 每一跳都做内网校验，防止通过公网302跳转到内网
            if (!$this->isSafeUrl($current)) {
                return false;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $current,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, // 禁止自动跟随，逐跳校验
                CURLOPT_HEADER => true,          // 需要响应头以解析 Location
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_MAXFILESIZE => self::MAX_IMAGE_SIZE, // 响应体超过5MB即失败
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, // 仅允许 http/https
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);

            if ($error !== '') {
                return false;
            }

            // 处理重定向：手动跟随，下一跳继续校验
            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                $headers = substr($response, 0, $headerSize);
                if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
                    $next = trim($m[1]);
                    $next = $this->resolveUrl($current, $next);
                    if ($next === '' || !preg_match('#^https?://#i', $next)) {
                        return false;
                    }
                    $current = $next;
                    continue;
                }
                return false;
            }

            if ($httpCode >= 400) {
                return false;
            }

            // 剥离响应头，返回纯响应体
            $body = substr($response, $headerSize);
            if ($body === '' || strlen($body) > self::MAX_IMAGE_SIZE) {
                return false;
            }
            return $body;
        }

        return false;
    }

    /**
     * 校验 URL 目标主机是否安全（非内网/非保留段）
     *
     * @param string $url
     * @return bool
     */
    private function isSafeUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        // 使用公共函数做内网/保留段/数字编码拦截
        return !is_internal_host($host);
    }

    /**
     * 解析相对重定向地址为绝对地址
     *
     * @param string $base
     * @param string $location
     * @return string
     */
    private function resolveUrl($base, $location)
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        if (!isset($parts['scheme']) || !isset($parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }
        // 相对路径：基于当前路径目录
        $dir = isset($parts['path']) ? rtrim(dirname($parts['path']), '/') : '';
        return $origin . $dir . '/' . $location;
    }

    /**
     * 检测图片MIME类型
     *
     * @param string $data
     * @return string
     */
    private function detectMimeType($data)
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($data);

        if (!$mimeType || strpos($mimeType, 'image/') !== 0) {
            // 默认使用png
            return 'image/png';
        }

        return $mimeType;
    }
}
