<?php

use think\facade\Db;
use think\facade\Config;
use app\common\model\ActionLog;
use app\common\model\History;

/**
 * 后台公共文件
 * 主要定义后台公共函数库
 */

if (!function_exists('get_attribute_type')) {
    /**
     * 获取属性类型信息
     */
    function get_attribute_type($type = '')
    {
        // TODO 可以加入系统配置
        static $_type = [
            'num' => array('数字', 'int(10) UNSIGNED NOT NULL'),
            'string' => array('字符串', 'varchar(255) NOT NULL'),
            'textarea' => array('文本框', 'text NOT NULL'),
            'datetime' => array('时间', 'int(10) NOT NULL'),
            'bool' => array('布尔', 'tinyint(2) NOT NULL'),
            'select' => array('枚举', 'char(50) NOT NULL'),
            'radio' => array('单选', 'char(10) NOT NULL'),
            'checkbox' => array('多选', 'varchar(100) NOT NULL'),
            'editor' => array('编辑器', 'text NOT NULL'),
            'picture' => array('上传图片', 'int(10) UNSIGNED NOT NULL'),
            'file' => array('上传附件', 'int(10) UNSIGNED NOT NULL'),
        ];

        return $type ? $_type[$type][0] : $_type;
    }
}

if (!function_exists('get_list_field')) {
    /* 解析列表定义规则*/

    function get_list_field($data, $grid, $model)
    {
        // 获取当前字段数据
        foreach ($grid['field'] as $field) {
            $array = explode('|', $field);
            $temp = $data[$array[0]];
            // 函数支持
            if (isset($array[1])) {
                $temp = call_user_func($array[1], $temp);
            }
            $data2[$array[0]] = $temp;
        }
        if (!empty($grid['format'])) {
            $value = preg_replace_callback('/\[([a-z_]+)\]/', function ($match) use ($data2) {
                return $data2[$match[1]];
            }, $grid['format']);
        } else {
            $value = implode(' ', $data2);
        }

        // 链接支持
        if (!empty($grid['href'])) {
            $links = explode(',', $grid['href']);
            foreach ($links as $link) {
                $array = explode('|', $link);
                $href = $array[0];
                if (preg_match('/^\[([a-z_]+)\]$/', $href, $matches)) {
                    $val[] = $data2[$matches[1]];
                } else {
                    $show = isset($array[1]) ? $array[1] : $value;
                    // 替换系统特殊字符串
                    $href = str_replace(
                        array('[DELETE]', '[EDIT]', '[MODEL]'),
                        array('del?ids=[id]&model=[MODEL]', 'edit?id=[id]&model=[MODEL]', $model['id']),
                        $href
                    );

                    // 替换数据变量
                    $href = preg_replace_callback('/\[([a-z_]+)\]/', function ($match) use ($data) {
                        return $data[$match[1]];
                    }, $href);

                    $val[] = '<a href="' . url($href) . '">' . $show . '</a>';
                }
            }
            $value = implode(' ', $val);
        }
        return $value;
    }
}

if (!function_exists('get_status_title')) {
    /**
     * 获取对应状态的文字信息
     * @param int $status
     * @return string 状态文字 ，false 未获取到
     */
    function get_status_title($status)
    {
        if (!isset($status)) {
            return false;
        }
        switch ($status) {
            case -2:
                return '审核未通过';
                break;
            case -1:
                return '删除';
                break;
            case 0:
                return '禁用';
                break;
            case 1:
                return '启用';
                break;
            case 2:
                return '未审核';
                break;
            default:
                return false;
                break;
        }
    }
}

if (!function_exists('int_to_string')) {
    /**
     * 将数组中的数字转换成对应的文字
     * @param array $data 要转换的数据集
     * @param array $map 转换映射关系 ['字段名'=>['值'=>'对应文字']]
     * @return array 转换后的数据集
     * 
     * 示例:
     * $map = ['status'=>[1=>'启用',-1=>'删除',0=>'禁用',-2=>'未审核',3=>'草稿']]
     * $data = [['id'=>1,'status'=>1],['id'=>2,'status'=>0]]
     * int_to_string($data,$map);
     * 返回: [['id'=>1,'status'=>1,'status_text'=>'启用'],['id'=>2,'status'=>0,'status_text'=>'禁用']]
     */
    function int_to_string(&$data, $map = ['status' => [1 => '启用', -1 => '删除', 0 => '禁用', -2 => '未审核', 3 => '草稿']])
    {
        if ($data === false || $data === null) {
            return $data;
        }
        $data = (array)$data;
        foreach ($data as $key => $row) {
            foreach ($map as $col => $pair) {
                if (isset($row[$col]) && isset($pair[$row[$col]])) {
                    $data[$key][$col . '_text'] = $pair[$row[$col]];
                }
            }
        }
        return $data;
    }
}

if (!function_exists('lists_plus')) {
    /**
     * 处理列表数据，为每条数据添加模块别名和积分变动信息
     * 
     * @param array &$data 需要处理的数据列表（通过引用传递）
     * @return array 处理后的数据列表
     * 
     * 功能说明：
     * 1. 从module表获取所有模块的别名信息
     * 2. 为每条数据添加对应模块的别名
     * 3. 从action_log表获取最新的积分变动记录
     * 4. 如果存在积分变动，则添加到vary字段
     */
    function lists_plus(&$data)
    {
        $alias = Db::name('module')->select();

        foreach ($alias as $value) {
            $alias_set[$value['name']] = $value['alias'];
        }
        foreach ($data as $key => $value) {
            if (empty($data[$key]['module'])) {
                $data[$key]['alias'] = '';
            } else {
                $data[$key]['alias'] = $alias_set[$data[$key]['module']];
            }

            $mid = Db::name('action_log')->field("max(create_time),remark")->where('action_id=' . $data[$key]['id'])->select();
            $mid_s = $mid[0]['remark'];
            if (isset($mid_s) && strpos($mid_s, lang('_INTEGRAL_')) !== false) {
                $data[$key]['vary'] = $mid_s;
            } else {
                $data[$key]['vary'] = '';
            }
        }
        return $data;
    }
}

if (!function_exists('parse_config_attr')) {
    // 分析枚举类型配置值 格式 a:名称1,b:名称2
    function parse_config_attr($string)
    {
        $array = preg_split('/[,;\r\n]+/', trim($string, ",;\r\n"));
        if (strpos($string, ':')) {
            $value = array();
            foreach ($array as $val) {
                list($k, $v) = explode(':', $val);
                $value[$k] = $v;
            }
        } else {
            $value = $array;
        }
        return $value;
    }
}

if (!function_exists('parse_field_attr')) {
    // 分析枚举类型字段值 格式 a:名称1,b:名称2
    // 暂时和 parse_config_attr功能相同
    // 但请不要互相使用，后期会调整
    function parse_field_attr($string)
    {
        if (0 === strpos($string, ':')) {
            // 采用函数定义
            //return eval(substr($string, 1) . ';');
        }
        $array = preg_split('/[,;\r\n]+/', trim($string, ",;\r\n"));
        if (strpos($string, ':')) {
            $value = array();
            foreach ($array as $val) {
                list($k, $v) = explode(':', $val);
                $value[$k] = $v;
            }
        } else {
            $value = $array;
        }
        return $value;
    }
}

if (!function_exists('get_action')) {
    /**
     * 获取行为数据
     * @param string $id 行为id
     * @param string $field 需要获取的字段
     * @author huajie <banhuajie@163.com>
     */
    function get_action($id = null, $field = null)
    {
        if (empty($id) && !is_numeric($id)) {
            return false;
        }
        $list = cache('action_list');
        if (empty($list[$id])) {
            $map[] = ['status', '>', -1];
            $map[] = ['id', '=', $id];
            $list[$id] = Db::name('Action')->where($map)->field(true)->find();
        }
        return empty($field) ? $list[$id] : $list[$id][$field];
    }
}

if (!function_exists('get_action_type')) {
    /**
     * 获取行为类型
     * @param string|int $type 类型
     * @param bool $all 是否返回全部类型
     * @author huajie <banhuajie@163.com>
     */
    function get_action_type(string|int $type, $all = false)
    {
        $list = array(
            1 => '系统',
            2 => '用户',
        );
        if ($all) {
            return $list;
        }
        return $list[$type];
    }
}

if (!function_exists('action_log')) {
    /**
     * 记录行为日志，并执行该行为的规则
     * @param string $action 行为标识
     * @param string $model 触发行为的模型名
     * @param int $record_id 触发行为的记录id
     * @param int $uid 执行行为的用户id
     * @return boolean
     */
    function action_log($action = null, $model = null, $record_id = null, $uid = null)
    {
        $actionLogModel = new ActionLog();

        return $actionLogModel->add($action, $model, $record_id, $uid);
    }
}

if (!function_exists('history_log')) {
    /**
     * 记录浏览记录
     * @param string $action 行为标识
     * @param string $model 触发行为的模型名
     * @param int $record_id 触发行为的记录id
     * @param int $uid 执行行为的用户id
     * @return boolean
     */
    function history_log($shopid, $app, $uid, $info_id, $info_type, $metadata)
    {
        return (new History())->addLog($shopid, $app, $uid, $info_id, $info_type, $metadata);
    }
}

if (!function_exists('str_replace_limit')) {
    /**
     * 对字符串执行指定次数替换
     * @param  Mixed $search   查找目标值
     * @param  Mixed $replace  替换值
     * @param  Mixed $subject  执行替换的字符串／数组
     * @param  Int   $limit    允许替换的次数，默认为-1，不限次数
     * @return Mixed
     */
    function str_replace_limit($search, $replace, $subject, $limit = -1)
    {
        if (is_array($search)) {
            foreach ($search as $k => $v) {
                $search[$k] = '`' . preg_quote($search[$k], '`') . '`';
            }
        } else {
            $search = '`' . preg_quote($search, '`') . '`';
        }
        return preg_replace($search, $replace, $subject, $limit);
    }
}

if (!function_exists('get_stemma')) {
    /**
     * 获取数据的所有子孙数据的id值
     */
    function get_stemma($pids, &$model, $field = 'id')
    {
        $collection = array();

        //非空判断
        if (empty($pids)) {
            return $collection;
        }

        if (is_array($pids)) {
            $pids = trim(implode(',', $pids), ',');
        }
        $result = $model->field($field)->where(array('pid' => array('IN', (string)$pids)))->select();
        $child_ids = array_column((array)$result, 'id');

        while (!empty($child_ids)) {
            $collection = array_merge($collection, $result);
            $result = $model->field($field)->where(array('pid' => array('IN', $child_ids)))->select();
            $child_ids = array_column((array)$result, 'id');
        }
        return $collection;
    }
}

if (!function_exists('get_nav_url')) {
    /**
     * 获取导航URL
     * @param  string $url 导航URL
     * @return string      解析或的url
     */
    function get_nav_url($url)
    {
        switch ($url) {
            case 'http://' === substr($url, 0, 7):
                return $url;
                break;
            case 'https://' === substr($url, 0, 8):
                return $url;
                break;
            case '#' === substr($url, 0, 1):
                return $url;
                break;
            case strpos($url, '/') !== false:
                $url = url($url);
                return $url;
                break;
            default:
                $url = url($url . '/index/index');
                return $url;
                break;
        }
    }
}

if (!function_exists('get_nav_active')) {
    /**
     * @param $url 检测当前自定义导航url是否被选中
     * @return bool|string
     */
    function get_nav_active($url)
    {
        switch ($url) {
            case '/':
                if (strtolower(request()->domain() . $url) === strtolower(request()->url(true))) {
                    return 1;
                }
            case 'http://' === substr($url, 0, 7):
                if (strtolower($url) === strtolower(request()->url(true))) {
                    return 1;
                }
            case 'https://' === substr($url, 0, 8):
                if (strtolower($url) === strtolower(request()->url(true))) {
                    return 1;
                }
            case '#' === substr($url, 0, 1):
                return 0;
                break;
            default:
                $url_array = explode('/', $url);
                if ($url_array[0] == '') {
                    $app_name = $url_array[1];
                } else {
                    $app_name = $url_array[0]; //发现模块就是当前模块即选中。
                }
                if (strtolower($app_name) === strtolower(app('http')->getName())) {
                    return 1;
                };
                break;
        }
        return 0;
    }
}

if (!function_exists('list_to_tree')) {
    /**
     * 把返回的数据集转换成Tree
     * @param array $list 要转换的数据集
     * @param string $pid parent标记字段
     * @param string $level level标记字段
     * @return array
     * @author 麦当苗儿 <zuojiazi@vip.qq.com>
     */
    function list_to_tree($list, $pk = 'id', $pid = 'pid', $child = '_child', $root = 0)
    {
        // 创建Tree
        $tree = array();
        if (is_array($list)) {
            // 创建基于主键的数组引用
            $refer = array();
            foreach ($list as $key => $data) {
                $refer[$data[$pk]] = &$list[$key];
            }
            foreach ($list as $key => $data) {
                // 判断是否存在parent
                $parentId = $data[$pid];
                if ($root == $parentId) {
                    $tree[] = &$list[$key];
                } else {
                    if (isset($refer[$parentId])) {
                        $parent = &$refer[$parentId];
                        $parent[$child][] = &$list[$key];
                    }
                }
            }
        }

        return $tree;
    }
}

if (!function_exists('tree_to_list')) {
    /**
     * 将list_to_tree的树还原成列表
     * @param  array $tree 原来的树
     * @param  string $child 孩子节点的键
     * @param  string $order 排序显示的键，一般是主键 升序排列
     * @param  array $list 过渡用的中间数组，
     * @return array        返回排过序的列表数组
     * @author yangweijie <yangweijiester@gmail.com>
     */
    function tree_to_list($tree, $child = '_child', $order = 'id', &$list = array())
    {
        if (is_array($tree)) {
            $refer = array();
            foreach ($tree as $key => $value) {
                $reffer = $value;
                if (isset($reffer[$child])) {
                    unset($reffer[$child]);
                    tree_to_list($value[$child], $child, $order, $list);
                }
                $list[] = $reffer;
            }
            $list = list_sort_by($list, $order, $sortby = 'asc');
        }
        return $list;
    }
}
