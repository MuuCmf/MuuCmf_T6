<?php

declare(strict_types=1);

namespace app\ucenter\controller;

use think\facade\View;
use app\common\model\Module;
use app\common\model\Vip as VipModel;
use app\common\logic\Vip as VipLogic;
use app\common\model\VipCard as VipCardModel;
use app\common\logic\VipCard as VipCardLogic;

class Vip extends Base
{
    protected $VipModel;
    protected $VipLogic;
    protected $VipCardModel;
    protected $VipCardLogic;

    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->VipModel = new VipModel();
        $this->VipLogic = new VipLogic();
        $this->VipCardModel = new VipCardModel();
        $this->VipCardLogic = new VipCardLogic();
    }

    /**
     * VIP卡项列表
     * @return [type] [description]
     */
    public function lists()
    {
        $uid = get_uid();
        $app = input('app', '', 'text');
        $rows = input('rows', 20, 'intval');
        // rows限制在1-100之间
        $rows = min($rows, 100);
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        // 拼接排序字段
        $order =  $order_field . ' ' . $order_type;
        $fields = '*';

        // 初始化查询条件
        $map = [];
        if (!empty($app)) {
            $map[] = ['category_ids', 'like', '%' . $app . '%'];
        }

        $map[] = ['status', '=', 1];

        $lists = $this->VipCardModel->getListByPage($map, $order, $fields, $rows);
        $pager = $lists->render();
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->VipCardLogic->formatData($val);
            //查询用户是否已拥有会员类型数据
            $have_card = $this->VipModel->getVipByCardId($this->shopid, $uid, $val['id']);
            if (!empty($have_card)) {
                $have_card = $this->VipLogic->formatData($have_card);
                $val['have_card'] = $have_card;
            }
        }
        unset($val);

        View::assign('pager', $pager);
        View::assign('lists', $lists);

        // 设置页面TITLE
        $this->setTitle('VIP卡项');
        View::assign('tab', 'vip');

        // 输出模板
        return View::fetch();
    }

    /**
     * 卡项详情
     */
    public function detail()
    {
        $uid = get_uid();
        $id = input('id', 0, 'intval');
        if (empty($id)) return $this->error('参数错误');

        $data = $this->VipCardModel->getDataById($id);
        if (empty($data)) return $this->error('参数错误');

        $data = $this->VipCardLogic->formatData($data);
        //查询用户是否已拥有会员类型数据
        $have_card = $this->VipModel->getVipByCardId($this->shopid, $uid, $id);
        if (!empty($have_card)) {
            $have_card = $this->VipLogic->formatData($have_card);
            $data['have_card'] = $have_card;
        }
        View::assign('data', $data);

        // 查询是否有兑换码
        $convert_is_setup = (new Module())->checkInstalled('convert');
        $convert_data = [];
        if ($convert_is_setup == true) {
            $class_path = 'app\\convert\\model\\ConvertMcard';
            $KcardModel = new $class_path;
            $convert_data = $KcardModel->where([
                ['shopid', '=', $this->shopid],
                ['info_id', '=', $id],
                ['info_type', '=', 'vipcard'],
                ['status', '=', 1]
            ])->find();
        }
        View::assign('convert_data', $convert_data);

        // 输出模板
        $this->setTitle($data['title']);
        return View::fetch();
    }

    /**
     * 下单
     */
    public function create()
    {
        $app = input('app', '', 'text');
        $info_id = input('info_id', 0, 'intval');
        View::assign('info_id', $info_id);
        $info_type = input('info_type', '', 'text');
        View::assign('info_type', $info_type);
        $cycle = input('cycle', 'month', 'text');
        // 周期白名单，避免非法键取不到价格
        $allow_cycles = ['month', 'quarter', 'year', 'year_two', 'year_three', 'year_five', 'forever'];
        if (!in_array($cycle, $allow_cycles, true)) {
            $cycle = 'month';
        }
        View::assign('cycle', $cycle);

        $data = $this->VipCardModel->getDataById($info_id);
        if (empty($data)) return $this->error('参数错误');

        $data = $this->VipCardLogic->formatData($data);
        View::assign('data', $data);

        // 支付价格
        $price = $data[$cycle . '_price'];
        View::assign('price', $price);

        // 获取支付是否启用
        $weixin_pay = config('extend.WX_PAY_MCH_ID');
        View::assign('weixin_pay', $weixin_pay);

        return View::fetch();
    }
}
