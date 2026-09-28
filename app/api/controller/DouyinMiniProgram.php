<?php
namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Member;
use app\common\model\MemberSync;
use app\common\model\Orders;
use app\common\facade\bytedance\MiniProgram as MiniProgramServer;
use app\common\model\DouyinMpSettle as DouyinMpSettleModel;
use thans\jwt\facade\JWTAuth;
use think\Exception;

/**
 * 抖音小程序接口
 */
class DouyinMiniProgram extends Api
{
    protected $MemberSyncModel;
    protected $MemberModel;
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth' => ['only'=>['bindMobile']],
    ];
    function __construct()
    {
        parent::__construct();
        //初始化用户平台标识模型
        $this->MemberSyncModel = new MemberSync();
        //初始化用户模型
        $this->MemberModel = new Member();
    }

    /**
     * 回调
     */
    public function callback()
    {
        $notify_data = file_get_contents("php://input");
        if(empty($notify_data)){
            return false;
        }
        $content = json_decode($notify_data, true);
        //Log::write($content);
        $sign = MiniProgramServer::handler($content);
        if($sign == $content['msg_signature']){
            $msg = json_decode($content['msg'],true); 
            // 结算分账回调
            if($content['type'] == 'settle'){
                try{
                    // 开发者侧分账单号
                    $cp_settle_no = $msg['cp_settle_no'];
                    // 结算分账成功
                    if($msg['status'] == 'SUCCESS'){
                        $DouyinMpSettleModel = new DouyinMpSettleModel;
                        $OrdersModel = new Orders();
                        // 查询结算数据
                        $has = $DouyinMpSettleModel->where([
                            'settle_no' => $cp_settle_no,
                            'order_no' => $msg['order_id']
                        ])->find();
                        $order_info = $OrdersModel->where([
                            'order_no' => $msg['order_id']
                        ])->find();
                        if($has && $order_info){
                            $DouyinMpSettleModel->edit([
                                'id' => $has['id'],
                                'status' => 1, // 结算完成
                            ]);
                            $OrdersModel->edit([
                                'id' => $order_info['id'],
                                'settle' => 1
                            ]);
                        }
                    }
                } catch (Exception $e){
                    return MiniProgramServer::returnMsg(400, 'business fail');
                }
            }
        }

        return MiniProgramServer::returnMsg(0, 'success');
    }

    /**
     * code 换取用户信息
     * @param $code
     */
    public function code($code, $anonymous_code)
    {
        $result = MiniProgramServer::code2Session($code, $anonymous_code);
        if($result['err_no'] != 0){
            return $this->error($result['err_tips']);
        }
        
        //查询是否注册过
        $map = [];
        $map[] = ['openid','=',$result['data']['openid']];
        $map[] = ['type','=', 'douyin_mp'];
        $user = $this->MemberSyncModel->getDataByMap($map);
        if ($user){
            $user = query_user($user['uid'],['uid','nickname','avatar','email','mobile','realname','sex','qq','score']);
            $this->MemberModel->updateLogin($user['uid']);
            $token = JWTAuth::builder(['uid'=>$user['uid']]);
            $token = 'Bearer ' . $token;
            $res = [
                'token'     => $token
            ];
            return $this->success('success',$res);
        }else{
            return $this->error('error','没有查询到用户信息');
        }

    }

    /**
     * 登录
     */
    public function login()
    {
        $params = input('param.');
        $oauth = MiniProgramServer::code2Session($params['code'], $params['anonymous_code']);
        if($oauth['err_no'] != 0){
            return $this->error($oauth['err_tips']);
        }
        if(!empty($params['userInfo'])){
            $userInfo = json_decode($params['userInfo'], true);
        }

        $data = [
            'unionid'   => $oauth['data']['unionid'] ?? '',
            'openid'    => $oauth['data']['openid'],
            'nickname'  => $userInfo['nickName'],
            'avatar'    => $userInfo['avatarUrl'],
            'sex'       => $userInfo['gender'],
            'shopid'    => $params['shopid'],
            'oauth_type' => 'douyin_mp'
        ];
        $user = $this->MemberModel->oauth($this->shopid, $data);
        if ($user){
            $token = JWTAuth::builder(['uid'=>$user['uid']]);
            $token = 'Bearer ' . $token;
            return $this->success('success',['token'=>$token]);
        }
        return $this->error('需要登录','login');
    }

    /**
     * 获取小程序码：适用于需要的码数量极多，或仅临时使用的业务场景
     * @return mixed
     */
    public function createQrcode()
    {
        //小程序路径（仅允许页面路径字符，长度限制防止滥用第三方接口）
        $path = input('path');
        $path = preg_replace('/[^a-zA-Z0-9_\-\/\.]/', '', (string)$path);
        $path = substr($path, 0, 200);
        if ($path === '') {
            return $this->error('参数错误');
        }

        $result = MiniProgramServer::createQRCode($path);
        Header("Content-type: image/jpeg");//直接输出显示jpg格式图片
        echo $result;
    }

    /**
     * 绑定手机号
     */
    public function bindMobile()
    {
        $uid = request()->uid;
        $code = input('code');
        $iv = input('iv');
        $encrypted = input('encrypted');
        $code_decode = MiniProgramServer::user($code);
        $session_key = $code_decode['session_key'];
        $data = MiniProgramServer::decryptData($session_key,$iv,$encrypted);
        //保存手机号
        $res = $this->MemberModel->edit([
            'uid' => $uid,
            'mobile' => $data['phoneNumber']
        ]);

        if ($res){
            return $this->success('绑定手机号成功');
        }
        return $this->error('绑定手机号失败');
    }
}