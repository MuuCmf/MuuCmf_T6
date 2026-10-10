<?php

namespace app\common\controller;

use think\exception\HttpResponseException;
use think\facade\Env;
use think\facade\App;
use think\Response;
use think\facade\View;
use app\common\model\Module;
use app\common\model\Member;

/**
 * 控制器基础类
 */
class Base
{
    private $debug = [];
    public $app_name;
    public $params;
    public $shopid = 0;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct()
    {
        $this->app_name = $this->params['app'] ?? App('http')->getName();
        $this->params = request()->param();
        $this->shopid = $this->params['shopid'] ?? 0;
        //验证应用安装状态
        $this->appInstalled();
        //记住登录
        $this->initRemberLogin();
        //当前应用模块信息（含版本号，供模板 ?v={$module.version|default=''} 静态资源缓存刷新使用）
        View::assign('module', (new Module())->getModule($this->app_name));
    }

    /** 
     * 操作成功跳转的快捷方法
     * @access protected
     * @param  mixed $msg 提示信息
     * @param  string $url 跳转的URL地址
     * @param  mixed $data 返回的数据
     * @param  integer $wait 跳转等待时间
     * @param  array $header 发送的Header信息
     * @return Response
     */
    protected function success($msg = '',  $data = '', $url = '', int $wait = 3, array $header = []): Response
    {
        if (empty($url) && isset($_SERVER["HTTP_REFERER"])) {
            $url = 'refresh';
        } elseif ($url) {
            if (is_object($url)) {
                $url = $url->build();
            } else {
                $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : 'refresh';
            }
        }

        $result = [
            'code' => 200,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'time' => time(),
            'wait' => $wait,
        ];

        if (Env::get('APP_DEBUG') && $this->debug) {
            $result['debug'] = $this->debug;
        }

        $type = (request()->isJson() || request()->isAjax()) ? 'json' : 'html';
        $response = null;
        if ($type == 'html') {
            $response = view(config('app.dispatch_success_tmpl'), $result);
        } else if ($type == 'json') {
            $response = json($result);
        }

        return $response;

        //throw new HttpResponseException($response);
    }

    /**
     * 操作错误跳转的快捷方法
     * @access protected
     * @param  mixed $msg 提示信息
     * @param  string $url 跳转的URL地址
     * @param  mixed $data 返回的数据
     * @param  integer $wait 跳转等待时间
     * @param  array $header 发送的Header信息
     * @return void
     */
    // protected function error($msg = '', $data = '', string $url = '', array $header = [])
    // {
    //     return $this->result(0, $msg, $data, $url);
    // }
    protected function error($msg = '', $data = '', $url = '', int $wait = 3, array $header = []): Response
    {
        $result = [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'time' => time(),
            'wait' => $wait,
        ];

        if (Env::get('APP_DEBUG') && $this->debug) {
            $result['debug'] = $this->debug;
        }

        $type = (request()->isJson() || request()->isAjax()) ? 'json' : 'html';
        $response = null;
        if ($type == 'html') {
            $response = view(config('app.dispatch_error_tmpl'), $result);
        } else if ($type == 'json') {
            $response = json($result);
        }

        return $response;

        //throw new HttpResponseException($response);
    }

    /**
     * 返回封装后的API数据到客户端
     * @access protected
     * @param  integer $code 返回的code
     * @param  mixed $data 要返回的数据
     * @param  mixed $msg 提示信息
     * @param  string $type 返回数据格式
     * @param  array $header 发送的Header信息
     * @return void
     */
    protected function result(int $code = 0, string $msg = '', $data = '', $url = '', int $wait = 3, array $header = []): Response
    {
        $result = [
            'code' => $code,
            'msg' => $msg,
            'data' => $data,
            'url' => $url,
            'wait' => $wait,
            'time' => time(),
        ];

        if (Env::get('APP_DEBUG') && $this->debug) {
            $result['debug'] = $this->debug;
        }

        return json($result);
    }

    /**
     * URL重定向
     * @access protected
     * @param  string $url 跳转的URL表达式
     * @param  integer $code http code
     * @param  array $with 隐式传参
     * @return void
     */
    protected function redirect($url, $code = 302, $with = [])
    {
        $response = Response::create($url, 'redirect');

        $response->code($code)->with($with);

        throw new HttpResponseException($response);
    }

    /**
     * 获取当前的response 输出类型
     * @access protected
     * @return string
     */
    protected function getResponseType()
    {
        return request()->isJson() || request()->isAjax() ? 'json' : 'html';
    }

    protected function initRemberLogin()
    {
        if (empty(get_uid())) {
            (new Member())->rembemberLogin($this->shopid);
        }
    }

    /**
     * 检查应用是否已安装
     * 如果应用未安装，则返回错误信息提示用户先安装应用
     */
    protected function appInstalled()
    {
        if ($this->app_name == 'admin' 
            || $this->app_name == 'api' 
            || $this->app_name == 'common' 
            || $this->app_name == 'channel' 
            || $this->app_name == 'ucenter'
            || $this->app_name == 'index'
        ) {
            return true;
        }

        $m = (new Module())->getModule($this->app_name);
        if (!empty($m) && $m['name'] ==$this->app_name && $m['is_setup'] == 1) {
            return true;
        }

        return $this->error('您访问的应用未安装');
    }

    /**
     * 获取版本号
     * @return     <type>  ( description_of_the_return_value )
     */
    protected function version()
    {
        $path = PUBLIC_PATH . '/../data/version.ini';
        $version = file_get_contents($path);

        return $version;
    }
}
