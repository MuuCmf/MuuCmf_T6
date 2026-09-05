<?php

namespace app\common\logic;

use think\Exception;

class Base
{
    public $_status = [
        1  => '启用',
        0  => '禁用',
        -1 => '已删除',
        -2 => '审核未通过'
    ];

    /**
     * 生成缩微图
     */
    public function setImgAttr($data, $proportion = '4:3', $prefix = 'cover')
    {
        if (empty($proportion)) {
            $proportion = '4:3';
        }
        if ($proportion == '1:1') {
            $width = 100;
            $height = 100;
        }
        if ($proportion == '4:3') {
            $width = 100;
            $height = 75;
        }
        if ($proportion == '16:9') {
            $width = 100;
            $height = 56;
        }
        if ($proportion == '9:16') {
            $width = 100;
            $height = 178;
        }
        if ($proportion == '3:5') {
            $width = 100;
            $height = 167;
        }
        if ($proportion == '3:4') {
            $width = 100;
            $height = 133;
        }
        if (empty($data[$prefix])) {
            $data[$prefix . '_100'] = $data[$prefix . '_200'] = $data[$prefix . '_300'] = $data[$prefix . '_400'] = $data[$prefix . '_800'] = request()->domain() . '/static/common/images/nopic.png';
        } else {
            //处理缩微图
            $data[$prefix . '_100'] = get_thumb_image($data[$prefix], intval($width), intval($height));
            $data[$prefix . '_200'] = get_thumb_image($data[$prefix], intval($width * 2), intval($height * 2));
            $data[$prefix . '_300'] = get_thumb_image($data[$prefix], intval($width * 3), intval($height * 3));
            $data[$prefix . '_400'] = get_thumb_image($data[$prefix], intval($width * 4), intval($height * 4));
            $data[$prefix . '_800'] = get_thumb_image($data[$prefix], intval($width * 8), intval($height * 8));
            $data[$prefix . '_original'] = get_attachment_src($data[$prefix]);
        }
        return $data;
    }


    /**
     * 生成封面缩微图
     */
    public function setCoverAttr($data, $proportion = '4:3')
    {
        return $this->setImgAttr($data, $proportion, 'cover');
    }

    public function setTitleAttr($data)
    {
        if (empty($data['title'])) {
            $data['title'] = '标题为空';
        }

        return $data;
    }

    public function setStatusAttr($data, $attrArray = [])
    {
        if (isset($data['status'])) {
            if (empty($attrArray)) {
                $attrArray = $this->_status;
            }
            $data['status_str'] = $attrArray[$data['status']];
        }

        return $data;
    }

    public function setTimeAttr($data)
    {
        $data['create_time_str'] = '';
        $data['create_time_friendly_str'] = '';
        $data['update_time_str'] = '';
        $data['update_time_friendly_str'] = '';

        if (!empty($data['create_time'])) {
            $data['create_time_str'] = time_format($data['create_time']);
            $data['create_time_friendly_str'] = friendly_date($data['create_time']);
        }
        if (!empty($data['update_time'])) {
            $data['update_time_str'] = time_format($data['update_time']);
            $data['update_time_friendly_str'] = friendly_date($data['update_time']);
        }
        if (!empty($data['start_time'])) {
            $data['start_time_str'] = time_format($data['start_time']);
        }
        if (!empty($data['end_time'])) {
            $data['end_time_str'] = time_format($data['end_time']);
        }
        if (!empty($data['use_time'])) {
            $data['use_time_str'] = time_format($data['use_time']);
        }
        if (!empty($data['paid_time'])) {
            $data['paid_time_str'] = time_format($data['paid_time']);
        }
        if (!empty($data['logistic_time'])) {
            $data['logistic_time_str'] = time_format($data['logistic_time']);
        }
        if (!empty($data['reply_time'])) {
            $data['reply_time_str'] = time_format($data['reply_time']);
        }


        return $data;
    }

    /**
     * @title 模块业务逻辑
     * @param array $data
     * @param string $logic_name
     * @return mixed
     */
    public function moduleFormat($data = [], $logic_name = '')
    {
        if (!isset($data['app']) || empty($data['app'])) {
            throw new Exception('未支持的模块');
        }
        $class = "app\\{$data['app']}\\logic\\{$logic_name}";
        $data = (new $class)->formatData($data);
        return $data;
    }
}
