<?php

namespace app\admin\controller;

use think\facade\Cache;
use think\facade\Db;
use app\admin\controller\Admin;
use app\common\model\MessageRecord;
use app\common\model\MessageSuppression;
use app\common\model\MessageTask;
use app\common\service\message\AudienceResolver;
use app\common\service\message\ChannelManager;
use app\common\service\message\Dispatcher;
use app\common\service\message\MessageCenter as MessageCenterService;
use app\common\service\message\MessageException;
use app\common\service\message\MessageLevel;
use app\common\service\message\MessageSeed;
use app\common\service\message\ShopContext;
use app\common\service\message\StatService;
use app\common\service\message\TemplateResolver;

/**
 * 统一消息推送中心（后台）
 *
 * 所有查询强制带当前登录店铺 shopid；跨店 shopid 一律拒绝。
 */
class MessageCenter extends Admin
{
    /** @var TemplateResolver */
    protected $resolver;

    /** @var ChannelManager */
    protected $channels;

    /** @var AudienceResolver */
    protected $audience;

    /** 支持配置的渠道 */
    protected const CHANNELS = ['inbox', 'email', 'sms', 'wechat_oa', 'wechat_mp'];

    public function __construct()
    {
        parent::__construct();
        // 所有消息中心后台接口统一做店铺归属校验
        try {
            ShopContext::assert((int)$this->shopid);
        } catch (\Throwable $e) {
            abort(403, $e->getMessage());
        }
        $this->resolver = new TemplateResolver();
        $this->channels = new ChannelManager();
        $this->audience = new AudienceResolver();

        // 新店铺首次进入时补齐默认类型与系统模板
        (new MessageSeed())->ensure((int)$this->shopid);
    }

    /* ==================== 模板 ==================== */

    /**
     * 模板列表（按级别分组）
     *
     * @return \think\Response
     */
    public function template()
    {
        $rows = Db::name('message_template')
            ->where('shopid', $this->shopid)
            ->order('sort asc,id asc')
            ->select()
            ->toArray();

        $ids = array_column($rows, 'id');
        $channelRows = empty($ids) ? [] : Db::name('message_template_channel')
            ->where('shopid', $this->shopid)
            ->whereIn('template_id', $ids)
            ->field('template_id,channel,title,status')
            ->select()
            ->toArray();

        $channelMap = [];
        foreach ($channelRows as $channel) {
            $channelMap[(int)$channel['template_id']][] = [
                'channel' => $channel['channel'],
                'title'   => $channel['title'],
                'status'  => (int)$channel['status'],
            ];
        }

        $groups = [];
        foreach (MessageLevel::labels() as $level => $label) {
            $groups[$level] = ['key' => $level, 'label' => $label, 'templates' => []];
        }

        foreach ($rows as $row) {
            $level = MessageLevel::isValid((string)$row['level']) ? $row['level'] : MessageLevel::MARKETING;
            $groups[$level]['templates'][] = [
                'id'           => (int)$row['id'],
                'code'         => $row['code'],
                'name'         => $row['name'],
                'app'          => $row['app'],
                'scene'        => $row['scene'],
                'description'  => $row['description'],
                'level'        => $level,
                'level_label'  => MessageLevel::labels()[$level],
                'status'       => (int)$row['status'],
                'is_system'    => (int)$row['is_system'],
                'always_inbox' => (int)$row['always_inbox'],
                'version'      => (int)$row['version'],
                'variables'    => $this->decodeArray($row['variables']),
                'fallback'     => $this->decodeFallback($row['fallback']),
                'channels'     => $channelMap[(int)$row['id']] ?? [],
                'update_time'  => (int)$row['update_time'],
                'update_text'  => time_format((int)$row['update_time']),
            ];
        }

        return $this->success('success', array_values($groups));
    }

    /**
     * 模板详情
     *
     * @return \think\Response
     */
    public function templateDetail()
    {
        $id = input('id', 0, 'intval');
        $row = Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->find();
        if (empty($row)) {
            return $this->error('模板不存在');
        }

        $channels = Db::name('message_template_channel')
            ->where('shopid', $this->shopid)
            ->where('template_id', $id)
            ->select()
            ->toArray();

        $channelData = [];
        foreach ($channels as $channel) {
            $channelData[$channel['channel']] = [
                'title'             => $channel['title'],
                'content'           => $channel['content'],
                'extra'             => $this->decodeArray($channel['extra']),
                'var_map'           => $this->decodeArray($channel['var_map']),
                'third_template_id' => $channel['third_template_id'],
                'status'            => (int)$channel['status'],
            ];
        }

        $data = [
            'id'             => (int)$row['id'],
            'code'           => $row['code'],
            'app'            => $row['app'],
            'scene'          => $row['scene'],
            'name'           => $row['name'],
            'description'    => $row['description'],
            'level'          => $row['level'],
            'level_label'    => MessageLevel::labels()[$row['level']] ?? $row['level'],
            'variables'      => $this->decodeArray($row['variables']),
            'default_vars'   => $this->decodeArray($row['default_vars']),
            'fallback'       => $this->decodeFallback($row['fallback']),
            'always_inbox'   => (int)$row['always_inbox'],
            'frequency_rule' => $this->decodeFrequency($row['frequency_rule']),
            'is_system'      => (int)$row['is_system'],
            'version'        => (int)$row['version'],
            'status'         => (int)$row['status'],
            'channels'       => $channelData,
            'levels'         => MessageLevel::labels(),
            'supported_channels' => self::CHANNELS,
        ];

        return $this->success('success', $data);
    }

    /**
     * 模板保存（新增/编辑）
     *
     * @return \think\Response
     */
    public function templateSave()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $id = input('id', 0, 'intval');
        $code = trim((string)input('code', '', 'trim'));
        $name = trim((string)input('name', '', 'trim'));
        $app = trim((string)input('app', 'common', 'trim'));
        $scene = trim((string)input('scene', '', 'trim'));
        $description = trim((string)input('description', '', 'trim'));
        $level = (string)input('level', MessageLevel::MARKETING, 'trim');
        $variables = input('variables/a', []);
        $defaultVars = input('default_vars/a', []);
        $fallback = input('fallback/a', []);
        $alwaysInbox = (int)input('always_inbox', 0, 'intval');
        $frequencyRule = input('frequency_rule/a', []);
        $channelsInput = input('channels/a', []);

        $errors = [];

        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $code) || mb_strlen($code) > 64) {
            $errors[] = ['channel' => 'base', 'field' => 'code', 'type' => 'invalid_code'];
        }
        if ($name === '' || mb_strlen($name) > 64) {
            $errors[] = ['channel' => 'base', 'field' => 'name', 'type' => 'invalid_value'];
        }
        if (!MessageLevel::isValid($level)) {
            $errors[] = ['channel' => 'base', 'field' => 'level', 'type' => 'invalid_value'];
        }

        // 变量名规范化
        $variables = array_values(array_unique(array_filter(array_map(function ($item) {
            return strtolower(trim((string)$item));
        }, (array)$variables), function ($item) {
            return preg_match('/^[a-z0-9_]+$/', $item) === 1;
        })));
        if (count($variables) > 30) {
            $errors[] = ['channel' => 'base', 'field' => 'variables', 'type' => 'too_many'];
        }

        $defaultVars = array_intersect_key((array)$defaultVars, array_flip($variables));

        // 渠道内容规范化
        $channels = $this->normalizeChannels($channelsInput, $variables);
        if (empty($channels)) {
            $errors[] = ['channel' => 'base', 'field' => 'channels', 'type' => 'missing_value'];
        }

        $fallback = array_values(array_intersect(array_map('strval', (array)$fallback), self::CHANNELS));
        if (empty($fallback)) {
            $fallback = ['inbox'];
        }

        $frequency = null;
        if (!empty($frequencyRule['max_times']) || !empty($frequencyRule['window_hours'])) {
            $max = (int)($frequencyRule['max_times'] ?? 0);
            $window = (int)($frequencyRule['window_hours'] ?? 0);
            if ($max <= 0 || $max > 100 || $window <= 0 || $window > 8760) {
                $errors[] = ['channel' => 'base', 'field' => 'frequency_rule', 'type' => 'invalid_value'];
            } else {
                $frequency = ['max_times' => $max, 'window_hours' => $window];
            }
        }

        // 系统模板保护
        $existing = null;
        if ($id > 0) {
            $existing = Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->find();
            if (empty($existing)) {
                return $this->error('模板不存在');
            }
            if ((int)$existing['is_system'] === 1) {
                if ($code !== $existing['code']) {
                    $errors[] = ['channel' => 'base', 'field' => 'code', 'type' => 'system_locked'];
                }
                if ($level !== $existing['level']) {
                    $errors[] = ['channel' => 'base', 'field' => 'level', 'type' => 'system_locked'];
                }
                $code = $existing['code'];
                $level = $existing['level'];
            }
        } else {
            $duplicate = Db::name('message_template')
                ->where('shopid', $this->shopid)
                ->where('code', $code)
                ->value('id');
            if (!empty($duplicate)) {
                $errors[] = ['channel' => 'base', 'field' => 'code', 'type' => 'duplicate_code'];
            }
        }

        if (empty($errors)) {
            $validateTemplate = [
                'code'      => $code,
                'variables' => $variables,
            ];
            $errors = array_merge($errors, $this->resolver->validate($validateTemplate, $channels));
        }

        if (!empty($errors)) {
            return $this->result(422, '模板配置校验未通过', [
                'valid'  => false,
                'errors' => $errors,
            ]);
        }

        $now = time();
        $version = $existing ? (int)$existing['version'] + 1 : 1;

        $saved = Db::transaction(function () use ($id, $existing, $code, $app, $scene, $name, $description, $level, $variables, $defaultVars, $fallback, $alwaysInbox, $frequency, $channels, $now, $version) {
            $data = [
                'shopid'         => $this->shopid,
                'code'           => $code,
                'app'            => $app !== '' ? $app : 'common',
                'scene'          => $scene,
                'name'           => $name,
                'description'    => mb_substr($description, 0, 255),
                'level'          => $level,
                'variables'      => json_encode($variables, JSON_UNESCAPED_UNICODE),
                'default_vars'   => json_encode((object)$defaultVars, JSON_UNESCAPED_UNICODE),
                'fallback'       => json_encode($fallback, JSON_UNESCAPED_UNICODE),
                'always_inbox'   => $alwaysInbox ? 1 : 0,
                'frequency_rule' => $frequency ? json_encode($frequency, JSON_UNESCAPED_UNICODE) : '',
                'version'        => $version,
                'update_time'    => $now,
            ];

            if ($existing) {
                Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->update($data);
                $templateId = $id;
            } else {
                $data['is_system'] = 0;
                $data['status'] = 1;
                $data['sort'] = 0;
                $data['create_time'] = $now;
                $templateId = (int)Db::name('message_template')->insertGetId($data);
            }

            // 只替换首版 3 个渠道；P1 微信渠道前端暂无编辑入口，保存时不能被静默删除
            Db::name('message_template_channel')
                ->where('shopid', $this->shopid)
                ->where('template_id', $templateId)
                ->whereIn('channel', ['inbox', 'email', 'sms'])
                ->whereNotIn('channel', array_keys($channels))
                ->delete();

            foreach ($channels as $channelKey => $channel) {
                $row = [
                    'shopid'            => $this->shopid,
                    'template_id'       => $templateId,
                    'template_code'     => $code,
                    'channel'           => $channelKey,
                    'title'             => $channel['title'],
                    'content'           => $channel['content'],
                    'extra'             => json_encode((object)$channel['extra'], JSON_UNESCAPED_UNICODE),
                    'third_template_id' => $channel['third_template_id'],
                    'var_map'           => json_encode((object)$channel['var_map'], JSON_UNESCAPED_UNICODE),
                    'status'            => 1,
                    'update_time'       => $now,
                ];
                $exists = Db::name('message_template_channel')
                    ->where('shopid', $this->shopid)
                    ->where('template_id', $templateId)
                    ->where('channel', $channelKey)
                    ->value('id');
                if ($exists) {
                    Db::name('message_template_channel')->where('id', $exists)->update($row);
                } else {
                    $row['create_time'] = $now;
                    Db::name('message_template_channel')->insert($row);
                }
            }

            return $templateId;
        });

        $this->resolver->clearCache((int)$this->shopid, $code);
        $this->clearExtendConfigCache();

        return $this->success('保存成功', [
            'valid'   => true,
            'errors'  => [],
            'id'      => (int)$saved,
            'version' => $version,
        ]);
    }

    /**
     * 模板启停
     *
     * @return \think\Response
     */
    public function templateStatus()
    {
        $id = input('id', 0, 'intval');
        $status = input('status', 0, 'intval');
        $row = Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->find();
        if (empty($row)) {
            return $this->error('模板不存在');
        }
        Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->update([
            'status'      => $status ? 1 : 0,
            'update_time' => time(),
        ]);
        $this->resolver->clearCache((int)$this->shopid, (string)$row['code']);

        return $this->success('操作成功');
    }

    /**
     * 模板复制
     *
     * @return \think\Response
     */
    public function templateCopy()
    {
        $id = input('id', 0, 'intval');
        $newCode = trim((string)input('code', '', 'trim'));

        $row = Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->find();
        if (empty($row)) {
            return $this->error('模板不存在');
        }
        if ($newCode === '') {
            $newCode = $row['code'] . '_copy';
        }
        if (!preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $newCode)) {
            return $this->error('模板编码格式不正确');
        }
        if (Db::name('message_template')->where('shopid', $this->shopid)->where('code', $newCode)->value('id')) {
            return $this->error('模板编码已存在');
        }

        $now = time();
        $newId = Db::transaction(function () use ($row, $newCode, $now) {
            $data = $row;
            unset($data['id']);
            $data['code'] = $newCode;
            $data['name'] = mb_substr($row['name'] . '（副本）', 0, 64);
            $data['is_system'] = 0;
            $data['version'] = 1;
            $data['status'] = 0;
            $data['create_time'] = $now;
            $data['update_time'] = $now;
            $newId = (int)Db::name('message_template')->insertGetId($data);

            $channels = Db::name('message_template_channel')
                ->where('shopid', $this->shopid)
                ->where('template_id', $row['id'])
                ->select()
                ->toArray();
            foreach ($channels as $channel) {
                unset($channel['id']);
                $channel['template_id'] = $newId;
                $channel['template_code'] = $newCode;
                $channel['create_time'] = $now;
                $channel['update_time'] = $now;
                Db::name('message_template_channel')->insert($channel);
            }

            return $newId;
        });

        return $this->success('复制成功', ['id' => (int)$newId, 'code' => $newCode]);
    }

    /**
     * 模板测试发送（单个用户单渠道，is_test=1）
     *
     * @return \think\Response
     */
    public function templateTest()
    {
        $id = input('id', 0, 'intval');
        $code = trim((string)input('code', '', 'trim'));
        $uid = input('uid', 0, 'intval');
        $mobile = trim((string)input('mobile', '', 'trim'));
        $email = trim((string)input('email', '', 'trim'));
        $vars = input('vars/a', []);
        $channel = (string)input('channel', 'inbox', 'trim');

        if ($mobile !== '' && !preg_match('/^\+?[0-9\-]{6,20}$/', $mobile)) {
            return $this->error('手机号格式不正确');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('邮箱格式不正确');
        }
        // 测试发送会真实调用服务商，限制同一管理员 60 秒内最多 10 次，避免成为短信/邮件轰炸入口
        try {
            $rateKey = 'msg:test:' . (int)get_uid();
            $count = (int)Cache::get($rateKey, 0);
            if ($count >= 10) {
                return $this->error('测试发送过于频繁，请稍后再试');
            }
            Cache::set($rateKey, $count + 1, 60);
        } catch (\Throwable $e) {
            // 缓存不可用时降级，不阻断后台功能
        }

        $row = $id > 0
            ? Db::name('message_template')->where('id', $id)->where('shopid', $this->shopid)->find()
            : Db::name('message_template')->where('shopid', $this->shopid)->where('code', $code)->find();
        if (empty($row)) {
            return $this->error('模板不存在');
        }
        if (empty($row['status'])) {
            return $this->error('模板已停用，请先启用');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            return $this->error('不支持的渠道');
        }

        if ($channel === 'sms' && $mobile !== '') {
            // 未注册手机号测试
            if ($uid <= 0) {
                try {
                    $result = MessageCenterService::sendNowToMobile((string)$row['code'], $mobile, (array)$vars, [
                        'shopid' => $this->shopid,
                        'is_test' => 1,
                    ]);
                } catch (MessageException $e) {
                    return $this->error($e->getMessage());
                }
                return $result['success'] ? $this->success('测试发送成功', $result) : $this->error($result['error_msg'] ?: '测试发送失败', $result);
            }
        }

        if ($channel === 'email' && $email !== '') {
            // 指定邮箱测试：未注册邮箱也可直接投递
            try {
                $result = MessageCenterService::sendNowToEmail((string)$row['code'], $email, (array)$vars, [
                    'shopid'  => $this->shopid,
                    'uid'     => $uid,
                    'is_test' => 1,
                    'biz_key' => 'test_' . time() . '_' . mt_rand(1000, 9999),
                ]);
            } catch (MessageException $e) {
                return $this->error($e->getMessage(), ['error_code' => $e->getErrorCode()]);
            }
            return $result['success'] ? $this->success('测试发送成功', $result) : $this->error($result['error_msg'] ?: '测试发送失败', $result);
        }

        if ($uid <= 0) {
            return $this->error('请选择测试接收用户');
        }

        if ($channel !== 'inbox' && MessageSuppression::isActive((int)$this->shopid, $uid)) {
            return $this->error('该用户已在黑名单中，已阻止测试发送');
        }

        try {
            $result = MessageCenterService::sendNow((string)$row['code'], $uid, (array)$vars, [
                'shopid'   => $this->shopid,
                'channels' => [$channel],
                'is_test'  => 1,
                'biz_key'  => 'test_' . time() . '_' . mt_rand(1000, 9999),
            ]);
        } catch (MessageException $e) {
            return $this->error($e->getMessage(), ['error_code' => $e->getErrorCode()]);
        }

        if (!$result['success']) {
            return $this->error($result['error_msg'] ?: '测试发送失败', $result);
        }

        return $this->success('测试发送成功', $result);
    }

    /* ==================== 任务 ==================== */

    /**
     * 任务列表 + 概览
     *
     * @return \think\Response
     */
    public function task()
    {
        $page = max(1, (int)input('page', 1, 'intval'));
        $rows = min(100, max(1, input('rows', 20, 'intval')));
        $status = trim((string)input('status', '', 'trim'));
        $code = trim((string)input('template_code', '', 'trim'));
        $keyword = trim((string)input('keyword', '', 'trim'));

        $query = Db::name('message_task')->where('shopid', $this->shopid);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($code !== '') {
            $query->where('template_code', $code);
        }
        if ($keyword !== '') {
            $query->where('title', 'like', '%' . $keyword . '%');
        }

        $total = (clone $query)->count();
        $list = $query->order('id desc')
            ->page($page, $rows)
            ->select()
            ->toArray();

        $counters = (new StatService())->recordCounters(array_column($list, 'id'), (int)$this->shopid);

        $data = [];
        foreach ($list as $row) {
            $data[] = $this->formatTask($row, $counters[(int)$row['id']] ?? []);
        }

        return $this->success('success', [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'rows'     => $rows,
            'overview' => (new StatService())->overview((int)$this->shopid, 7),
        ]);
    }

    /**
     * 新建任务（GET 初始化数据 / POST 创建）
     *
     * @return \think\Response
     */
    public function taskCreate()
    {
        if (!request()->isPost()) {
            $templates = Db::name('message_template')
                ->where('shopid', $this->shopid)
                ->where('status', 1)
                ->order('sort asc,id asc')
                ->field('id,code,name,level,variables,fallback,always_inbox,frequency_rule')
                ->select()
                ->toArray();

            $list = [];
            foreach ($templates as $template) {
                $list[] = [
                    'id'           => (int)$template['id'],
                    'code'         => $template['code'],
                    'name'         => $template['name'],
                    'level'        => $template['level'],
                    'level_label'  => MessageLevel::labels()[$template['level']] ?? $template['level'],
                    'variables'    => $this->decodeArray($template['variables']),
                    'fallback'     => $this->decodeFallback($template['fallback']),
                    'always_inbox' => (int)$template['always_inbox'],
                    'frequency'    => $this->decodeFrequency($template['frequency_rule']),
                ];
            }

            $groups = Db::name('auth_group')->field('id,title')->order('id asc')->select()->toArray();

            return $this->success('success', [
                'templates' => $list,
                'groups'    => $groups,
                'channels'  => $this->channelStatus(),
                'levels'    => MessageLevel::labels(),
            ]);
        }

        $code = trim((string)input('template_code', '', 'trim'));
        $title = trim((string)input('title', '', 'trim'));
        $targetType = (string)input('target_type', 'uids', 'trim');
        $uids = input('uids/a', []);
        $groupIds = input('group_ids/a', []);
        $vars = input('vars/a', []);
        $channels = input('channels/a', []);
        $sendTime = trim((string)input('send_time', '', 'trim'));

        if ($code === '') {
            return $this->error('请选择消息模板');
        }
        // message_task.title 为 varchar(128)，超长会直接插入失败
        if (mb_strlen($title, 'utf-8') > 128) {
            return $this->error('任务标题不能超过 128 个字符');
        }
        if ($targetType === 'uids') {
            $uids = array_values(array_unique(array_filter(array_map('intval', (array)$uids))));
            if (empty($uids)) {
                return $this->error('请选择接收用户');
            }
            $targets = ['type' => 'uids', 'value' => $uids];
        } else {
            $groupIds = array_values(array_unique(array_filter(array_map('intval', (array)$groupIds))));
            if (empty($groupIds)) {
                return $this->error('请选择接收用户组');
            }
            $targets = ['type' => 'groups', 'value' => $groupIds];
        }

        $channels = array_values(array_unique(array_intersect(array_map('strval', (array)$channels), self::CHANNELS)));
        if (count($channels) > 3) {
            return $this->error('渠道数量不能超过 3 个');
        }

        $sendTimestamp = 0;
        // 立即发送时前端会传 0/'0'；历史前端 value-format="x" 会传毫秒时间戳，统一折算为秒
        if ($sendTime !== '' && $sendTime !== '0') {
            $sendTimestamp = is_numeric($sendTime) ? (int)$sendTime : (int)strtotime($sendTime);
            if ($sendTimestamp > 100000000000) {
                $sendTimestamp = (int)($sendTimestamp / 1000);
            }
            if ($sendTimestamp <= 0) {
                return $this->error('发送时间格式不正确');
            }
            if ($sendTimestamp < time()) {
                return $this->error('发送时间不能早于当前时间');
            }
        }

        try {
            $result = MessageCenterService::send($code, $targets, (array)$vars, [
                'shopid'       => $this->shopid,
                'channels'     => $channels,
                'send_time'    => $sendTimestamp,
                'title'        => $title,
                'operator_uid' => get_uid(),
                'biz_key'      => 'admin_' . get_uid() . '_' . time() . '_' . mt_rand(1000, 9999),
            ]);
        } catch (MessageException $e) {
            return $this->error($e->getMessage(), ['error_code' => $e->getErrorCode()]);
        }

        return $this->success('任务已提交', $result);
    }

    /**
     * 发送前预览
     *
     * @return \think\Response
     */
    public function taskPreview()
    {
        $code = trim((string)input('template_code', '', 'trim'));
        $vars = input('vars/a', []);
        $channels = input('channels/a', []);
        $targetType = (string)input('target_type', 'uids', 'trim');
        $uids = input('uids/a', []);
        $groupIds = input('group_ids/a', []);

        if ($code === '') {
            return $this->error('请选择消息模板');
        }

        $targets = $targetType === 'groups'
            ? ['type' => 'groups', 'value' => array_map('intval', (array)$groupIds)]
            : ['type' => 'uids', 'value' => array_map('intval', (array)$uids)];

        try {
            $data = MessageCenterService::preview(
                (int)$this->shopid,
                $code,
                (array)$vars,
                $targets,
                array_map('strval', (array)$channels)
            );
        } catch (MessageException $e) {
            return $this->error($e->getMessage(), ['error_code' => $e->getErrorCode()]);
        }

        return $this->success('success', $data);
    }

    /**
     * 任务详情 + 渠道进度 + 失败原因 Top5
     *
     * @return \think\Response
     */
    public function taskDetail()
    {
        $id = input('id', 0, 'intval');
        $row = Db::name('message_task')->where('id', $id)->where('shopid', $this->shopid)->find();
        if (empty($row)) {
            return $this->error('任务不存在');
        }

        $statService = new StatService();
        $counters = array_merge(
            $statService->recordCounters([$id], (int)$this->shopid)[$id] ?? [],
            $statService->userCounters($id, (int)$this->shopid)
        );

        $task = $this->formatTask($row, $counters);
        $stat = $statService->task($id, (int)$this->shopid);

        return $this->success('success', [
            'task' => $task,
            'stat' => $stat,
        ]);
    }

    /**
     * 取消任务
     *
     * @return \think\Response
     */
    public function taskCancel()
    {
        $id = input('id', 0, 'intval');
        $ok = MessageCenterService::cancel($id, (int)$this->shopid);

        return $ok ? $this->success('任务已取消') : $this->error('任务不存在或已结束');
    }

    /**
     * 任务失败项重试
     *
     * @return \think\Response
     */
    public function taskRetry()
    {
        $id = input('id', 0, 'intval');
        $summary = MessageCenterService::retry($id, (int)$this->shopid);

        return $this->success('重试完成', $summary);
    }

    /* ==================== 记录 ==================== */

    /**
     * 记录列表
     *
     * @return \think\Response
     */
    public function record()
    {
        $page = max(1, (int)input('page', 1, 'intval'));
        $rows = min(100, max(1, input('rows', 20, 'intval')));
        $status = trim((string)input('status', '', 'trim'));
        $channel = trim((string)input('channel', '', 'trim'));
        $code = trim((string)input('template_code', '', 'trim'));
        $uid = input('uid', 0, 'intval');
        $taskId = input('task_id', 0, 'intval');
        $isRead = trim((string)input('is_read', '', 'trim'));
        $start = trim((string)input('start_time', '', 'trim'));
        $end = trim((string)input('end_time', '', 'trim'));

        $query = Db::name('message_record')->where('shopid', $this->shopid);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        if ($code !== '') {
            $query->where('template_code', $code);
        }
        if ($uid > 0) {
            $query->where('uid', $uid);
        }
        if ($taskId > 0) {
            $query->where('task_id', $taskId);
        }
        // 已读状态只存在于站内信投递记录（message.is_read），仅对 inbox 渠道生效
        if ($isRead === '0' || $isRead === '1') {
            $prefix = Db::getConfig('prefix');
            $query->where('channel', 'inbox')
                ->whereRaw("inbox_id IN (SELECT id FROM {$prefix}message WHERE shopid = ? AND is_read = ?)", [$this->shopid, (int)$isRead]);
        }
        $startTs = $start !== '' ? (int)strtotime($start) : 0;
        $endTs = $end !== '' ? (int)strtotime($end) : 0;
        if ($startTs > 0) {
            $query->where('create_time', '>=', $startTs);
        }
        if ($endTs > 0) {
            // daterange 只传日期，结束日需包含当天 23:59:59；解析失败也不能悄悄退化成 time()
            $query->where('create_time', '<=', $endTs + 86399);
        }

        $total = (clone $query)->count();
        $list = $query->order('id desc')->page($page, $rows)->select()->toArray();

        $uids = array_values(array_unique(array_column($list, 'uid')));
        $nicknames = empty($uids) ? [] : Db::name('member')->whereIn('uid', $uids)->column('nickname', 'uid');
        $readMap = $this->inboxReadMap($list);

        $data = [];
        foreach ($list as $row) {
            $data[] = $this->formatRecord($row, $nicknames, $readMap);
        }

        return $this->success('success', [
            'data'  => $data,
            'total' => $total,
            'page'  => $page,
            'rows'  => $rows,
        ]);
    }

    /**
     * 单条重试
     *
     * @return \think\Response
     */
    public function recordRetry()
    {
        $id = input('id', 0, 'intval');
        $row = Db::name('message_record')->where('id', $id)->where('shopid', $this->shopid)->find();
        if (empty($row)) {
            return $this->error('记录不存在');
        }

        $result = (new Dispatcher())->retryRecord($id, true);
        if ($result['status'] === 'success') {
            return $this->success('重试成功', $result);
        }
        if ($result['status'] === 'skipped') {
            return $this->error('当前状态无需重试', $result);
        }

        return $this->error('重试未成功：' . MessageException::text((string)($result['reason'] ?? 'provider_error')), $result);
    }

    /**
     * 记录导出（CSV）
     *
     * @return void
     */
    public function recordExport()
    {
        $status = trim((string)input('status', '', 'trim'));
        $channel = trim((string)input('channel', '', 'trim'));
        $code = trim((string)input('template_code', '', 'trim'));
        $taskId = input('task_id', 0, 'intval');
        $uid = input('uid', 0, 'intval');
        $isRead = trim((string)input('is_read', '', 'trim'));
        $start = trim((string)input('start_time', '', 'trim'));
        $end = trim((string)input('end_time', '', 'trim'));

        $query = Db::name('message_record')->where('shopid', $this->shopid);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        if ($code !== '') {
            $query->where('template_code', $code);
        }
        if ($taskId > 0) {
            $query->where('task_id', $taskId);
        }
        if ($uid > 0) {
            $query->where('uid', $uid);
        }
        $startTs = $start !== '' ? (int)strtotime($start) : 0;
        $endTs = $end !== '' ? (int)strtotime($end) : 0;
        if ($startTs > 0) {
            $query->where('create_time', '>=', $startTs);
        }
        if ($endTs > 0) {
            $query->where('create_time', '<=', $endTs + 86399);
        }
        if ($isRead === '0' || $isRead === '1') {
            $prefix = Db::getConfig('prefix');
            $query->where('channel', 'inbox')
                ->whereRaw("inbox_id IN (SELECT id FROM {$prefix}message WHERE shopid = ? AND is_read = ?)", [$this->shopid, (int)$isRead]);
        }

        // 导出上限 5000 条（需求 §7.7），避免大表一次性读进内存
        $exportLimit = 5000;
        $total = (clone $query)->count();
        if ($total > $exportLimit) {
            // 导出走 window.open 直接下载，超限时也必须返回可下载的 CSV，而不是纯文本页面
            $this->sendCsvHeaders('message_records_limit_' . date('YmdHis') . '.csv');
            echo "\xEF\xBB\xBF" . $this->csvEscape('提示') . "\n"
                . $this->csvEscape('匹配记录 ' . $total . ' 条，超过单次导出上限 ' . $exportLimit . ' 条，请缩小时间范围或增加筛选条件后重试。') . "\n";
            return;
        }

        $list = $query->order('id desc')->limit($exportLimit)->select()->toArray();
        $uids = array_values(array_unique(array_column($list, 'uid')));
        $nicknames = empty($uids) ? [] : Db::name('member')->whereIn('uid', $uids)->column('nickname', 'uid');

        $statusMap = $this->statusLabels();
        $readMap = $this->inboxReadMap($list);
        $data = "\xEF\xBB\xBF" . '记录ID,任务ID,模板编码,渠道,用户UID,昵称,状态,已读状态,拦截原因,错误码,第三方ID,尝试次数,发送时间' . "\n";
        foreach ($list as $row) {
            $isReadText = '';
            if ($row['channel'] === 'inbox' && isset($readMap[(int)$row['inbox_id']])) {
                $isReadText = (int)$readMap[(int)$row['inbox_id']] === 1 ? '已读' : '未读';
            }
            $data .= implode(',', [
                (int)$row['id'],
                (int)$row['task_id'],
                $this->csvEscape((string)$row['template_code']),
                $this->csvEscape((string)$row['channel']),
                (int)$row['uid'],
                $this->csvEscape((string)($nicknames[$row['uid']] ?? '')),
                $this->csvEscape((string)($statusMap[$row['status']] ?? $row['status'])),
                $this->csvEscape($isReadText),
                $this->csvEscape((string)$row['block_reason']),
                $this->csvEscape((string)$row['error_code']),
                $this->csvEscape((string)$row['third_msg_id']),
                (int)$row['attempts'],
                $this->csvEscape($row['send_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['send_time']) : ''),
            ]) . "\n";
        }

        $this->sendCsvHeaders('message_records_' . date('YmdHis') . '.csv');
        echo $data;
    }

    /**
     * CSV 下载响应头
     *
     * @param string $filename
     * @return void
     */
    protected function sendCsvHeaders(string $filename): void
    {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Cache-Control: must-revalidate,post-check=0,pre-check=0');
        header('Expires: 0');
        header('Pragma: public');
    }

    /* ==================== 设置 ==================== */

    /**
     * 触达设置
     *
     * @return \think\Response
     */
    public function setting()
    {
        $config = $this->channels->configProvider()->get((int)$this->shopid);

        $values = [];
        foreach (['MESSAGE_QUIET_ENABLED', 'MESSAGE_QUIET_START', 'MESSAGE_QUIET_END', 'MESSAGE_BATCH_SIZE', 'MESSAGE_RETRY_MAX', 'MESSAGE_FREQ_MAX', 'MESSAGE_FREQ_WINDOW', 'MESSAGE_DEFAULT_CHAIN', 'MESSAGE_CHANNEL_EMAIL', 'MESSAGE_CHANNEL_SMS', 'SMS_SEND_DRIVER'] as $name) {
            $values[$name] = (string)config('extend.' . $name, '');
        }

        return $this->success('success', [
            'values'   => $values,
            'channels' => $this->channelStatus(),
            'quiet'    => $config['quiet'],
            'defaults' => [
                'batch_size'    => $config['batch_size'],
                'retry_max'     => $config['retry_max'],
                'frequency'     => $config['frequency'],
                'default_chain' => $config['default_chain'],
            ],
            'channel_options' => [
                ['key' => 'inbox', 'label' => '站内信'],
                ['key' => 'email', 'label' => '邮件'],
                ['key' => 'sms', 'label' => '短信'],
            ],
        ]);
    }

    /**
     * 保存触达设置
     *
     * @return \think\Response
     */
    public function settingSave()
    {
        if (!request()->isPost()) {
            return $this->error('请求方式错误');
        }

        $input = request()->param();

        $timePattern = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $start = (string)($input['MESSAGE_QUIET_START'] ?? '');
        $end = (string)($input['MESSAGE_QUIET_END'] ?? '');
        if ($start !== '' && !preg_match($timePattern, $start)) {
            return $this->error('免打扰开始时间格式应为 HH:MM');
        }
        if ($end !== '' && !preg_match($timePattern, $end)) {
            return $this->error('免打扰结束时间格式应为 HH:MM');
        }

        $batch = (int)($input['MESSAGE_BATCH_SIZE'] ?? 500);
        if ($batch < 100 || $batch > 2000) {
            return $this->error('批次大小需在 100-2000 之间');
        }
        $retry = (int)($input['MESSAGE_RETRY_MAX'] ?? 3);
        if ($retry < 0 || $retry > 5) {
            return $this->error('重试次数需在 0-5 之间');
        }
        $freqMax = (int)($input['MESSAGE_FREQ_MAX'] ?? 3);
        $freqWindow = (int)($input['MESSAGE_FREQ_WINDOW'] ?? 24);
        if ($freqMax < 1 || $freqMax > 100 || $freqWindow < 1 || $freqWindow > 8760) {
            return $this->error('频控参数不合法');
        }

        $chain = array_values(array_intersect(
            array_filter(array_map('trim', explode(',', (string)($input['MESSAGE_DEFAULT_CHAIN'] ?? 'sms')))),
            ['inbox', 'email', 'sms']
        ));
        if (empty($chain)) {
            return $this->error('默认兜底链不能为空');
        }

        $values = [
            'MESSAGE_QUIET_ENABLED' => (int)($input['MESSAGE_QUIET_ENABLED'] ?? 0) ? '1' : '0',
            'MESSAGE_QUIET_START'   => $start,
            'MESSAGE_QUIET_END'     => $end,
            'MESSAGE_BATCH_SIZE'    => (string)$batch,
            'MESSAGE_RETRY_MAX'     => (string)$retry,
            'MESSAGE_FREQ_MAX'      => (string)$freqMax,
            'MESSAGE_FREQ_WINDOW'   => (string)$freqWindow,
            'MESSAGE_DEFAULT_CHAIN' => implode(',', $chain),
            'MESSAGE_CHANNEL_EMAIL' => (int)($input['MESSAGE_CHANNEL_EMAIL'] ?? 0) ? '1' : '0',
            'MESSAGE_CHANNEL_SMS'   => (int)($input['MESSAGE_CHANNEL_SMS'] ?? 0) ? '1' : '0',
        ];

        $now = time();
        foreach ($values as $name => $value) {
            $exists = Db::name('ExtendConfig')->where('name', $name)->value('id');
            if ($exists) {
                Db::name('ExtendConfig')->where('id', $exists)->update(['value' => $value, 'update_time' => $now]);
            } else {
                Db::name('ExtendConfig')->insert([
                    'name'        => $name,
                    'type'        => 'string',
                    'title'       => $name,
                    'group'       => 'message',
                    'value'       => $value,
                    'status'      => 1,
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
        }

        // 触达设置写在全局 extend_config，任一店铺保存都必须让所有店铺的缓存失效
        $shopids = Db::name('message_task')->distinct(true)->column('shopid');
        $shopids[] = 0;
        $shopids[] = (int)$this->shopid;
        foreach (array_unique($shopids) as $sid) {
            $this->channels->configProvider()->invalidate((int)$sid);
        }
        $this->clearExtendConfigCache();

        return $this->success('保存成功');
    }

    /**
     * 黑名单管理（GET 列表 / POST 新增或解除）
     *
     * @return \think\Response
     */
    public function blacklist()
    {
        if (!request()->isPost()) {
            $page = max(1, (int)input('page', 1, 'intval'));
            $rows = min(100, max(1, input('rows', 20, 'intval')));
            $uid = input('uid', 0, 'intval');
            $status = input('status', 1, 'intval');

            $query = Db::name('message_suppression')->where('shopid', $this->shopid);
            if ($uid > 0) {
                $query->where('uid', $uid);
            }
            if ($status >= 0) {
                $query->where('status', $status);
            }

            $total = (clone $query)->count();
            $list = $query->order('id desc')->page($page, $rows)->select()->toArray();

            $uids = array_values(array_unique(array_column($list, 'uid')));
            $nicknames = empty($uids) ? [] : Db::name('member')->whereIn('uid', $uids)->column('nickname', 'uid');

            $data = [];
            foreach ($list as $row) {
                $data[] = [
                    'id'            => (int)$row['id'],
                    'uid'           => (int)$row['uid'],
                    'nickname'      => $nicknames[$row['uid']] ?? '',
                    'type'          => $row['type'],
                    'reason'        => $row['reason'],
                    'status'        => (int)$row['status'],
                    'expire_time'   => (int)$row['expire_time'],
                    'expire_text'   => $row['expire_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['expire_time']) : '永久',
                    'create_time'   => (int)$row['create_time'],
                    'create_text'   => time_format((int)$row['create_time']),
                    'release_text'  => $row['release_time'] > 0 ? time_format((int)$row['release_time']) : '',
                ];
            }

            return $this->success('success', [
                'data'  => $data,
                'total' => $total,
                'page'  => $page,
                'rows'  => $rows,
            ]);
        }

        $action = (string)input('action', 'add', 'trim');
        $uid = input('uid', 0, 'intval');
        if ($uid <= 0) {
            return $this->error('请选择用户');
        }

        $now = time();
        $where = ['shopid' => $this->shopid, 'uid' => $uid];
        // 解黑/重新拉黑必须定位「仍生效」的那行，避免命中历史已解除记录
        $active = Db::name('message_suppression')->where($where)->where('status', 1)->order('id desc')->find();

        if ($action === 'release') {
            if (empty($active)) {
                return $this->error('黑名单记录不存在');
            }
            Db::name('message_suppression')->where('id', $active['id'])->update([
                'status'       => 0,
                'release_uid'  => get_uid(),
                'release_time' => $now,
                'update_time'  => $now,
            ]);
            return $this->success('已解除黑名单');
        }

        $expireTime = (int)input('expire_time', 0, 'intval');
        // 兼容历史前端 value-format="x"（毫秒）与非法的过去时间
        if ($expireTime > 100000000000) {
            $expireTime = (int)($expireTime / 1000);
        }
        if ($expireTime > 0 && $expireTime <= $now) {
            return $this->error('失效时间必须晚于当前时间');
        }

        $data = [
            'shopid'      => $this->shopid,
            'uid'         => $uid,
            'type'        => (string)input('type', 'manual', 'trim'),
            'reason'      => mb_substr((string)input('reason', '', 'trim'), 0, 255),
            'operator_uid' => get_uid(),
            'status'      => 1,
            'expire_time' => $expireTime,
            'update_time' => $now,
        ];

        $exists = $active ?: Db::name('message_suppression')->where($where)->order('id desc')->find();
        if ($exists) {
            Db::name('message_suppression')->where('id', $exists['id'])->update($data);
        } else {
            $data['create_time'] = $now;
            Db::name('message_suppression')->insert($data);
        }

        return $this->success('已加入黑名单');
    }

    /* ==================== 内部方法 ==================== */

    /**
     * 渠道内容规范化
     *
     * @param array $input
     * @param array $variables
     * @return array
     */
    protected function normalizeChannels(array $input, array $variables): array
    {
        $channels = [];

        // 兼容 {channel => {...}} 与 [{channel: 'sms', ...}]
        if (isset($input[0]) && is_array($input[0])) {
            $normalized = [];
            foreach ($input as $item) {
                if (!empty($item['channel'])) {
                    $normalized[$item['channel']] = $item;
                }
            }
            $input = $normalized;
        }

        foreach ($input as $channel => $content) {
            if (!in_array($channel, self::CHANNELS, true) || !is_array($content)) {
                continue;
            }

            $extra = isset($content['extra']) && is_array($content['extra']) ? $content['extra'] : [];
            if (isset($extra['provider_template_id']) && is_array($extra['provider_template_id'])) {
                $extra['provider_template_id'] = array_filter(array_map('strval', $extra['provider_template_id']));
            }

            $varMap = isset($content['var_map']) && is_array($content['var_map']) ? $content['var_map'] : [];
            $varMap = array_filter($varMap, function ($value, $key) use ($variables) {
                return is_string($key) && $key !== '' && in_array((string)$value, $variables, true);
            }, ARRAY_FILTER_USE_BOTH);

            $channels[$channel] = [
                'title'             => isset($content['title']) && $content['title'] !== null ? (string)$content['title'] : null,
                'content'           => isset($content['content']) && $content['content'] !== null ? (string)$content['content'] : null,
                'extra'             => $extra,
                'var_map'           => $varMap,
                'third_template_id' => (string)($content['third_template_id'] ?? ''),
            ];
        }

        return $channels;
    }

    /**
     * 表单化任务
     *
     * @param array $row
     * @param array $counters 记录明细聚合值（优先于任务表计数器）
     * @return array
     */
    protected function formatTask(array $row, array $counters = []): array
    {
        $statusMap = $this->statusLabels();
        $cancelable = in_array($row['status'], [MessageTask::STATUS_PENDING, MessageTask::STATUS_RUNNING, MessageTask::STATUS_SCHEDULED], true);
        $channelText = implode('/', array_map(function ($channel) {
            return $this->channelLabels()[$channel] ?? $channel;
        }, $this->decodeArray($row['channels'])));

        $userDone    = (int)($counters['user_done'] ?? $row['user_done']);
        $userSuccess = (int)($counters['user_success'] ?? $row['user_success']);
        $userFailed  = (int)($counters['user_failed'] ?? $row['user_failed']);
        $userBlocked = (int)($counters['user_blocked'] ?? $row['user_blocked']);
        $recordTotal   = (int)($counters['record_total'] ?? $row['record_total']);
        $recordSuccess = (int)($counters['record_success'] ?? $row['record_success']);
        $recordFailed  = (int)($counters['record_failed'] ?? $row['record_failed']);
        $recordBlocked = (int)($counters['record_blocked'] ?? $row['record_blocked']);

        return [
            'id'             => (int)$row['id'],
            'title'          => $row['title'],
            'template_code'  => $row['template_code'],
            'level'          => $row['level'],
            'level_label'    => MessageLevel::labels()[$row['level']] ?? $row['level'],
            'status'         => $row['status'],
            'status_label'   => $statusMap[$row['status']] ?? $row['status'],
            'channels'       => $this->decodeArray($row['channels']),
            'channel_text'   => $channelText,
            'user_total'     => (int)$row['user_total'],
            'user_done'      => $userDone,
            'user_success'   => $userSuccess,
            'user_failed'    => $userFailed,
            'user_blocked'   => $userBlocked,
            'record_total'   => $recordTotal,
            'record_success' => $recordSuccess,
            'record_failed'  => $recordFailed,
            'record_blocked' => $recordBlocked,
            'success_rate'   => $recordTotal > 0
                ? round($recordSuccess * 100 / max(1, $recordTotal - $recordBlocked), 2)
                : 0,
            'send_time'      => (int)$row['send_time'],
            'send_text'      => (int)$row['send_time'] > 0 ? date('Y-m-d H:i:s', (int)$row['send_time']) : '立即发送',
            'create_time'    => (int)$row['create_time'],
            'create_text'    => time_format((int)$row['create_time']),
            'finish_text'    => (int)$row['finish_time'] > 0 ? time_format((int)$row['finish_time']) : '',
            'cancelable'     => $cancelable,
        ];
    }

    /**
     * 表单化记录
     *
     * @param array $row
     * @param array $nicknames
     * @return array
     */
    protected function formatRecord(array $row, array $nicknames, array $readMap = []): array
    {
        $statusMap = $this->statusLabels();
        $inboxId = (int)($row['inbox_id'] ?? 0);

        return [
            'id'            => (int)$row['id'],
            'task_id'       => (int)$row['task_id'],
            'template_code' => $row['template_code'],
            'channel'       => $row['channel'],
            'channel_label' => $this->channelLabels()[$row['channel']] ?? $row['channel'],
            'uid'           => (int)$row['uid'],
            'nickname'      => $nicknames[$row['uid']] ?? '',
            'is_read'       => ($row['channel'] === 'inbox' && isset($readMap[$inboxId])) ? (int)$readMap[$inboxId] : null,
            'title'         => $row['title'],
            'status'        => $row['status'],
            'status_label'  => $statusMap[$row['status']] ?? $row['status'],
            'block_reason'  => $row['block_reason'],
            'error_code'    => $row['error_code'],
            'error_text'    => $row['error_code'] !== '' ? MessageException::text((string)$row['error_code']) : '',
            'error_msg'     => $row['error_msg'],
            'attempts'      => (int)$row['attempts'],
            'is_test'       => (int)$row['is_test'],
            'retryable'     => in_array($row['status'], [MessageRecord::STATUS_FAILED, MessageRecord::STATUS_DELAYED, MessageRecord::STATUS_BLOCKED], true),
            'send_text'     => (int)$row['send_time'] > 0 ? time_format((int)$row['send_time']) : '',
            'create_text'   => time_format((int)$row['create_time']),
        ];
    }

    /**
     * 批量取站内信已读状态
     *
     * message_record 只存 inbox_id，已读状态在 message.is_read；
     * 按当前页的 inbox_id 一次查出，避免逐行查询。
     *
     * @param array $rows message_record 行集
     * @return array<int,int> inbox_id => is_read
     */
    protected function inboxReadMap(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (($row['channel'] ?? '') === 'inbox' && (int)($row['inbox_id'] ?? 0) > 0) {
                $ids[] = (int)$row['inbox_id'];
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        return Db::name('message')->whereIn('id', $ids)->column('is_read', 'id');
    }

    /**
     * 渠道状态
     *
     * @return array
     */
    protected function channelStatus(): array
    {
        $config = $this->channels->configProvider()->get((int)$this->shopid);
        $labels = $this->channelLabels();
        $status = [];

        foreach (['inbox', 'email', 'sms'] as $channel) {
            $status[] = [
                'key'        => $channel,
                'label'      => $labels[$channel],
                'configured' => $channel === 'inbox' ? true : $this->channels->configured((int)$this->shopid, $channel),
                'enabled'    => $channel === 'inbox' ? true : !empty($config['enabled'][$channel]),
            ];
        }

        return $status;
    }

    /**
     * 渠道路由文案
     *
     * @return array
     */
    protected function channelLabels(): array
    {
        return ['inbox' => '站内信', 'email' => '邮件', 'sms' => '短信', 'wechat_oa' => '公众号', 'wechat_mp' => '小程序'];
    }

    /**
     * 记录/任务状态文案
     *
     * @return array
     */
    protected function statusLabels(): array
    {
        return [
            MessageTask::STATUS_PENDING   => '待发送',
            MessageTask::STATUS_RUNNING   => '发送中',
            MessageTask::STATUS_SCHEDULED => '定时等待',
            MessageTask::STATUS_FINISHED  => '已完成',
            MessageTask::STATUS_PARTIAL   => '部分失败',
            MessageTask::STATUS_FAILED    => '发送失败',
            MessageTask::STATUS_CANCELED  => '已取消',
            MessageRecord::STATUS_PENDING  => '待发送',
            MessageRecord::STATUS_DELAYED  => '顺延中',
            MessageRecord::STATUS_SUCCESS  => '成功',
            MessageRecord::STATUS_FAILED   => '失败',
            MessageRecord::STATUS_BLOCKED  => '已拦截',
            MessageRecord::STATUS_CANCELED => '已取消',
        ];
    }

    /**
     * JSON 解码为数组
     *
     * @param mixed $value
     * @return array
     */
    protected function decodeArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 兜底链解码
     *
     * @param mixed $value
     * @return array
     */
    protected function decodeFallback($value): array
    {
        $decoded = json_decode((string)$value, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }
        return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
    }

    /**
     * 频控规则解码
     *
     * @param mixed $value
     * @return array|null
     */
    protected function decodeFrequency($value)
    {
        $decoded = json_decode((string)$value, true);
        if (is_array($decoded) && (int)($decoded['max_times'] ?? 0) > 0) {
            return $decoded;
        }
        return null;
    }

    /* ==================== 消息类型 ==================== */

    /**
     * 消息类型列表
     *
     * 站内信按 message_type.code 映射 message.type_id，类型管理从旧消息模块并入此处。
     *
     * @return \think\Response
     */
    public function type()
    {
        $rows = Db::name('message_type')
            ->where('shopid', $this->shopid)
            ->where('status', '>', -1)
            ->order('sort desc,id asc')
            ->select()
            ->toArray();

        foreach ($rows as &$row) {
            $row['icon_80'] = empty($row['icon'])
                ? request()->domain() . '/static/common/images/nopic.png'
                : get_thumb_image($row['icon'], 80, 80);
            $row['status'] = (int)$row['status'];
            $row['sort'] = (int)$row['sort'];
            $row['status_str'] = $row['status'] === 1 ? '启用' : '禁用';
        }
        unset($row);

        return $this->success('success', $rows);
    }

    /**
     * 消息类型保存（新增/编辑）
     *
     * @return \think\Response
     */
    public function typeSave()
    {
        $id = input('id', 0, 'intval');
        $title = trim((string)input('title', '', 'trim'));
        $description = trim((string)input('description', '', 'trim'));
        $icon = trim((string)input('icon', '', 'trim'));

        if ($title === '') {
            return $this->error('请输入类型名称');
        }
        if ($description === '') {
            return $this->error('请输入描述');
        }
        if ($icon === '') {
            return $this->error('请上传图标');
        }

        $code = trim((string)input('code', '', 'trim'));
        if ($code === '') {
            $code = 'type_' . ($id > 0 ? $id : substr(md5(microtime(true) . mt_rand()), 0, 8));
        }
        if (!preg_match('/^[a-z0-9_]{1,32}$/', $code)) {
            return $this->error('类型编码只能包含小写字母、数字与下划线');
        }

        $duplicate = Db::name('message_type')
            ->where('shopid', $this->shopid)
            ->where('code', $code)
            ->where('id', '<>', $id)
            ->value('id');
        if (!empty($duplicate)) {
            return $this->error('类型编码已存在');
        }

        $now = time();
        $data = [
            'title'       => $title,
            'description' => $description,
            'icon'        => $icon,
            'code'        => $code,
            'sort'        => (int)input('sort', 0, 'intval'),
            'status'      => input('status', 1, 'intval') ? 1 : 0,
            'update_time' => $now,
        ];

        if ($id > 0) {
            $exists = Db::name('message_type')
                ->where('id', $id)
                ->where('shopid', $this->shopid)
                ->find();
            if (empty($exists)) {
                return $this->error('消息类型不存在');
            }
            Db::name('message_type')->where('id', $id)->where('shopid', $this->shopid)->update($data);
            return $this->success('编辑成功');
        }

        $data['shopid'] = $this->shopid;
        $data['create_time'] = $now;
        $newId = Db::name('message_type')->insertGetId($data);
        if (!$newId) {
            return $this->error('新建失败');
        }

        return $this->success('新建成功', ['id' => $newId]);
    }

    /**
     * 消息类型启停/删除
     *
     * @return \think\Response
     */
    public function typeStatus()
    {
        $ids = input('ids/a');
        !is_array($ids) && $ids = explode(',', (string)$ids);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        if (empty($ids)) {
            return $this->error('请选择消息类型');
        }

        $status = input('status', 0, 'intval');
        if (!in_array($status, [-1, 0, 1], true)) {
            return $this->error('状态不合法');
        }

        $title = $status === -1 ? '删除' : ($status === 1 ? '启用' : '禁用');
        $res = Db::name('message_type')
            ->where('shopid', $this->shopid)
            ->whereIn('id', $ids)
            ->update(['status' => $status, 'update_time' => time()]);

        return $res !== false ? $this->success($title . '成功') : $this->error($title . '失败');
    }

    /**
     * CSV 字段转义
     *
     * @param string $value
     * @return string
     */
    protected function csvEscape(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // 防止 Excel/WPS 把 = + - @ 开头的单元格当公式执行
        if (preg_match('/^[=+\-@\t\r]/', $value)) {
            $value = "'" . $value;
        }
        return '"' . str_replace('"', '""', $value) . '"';
    }

    /**
     * 清除扩展配置缓存（与 Extend 控制器保持同一缓存键）
     *
     * @return void
     */
    protected function clearExtendConfigCache(): void
    {
        try {
            Cache::delete(request()->host() . '_MUUCMF_EXT_CONFIG_DATA');
        } catch (\Throwable $e) {
            // 忽略
        }
    }
}
