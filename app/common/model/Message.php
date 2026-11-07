<?php

namespace app\common\model;

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
     * 发送消息至用户（兼容入口）
     *
     * 内部转调统一消息中心（模板 common.custom_message），站内信与外部渠道都会落 message_record；
     * 旧版消息队列已移除，统一中心不可用（未执行升级脚本）时返回 false 并记录日志。
     *
     * send_type array msg站内信 email邮件 sms短信
     */
    public function sendMessageToUid($shopid = 0, $uid = 0, $to_uids = [], $title = '您有新的消息', $description = '', $content = '', $type_id = 1, $send_type = ['msg', 'email'])
    {
        // 指定用户ID
        $to_uids = is_array($to_uids) ? $to_uids : explode(',', $to_uids);
        $to_uids = array_values(array_unique(array_filter(array_map('intval', $to_uids))));
        if (!count($to_uids)) {
            return false;
        }

        $channels = $this->mapSendType($send_type);

        try {
            \app\common\service\message\MessageCenter::send(
                'common.custom_message',
                ['type' => 'uids', 'value' => $to_uids],
                ['title' => $title, 'description' => $description, 'content' => $content],
                [
                    'shopid'         => (int)$shopid,
                    'channels'       => $channels,
                    'inbox_type_id'  => (int)$type_id,
                    'operator_uid'   => (int)$uid,
                    'biz_key'        => 'legacy_' . substr(md5($shopid . $type_id . $title . microtime(true) . mt_rand()), 0, 24),
                ]
            );
            $this->updateHeartbeat();
            return true;
        } catch (\Throwable $e) {
            trace('[message] sendMessageToUid failed: ' . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * 发送消息至用户组（兼容入口）
     */
    public function sendMessageToGroup($shopid = 0, $uid = 0, $to_groud_ids = [], $title = '您有新的消息', $description = '', $content = '', $type_id = 1, $send_type = ['msg', 'email'])
    {
        // 指定用户ID
        $to_groud_ids = is_array($to_groud_ids) ? $to_groud_ids : explode(',', $to_groud_ids);
        $to_groud_ids = array_values(array_unique(array_filter(array_map('intval', $to_groud_ids))));
        if (!count($to_groud_ids)) {
            return false;
        }

        $channels = $this->mapSendType($send_type);

        try {
            \app\common\service\message\MessageCenter::send(
                'common.custom_message',
                ['type' => 'groups', 'value' => $to_groud_ids],
                ['title' => $title, 'description' => $description, 'content' => $content],
                [
                    'shopid'        => (int)$shopid,
                    'channels'      => $channels,
                    'inbox_type_id' => (int)$type_id,
                    'operator_uid'  => (int)$uid,
                    'biz_key'       => 'legacy_' . substr(md5($shopid . $type_id . $title . microtime(true) . mt_rand()), 0, 24),
                ]
            );
            $this->updateHeartbeat();
            return true;
        } catch (\Throwable $e) {
            trace('[message] sendMessageToGroup failed: ' . $e->getMessage(), 'error');
        }

        return false;
    }

    /**
     * 旧渠道标识映射到统一渠道
     *
     * @param array $send_type
     * @return array
     */
    protected function mapSendType($send_type): array
    {
        $map = ['msg' => 'inbox', 'message' => 'inbox', 'inbox' => 'inbox', 'email' => 'email', 'sms' => 'sms'];
        $channels = [];
        foreach ((array)$send_type as $type) {
            $channel = $map[strtolower((string)$type)] ?? '';
            if ($channel !== '') {
                $channels[] = $channel;
            }
        }
        return array_values(array_unique($channels)) ?: ['inbox'];
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
        } catch (\Throwable $e) {
            // 心跳更新失败不影响发送结果：redis 扩展缺失时抛的是 Error 而非 Exception，
            // 只捕获 Exception 会让已经发出的消息被误报为失败
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
            $data['content']['link'] = (string)$content->getData('link');
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
     * 按渠道过滤可触达用户
     *
     * 站内信不受登录活跃度限制（用户下次登录仍可见），只有外部触达渠道（短信/邮件/微信）
     * 才剔除一个月未登录的用户，避免把成本花在沉默用户上。
     *
     * @param array|string $to_uids 目标用户
     * @param string       $channel inbox 站内信 / email 邮件 / sms 短信
     * @return array
     */
    public function _removeOldUser($to_uids, $channel = 'inbox')
    {
        $to_uids = is_array($to_uids) ? $to_uids : explode(',', (string)$to_uids);
        $to_uids = array_values(array_unique(array_filter(array_map('intval', $to_uids))));
        if (empty($to_uids)) {
            return [];
        }

        $map[] = ['uid', 'in', $to_uids];
        $map[] = ['status', '=', 1];
        // 站内信不做活跃度过滤；外部渠道才剔除长期未登录用户
        if (!in_array(strtolower((string)$channel), ['inbox', 'msg', 'message'], true)) {
            $map[] = ['last_login_time', '>', get_time_ago('month')];
        }

        $uids = (new MemberModel())->where($map)->column('uid');

        return array_map('intval', $uids);
    }
}
