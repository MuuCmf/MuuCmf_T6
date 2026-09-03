<?php

namespace app\api\controller;

use app\common\controller\Api;
use app\common\model\Attachment;
use think\facade\Cache;

/**
 * 文件控制器
 * 主要用于下载模型的文件上传和下载
 */

class File extends Api
{
    //添加token验证中间件
    protected $middleware = [
        'app\\common\\middleware\\CheckAuth',
    ];
    protected $Attachment;
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->Attachment = new Attachment();
    }

    /* 通用文件上传 */
    public function upload()
    {
        $shopid = input('shopid', 0, 'intval');
        // 强制上传方法，默认自动
        $enforce = input('enforce', 'auto', 'text');
        // 自定义文件名参数
        $filename = input('filename', '', 'text');
        $uid = get_uid();
        $files = request()->file();

        if (empty($files)) {
            return $this->error('未选择文件');
        }

        $result = $this->Attachment->upload($shopid, $files, 'file', $uid, $enforce, $filename);

        if (is_array($result) && $result['code'] == 200) {
            return $this->result(200, '上传成功', $result);
        } else {
            $err_msg = '上传失败';
            if (!empty($result['msg'])) {
                $err_msg = $result['msg'];
            }
            return $this->result(0, $err_msg);
        }
    }

    /**
     * 获取直传上传策略（COS/OSS 预签名直传 / 本地中转）
     *
     * 适用于不使用云点播、仅文件上传并使用腾讯云COS/阿里云OSS 时的大文件音视频上传。
     * 流程：前端携带 type/filename/size 换取策略 → mode=direct 时 PUT upload_url 直传对象存储
     *      → 成功后调用 api/file/complete 上报写附件表；mode=local 时仍走 api/file/upload 中转。
     *
     * @return \think\Response
     */
    public function policy()
    {
        $shopid = input('shopid', 0, 'intval');
        $type = input('type', '', 'text');
        $filename = input('filename', '', 'text');
        $size = input('size', 0, 'intval');
        $enforce = input('enforce', 'auto', 'text');
        $uid = get_uid();

        // 仅支持音视频直传
        if (!in_array($type, ['video', 'audio'])) {
            return $this->result(0, '不支持的直传类型');
        }
        if (empty($filename) || $size <= 0) {
            return $this->result(0, '参数错误');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rule = $this->Attachment->getUploadRule($type);
        if (empty($ext) || !in_array($ext, $rule['ext'])) {
            return $this->result(0, '不支持的文件格式' . ($ext ? '：.' . $ext : ''));
        }
        if ($size > $rule['max']) {
            return $this->result(0, '文件大小超过限制（最大' . intval($rule['max'] / 1024 / 1024) . 'MB）');
        }

        // 存储驱动：local 走传统中转；aliyun/tencent 走对象存储预签名直传
        $driver = ($enforce == 'local') ? 'local' : config('extend.FILE_UPLOAD_DRIVER');
        if (!in_array($driver, ['local', 'aliyun', 'tencent'])) {
            $driver = 'local';
        }

        // 服务端统一下发对象键，前端不可自选路径（防路径穿越/覆盖）
        $file_dir = $rule['dir'];
        if (!empty($shopid)) {
            $shopid = intval($shopid);
            if ($shopid > 0) {
                $file_dir = $shopid . '/' . $file_dir;
            }
        }
        $name = md5(uniqid((string)mt_rand(), true)) . '.' . $ext;
        $attachment = $file_dir . '/' . $name;      // 入库 attachment 相对路径
        $object = 'attachment/' . $attachment;      // 对象存储对象键

        $expireSec = 3600; // 预签名有效期 1 小时
        $policy = [
            'mode' => 'local',
            'driver' => 'local',
            'filename' => $filename,
            'ext' => $ext,
            'size' => $size,
            'max_size' => $rule['max'],
            'accept' => $rule['ext'],
            'attachment' => $attachment,
            'upload_url' => '',
            'expire' => $expireSec,
        ];

        // 腾讯云COS 预签名直传
        if ($driver == 'tencent') {
            $uploadUrl = $this->Attachment->directSignUrl('cos', $object, $expireSec);
            if ($uploadUrl === false) {
                $signErr = $this->Attachment->getDirectSignErr();
                return $this->result(0, '腾讯云COS配置错误或签名失败' . ($signErr ? '：' . $signErr : ''));
            }
            $policy['mode'] = 'direct';
            $policy['driver'] = 'cos';
            $policy['upload_url'] = $uploadUrl;
        }
        // 阿里云OSS 预签名直传
        if ($driver == 'aliyun') {
            $uploadUrl = $this->Attachment->directSignUrl('oss', $object, $expireSec);
            if ($uploadUrl === false) {
                $signErr = $this->Attachment->getDirectSignErr();
                return $this->result(0, '阿里云OSS配置错误或签名失败' . ($signErr ? '：' . $signErr : ''));
            }
            $policy['mode'] = 'direct';
            $policy['driver'] = 'oss';
            $policy['upload_url'] = $uploadUrl;
        }

        // 签发一次性直传凭证（缓存绑定 uid/对象键/归属，回调时校验）
        $token = md5($uid . '_' . $object . '_' . time() . '_' . uniqid((string)mt_rand(), true));
        $policy['token'] = $token;
        Cache::set('file_upload_policy_' . $token, [
            'uid' => $uid,
            'shopid' => $shopid,
            'type' => $type,
            'filename' => $filename,
            'ext' => $ext,
            'size' => $size,
            'driver' => $policy['driver'],
            'attachment' => $attachment,
            'object' => $object,
            'mime' => $this->Attachment->getMimeByExt($ext),
        ], $expireSec);

        return $this->result(200, 'success', $policy);
    }

    /**
     * 直传完成回调：校验凭证后写附件表，返回与 api/file/upload 一致的附件信息
     *
     * @return \think\Response
     */
    public function complete()
    {
        $token = input('token', '', 'text');
        if (empty($token)) {
            return $this->result(0, '参数错误');
        }

        $policy = Cache::get('file_upload_policy_' . $token);
        if (empty($policy)) {
            return $this->result(0, '上传凭证已失效，请重新上传');
        }
        // 一次性凭证，校验后即失效
        Cache::delete('file_upload_policy_' . $token);

        $duration = max(0, intval(input('duration', 0, 'intval')));
        $md5 = input('md5', '', 'trim');
        $sha1 = input('sha1', '', 'trim');
        $policy['duration'] = $duration;
        $policy['md5'] = $md5 ?: null;
        $policy['sha1'] = $sha1 ?: null;

        $result = $this->Attachment->completeDirect($policy);
        if (is_array($result) && $result['code'] == 200) {
            return $this->result(200, '上传成功', $result);
        }
        $msg = is_array($result) && !empty($result['msg']) ? $result['msg'] : '保存失败';
        return $this->result(0, $msg);
    }

    /**
     * 用户头像上传
     * @return [type] [description]
     */
    public function avatar()
    {
        $shopid = input('shopid', 0, 'intval');
        $uid = get_uid();
        /* 调用文件上传组件上传文件 */
        $files = request()->file();

        if (empty($files)) {
            $return['code'] = 0;
            $return['msg'] = '未上传文件或文件大小超过限制';
            return json($return);
        }

        $arr = $this->Attachment->upload($shopid, $files, 'avatar', $uid);

        if (is_array($arr)) {
            $return['code'] = 200;
            $return['msg'] = '上传成功';
            $return['data'] = $arr;
        } else {
            $return['code'] = 0;
            $return['msg'] = '上传失败';
        }

        return json($return);
    }
    /**
     * [ueditor 编辑器方法]
     * @return [type] [description]
     */
    public function ueditor()
    {
        $shopid = input('shopid', 0, 'intval');
        $action = input('action', '', 'text');
        switch ($action) {

            case 'config':
                $result = json_decode(preg_replace("/\/\*[\s\S]+?\*\//", "", file_get_contents(PUBLIC_PATH . '/static/common/lib/ueditor/php/config.json')), true);
                return json($result);
                break;

            case 'uploadimage':
                $files = request()->file();
                if (empty($files)) {
                    $return['code'] = 0;
                    $return['msg'] = '未上传文件或文件大小超过限制';
                    return json($return);
                }

                $uid = get_uid();
                $res = $this->Attachment->upload($shopid, $files, 'file', $uid);
                $res['state'] = 'SUCCESS';

                return json($res);
                break;

            case 'uploadscrawl':
                $files = input('upfile');
                if (empty($files)) {
                    $return['code'] = 0;
                    $return['msg'] = '未上传文件或文件大小超过限制';
                    return json($return);
                }

                $uid = get_uid();
                $arr = $this->Attachment->upload($shopid, $files, 'base64', $uid);

                $result['state'] = 'SUCCESS';
                $result['url'] = $arr['url'];
                return json($result);
                break;

            case 'uploadfile':

                $files = request()->file();

                if (empty($files)) {
                    $return['code'] = 0;
                    $return['msg'] = '未上传文件或文件大小超过限制';
                    return json($return);
                }

                $uid = get_uid();
                $arr = $this->Attachment->upload($shopid, $files, 'file', $uid);

                if (is_array($arr) && $arr['code'] == 200) {
                    $result['state'] = 'SUCCESS';
                    $result['url'] = $arr['url'];
                    $result['original'] = $arr['filename'];
                } else {
                    $result['state'] = 'error';
                    $result['msg'] = '上传失败';
                }
                return json($result);

                break;

            case 'uploadvideo':
                $files = request()->file();

                if (empty($files)) {
                    $return['code'] = 0;
                    $return['msg'] = '未上传文件或文件大小超过限制';
                    return json($return);
                }

                $uid = get_uid();
                $arr = $this->Attachment->upload($shopid, $files, 'file', $uid);

                if (is_array($arr) && $arr['code'] == 200) {
                    $result['state'] = 'SUCCESS';
                    $result['url'] = $arr['url'];
                    $result['original'] = $arr['filename'];
                } else {
                    $result['state'] = 'error';
                    $result['msg'] = '上传失败';
                }
                return json($result);

                break;

            default:
                break;
        }
    }


    /**
     * 获取文件列表
     * 
     * @return json 返回文件列表数据
     * 
     * 支持以下查询参数:
     * @param string $keyword 关键字搜索
     * @param string $driver 存储驱动类型
     * @param string $type 文件类型
     * @param int $rows 每页数量,默认20条
     * @param string $order_field 排序字段,默认id
     * @param string $order_type 排序方式,默认desc
     * 
     * 返回数据包含:
     * - 基础文件信息
     * - 腾讯云点播媒体文件额外信息(psign和播放地址)
     */
    public function lists()
    {
        // 关键字
        $keyword = input('keyword', '', 'text');
        // 驱动
        $driver = input('driver', '', 'text');
        // 类型
        $type = input('type', '', 'text');
        $rows = input('rows', 20, 'intval');
        // 查询条件
        $map = [
            ['shopid', '=', 0],
        ];
        $uid = get_uid();
        if ($uid != 1 && !empty($uid)) {
            $map[] = ['uid', '=', $uid];
        }
        if (!empty($keyword)) {
            $map[] = ['filename', 'like', '%' . $keyword . '%'];
        }
        if (!empty($driver)) {
            $map[] = ['driver', '=', $driver];
        }
        if (!empty($type)) {
            $map[] = ['type', '=', $type];
        }
        // 排序
        $order_field = input('order_field', 'id', 'text');
        $order_type = input('order_type', 'desc', 'text');
        // 定义允许排序的字段白名单
        $allowed_fields = ['id', 'create_time', 'update_time'];
        $allowed_types = ['asc', 'desc'];
        // 白名单验证
        $order_field = in_array($order_field, $allowed_fields) ? $order_field : 'create_time';
        $order_type = in_array($order_type, $allowed_types) ? $order_type : 'desc';
        // 排序
        $order =  $order_field . ' ' . $order_type;
        $fields = '*';
        $lists = $this->Attachment->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            if ($val['driver'] == 'tcvod') {
                $data = $this->Attachment->vodMediaHandle($val['file_id'], $val['attachment']);
                if (!empty($data)) {
                    $val['psign'] = $data['psign'];
                    $val['all_media_url'] = $data['all_media_url'];
                }
            }
        }
        unset($val);

        // 返回数据
        return $this->success('success', $lists);
    }

    /**
     * 文件数据写入附件表接口
     */
    public function attachment()
    {
        $data = input('post.');
        $data['uid'] = get_uid();

        $res = $this->Attachment->edit($data);
        if ($res) {
            return $this->success('success');
        } else {
            return $this->error('error');
        }
    }

    /**
     * 删除附件数据风险较大，仅可删除自身上传数据
     * （前台暂不提供）
     */
    public function delete() {}
}
