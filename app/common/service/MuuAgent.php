<?php
namespace app\common\service;

use think\facade\Cache;
use think\facade\Log;

/**
 * MuuAgent 中台客户端
 *
 * 管理端接口（callAdmin）：通过 OAuth 2.0 client_credentials 模式调用 /admin/* 接口
 * 业务端接口（callApi）  ：通过 API Key + 透传 UID 调用 /agent、/ai、/kb 等业务接口
 */
class MuuAgent
{
    /**
     * 中台基础地址
     * @var string
     */
    private string $baseUrl;

    /**
     * OAuth Client ID
     * @var string
     */
    private string $clientId;

    /**
     * OAuth Client Secret
     * @var string
     */
    private string $clientSecret;

    /**
     * API Key（对应中台 AppTenant.apiKey）
     * @var string
     */
    private string $apiKey;

    /**
     * 应用标识
     * @var string
     */
    private string $appCode;

    /**
     * 缓存前缀
     * @var string
     */
    private string $cachePrefix;

    /**
     * Access Token 缓存键
     * @var string
     */
    private string $accessTokenKey;

    /**
     * Refresh Token 缓存键
     * @var string
     */
    private string $refreshTokenKey;

    public function __construct()
    {
        // 初始化配置（通过扩展配置获取）
        $this->baseUrl      = rtrim(config('extend.MUUAGENT_BASE_URL'), '/');
        $this->clientId     = config('extend.MUUAGENT_CLIENT_ID') ?? '';
        $this->clientSecret = config('extend.MUUAGENT_CLIENT_SECRET') ?? '';
        $this->apiKey       = config('extend.MUUAGENT_API_KEY') ?? '';
        $this->appCode      = config('extend.MUUAGENT_APP_CODE') ?? 'muucmf_t6';
        $this->cachePrefix  = config('extend.MUUAGENT_CACHE_PREFIX') ?? request()->host() . '_muuagent_';

        $this->accessTokenKey  = $this->cachePrefix . 'access_token';
        $this->refreshTokenKey = $this->cachePrefix . 'refresh_token';
    }

    // ========================================================================
    //  管理端接口（OAuth 认证）
    // ========================================================================

    /**
     * 获取有效的 Access Token
     * @return string Access Token
     * @throws \RuntimeException
     */
    public function getAccessToken(): string
    {
        $cached = Cache::get($this->accessTokenKey);
        if ($cached) {
            return $cached;
        }

        $refreshToken = Cache::get($this->refreshTokenKey);
        if ($refreshToken) {
            try {
                return $this->refreshToken($refreshToken);
            } catch (\Exception $e) {
                Log::warning('MuuAgent Token 刷新失败，将重新获取: ' . $e->getMessage());
            }
        }

        return $this->fetchNewToken();
    }

    /**
     * 调用中台管理端接口（OAuth Token 认证）
     * @param string $method 请求方法 GET/POST/PUT/DELETE
     * @param string $path 接口路径，如 /admin/model
     * @param array $data 请求参数
     * @param array $extraHeaders 额外请求头
     * @return array 响应数据
     * @throws \RuntimeException
     */
    public function callAdmin(string $method, string $path, array $data = [], array $extraHeaders = []): array
    {
        $token = $this->getAccessToken();

        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        return $this->send($method, $path, $data, $headers);
    }

    // ========================================================================
    //  业务端接口（API Key 认证）
    // ========================================================================

    /**
     * 调用中台业务端接口（API Key + 透传 UID 认证）
     * @param string $method 请求方法 GET/POST/PUT/DELETE
     * @param string $path 接口路径，如 /agent/chat
     * @param array $data 请求参数
     * @param string $uid 终端用户 ID（必传，用于标识操作者）
     * @param array $extraHeaders 额外请求头
     * @return array 响应数据
     * @throws \RuntimeException
     */
    public function callApi(string $method, string $path, array $data = [], string $uid = '', array $extraHeaders = []): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('MuuAgent API Key 未配置');
        }

        $headers = array_merge([
            'x-api-key: ' . $this->apiKey,
            'x-app-code: ' . $this->appCode,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        if ($uid) {
            $headers[] = 'x-uid: ' . $uid;
        }

        return $this->send($method, $path, $data, $headers);
    }

    // ========================================================================
    //  底层请求
    // ========================================================================

    /**
     * 发送 HTTP 请求
     * @param string $method 请求方法
     * @param string $path 接口路径
     * @param array $data 请求数据
     * @param array $headers 请求头
     * @return array 响应数据
     * @throws \RuntimeException
     */
    private function send(string $method, string $path, array $data = [], array $headers = []): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . $path;

        $body = $data;

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            $body = [];
        }

        $response = $this->curlRequest($url, $method, $body, $headers);

        $errorMsg = '';
        if (!empty($response['error'])) {
            $errorMsg = $response['error'];
        } elseif (!empty($response['message'])) {
            $errorMsg = $response['message'];
        }

        if ($errorMsg) {
            throw new \RuntimeException('MuuAgent API 调用失败: ' . $errorMsg);
        }

        return $response;
    }

    /**
     * OAuth Token 端点专用 POST 请求
     * @param string $path 接口路径
     * @param array $params 表单参数
     * @param bool $withAuth 是否附加 Authorization
     * @return array 响应数据
     */
    private function httpPost(string $path, array $params, bool $withAuth = true): array
    {
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];

        if ($withAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->getAccessToken();
        }

        return $this->curlRequest(
            $this->baseUrl . $path,
            'POST',
            http_build_query($params),
            $headers
        );
    }

    /**
     * 执行 cURL 请求
     * @param string $url 请求地址
     * @param string $method 请求方法
     * @param array|string $data 请求数据
     * @param array $headers 请求头
     * @return array 响应数据
     */
    private function curlRequest(string $url, string $method = 'GET', $data = [], array $headers = []): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error('MuuAgent HTTP 请求失败: ' . $error, ['url' => $url]);
            return ['error' => $error, 'code' => $httpCode];
        }

        $decoded = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('MuuAgent 响应 JSON 解析失败: ' . json_last_error_msg(), ['response' => $result]);
            return ['error' => '响应格式错误', 'code' => $httpCode];
        }

        return $decoded;
    }

    // ========================================================================
    //  OAuth Token 管理
    // ========================================================================

    /**
     * 通过 client_credentials 获取新 Token
     * @return string Access Token
     * @throws \RuntimeException
     */
    private function fetchNewToken(): string
    {
        $response = $this->httpPost('/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], false);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('获取 MuuAgent Token 失败: ' . ($response['error'] ?? '未知错误'));
        }

        $this->cacheToken($response);

        return $response['access_token'];
    }

    /**
     * 刷新 Token
     * @param string $refreshToken 刷新令牌
     * @return string 新的 Access Token
     * @throws \RuntimeException
     */
    private function refreshToken(string $refreshToken): string
    {
        $response = $this->httpPost('/oauth/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], false);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('刷新 MuuAgent Token 失败: ' . ($response['error'] ?? '未知错误'));
        }

        $this->cacheToken($response);

        return $response['access_token'];
    }

    /**
     * 缓存 Token
     * @param array $response Token 响应
     */
    private function cacheToken(array $response): void
    {
        $expiresIn = $response['expires_in'] ?? 7200;
        Cache::set($this->accessTokenKey, $response['access_token'], $expiresIn - 100);

        if (!empty($response['refresh_token'])) {
            Cache::set($this->refreshTokenKey, $response['refresh_token'], 7 * 86400);
        }
    }

    /**
     * 撤销当前 Access Token
     */
    public function revokeToken(): void
    {
        $token = Cache::get($this->accessTokenKey);
        if ($token) {
            try {
                $this->httpPost('/oauth/revoke', [
                    'token' => $token,
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ]);
            } catch (\Exception $e) {
                Log::warning('MuuAgent Token 撤销失败: ' . $e->getMessage());
            }
        }

        Cache::delete($this->accessTokenKey);
        Cache::delete($this->refreshTokenKey);
    }
}