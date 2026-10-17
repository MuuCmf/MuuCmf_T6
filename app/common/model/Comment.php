<?php
namespace app\common\model;

class Comment extends Base
{
    //自动写入创建和更新的时间戳字段
    protected $autoWriteTimestamp = true;

    /**
     * 评论计数器增减（点赞数等）
     *
     * @param int    $id    评论ID
     * @param string $field 计数字段（support 等）
     * @param int    $value 增减值，正数增加、负数减少
     * @return bool
     */
    public function setStep(int $id, string $field, int $value = 1)
    {
        if (empty($id) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return false;
        }

        // 递减时先判断当前值，避免出现负数
        if ($value < 0) {
            $count = $this->where('id', $id)->value($field);
            if ($count <= 0) {
                return false;
            }
        }

        $res = $this->where('id', $id)->inc($field, $value)->update();

        return $res !== false;
    }
}
