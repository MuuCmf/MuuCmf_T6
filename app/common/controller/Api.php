<?php
namespace app\common\controller;

use think\facade\Config;

class Api extends Base
{
    public function __construct()
    {
        parent::__construct();
        $this->initSiteStatus();
    }

    /**
     * 初始化站点状态
     */
    protected function initSiteStatus()
    {
        // 判断站点是否关闭
        $app = strtolower(App('http')->getName());
        $controller = strtolower(request()->controller());
        $action = strtolower(request()->action());
        // 放行管理后台、用户中心以及 API 登录、用户信息接口
        $allow = in_array($app, ['ucenter', 'admin']);
        if ($app == 'api' && $controller == 'member' && in_array($action, ['login', 'userinfo'])) {
            $allow = true;
        }
        if (!$allow) {
            if (!Config::get('system.SITE_CLOSE')) {
                $type = (request()->isJson() || request()->isAjax()) ? 'json' : 'html';
                $result = [
                    'code' => 0,
                    'msg' => '站点临时关闭，请稍后访问',
                ];
                if ($type == 'html') {
                    $response = view(Config::get('app.dispatch_error_tmpl'), $result);
                } else if ($type == 'json') {
                    $response = json($result);
                }
                throw new \think\exception\HttpResponseException($response);
            }
        }
    }
}