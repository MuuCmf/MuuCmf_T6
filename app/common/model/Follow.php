<?php
namespace app\common\model;

/**
 * 关注关系模型
 *
 * 关注对象为作者绑定的用户：who_follow = 关注者UID，follow_who = 作者绑定用户UID，
 * author_id 记录关注来源作者，仅用于列表展示与粉丝归属。
 */
class Follow extends Base
{
    //自动写入创建和更新的时间戳字段
    protected $autoWriteTimestamp = true;


}
