<?php
declare (strict_types=1);

namespace app\common\middleware;

use Closure;
use thans\jwt\exception\JWTException;
use thans\jwt\exception\TokenBlacklistException;
use thans\jwt\exception\TokenBlacklistGracePeriodException;
use thans\jwt\exception\TokenExpiredException;
use thans\jwt\middleware\JWTAuth;
use think\exception\HttpException;

class CheckAuth extends JWTAuth
{
    protected $auth;

    /**
     * 仅基于 Session 判断登录态（用于 H5/浏览器端回退）
     * 注意：不使用 is_login()，因为 is_login() 对 JWT token 仅做 decode（不查黑名单），
     * 会让被拉黑的 token（用户退出、刷新后的旧 token）绕过鉴权。
     * @return int
     */
    protected function sessionUid()
    {
        $user = session('user_auth');
        if (!empty($user) && session('user_auth_sign') == data_auth_sign($user)) {
            return $user['uid'];
        }
        return 0;
    }

    /**
     * 刷新token
     * @param $request
     * @param Closure $next
     * @return mixed
     * @throws JWTException
     * @throws TokenBlacklistException
     * @throws TokenBlacklistGracePeriodException
     */
    public function handle($request, Closure $next): object
    {
        header('Access-Control-Expose-Headers:Authorization,authorization');//用于暴露response中的token，h5因w3c规范导致获取不到
        $type = ($request->isJson() || $request->isAjax()) ? 'json' : 'html';
        try {
            $payload = $this->auth->auth();
            // 可以获取payload里自定义的字段，比如uid
            $uid = $payload['uid'];
        } catch (TokenExpiredException $e) { // 捕获token过期
            // 尝试刷新token，会将旧token加入黑名单
            try {
                $this->auth->setRefresh();
                $token = $this->auth->refresh();
                $payload = $this->auth->auth(false);
                $uid = $payload['uid'];
            } catch (TokenBlacklistGracePeriodException $e) {
                $payload = $this->auth->auth(false);
                $uid = $payload['uid'];
            } catch (JWTException $exception) {
                // 如果捕获到此异常，即代表 refresh 也过期了，用户无法刷新令牌，需要重新登录。
                // 浏览器端基于session部分判断（注意：不能用 is_login()，其 JWT 解码不查黑名单，会绕过黑名单）
                if($sessionUid = $this->sessionUid()){
                    $uid = $sessionUid;
                }else{
                    if($type == 'json'){
                        return json(['code' => 0, 'data' => 'login', 'msg' => $exception->getMessage()]);
                    }
                    if($type == 'html'){
                        return redirect('/ucenter/common/login');
                    }
                }
            }
        } catch (TokenBlacklistGracePeriodException $e) { 
            // 捕获黑名单宽限期
            $payload = $this->auth->auth(false);
            $uid = $payload['uid'];
        } catch (TokenBlacklistException $exception) { 
            // 捕获黑名单，退出登录或者已经自动刷新，当前token就会被拉黑
            // 浏览器端基于session部分判断
            if(sessionUid()){
                $uid = sessionUid();
            }else{
                if($type == 'json'){
                    return json(['code' => 0, 'data' => 'login', 'msg' => $exception->getMessage()]);
                }
                if($type == 'html'){
                    return redirect('/ucenter/common/login');
                }
            }
        } catch (JWTException $exception) {
            // 如果捕获到此异常，即代表 refresh 也过期了，用户无法刷新令牌，需要重新登录。
            if(sessionUid()){
                $uid = sessionUid();
            }else{
                if($type == 'json'){
                    return json(['code' => 0, 'data' => 'login', 'msg' => $exception->getMessage()]);
                }
                if($type == 'html'){
                    return redirect('/ucenter/common/login');
                }
            }
        }
        $request->uid = $uid;
        $response = $next($request);

        // 如果有新的token，则在响应头返回（前端判断一下响应中是否有 token，如果有就直接使用此 token 替换掉本地的 token，以此达到无痛刷新token效果）
        if (isset($token)) {
            $this->setAuthentication($response, $token);
        }
        
        return $response;
    }
}