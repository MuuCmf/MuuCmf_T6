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

    // 发送能力已统一收敛到消息推送中心（后台新建发送任务 / MessageCenter::send），
    // 旧 sendMessageToUid、sendMessageToGroup 兼容壳与 mapSendType、队列心跳均已移除；
    // 本模型只保留站内信的读取、格式化与受众过滤能力。

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
