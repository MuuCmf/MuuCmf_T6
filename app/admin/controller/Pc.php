<?php

namespace app\admin\controller;

use think\facade\Db;
use think\Exception;
use app\common\model\UserNav as UserNavModel;
use app\common\model\Channel as ChannelModel;
use app\common\model\SeoRule as SeoRuleModel;

class Pc extends Admin
{
    protected UserNavModel $userNavModel;
    protected ChannelModel $channelModel;
    protected SeoRuleModel $seoRuleModel;
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();
        $this->channelModel = new ChannelModel();
        $this->seoRuleModel = new SeoRuleModel();
        $this->userNavModel = new UserNavModel();
    }

    /**
     * 顶部通用导航
     */
    public function navbar()
    {

        if (request()->isPost()) {

            $nav = (array)input('post.nav', [], 'trim,text');

            // 启动事务
            Db::startTrans();
            try {
                if (count((array)$nav) > 0) {
                    $this->channelModel->where([
                        'block' => 'navbar',
                    ])->delete();
                    for ($i = 0; $i < count(reset($nav)); $i++) {
                        $data[$i] = [
                            'id' => create_guid(),
                            'block' => 'navbar',
                            'type' => text($nav['type'][$i]),
                            'app' => text($nav['app'][$i]),
                            'title' => html($nav['title'][$i]),
                            'url' => text($nav['url'][$i]),
                            'sort' => intval($i),
                            'target' => empty($nav['target'][$i]) ? 0 : intval($nav['target'][$i]),
                            'status' => 1,
                            'color' => empty($nav['color'][$i]) ?? '',
                            'icon' => empty($nav['icon'][$i]) ?? ''
                        ];
                    }
                    $res = $this->channelModel->insertAll($data);
                    if ($res) {
                        // 提交事务
                        Db::commit();
                        return $this->success('修改成功', $res);
                    }
                } else {
                    throw new Exception('导航至少存在一个。');
                }
            } catch (Exception $e) {
                // 回滚事务
                Db::rollback();
                return $this->error($e->getMessage());
            }
        }

        // 输出类型
        $output = input('output', 'html', 'trim');
        /* 获取频道列表 */
        $map[] = ['status', '=', 1];
        $map[] = ['block', '=', 'navbar'];
        $list = $this->channelModel->where($map)->order('sort asc')->select()->toArray();
        // 返回JSON数据
        return $this->success('获取成功', $list);
    }

    /**
     * 底部快捷导航
     */
    public function footer()
    {
        if (request()->isPost()) {

            $nav = (array)input('post.nav', [], 'trim,text');
            // 启动事务
            Db::startTrans();
            try {
                // 移除现有内容
                $this->channelModel->where([
                    'block' => 'footer',
                ])->delete();
                for ($i = 0; $i < count(reset($nav)); $i++) {
                    $data[$i] = [
                        'id' => create_guid(),
                        'block' => 'footer',
                        'type' => text($nav['type'][$i]),
                        'app' => text($nav['app'][$i]),
                        'title' => html($nav['title'][$i]),
                        'url' => text($nav['url'][$i]),
                        'sort' => intval($i),
                        'target' => empty($nav['target'][$i]) ? 0 : intval($nav['target'][$i]),
                        'status' => 1,
                        'color' => empty($nav['color'][$i]) ?? '',
                        'icon' => empty($nav['icon'][$i]) ?? ''
                    ];
                }

                $res = $this->channelModel->insertAll($data);
                if ($res) {
                    // 提交事务
                    Db::commit();
                    return $this->success('修改成功', $res);
                }
            } catch (Exception $e) {
                // 回滚事务
                Db::rollback();
                return $this->error($e->getMessage());
            }
        }

        /* 获取频道列表 */
        $map[] = ['status', '>', -1];
        $map[] = ['block', '=', 'footer'];
        $list = $this->channelModel->where($map)->order('sort asc')->select()->toArray();
        // 返回JSON数据
        return $this->success('获取成功', $list);
    }


    /**
     * 用户导航
     * @return [type] [description]
     */
    public function user()
    {
        if (request()->isPost()) {
            $nav = (array)input('post.nav', [], 'trim,text');
            // 启动事务
            Db::startTrans();
            try {
                if (count((array)$nav) > 0) {
                    // 清空表
                    Db::execute('TRUNCATE TABLE ' . config('database.connections.mysql.prefix') . 'user_nav');

                    for ($i = 0; $i < count(reset($nav)); $i++) {
                        $data[$i] = array(
                            'type' => text($nav['type'][$i]),
                            'app' => text($nav['app'][$i]),
                            'title' => text($nav['title'][$i]),
                            'url' => text($nav['url'][$i]),
                            'sort' => intval($nav['sort'][$i]),
                            'target' => empty($nav['target'][$i]) ? 0 : intval($nav['target'][$i]),
                            'status' => 1
                        );
                    }

                    $res = $this->userNavModel->insertAll($data);
                    if ($res) {
                        // 提交事务
                        Db::commit();
                        cache(request()->domain() . '_muucmf_user_nav', null);
                        return $this->success('修改成功', $res);
                    }
                }
            } catch (Exception $e) {
                // 回滚事务
                Db::rollback();
                return $this->error($e->getMessage());
            }
        } else {
            /* 获取频道列表 */
            $map[] = ['status', '>', -1];
            $list = Db::name('UserNav')->where($map)->order('sort asc,id asc')->select()->toArray();

            // 返回JSON数据
            return $this->success('获取成功', $list);
        }
    }
}
