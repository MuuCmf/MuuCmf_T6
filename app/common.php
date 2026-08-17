<?php

use think\facade\Db;

require_once(__DIR__ . '/common/function/attachment.php');
require_once(__DIR__ . '/common/function/builder.php');
require_once(__DIR__ . '/common/function/config.php');
require_once(__DIR__ . '/common/function/editor.php');
require_once(__DIR__ . '/common/function/member.php');
require_once(__DIR__ . '/common/function/parse.php');
require_once(__DIR__ . '/common/function/poster.php');
require_once(__DIR__ . '/common/function/qrcode.php');
require_once(__DIR__ . '/common/function/time.php');

/**
 * 系统公共库文件
 * 主要定义系统公共函数库
 */

if (!function_exists('is_really_writable')) {

    /**
     * 判断文件或文件夹是否可写
     * @param    string $file 文件或目录
     * @return    bool
     */
    function is_really_writable($file)
    {
        if (DIRECTORY_SEPARATOR === '/') {
            return is_writable($file);
        }
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/' . md5(mt_rand());
            if (($fp = @fopen($file, 'ab')) === FALSE) {
                return FALSE;
            }
            fclose($fp);
            @chmod($file, 0777);
            @unlink($file);
            return TRUE;
        } elseif (!is_file($file) OR ($fp = @fopen($file, 'ab')) === FALSE) {
            return FALSE;
        }
        fclose($fp);
        return TRUE;
    }
}

if (!function_exists('rmdirs')) {

    /**
     * 删除文件夹
     * @param string $dirname 目录
     * @param bool $withself 是否删除自身
     * @return boolean
     */
    function rmdirs($dirname, $withself = true)
    {
        if (!is_dir($dirname))
            return false;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            if(realpath($fileinfo->getRealPath()) === false){
                continue;
            }
            $todo(realpath($fileinfo->getRealPath()));
        }
        if ($withself) {
            @rmdir($dirname);
        }
        return true;
    }
}


if (!function_exists('copydirs')) {

    /**
     * 递归复制目录（包含所有文件和子目录）
     * @param string $source 源目录路径
     * @param string $dest 目标目录路径
     * @param bool $overwrite 是否覆盖已存在的文件（默认true）
     * @return bool 复制成功返回true，失败返回false
     */
    function copydirs($source, $dest, $overwrite = true)
    {
        // 1. 校验源目录是否存在
        if (!is_dir($source)) {
            trigger_error("源目录不存在：{$source}", E_USER_ERROR);
            return false;
        }

        // 2. 创建目标目录（递归创建，如不存在则创建）
        if (!is_dir($dest)) {
            if (!mkdir($dest, 0755, true)) {
                trigger_error("无法创建目标目录：{$dest}", E_USER_ERROR);
                return false;
            }
            // 修改目录权限（可选，确保后续可写入）
            chmod($dest, 0755);
        }

        // 3. 打开源目录并遍历所有内容
        $dir = opendir($source);
        if (!$dir) {
            trigger_error("无法打开源目录：{$source}", E_USER_ERROR);
            return false;
        }

        // 4. 循环处理目录中的每个文件/子目录
        while (($file = readdir($dir)) !== false) {
            // 跳过 . 和 ..（当前目录和上级目录）
            if ($file == '.' || $file == '..') {
                continue;
            }

            $sourcePath = rtrim($source, '/\\') . DIRECTORY_SEPARATOR . $file;
            $destPath = rtrim($dest, '/\\') . DIRECTORY_SEPARATOR . $file;

            // 5. 如果是子目录，递归调用自身
            if (is_dir($sourcePath)) {
                if (!copydirs($sourcePath, $destPath, $overwrite)) {
                    closedir($dir);
                    return false;
                }
            }
            // 6. 如果是文件，执行复制
            else if (is_file($sourcePath)) {
                // 判断是否覆盖：目标文件存在且不覆盖则跳过
                if (file_exists($destPath) && !$overwrite) {
                    continue;
                }
                // 复制文件（保留文件属性，如修改时间）
                if (!copy($sourcePath, $destPath)) {
                    trigger_error("文件复制失败：{$sourcePath} -> {$destPath}", E_USER_WARNING);
                    closedir($dir);
                    return false;
                }
                // 同步文件权限
                chmod($destPath, fileperms($sourcePath));
            }
        }

        // 7. 关闭目录句柄
        closedir($dir);
        return true;
    }
}


if (!function_exists('think_encrypt')) {
    /**
     * 系统加密方法
     * @param string $data 要加密的字符串
     * @param string $key 加密密钥
     * @param int $expire 过期时间 单位 秒
     * @return string
     * @author 麦当苗儿 <zuojiazi@vip.qq.com>
     */
    function think_encrypt($data, $key = '', $expire = 0)
    {
        $key = md5(empty($key) ? cache('DATA_AUTH_KEY') : $key);
        $data = base64_encode($data);
        $x = 0;
        $len = strlen($data);
        $l = strlen($key);
        $char = '';

        for ($i = 0; $i < $len; $i++) {
            if ($x == $l) $x = 0;
            $char .= substr($key, $x, 1);
            $x++;
        }

        $str = sprintf('%010d', $expire ? $expire + time() : 0);

        for ($i = 0; $i < $len; $i++) {
            $str .= chr(ord(substr($data, $i, 1)) + (ord(substr($char, $i, 1))) % 256);
        }
        return str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($str));
    }
}

if (!function_exists('think_decrypt')) {
    /**
     * 系统解密方法
     * @param  string $data 要解密的字符串 （必须是think_encrypt方法加密的字符串）
     * @param  string $key 加密密钥
     * @return string
     * @author 麦当苗儿 <zuojiazi@vip.qq.com>
     */
    function think_decrypt($data, $key = '')
    {
        $key = md5(empty($key) ? cache('DATA_AUTH_KEY') : $key);
        $data = str_replace(array('-', '_'), array('+', '/'), $data);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= substr('====', $mod4);
        }
        $data = base64_decode($data);
        $expire = substr($data, 0, 10);
        $data = substr($data, 10);

        if ($expire > 0 && $expire < time()) {
            return '';
        }
        $x = 0;
        $len = strlen($data);
        $l = strlen($key);
        $char = $str = '';

        for ($i = 0; $i < $len; $i++) {
            if ($x == $l) $x = 0;
            $char .= substr($key, $x, 1);
            $x++;
        }

        for ($i = 0; $i < $len; $i++) {
            if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1))) {
                $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
            } else {
                $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
            }
        }
        return base64_decode($str);
    }
}

if (!function_exists('data_auth_sign')) {
    /**
     * 数据签名认证
     * @param  array $data 被认证的数据
     * @return string       签名
     * @author 麦当苗儿 <zuojiazi@vip.qq.com>
     */
    function data_auth_sign($data)
    {
        //数据类型检测
        if (!is_array($data)) {
            $data = (array)$data;
        }
        ksort($data); //排序
        $code = http_build_query($data); //url编码并生成query字符串
        $sign = sha1($code); //生成签名
        return $sign;
    }
}

/**
 * 对查询结果集进行排序
 * @access public
 * @param array $list 查询结果
 * @param string $field 排序的字段名
 * @param array $sortby 排序类型
 * asc正向排序 desc逆向排序 nat自然排序
 * @return array
 */
if (!function_exists('list_sort_by')) {
    function list_sort_by($list, $field, $sortby = 'asc')
    {
        if (is_array($list)) {
            $refer = $resultSet = array();
            foreach ($list as $i => $data)
                $refer[$i] = &$data[$field];
            switch ($sortby) {
                case 'asc': // 正向排序
                    asort($refer);
                    break;
                case 'desc': // 逆向排序
                    arsort($refer);
                    break;
                case 'nat': // 自然排序
                    natcasesort($refer);
                    break;
            }
            foreach ($refer as $key => $val)
                $resultSet[] = &$list[$key];
            return $resultSet;
        }
        return false;
    }
}

if (!function_exists('create_dir_or_files')) {
    //基于数组创建目录和文件
    function create_dir_or_files($files)
    {
        foreach ($files as $key => $value) {
            if (substr($value, -1) == '/') {
                mkdir($value);
            } else {
                @file_put_contents($value, '');
            }
        }
    }
}

if (!function_exists('is_ie')) {
    function is_ie()
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $pos = strpos($userAgent, ' MSIE ');
        if ($pos === false) {
            return false;
        } else {
            return true;
        }
    }
}

if (!function_exists('curl_get_headers')) {
    /**
     * curl_get_headers 获取链接header
     * @param $url
     * @return array
     */
    function curl_get_headers($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_NOBODY, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $f = curl_exec($ch);
        curl_close($ch);
        $h = explode("\n", $f);
        $r = array();
        foreach ($h as $t) {
            $rr = explode(":", $t, 2);
            if (count($rr) == 2) {
                $r[$rr[0]] = trim($rr[1]);
            }
        }
        return $r;
    }
}

if (!function_exists('curl_request')) {
    /**
     * 发送请求
     * @param string $url 访问的URL
     * @param array $post post数据(不填则为GET)
     * @param string $cookie 提交的$cookies
     * @param int $returnCookie 是否返回$cookies
     * @return bool|string
     */
    function curl_request($url, $post = [], $cookie = '', $returnCookie = 0)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; MSIE 10.0; Windows NT 6.1; Trident/6.0)');
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_AUTOREFERER, 1);
        curl_setopt($curl, CURLOPT_REFERER, request()->domain());
        curl_setopt($curl, CURLOPT_DNS_USE_GLOBAL_CACHE, false ); #使用DNS缓存
        curl_setopt($curl, CURLOPT_DNS_CACHE_TIMEOUT, 2 );
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );#禁用IPV6
        if ($post) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        if ($cookie) {
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        }
        curl_setopt($curl, CURLOPT_HEADER, $returnCookie);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);

        $data = curl_exec($curl);
        if (curl_errno($curl)) {
            return curl_error($curl);
        }

        if ($returnCookie) {
            list($header, $body) = explode("\r\n\r\n", $data, 2);
            preg_match_all("/Set\-Cookie:([^;]*);/", $header, $matches);
            $info['cookie'] = substr($matches[1][0], 1);
            $info['content'] = $body;
            return $info;
        } else {
            return $data;
        }
    }
}

if (!function_exists('convert_url_query')) {
    /**
     * convert_url_query  转换url参数为数组
     * @param $query
     * @return array|string
     */
    function convert_url_query($query)
    {
        if(!empty($query)){
            $query = urldecode($query);
            $queryParts = explode('&', $query);
            $params = array();
            foreach ($queryParts as $param)
            {
                $item = explode('=', $param);
                $params[$item[0]] = $item[1];
            }
            return $params;
        }
        return '';
    }
}

if (!function_exists('get_http_https')) {
    //判断是http or https
    function get_http_https(){
        $url = 'http://';
        if (isset ( $_SERVER ['HTTPS'] ) && $_SERVER ['HTTPS'] == 'on') {
        $url = 'https://';
        }else{
            $url = 'http://';
        }
        return $url;
    }
}

if (!function_exists('get_url')) {
    /**
     * 获取当前完整URL
     * @return [type] [description]
     */
    function get_url() {
        $url = 'http://';
        if (isset ( $_SERVER ['HTTPS'] ) && $_SERVER ['HTTPS'] == 'on') {
            $url = 'https://';
        }
        if ($_SERVER ['SERVER_PORT'] != '80') {
            $url .= $_SERVER ['HTTP_HOST'] . ':' . $_SERVER ['SERVER_PORT'] . $_SERVER ['REQUEST_URI'];
        } else {
            $url .= $_SERVER ['HTTP_HOST'] . $_SERVER ['REQUEST_URI'];
        }
        // 兼容后面的参数组装
        if (stripos ( $url, '?' ) === false) {
            $url .= '?t=' . time ();
        }
        return $url;
    }
}

if (!function_exists('url_query')) {
    /**
     * 判断网址是否包含参数,有参数返回后缀&，无返回后缀？
     * @param  [type] $url [description]
     * @return [type]      [description]
     */
    function url_query($url){
        $array = parse_url($url);
        if(!isset($array['query'])){
            $url = $url.'?';
        }else{
            $url = $url.'&';
        }
        return $url;
    }
}


if (!function_exists('is_weixin')) {
    /**
     * 检测是否在微信客户端打开
     */
    function is_weixin(){
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false ){
            return true;
        }
        return false;
    }
}

if (!function_exists('is_miniprogram')) {
    /**
     * 检测是否在微信小程序端打开
     */
    function is_miniprogram(){
        if (strpos($_SERVER['HTTP_USER_AGENT'], 'miniProgram') !== false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('is_mobile')) {
    /**
     * 检测是否在移动端打开
     * @return bool true-移动端 false-PC端
     */
    function is_mobile()
    {
        // 兼容 CLI / 无 UA 环境，避免 PHP 8 下未定义数组键报错
        $useragent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // 匹配主流移动设备 UA（含 iPad、微信浏览器 MicroMessenger）
        if (preg_match('/(android|bb\\d+|meego).+mobile|avantgo|bada\\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od|ad)|iris|kindle|lge |maemo|midp|mmp|mobile.+firefox|micromessenger|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $useragent)) {
            return true;
        }

        // 兜底：匹配早期功能机 UA 前 4 位特征码
        if (preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\\-(n|u)|c55\\/|capi|ccwa|cdm\\-|cell|chtm|cldc|cmd\\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\\-s|devi|dica|dmob|do(c|p)o|ds(12|\\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\\-|_)|g1 u|g560|gene|gf\\-5|g\\-mo|go(\\.w|od)|gr(ad|un)|haie|hcit|hd\\-(m|p|t)|hei\\-|hi(pt|ta)|hp( i|ip)|hs\\-c|ht(c(\\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\\-(20|go|ma)|i230|iac( |\\-|\\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\\/)|klon|kpt |kwc\\-|kyo(c|k)|le(no|xi)|lg( g|\\/(k|l|u)|50|54|\\-[a-w])|libw|lynx|m1\\-w|m3ga|m50\\/|ma(te|ui|xo)|mc(01|21|ca)|m\\-cr|me(rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\\-2|po(ck|rt|se)|prox|psio|pt\\-g|qa\\-a|qc(07|12|21|32|60|\\-[2-7]|i\\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\\-|oo|p\\-)|sdk\\/|se(c(\\-|0|1)|47|mc|nd|ri)|sgh\\-|shar|sie(\\-|m)|sk\\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\\-|v\\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\\-|tdg\\-|tel(i|m)|tim\\-|t\\-mo|to(pl|sh)|ts(70|m\\-|m3|m5)|tx\\-9|up(\\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\\-|your|zeto|zte\\-/i', substr($useragent, 0, 4))) {
            return true;
        }

        return false;
    }
}

if (!function_exists('get_device_type')) {
    /**
     * 判断手机是IOS还是Android
     */
    function get_device_type()
    {
        //全部变成小写字母
        $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        $type = 'other';
        //分别进行判断
        if(strpos($agent, 'iphone') || strpos($agent, 'ipad'))
        {
            $type = 'ios';
        }

        if(strpos($agent, 'android'))
        {
            $type = 'android';
        }
        return $type;
    }
}

if (!function_exists('save_local_storage')) {
    /**
     * 保存至localStorage
     */
    function save_local_storage($key,$value,$script = '')
    {
        $html = "<script language='javascript'>";
        $value = is_array($value) ? json_encode($value) : $value;
        $html .= "localStorage.setItem('{$key}','{$value}');";
        $html .= $script;
        $html .= "</script>";
        return $html;
    }
}

if (!function_exists('get_module_name')) {
    /**
     * 获取当前模块名
     */
    function get_module_name()
    {
        return request()->param('app') ?? App('http')->getName();
    }
}

if (!function_exists('version_contrast')) {
    /**
     * 版本号对比
     */
    function version_contrast($versionA,$versionB)
    {
        if ($versionA>2147483646 || $versionB>2147483646) {
            throw new Exception('版本号,位数太大暂不支持!','101');
        }
        $dm = '.';
        $verListA = explode($dm, (string)$versionA);
        $verListB = explode($dm, (string)$versionB);

        $len = max(count($verListA),count($verListB));
        $i = -1;
        while ($i++<$len) {
            $verListA[$i] = intval(@$verListA[$i]);
            if ($verListA[$i] <0 ) {
                $verListA[$i] = 0;
            }
            $verListB[$i] = intval(@$verListB[$i]);
            if ($verListB[$i] <0 ) {
                $verListB[$i] = 0;
            }

            if ($verListA[$i]>$verListB[$i]) {
                return $versionA;
            } else if ($verListA[$i]<$verListB[$i]) {
                return $versionB;
            } else if ($i==($len-1)) {
                return $versionA;
            }
        }
    }
}

if (!function_exists('get_upgrade_status')) {
    /**
     * @title 可以升级
     * @param $local_version
     * @param $cloud_version
     * @return bool
     * @throws Exception
     */
    function get_upgrade_status($local_version,$cloud_version){
        if ($local_version == $cloud_version){
            return false;
        }
        $max_version = version_contrast($local_version,$cloud_version);
        if ($max_version == $cloud_version){
            return true;
        }
        return false;
    }
}
