<?php

namespace app\common\model;

use think\facade\Queue;
use app\common\model\Member as MemberModel;
use app\common\model\MessageType;
use app\common\model\MessageContent;

class Message extends Base
{
    //自动写入创建和更新的时间戳字段
    protected $autoWriteTimestamp = true;
    public $_status  = [
        1  => '启用',
        0  => '禁用',
        -1 => '删除',
    ];
    public $_is_read = [
        0 => '未读',
        1 => '已读'
    ];

    /**
     * 发送消息至用户
     * send_type array msg站内信 email邮件 sms短信
     * 
     */
    public function sendMessageToUid($shopid = 0, $uid = 0, $to_uids = [], $title = '您有新的消息', $description = '', $content = '', $type_id = 1, $send_type = ['msg', 'email'])
    {
        // 指定用户ID
        $to_uids = is_array($to_uids) ? $to_uids : explode(',', $to_uids);
        if (!count($to_uids)) {
            return false;
        }

        // 写入消息内容
        $content_id = (new MessageContent())->addMessageContent($shopid, $title, $description, $content);

        // 消息队列名称
        $queue_name = config('queue')['connections']['redis']['queue'] ?? 'default';
        // 发送至消息队列
        $isPushed = Queue::push('\app\common\queue\Message@sendToUids', [
            'shopid' => $shopid,
            'uid' => $uid,
            'to_uids' => $to_uids,
            'type_id' => $type_id,
            'content_id' => $content_id,
            'send_type' => $send_type,
        ], $queue_name);

        if ($isPushed !== false) {
            // 更新心跳
            $this->updateHeartbeat();
            return true;
        }

        return false;
    }

    /**
     * 发送消息至用户组
     */
    public function sendMessageToGroup($shopid = 0, $uid = 0, $to_groud_ids = [], $title = '您有新的消息', $description = '', $content = '', $type_id = 1, $send_type = ['msg', 'email'])
    {
        // 指定用户ID
        $to_groud_ids = is_array($to_groud_ids) ? $to_groud_ids : explode(',', $to_groud_ids);
        if (!count($to_groud_ids)) {
            return false;
        }

        // 写入消息内容
        $content_id = (new MessageContent())->addMessageContent($shopid, $title, $description, $content);
        // 消息队列名称
        $queue_name = config('queue')['connections']['redis']['queue'] ?? 'default';
        // 发送至消息队列
        $isPushed = Queue::push('\app\common\queue\Message@sendToGroups', [
            'shopid' => $shopid,
            'uid' => $uid,
            'to_groud_ids' => $to_groud_ids,
            'type_id' => $type_id,
            'content_id' => $content_id,
            'send_type' => $send_type,
        ], $queue_name);

        if ($isPushed !== false) {
            // 更新心跳
            $this->updateHeartbeat();
            return true;
        }

        return false;
    }

    /**
     * 更新队列心跳
     */
    protected function updateHeartbeat($queueName = null)
    {
        try {
            $queueName = $queueName ?: config('queue.connections.redis.queue');
            $config = config('queue.connections.redis');

            $func = $config['persistent'] ? 'pconnect' : 'connect';
            $redis = new \Redis;
            $redis->$func($config['host'], $config['port'], $config['timeout']);

            if ('' != $config['password']) {
                $redis->auth($config['password']);
            }

            if (0 != $config['select']) {
                $redis->select($config['select']);
            }

            $heartbeatKey = 'queue:heartbeat:' . $queueName;
            $redis->set($heartbeatKey, time());
            $redis->expire($heartbeatKey, 600); // 10分钟过期

            $redis->close();
        } catch (\Exception $e) {
            // 心跳更新失败不影响任务执行
        }
    }

    /**
     * 处理消息数据
     */
    public function formatData($data)
    {
        // 发送用户
        if ($data['uid'] == 0) {
            $avatar = request()->domain() . '/static/common/images/message_icon/system.png';
            // uid为0时属系统信息
            $data['form_user'] = [
                'nickname' => '系统',
                'avatar' => $avatar,
                'avatar64' => $avatar,
                'avatar128' => $avatar,
                'avatar256' => $avatar,
                'avatar512' => $avatar,
            ];
        } else {
            // 包含uid时为用户之间互动消息
            $data['form_user'] = query_user($data['uid'], ['nickname', 'avatar']);
        }

        // 接收用户
        $data['to_user'] = query_user($data['to_uid'], ['nickname', 'avatar']);

        // 获取消息类型数据
        if (!empty($data['type_id'])) {
            $type = (new MessageType())->find($data['type_id']);
            if (!$type->isEmpty()) {
                $data['type']['title'] = $type->title;
                $data['type']['icon'] = get_attachment_src($type->icon);
            }
        }

        // 获取消息内容
        $content = (new MessageContent())->find($data['content_id']);
        if (!empty($content)) {
            $data['content']['title'] = $content->title;
            $data['content']['description'] = $content->description;
            $data['content']['content'] = $content->content;
        } else {
            $data['content']['title'] = '内容已删除';
            $data['content']['description'] = '';
            $data['content']['content'] = '';
        }
        // 状态
        $data['is_read_str'] = $this->_is_read[$data['is_read']];
        $data['status_str'] = $this->_status[$data['status']];
        //时间戳格式化
        $data['create_time_str'] = time_format($data['create_time']);
        $data['update_time_str'] = time_format($data['update_time']);
        return $data;
    }

    /**
     * 去除一个月没有登录的用户
     * @param $to_uids
     * @return array
     */
    public function _removeOldUser($to_uids)
    {
        $to_uids = is_array($to_uids) ? implode(',', $to_uids) : $to_uids;
        if (!empty($to_uids)) {
            $map[] = ['uid', 'in', $to_uids];
        }

        $map[] = ['status', '=', 1];
        $map[] = ['last_login_time', '>', get_time_ago('month')];

        $uids = (new MemberModel())->where($map)->field('uid')->select()->toArray();
        $uids = array_column($uids, 'uid');
        return $uids;
    }
}
