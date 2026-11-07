<?php

if (!function_exists('getShort')) {
    /**
     * 限制字符串长度
     * @param        $str
     * @param int $length
     * @param string $ext
     * @return string
     */
    function getShort($str, $length = 40, $ext = '')
    {
        $str = htmlspecialchars($str);
        $str = strip_tags($str);
        $str = htmlspecialchars_decode($str);
        $strlenth = 0;
        $output = '';
        preg_match_all("/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/", $str, $match);
        foreach ($match[0] as $v) {
            preg_match("/[\xe0-\xef][\x80-\xbf]{2}/", $v, $matchs);
            if (!empty($matchs[0])) {
                $strlenth += 1;
            } elseif (is_numeric($v)) {
                //$strlenth +=  0.545;  // 字符像素宽度比例 汉字为1
                $strlenth += 0.5; // 字符字节长度比例 汉字为1
            } else {
                //$strlenth +=  0.475;  // 字符像素宽度比例 汉字为1
                $strlenth += 0.5; // 字符字节长度比例 汉字为1
            }

            if ($strlenth > $length) {
                $output .= $ext;
                break;
            }

            $output .= $v;
        }
        return $output;
    }
}

if (!function_exists('getShortSp')) {
    /**
     * 带省略号的限制字符串长
     * @param $str
     * @param $num
     * @return string
     */
    function getShortSp($str, $num)
    {
        if (utf8_strlen($str) > $num) {
            $tag = '...';
        }
        $str = getShort($str, $num) . $tag;
        return $str;
    }
}

if (!function_exists('utf8_strlen')) {
    /**
     * 计算UTF-8字符串的长度
     * 
     * 该函数用于计算包含中文等多字节字符的UTF-8编码字符串的实际字符数
     * 
     * @param string|null $string 需要计算长度的UTF-8字符串
     * @return int 返回字符串中的字符数量
     */
    function utf8_strlen($string = null)
    {
        // 将字符串分解为单元
        preg_match_all("/./us", $string, $match);
        // 返回单元个数
        return count($match[0]);
    }
}

if (!function_exists('replace_attr')) {
    /**
     * 替换HTML内容中的class和id属性
     * 
     * 该函数会移除HTML内容中的class和id属性,但会保护<pre>标签中的代码内容不被过滤
     * 
     * @param string $content 需要处理的HTML内容
     * @return string 处理后的HTML内容
     */
    function replace_attr($content)
    {
        // 阻止代码部分被过滤 过滤前
        preg_match_all('/\<pre .*?\<\/pre\>/si', $content, $matches);
        $pattens = array();
        foreach ($matches[0] as $key => $val) {
            $pattens[$key] = '{$pre}_' . $key;
            $content = str_replace($val, $pattens[$key], $content);
        }
        //阻止代码部分被过滤 过滤前end

        $content = preg_replace("/class=\".*?\"/si", "", $content);
        $content = preg_replace("/id=\".*?\"/si", "", $content);
        $content = closetags($content);

        //阻止代码部分被过滤 过滤后
        $content = str_replace($pattens, $matches[0], $content);
        //阻止代码部分被过滤 过滤后end
        return $content;
    }
}

if (!function_exists('closetags')) {
    /**
     * 闭合HTML标签
     * 检查HTML字符串中未闭合的标签并自动补全
     * 
     * @param string $html 需要处理的HTML字符串
     * @return string 补全闭合标签后的HTML字符串
     *
     * 示例:
     * closetags('<div><p>test') 返回 '<div><p>test</p></div>'
     */
    function closetags($html)
    {
        preg_match_all('#<([a-z]+)(?: .*)?(?<![/|/ ])>#iU', $html, $result);
        $openedtags = $result[1];

        preg_match_all('#</([a-z]+)>#iU', $html, $result);
        $closedtags = $result[1];
        $len_opened = count($openedtags);

        if (count($closedtags) == $len_opened) {
            return $html;
        }
        $openedtags = array_reverse($openedtags);
        $openedtags = array_diff($openedtags, array('br'));
        for ($i = 0; $i < $len_opened; $i++) {
            if (!in_array($openedtags[$i], $closedtags)) {
                $html .= '</' . $openedtags[$i] . '>';
            } else {
                unset($closedtags[array_search($openedtags[$i], $closedtags)]);
            }
        }
        return $html;
    }
}

if (!function_exists('check_image_src')) {
    /**
     * check_image_src  判断链接是否为图片
     * @param $file_path
     * @return bool
     */
    function check_image_src($file_path)
    {
        if (!is_bool(strpos($file_path, 'http://'))) {
            $header = curl_get_headers($file_path);
            $res = strpos($header['Content-Type'], 'image/');
            return is_bool($res) ? false : true;
        } else {
            return true;
        }
    }
}

if (!function_exists('filter_image')) {
    /**
     * filter_image  对图片src进行安全过滤
     * @param $content
     * @return mixed
     */
    function filter_image($content)
    {
        preg_match_all("/<[img|IMG].*?src=[\'|\"](.*?(?:[\.gif|\.jpg|\.png]))[\'|\"].*?[\/]?>/", $content, $arr); //匹配所有的图片
        if ($arr[1]) {
            foreach ($arr[1] as $v) {
                $check = check_image_src($v);
                if (!$check) {
                    $content = str_replace($v, '', $content);
                }
            }
        }
        return $content;
    }
}

if (!function_exists('filter_string')) {
    /**
     * 过滤字符串中的非法字符
     * 
     * @param string $content 需要过滤的字符串内容
     * @return mixed 如果包含非法字符返回false,否则返回过滤后的字符串
     */
    function filter_string($content)
    {
        $illegal_character = "#['!`~\/\\\%^&*()+=\$\#:;<>\]\[{}]#";
        if (preg_match($illegal_character, $content)) {
            return false;
        }

        return $content;
    }
}

if (!function_exists('check_html_tags')) {
    /**
     * 检查内容中是否包含指定的HTML标签
     * 
     * @param string $content 需要检查的内容
     * @param array $tags 需要检查的HTML标签数组，如果为空则使用默认标签列表
     * @return boolean 如果包含指定标签返回true，否则返回false
     * 
     * 默认检查的标签包括:
     * script, !DOCTYPE, meta, html, head, title, body, base, basefont,
     * noscript, applet, object, param, style, frame, frameset, noframes, iframe
     */
    function check_html_tags($content, $tags = array())
    {
        $tags = is_array($tags) ? $tags : array($tags);
        if (empty($tags)) {
            $tags = array('script', '!DOCTYPE', 'meta', 'html', 'head', 'title', 'body', 'base', 'basefont', 'noscript', 'applet', 'object', 'param', 'style', 'frame', 'frameset', 'noframes', 'iframe');
        }
        foreach ($tags as $v) {
            $res = strpos($content, '<' . $v);
            if (!is_bool($res)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('filter_base64')) {
    /**
     * filter_base64   对内容进行base64过滤
     * @param $content
     * @return mixed
     */
    function filter_base64($content)
    {
        preg_match_all("/data:.*?,(.*?)\"/", $content, $arr); //匹配base64编码
        if ($arr[1]) {
            foreach ($arr[1] as $v) {
                $base64_decode = base64_decode($v);
                $check = check_html_tags($base64_decode);
                if ($check) {
                    $content = str_replace($v, '', $content);
                }
            }
        }
        return $content;
    }
}

if (!function_exists('msubstr')) {
    /**
     * 字符串截取函数
     * 
     * @param string $str 需要截取的字符串
     * @param int $start 开始位置
     * @param int $length 截取长度
     * @param string $charset 字符编码,默认为utf-8
     * @param bool $suffix 是否在截取后加上省略号,默认为true
     * @return string 返回截取后的字符串
     *
     * 该函数支持多种字符编码(utf-8、gb2312、gbk、big5),会优先使用mb_substr()函数,
     * 其次使用iconv_substr()函数,如果都不存在则使用正则表达式匹配截取。
     */
    function msubstr($str, $start, $length, $charset = "utf-8", $suffix = true)
    {
        if (function_exists("mb_substr"))
            $slice = mb_substr($str, $start, $length, $charset);
        elseif (function_exists('iconv_substr')) {
            $slice = iconv_substr($str, $start, $length, $charset);
            if (false === $slice) {
                $slice = '';
            }
        } else {
            $re['utf-8'] = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
            $re['gb2312'] = "/[\x01-\x7f]|[\xb0-\xf7][\xa0-\xfe]/";
            $re['gbk'] = "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/";
            $re['big5'] = "/[\x01-\x7f]|[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";
            preg_match_all($re[$charset], $str, $match);
            $slice = join("", array_slice($match[0], $start, $length));
        }
        return $suffix ? $slice . '...' : $slice;
    }
}

if (!function_exists('format_bytes')) {

    /**
     * 将字节转换为可读文本
     * @param int $size 大小
     * @param string $delimiter 分隔符
     * @return string
     */
    function format_bytes($size, $delimiter = '')
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
        for ($i = 0; $size >= 1024 && $i < 6; $i++)
            $size /= 1024;
        return round($size, 2) . $delimiter . $units[$i];
    }
}

if (!function_exists('cut_str')) {
    /**
     * 字符串截取函数
     * @param string $search 需要查找的字符串
     * @param string $str 被查找的字符串
     * @param string $place 截取位置,可选值:l(左边),r(右边),默认截取匹配字符串
     * @return string 返回截取后的字符串
     */
    function cut_str($search, $str, $place = '')
    {
        switch ($place) {
            case 'l':
                $result = preg_replace('/.*?' . addcslashes(quotemeta($search), '/') . '/', '', $str);
                break;
            case 'r':
                $result = preg_replace('/' . addcslashes(quotemeta($search), '/') . '.*/', '', $str);
                break;
            default:
                $result =  preg_replace('/' . addcslashes(quotemeta($search), '/') . '/', '', $str);
        }
        return $result;
    }
}

if (!function_exists('mb_ucfirst')) {

    /**
     * 将字符串的首字母转换为大写，其余字母转换为小写
     * 支持多字节字符串(如中文、日文等)的处理
     * 
     * @param string $string 需要处理的字符串
     * @return string 处理后的字符串，首字母大写其余小写
     */
    function mb_ucfirst($string)
    {
        return mb_strtoupper(mb_substr($string, 0, 1)) . mb_strtolower(mb_substr($string, 1));
    }
}

if (!function_exists('text')) {
    /**
     * 文本格式化处理
     * @param string $text 需要处理的文本
     * @param boolean $addslanshes 是否添加转义字符,默认为false
     * @return string 返回处理后的文本
     * 
     * 功能说明:
     * 1. 将换行符转换为HTML的<br>标签
     * 2. 去除HTML和PHP标签
     * 3. 可选择是否添加转义字符
     * 4. 去除首尾空白字符
     */
    function text($text, $addslanshes = false)
    {
        $text = nl2br($text);
        $text = real_strip_tags($text);
        if ($addslanshes)
            $text = addslashes($text);
        $text = trim($text);
        return $text;
    }
}

if (!function_exists('html')) {
    /**
     * HTML内容过滤函数
     * 根据不同类型过滤HTML标签和危险属性
     * 
     * @param string $text 需要过滤的文本内容
     * @param string $type 过滤类型,可选值:text_tags|link_tags|image_tags|font_tags|base_tags|form_tags|html_tags|all_tags
     * @return string 过滤后的文本内容
     *
     * 过滤类型说明:
     * - text_tags: 无标签格式
     * - link_tags: 只保留链接
     * - image_tags: 只保留图片
     * - font_tags: 只存在字体样式
     * - base_tags: 标题摘要基本格式
     * - form_tags: 兼容Form格式
     * - html_tags: 内容等允许HTML的格式
     * - all_tags: 专题等全HTML格式
     */
    function html($text, $type = 'html')
    {
        // 无标签格式
        $text_tags = '';
        //只保留链接
        $link_tags = '<a>';
        //只保留图片
        $image_tags = '<img>';
        //只存在字体样式
        $font_tags = '<i><b><u><s><em><strong><font><big><small><sup><sub><bdo><h1><h2><h3><h4><h5><h6>';
        //标题摘要基本格式
        $base_tags = $font_tags . '<p><br><hr><a><img><map><area><pre><code><q><blockquote><acronym><cite><ins><del><center><strike>';
        //兼容Form格式
        $form_tags = $base_tags . '<form><input><textarea><button><select><optgroup><option><label><fieldset><legend>';
        //内容等允许HTML的格式
        $html_tags = $base_tags . '<ul><ol><li><dl><dd><dt><table><caption><td><th><tr><thead><tbody><tfoot><col><colgroup><div><span><object><embed><param>';
        //专题等全HTML格式
        $all_tags = $form_tags . $html_tags . '<!DOCTYPE><meta><html><head><title><body><base><basefont><script><noscript><applet><object><param><style><frame><frameset><noframes><iframe>';
        //过滤标签
        $text = real_strip_tags($text, ${$type . '_tags'});
        // 过滤攻击代码
        if ($type != 'all') {
            // 过滤危险的属性，如：过滤on事件lang js
            while (preg_match('/(<[^><]+)(ondblclick|onclick|onload|onerror|unload|onmouseover|onmouseup|onmouseout|onmousedown|onkeydown|onkeypress|onkeyup|onblur|onchange|onfocus|action|background[^-]|codebase|dynsrc|lowsrc)([^><]*)/i', $text, $mat)) {
                $text = str_ireplace($mat[0], $mat[1] . $mat[3], $text);
            }
            while (preg_match('/(<[^><]+)(window\.|javascript:|js:|about:|file:|document\.|vbs:|cookie)([^><]*)/i', $text, $mat)) {
                $text = str_ireplace($mat[0], $mat[1] . $mat[3], $text);
            }
        }
        return $text;
    }
}

if (!function_exists('real_strip_tags')) {
    /**
     * 过滤HTML标签，保留指定的标签
     * @param string $str 需要过滤的字符串
     * @param string $allowable_tags 允许保留的标签，如"<p><a>"
     * @return string 过滤后的字符串
     */
    function real_strip_tags($str, $allowable_tags = "")
    {
        // $str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
        return strip_tags($str, $allowable_tags);
    }
}

if (!function_exists('getsub_bykey')) {
    /**
     * 从数组中根据指定键值获取子集
     * 
     * @param array $pArray 需要处理的数组
     * @param string $pKey 要获取的键名，为空时返回整个子数组
     * @param array $pCondition 条件数组，格式为 [键名, 值]，用于筛选符合条件的元素
     * @return array|false 返回符合条件的子集数组，如果输入不是数组则返回 false
     * 
     * @example
     * $array = [['id'=>1, 'name'=>'张三'], ['id'=>2, 'name'=>'李四']];
     * getsub_bykey($array, 'name'); // 返回 ['张三', '李四']
     * getsub_bykey($array, 'name', ['id', 1]); // 返回 ['张三']
     */
    function getsub_bykey($pArray, $pKey = "", $pCondition = "")
    {
        $result = array();
        if (is_array($pArray)) {
            foreach ($pArray as $temp_array) {
                if (is_object($temp_array)) {
                    $temp_array = (array)$temp_array;
                }
                if (("" != $pCondition && $temp_array[$pCondition[0]] == $pCondition[1]) || "" == $pCondition) {
                    $result[] = (("" == $pKey) ? $temp_array : isset($temp_array[$pKey])) ? $temp_array[$pKey] : "";
                }
            }
            return $result;
        } else {
            return false;
        }
    }
}

if (!function_exists('create_rand')) {
    /**
     * 生成指定长度的随机字符串
     * @param int $length 生成的字符串长度，默认为8
     * @param string $type 生成字符串的类型:
     *                     'num' - 仅数字
     *                     'letter' - 仅字母
     *                     'all' - 数字和字母混合(默认)
     * @return string 返回生成的随机字符串
     */
    function create_rand($length = 8, $type = 'all')
    {
        $num = '0123456789';
        $letter = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        if ($type == 'num') {
            $chars = $num;
        } elseif ($type == 'letter') {
            $chars = $letter;
        } else {
            $chars = $letter . $num;
        }

        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $str;
    }
}

if (!function_exists('array_subtract')) {
    /**
     * 计算两个数组的差集，返回在数组 $a 中但不在数组 $b 中的元素
     * 
     * @param array $a 第一个数组
     * @param array $b 第二个数组
     * @return array 返回数组 $a 中独有的元素
     */
    function array_subtract($a, $b)
    {
        return array_diff($a, array_intersect($a, $b));
    }
}

if (!function_exists('is_json')) {
    /**
     * 判断字符串是否为 Json 格式
     *
     * @param string $data Json 字符串
     * @param bool $assoc 是否返回关联数组。默认返回对象
     * @param bool $htmlspecialchars_decode 是否进行html反转义
     * @return array|bool|object 成功返回转换后的对象或数组，失败返回 false
     */
    function is_json($data = '', $assoc = false, $htmlspecialchars_decode = false)
    {
        $data = json_decode($data, $assoc);
        if ($htmlspecialchars_decode) {
            if (($data && is_object($data)) || (is_array($data) && !empty($data))) {
                return $data;
            }
        } else {
            if (($data && is_object($data)) || (is_array($data) && !empty($data))) {
                return $data;
            }
        }
        return false;
    }
}

if (!function_exists('deep_in_array')) {
    /**
     * 多维数组中查询是否包含值
     * @param  [type] $value [description]
     * @param  [type] $array [description]
     * @return [type]        [description]
     */
    function deep_in_array($value, $array)
    {
        foreach ($array as $item) {
            if (!is_array($item)) {
                if ($item == $value) {
                    return true;
                } else {
                    continue;
                }
            }

            if (in_array($value, $item)) {
                return true;
            } else if (deep_in_array($value, $item)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('num2string')) {
    /**
     * 数字转友好显示： 如： 10000 -》 1w
     */
    function num2string($num)
    {
        if ($num >= 10000) {
            $num = number_format(round($num / 10000 * 100) / 100, 1) . 'w';
        } elseif ($num >= 1000) {
            $num = number_format(round($num / 1000 * 100) / 100, 1) . 'k';
        }
        return $num;
    }
}

if (!function_exists('create_uuid')) {
    /**
     * 生成一个UUID (通用唯一识别码)
     * 生成格式为: {8位字符-4位字符-4位字符-4位字符-12位字符}
     * 
     * @return string 返回生成的UUID字符串
     */
    function create_uuid()
    {
        mt_srand((float)microtime() * 10000); //optional for php 4.2.0 and up.
        $charid = strtoupper(md5(uniqid(rand(), true)));
        $hyphen = chr(45); // "-"
        $uuid = chr(123) // "{"
            . substr($charid, 0, 8) . $hyphen
            . substr($charid, 8, 4) . $hyphen
            . substr($charid, 12, 4) . $hyphen
            . substr($charid, 16, 4) . $hyphen
            . substr($charid, 20, 12)
            . chr(125); // "}"
        return $uuid;
    }
}

if (!function_exists('create_guid')) {
    /**
     * 生成全局唯一标识符(GUID)
     * 基于多个系统环境变量和时间戳生成唯一的GUID字符串
     * 
     * @param string $namespace 命名空间前缀,可选参数
     * @return string 返回格式化的GUID字符串,格式为:8-4-4-4-12字符
     *
     * 示例:
     * create_guid() 返回 "550E8400-E29B-41D4-A716-446655440000"
     * create_guid('test') 返回 "61B24D7F-6A85-4E87-95CA-87B9E3A1F374" 
     */
    function create_guid($namespace = '')
    {
        static $guid = '';
        $uid = uniqid("", true);
        $data = $namespace;
        $data .= $_SERVER['REQUEST_TIME'];
        $data .= $_SERVER['HTTP_USER_AGENT'];
        $data .= $_SERVER['SERVER_ADDR'];
        $data .= $_SERVER['SERVER_PORT'];
        $data .= $_SERVER['REMOTE_ADDR'];
        $data .= $_SERVER['REMOTE_PORT'];
        $hash = strtoupper(hash('ripemd128', $uid . $guid . md5($data)));
        $guid =
            substr($hash,  0,  8) .
            '-' .
            substr($hash,  8,  4) .
            '-' .
            substr($hash, 12,  4) .
            '-' .
            substr($hash, 16,  4) .
            '-' .
            substr($hash, 20, 12);
        return $guid;
    }
}

if (!function_exists('create_unique')) {
    /**
     * 生成唯一标识符
     * 基于用户代理、IP地址、时间戳和随机数生成SHA1哈希值
     * 
     * @return string 返回40位的SHA1哈希字符串
     */
    function create_unique()
    {
        $data = $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR'] . time() . rand();
        return sha1($data);
    }
}

if (!function_exists('build_order_no')) {
    /**
     * 生成订单编号
     * 
     * 根据当前时间、微秒时间戳和随机数生成唯一的订单编号
     * 格式为: 年月日 + 微秒时间戳后6位 + 6位随机数
     * 
     * @return string 返回生成的订单编号
     */
    function build_order_no()
    {
        // 随机段由 4 位提升至 6 位并使用 CSPRNG，降低订单号可预测性
        return date('Ymd') . substr(microtime(), 2, 6) . sprintf('%06d', random_int(0, 999999));
    }
}

if (!function_exists('build_serial_no')) {
    /**
     * 构建序列号
     * 调用 build_order_no() 函数生成序列号
     * 
     * @return string 返回生成的序列号
     */
    function build_serial_no()
    {
        return build_order_no();
    }
}

if (!function_exists('emoji_encode')) {
    /**
     * Emoji表情编码
     * 将包含emoji表情的字符串进行编码处理
     * 
     * @param string $str 需要编码的字符串
     * @return string 返回编码后的字符串,emoji表情会被转换为[[EMOJI:编码]]格式
     */
    function emoji_encode($str)
    {
        $strEncode = '';

        $length = mb_strlen($str, 'utf-8');

        for ($i = 0; $i < $length; $i++) {
            $_tmpStr = mb_substr($str, $i, 1, 'utf-8');
            if (strlen($_tmpStr) >= 4) {
                $strEncode .= '[[EMOJI:' . rawurlencode($_tmpStr) . ']]';
            } else {
                $strEncode .= $_tmpStr;
            }
        }

        return $strEncode;
    }
}

if (!function_exists('emoji_decode')) {
    /**
     * Emoji表情解码函数
     * 将[[EMOJI:xxx]]格式的字符串解码为对应的emoji表情
     * 
     * @param string $str 包含编码后emoji表情的字符串
     * @return string 解码后的字符串
     */
    function emoji_decode($str)
    {
        $strDecode = preg_replace_callback('|\[\[EMOJI:(.*?)\]\]|', function ($matches) {
            return rawurldecode($matches[1]);
        }, $str);
        return $strDecode;
    }
}

if (!function_exists('filter_emoji')) {
    /**
     * 过滤 Emoji 表情字符
     * 将字符串中的 Emoji 表情字符替换为空字符串
     * 
     * @param string $str 需要过滤的字符串
     * @return string 过滤后的字符串
     */
    function filter_emoji($str)
    {
        $str = preg_replace_callback(
            '/./u',
            function (array $match) {
                return strlen($match[0]) >= 4 ? '' : $match[0];
            },
            $str
        );

        return $str;
    }
}

if (!function_exists('array_to_xml')) {
    /**
     * 将数组转换为XML格式
     * @param array $arr 需要转换的数组
     * @return string 返回XML格式字符串
     * 
     * 说明:
     * - 数字类型值直接转换
     * - 非数字类型值使用CDATA包装
     * - 生成的XML以<xml>作为根节点
     */
    function array_to_xml($arr)
    {
        $xml = "<xml>";
        foreach ($arr as $key => $val) {
            if (is_numeric($val)) {
                $xml .= "<$key>$val</$key>";
            } else
                $xml .= "<$key><![CDATA[$val]]></$key>";
        }
        $xml .= "</xml>";
        return $xml;
    }
}
