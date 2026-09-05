<?php

namespace app\common\model;

use think\Model;
use app\common\service\SnowFlake;

/**
 * 业务模型基类
 *
 * 提供统一的新增/更新、列表/详情查询、状态修改、字段增减等方法。
 * 基于 ThinkPHP 6 / think-orm 2.0 重写，修复了旧版 TP5 风格写法遗留的问题。
 */
class Base extends Model
{
    /**
     * 状态文案映射（保留给子类/模板使用）
     *
     * 注意：这里不能使用 public，否则会与数据表中真实的 status 字段重名冲突——
     * 直接通过 $model->status 读/写数据时命中的将是该数组而不是记录字段，
     * 导致模型属性读取全部错乱。声明为 protected 后，外部访问会走模型 __get，
     * 读到的是记录中真实的 status 字段值。
     */
    protected array $status = [
        -1 => '删除',
        0  => '禁用',
        1  => '正常',
        2  => '待审核',
    ];

    /**
     * 新增或更新数据（按主键是否传值自动判断）
     *
     * @param array $data 数据（含主键为更新，否则为新增）
     * @return int|string|false 成功返回主键值，失败返回 false
     */
    public function edit($data)
    {
        $pk = $this->getPk();

        // 已携带主键 -> 更新
        if (!empty($data[$pk])) {
            // 显式指定更新条件，避免依赖静态 update 对主键的隐式解析
            $result = static::update($data, [$pk => $data[$pk]]);

            return $result->getKey() ?: $data[$pk];
        }

        // 未携带主键 -> 新增
        unset($data[$pk]);

        $result = $this->save($data);

        if (false === $result) {
            return false;
        }

        return $this->getKey() ?: $result;
    }

    /**
     * 新增或更新数据（新增时使用 SNOWFLAKE 算法生成全局唯一主键）
     *
     * @param array  $data         数据（含主键为更新，否则为新增）
     * @param string $datacenterId SNOWFLAKE 数据中心 ID
     * @param string $machineId    SNOWFLAKE 机器 ID
     * @return int|string|false 成功返回主键值，失败返回 false
     */
    public function editSnowFlake($data, $datacenterId = '0', $machineId = '0')
    {
        $pk = $this->getPk();

        // 已携带主键 -> 走普通更新
        if (!empty($data[$pk])) {
            return $this->edit($data);
        }

        // 生成雪花主键并新增
        unset($data[$pk]);
        $data[$pk] = (new SnowFlake($datacenterId, $machineId))->nextId();

        $result = $this->save($data);

        if (false === $result) {
            return false;
        }

        return $this->getKey() ?: $data[$pk];
    }

    /**
     * 分页查询列表
     *
     * @param mixed  $map   查询条件（数组或原生 SQL 字符串）
     * @param string $order 排序，如 "create_time desc"
     * @param string $field 查询字段
     * @param int    $r     每页条数
     * @return \think\Paginator
     */
    public function getListByPage($map, $order = 'create_time desc', $field = '*', $r = 20)
    {
        $r = max(1, (int)$r);

        $query = empty($map) ? $this : $this->buildMapQuery($map);

        return $query->order($order)
            ->field($field)
            ->paginate(['list_rows' => $r, 'query' => request()->param()], false);
    }

    /**
     * 按主键查询单条数据
     *
     * @param int|string $id    主键值
     * @param string     $field 查询字段
     * @return \think\Model|null
     */
    public function getDataById($id, $field = '*')
    {
        if (empty($id)) {
            return null;
        }

        return $this->field($field)->find($id);
    }

    /**
     * 按条件查询单条数据
     *
     * @param mixed  $map   查询条件（数组或原生 SQL 字符串）
     * @param string $field 查询字段
     * @return \think\Model|null
     */
    public function getDataByMap($map, $field = '*')
    {
        return $this->buildMapQuery($map)->field($field)->find();
    }

    /**
     * 按条件查询列表
     *
     * @param mixed  $map   查询条件（数组或原生 SQL 字符串）
     * @param int    $limit 条数
     * @param string $order 排序，如 "create_time desc"
     * @param string $field 查询字段
     * @return \think\Collection
     */
    public function getList($map, $limit = 10, $order = 'create_time desc', $field = '*')
    {
        return $this->buildMapQuery($map)
            ->field($field)
            ->order($order)
            ->limit($limit)
            ->select();
    }

    /**
     * 统计满足条件的记录数
     *
     * @param mixed $map 查询条件（数组或原生 SQL 字符串）
     * @return int
     */
    public function getCount($map)
    {
        return $this->buildMapQuery($map)->count();
    }

    /**
     * 统计满足条件记录某字段的平均值
     *
     * @param mixed  $map   查询条件（数组或原生 SQL 字符串）
     * @param string $field 统计字段
     * @return string
     */
    public function getAvg($map, $field = 'score')
    {
        return $this->buildMapQuery($map)->avg($field);
    }

    /**
     * 批量设置状态（同时刷新 update_time）
     *
     * @param int|string|array $ids    主键 ID（支持单个、逗号分隔字符串或数组）
     * @param int              $status 目标状态值
     * @return bool
     */
    public function setStatus($ids, $status)
    {
        $pk  = $this->getPk();
        $ids = is_array($ids) ? $ids : explode(',', (string)$ids);

        if (empty($ids)) {
            return false;
        }

        $data = [
            'status'      => $status,
            'update_time' => time(),
        ];

        return false !== $this->where($pk, 'in', $ids)->update($data);
    }

    /**
     * 字段递增
     *
     * @param mixed $map   查询条件（数组或原生 SQL 字符串）
     * @param string $field 递增字段
     * @param int    $value 递增步长
     * @return int
     */
    public function setInc($map, $field, $value = 1)
    {
        // think-orm 中 inc() 仅设置写入值，必须再执行 update() 才会真正落库
        return $this->buildMapQuery($map)->inc($field, $value)->update();
    }

    /**
     * 字段递减
     *
     * @param mixed  $map   查询条件（数组或原生 SQL 字符串）
     * @param string $field 递减字段
     * @param int    $value 递减步长
     * @return int
     */
    public function setDec($map, $field, $value = 1)
    {
        return $this->buildMapQuery($map)->dec($field, $value)->update();
    }

    /**
     * 获取错误信息
     *
     * @return mixed
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * 依据查询条件构建查询对象
     *
     * 兼容两种入参风格：数组条件直接 where()；原生 SQL 字符串使用 whereRaw()，
     * 避免把 SQL 字符串误当作数组条件解析导致异常。
     *
     * @param mixed $map 查询条件
     * @return $this|\think\db\BaseQuery
     */
    private function buildMapQuery($map)
    {
        if (is_array($map)) {
            return $this->where($map);
        }

        return $this->whereRaw((string)$map);
    }
}
