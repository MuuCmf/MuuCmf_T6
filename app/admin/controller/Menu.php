<?php

namespace app\admin\controller;

use app\common\model\AuthRule;
use app\common\model\Menu as MenuModel;
use app\common\model\Module as ModuleModel;

/**
 * 后台管理菜单控制器
 */
class Menu extends Admin
{
    protected MenuModel $MenuModel;
    protected ModuleModel $ModuleModel;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct()
    {
        parent::__construct();

        $this->MenuModel = new MenuModel();
        $this->ModuleModel = new ModuleModel();
    }

    /**
     * 后台权限菜单列表
     * @return array
     */
    public function list()
    {
        $title = input('title', '', 'text');

        $list_map = [];
        if (!empty($title)) {
            $list_map[] = ['title', 'like', '%' . $title . '%'];
        }
        $list_map[] = ['type', '=', '0'];
        $list_map[] = ['module', '=', 'admin'];

        $result_list = $this->MenuModel->where($list_map)->order('sort asc')->select();
        if (!empty($result_list)) {
            $result_list = $result_list->toArray();
        }
        foreach ($result_list as &$val) {
            $val = $this->MenuModel->handle($val);
        }
        unset($val);

        // 转树结构
        $list = list_to_tree($result_list, 'id', 'pid', '_child', '0');

        return $this->success('success', $list);
    }

    /**
     * 获取分组后的权限管理菜单树
     */
    public function tree()
    {
        $app = input('app', '', 'text');
        // 获取主菜单
        if (!empty($app)) {
            $where[] = ['module', '=', $app];
        }
        $where[] = ['pid', '=', '0'];
        $main_menu = $this->MenuModel->where($where)->field('id,pid,title,url,icon,tip,type,module')->order('sort', 'asc')->select()->toArray();

        foreach ($main_menu as $key => $item) {

            if (!is_array($item) || empty($item['title']) || empty($item['url'])) {
                return $this->error('控制器基类{$menus}属性元素配置有误');
            }

            // 判断主菜单权限
            if (!$this->isRoot && !$this->checkRule($item['url'], get_uid(), AuthRule::RULE_URL, null)) {
                unset($main_menu[$key]);
                continue; //继续循环
            }

            // 获取当前主菜单的分组
            $groups = $this->MenuModel->where('pid', $item['id'])->order('sort asc')->column('group');
            $groups = array_unique($groups);
            //获取二级分类的合法url
            $map_2_url = [];
            $map_2_url['pid'] = $item['id'];
            $map_2_url['hide'] = 0;
            $urls_2 = $this->MenuModel->where($map_2_url)->order('sort asc')->column('url');
            // 检测菜单权限
            if (!$this->isRoot) {
                $to_check_urls_2 = [];
                foreach ($urls_2 as $url) {
                    if ($this->checkRule($url, get_uid())) {
                        $to_check_urls_2[] = $url;
                    }
                }
            }

            // 按照分组生成子菜单树
            foreach ($groups as $group) {
                $map_2_level = [];
                if (isset($to_check_urls_2)) {
                    if (empty($to_check_urls_2)) {
                        // 没有任何权限
                        continue;
                    } else {
                        $map_2_level[] = ['url', 'in', $to_check_urls_2];
                    }
                }
                $map_2_level[] = ['group', '=', $group];
                $map_2_level[] = ['pid', '=', $item['id']];
                $map_2_level[] = ['hide', '=', 0];
                $menu_2_list = $this->MenuModel->where($map_2_level)->field('id,pid,title,url,icon,tip,type,module')->order('sort asc')->select()->toArray();

                // 该分组下没有可见菜单（例如只包含隐藏权限节点）时跳过，避免使用未初始化的 $menus
                if (empty($menu_2_list)) {
                    continue;
                }

                $menus = [];
                $menus['group'] = $group;
                $menus['lists'] = $menu_2_list;

                // 按照三级分类生成子菜单树
                foreach ($menus['lists'] as $k2 => $v2) {
                    $map_3_level = [];
                    if (isset($to_check_urls_3)) {
                        if (empty($to_check_urls_3)) {
                            // 没有任何权限
                            continue;
                        } else {
                            $map_3_level[] = ['url', 'in', $to_check_urls_3];
                        }
                    }
                    $map_3_level[] = ['pid', '=', $v2['id']];
                    $map_3_level[] = ['hide', '=', 0];
                    $menu_3_list = $this->MenuModel->where($map_3_level)->field('id,pid,title,url,icon,tip,type,module')->order('sort asc')->select()->toArray();

                    // 检测三级菜单权限
                    foreach ($menu_3_list as $k3 => $v3) {
                        if (!$this->isRoot && !$this->checkRule($v3['url'], get_uid(), AuthRule::RULE_MAIN, null)) {
                            unset($menu_3_list[$k3]);
                            continue; //继续循环
                        }
                    }

                    if (!empty($menu_3_list)) {
                        $menus['lists'][$k2]['_child'] = $menu_3_list;
                    }
                }

                $main_menu[$key]['_child'][] = $menus;
            }
        }

        // 重新索引数组，确保返回的是连续索引的数组
        $main_menu = array_values($main_menu);
        return $this->success('success', $main_menu);
    }

    /**
     * 新增/编辑配置
     */
    public function edit()
    {
        if (request()->isPost()) {
            $data = (array)input('');
            if ($data['title'] == '') {
                return $this->error('菜单标题不能为空');
            }
            if ($data['url'] == '') {
                return $this->error('菜单链接不能为空');
            }

            $res = $this->MenuModel->edit($data);
            if ($res) {
                //记录行为
                action_log('update_menu', 'Menu', $res, is_login());
                return $this->success('保存成功', $res, cookie('__forward__'));
            } else {
                return $this->error('保存失败');
            }
        } else {
            $id = (string)input('id', '0', 'text');
            $pid = (string)input('pid', '0', 'text');

            $info = [];
            /* 获取数据 */
            $info = $this->MenuModel->where(['id' => $id])->find();

            if (empty($info)) {
                $map['id'] = $pid;
                $info = $this->MenuModel->where($map)->field('module,pid,hide,type')->find();
                $info['pid'] = $pid;
            }

            return $this->success('success', $info);
        }
    }

    /**
     * 删除后台菜单
     */
    public function del()
    {
        $id = array_unique((array)input('id/a', []));

        if (empty($id)) {
            return $this->error('参数错误');
        }
        //判断是否有下级菜单
        $res =  $this->MenuModel->where('pid', 'in', $id)->select()->toArray();

        if (!empty($res)) {
            return $this->error('下级菜单不为空');
        }
        //开始移除菜单
        if ($this->MenuModel->where('id', 'in', $id)->delete()) {
            //记录行为
            // action_log('update_menu', 'Menu', $id, is_login());
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }

    /**
     * 菜单导入
     */
    public function import()
    {
        if (request()->isPost()) {
            $tree = input('post.tree');
            $lists = explode(PHP_EOL, $tree);

            if ($lists == array()) {
                return $this->error('请按格式填写批量导入的菜单，至少一个菜单');
            } else {
                $pid = input('post.pid');
                foreach ($lists as $key => $value) {
                    $record = explode('|', $value);
                    if (count($record) == 2) {
                        $this->MenuModel->insert([

                            'id' => create_guid(),
                            'title' => $record[0],
                            'url' => $record[1],
                            'pid' => $pid,
                            'sort' => 0,
                            'hide' => 0,
                            'tip' => '',
                            'group' => '',
                        ]);
                    }
                }
                return $this->success('导入成功', '', url('index', ['pid' => $pid]));
            }
        }
    }

    /**
     * 菜单排序
     */
    public function sort()
    {
        if (request()->isPost()) {
            $ids = input('post.ids');
            $ids = explode(',', $ids);
            foreach ($ids as $key => $value) {
                $res = $this->MenuModel->where(['id' => $value])->update(['sort' => $key + 1]);
            }
            if ($res !== false) {
                return $this->success('排序成功');
            } else {
                return $this->error('排序失败');
            }
        } else {
            $map[] = ['pid', '=', '0'];
            $map[] = ['type', '=', 0];
            $list = $this->MenuModel->where($map)->field('id,title')->order('sort asc')->select();

            return $this->success('success', $list);
        }
    }
}
