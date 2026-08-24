<?php

namespace app\common\controller;

use think\facade\Config;
use think\facade\Db;
use think\facade\View;
use app\common\model\Channel;
use app\common\model\SeoRule;
use app\common\model\Module;
use app\common\logic\Config as ConfigLogic;

/**
 * 前台控制器基类
 */
class Common extends Base
{
    public $muu_config_data;
    public $micro_config_data;
    public $title = '';
    public $keywords = '';
    public $description = '';
    
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        
        // 控制器初始化
        $this->initialize();
    }

    /**
     * 初始化
     */
    public function initialize()
    {
        //管理后台入口
        $this->initAdminEntry();
        //站点状态
        $this->initSiteStatus();
        //访问类型
        $this->initSiteAccessType();
        //获取shopid
        $this->initShopid();
        //获取系统配置
        $this->initMuuConfig();
        //获取micro模块配置
        $this->initMicroConfig();
        //获取公众号配置
        $this->initWechatConfig();
        //获取前端导航菜单
        $this->initNavbar();
        //获取底部导航菜单
        $this->initFooterNav();
        //获取用户菜单
        $this->initUserNav();
        //用户登录、注册
        $this->initRegAndLogin();
        //获取用户基本资料
        $this->initUserBaseInfo();
        //SEO规则
        $this->initSeo();
        //版本号
        $this->initVersion();
    }

    /**
     * 初始化管理后台入口
     */
    protected function initAdminEntry()
    {
        $admin_entry = request()->domain() . '/static/admin/index.html';
        View::assign('admin_entry', $admin_entry);
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
        // 放行管理后台、用户中心以及验证码发送接口
        $allow = in_array($app, ['ucenter', 'admin']);
        if ($app == 'api' && $controller == 'verify' && $action == 'send') {
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
                    $response = view(config('app.dispatch_error_tmpl'), $result);
                } else if ($type == 'json') {
                    $response = json($result);
                }
                throw new \think\exception\HttpResponseException($response);
            }
        }
    }
    
    /**
     * 初始化站点访问权限
     */
    protected function initSiteAccessType()
    {
        if (Config::get('system.SITE_ACCESS_TYPE') == 1) {
            $app = strtolower(app('http')->getName());
            $controller = strtolower(request()->controller());
            if ($app != 'ucenter' && $controller != 'common') {
                $type = (request()->isJson() || request()->isAjax()) ? 'json' : 'html';
                if(!is_login()){
                    if($type == 'json'){
                        return json(['code' => 0, 'data' => 'login', 'msg' => '请先登录']);
                    }
                    if($type == 'html'){
                        $this->redirect(url('ucenter/common/login'));
                    }
                }
            }
        }
    }

    /**
     * 初始化shopid
     */
    protected function initShopid()
    {
        View::assign('shopid', $this->shopid);
    }

    /**
     * 初始化系统配置项
     */
    protected function initMuuConfig()
    {
        $this->muu_config_data = $muu_config_data = (new ConfigLogic())->frontend();
        View::assign('muu_config_data', $muu_config_data);
    }

    /**
     * 初始化micro应用配置项
     */
    protected function initMicroConfig()
    {
        $micro_config_data = [];
        $is_install = (new Module())->checkInstalled('micro');
        if($is_install){
            $namespace = "\\app\\micro\\model\\MicroConfig";
            // 判断是否存在该模型
            if(!class_exists($namespace)){
                return;
            }
            $MicroConfigModel = new $namespace;
            $this->micro_config_data = $micro_config_data = $MicroConfigModel->getConfig($this->shopid);
        }
        View::assign('micro_config_data', $micro_config_data);
    }

    /**
     * 获取公众号配置
     */
    public function initWechatConfig()
    {
        $weixin_config_data = (new \app\common\model\WechatConfig())->where('shopid', $this->shopid)->field('title,desc,cover,qrcode,appid,auth_login')->find();
        if ($weixin_config_data) {
            $weixin_config_data = $weixin_config_data->toArray();
            $weixin_config_data = (new \app\common\logic\OfficialAccount())->formatData($weixin_config_data);
        }
        View::assign('weixin_config_data', $weixin_config_data);
    }

    /**
     * 初始化前端导航
     */
    private function initNavbar()
    {
        $channelModel = new Channel();
        $nav = $channelModel->lists('navbar');
        $nav_count = count($nav);
        View::assign('navbar_count', $nav_count);
        View::assign('navbar', $nav);
    }

    /**
     * 初始化底部导航
     */
    private function initFooterNav()
    {
        $channelModel = new Channel();
        $nav = $channelModel->lists('footer');
        View::assign('footer_nav', $nav);
    }

    /**
     * 初始化用户导航
     */
    private function initUserNav()
    {
        $user_nav = Db::name('UserNav')->order('sort asc')->where('status', '=', 1)->select();
        View::assign('user_nav', $user_nav);
    }

    /**
     * 初始化用户基本信息
     */
    private function initUserBaseInfo()
    {
        $common_header_user = query_user(is_login(), ['nickname', 'avatar']);
        View::assign('common_header_user', $common_header_user);
    }

    /**
     * 初始化用户登陆注册
     */
    private function initRegAndLogin()
    {
        // 用户注册登陆
        $open_quick_login = config('system.OPEN_QUICK_LOGIN');
        View::assign('open_quick_login', $open_quick_login);
        $register_switch = config('system.USER_REG_SWITCH');
        View::assign('register_switch', $register_switch);
        $login_url = url('ucenter/Common/login');
        View::assign('login_url', $login_url);
    }

    private function initVersion()
    {
        //框架版本号
        View::assign('version', $this->version());
    }

    private function initSeo()
    {
        $app = strtolower(app('http')->getName());
        $controller = strtolower(request()->controller());
        $action = strtolower(request()->action());

        // 查询是否有Seo规则
        $rule = (new SeoRule())->getRule($app, $controller, $action);
        if ($rule) {
            $this->setTitle($rule['seo_title']);
            $this->setKeywords($rule['seo_keywords']);
            $this->setDescription($rule['seo_description']);
        }
    }

    public function setTitle($title)
    {
        $this->title = $title;
        View::assign('title', $this->title);
    }

    public function setKeywords($keywords)
    {
        $this->keywords = $keywords;
        View::assign('keywords', $this->keywords);
    }

    public function setDescription($description)
    {
        $this->description = $description;
        View::assign('description', $this->description);
    }
}
