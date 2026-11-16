<?php

namespace app\admin\middleware;

use app\common\controller\Base;

class CheckRule extends Base
{
    public function handle($request, \Closure $next)
    {
        $type = ($request->isJson() || $request->isAjax()) ? 'json' : 'html';
        $isRoot = 0;
        $uid = get_uid();
        if($uid == 1){
            $isRoot = 1;
        }

        if (!$uid && $type == 'html') {
            // 跳转至前台登陆页
            $this->redirect(url('ucenter/common/login'));
        }
        if ($isRoot) {
            $request->isRoot = 1;
            return $next($request);
        }

        // 检查权限
        $rule = strtolower(app('http')->getName() . '/' . $request->controller() . '/' . $request->action());
        // 排除权限校验
        $except = [
            'admin/menu/tree',
            'admin/module/all',
            'admin/module/info',
            'admin/module/cv',
            'admin/module/cvlist',
            'admin/config/grouplist',
            'admin/extend/grouplist',
            // S4-10：公共壳层需读取激活状态展示未激活横幅，仅返回是否激活，不含密钥
            'admin/license/index',
        ];

        if (in_array($rule, $except)) {
            return $next($request);
        }
        
        $Auth = new \muucmf\Auth();
        if (!$Auth->check($rule, $uid, 1, 'url')) {
            $referer = isset($request->header()['referer']) ? $request->header()['referer'] : '';
            $type = ($request->isJson() || $request->isAjax()) ? 'json' : 'html';
            $result = ['code' => 401, 'msg'  => '您没有操作权限，请联系管理员！', 'data' => 'Unauthorized', 'url'  => $referer, 'wait' => 3,];
            if ($type == 'html') {
                $response = view(config('app.dispatch_error_tmpl'), $result);
            } else if ($type == 'json') {
                $result['url'] = '';
                $response = json($result, 401);
            }
            throw new \think\exception\HttpResponseException($response);
        }
        
        return $next($request);
    }
}