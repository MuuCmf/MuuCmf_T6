<?php

namespace app\admin\controller;

use app\common\model\Attachment as AttachmentModel;

/**
 * 附件管理控制器
 */
class Attachment extends Admin
{
    protected AttachmentModel $AttachmentModel;
    /**
     * 构造方法
     * @access public
     */
    public function __construct()
    {
        parent::__construct();

        $this->AttachmentModel = new AttachmentModel();
    }

    /**
     * 附件列表
     */
    public function list()
    {
        // 关键字
        $keyword = input('keyword', '', 'text');
        // 驱动
        $driver = input('driver', '', 'text');
        // 类型
        $type = input('type', '', 'text');
        // 每页数量
        $rows = input('rows', 20, 'intval');
        //rows限制
        $rows = min($rows, 100);

        // 查询条件
        $map[] = ['shopid', '=', 0];

        if (!empty($driver)) {
            $map[] = ['driver', '=', $driver];
        }
        if (!empty($type)) {
            if ($type == 'video' || $type == 'image' || $type == 'application' || $type == 'audio') {
                $map[] = ['type', '=', $type];
            } else {
                $map[] = ['type', 'not in', ['video', 'image', 'application', 'audio']];
            }
        }

        if (!empty($keyword)) {
            $map[] = function ($query) use ($keyword) {
                $query->where('filename', 'like', "%{$keyword}%");
            };
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
        // 排序字段
        $order = $order_field . ' ' . $order_type;
        $fields = '*';

        $lists = $this->AttachmentModel->getListByPage($map, $order, $fields, $rows);
        $lists = $lists->toArray();

        foreach ($lists['data'] as &$val) {
            $val = $this->AttachmentModel->handle($val);

            if ($val['driver'] == 'tcvod') {
                $data = $this->AttachmentModel->vodMediaHandle($val['file_id'], $val['attachment']);
                if (!empty($data)) {
                    $val['psign'] = $data['psign'];
                    $val['all_media_url'] = $data['all_media_url'];
                }
            }
        }
        unset($val);

        // json response
        return $this->success('success', $lists);
    }

    /**
     * 附件数据写入和编辑附件表接口
     */
    public function edit()
    {
        $data = input('post.');

        $res = $this->AttachmentModel->edit($data);
        if ($res) {
            return $this->success('success');
        } else {
            return $this->error('error');
        }
    }

    /**
     * 删除附件 删除附件数据风险较大，需谨慎操作
     * 
     * @return mixed 删除结果，成功返回success，失败返回error
     * @throws \think\Exception 当参数错误或数据不存在时抛出异常
     */
    public function del()
    {
        $id = input('id', 0, 'intval');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        // 验证数据
        $data = $this->AttachmentModel->getDataById($id);
        if (empty($data)) {
            return $this->error('数据不存在');
        }

        // 删除附件数据
        $res = $this->AttachmentModel->where('id', '=', $id)->delete();
        if ($res) {
            // 删除附件文件（本地或云端对象，按附件记录 driver 分发）
            $this->AttachmentModel->removFile($data['attachment'], $data['driver'] ?? 'local');
            return $this->success('删除成功');
        } else {
            return $this->error('删除失败');
        }
    }
}
