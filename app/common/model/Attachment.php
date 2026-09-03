<?php

namespace app\common\model;

use think\Exception;
use think\facade\Filesystem;
use think\Image;
use OSS\OssClient;
use OSS\Core\OssException;
use Qcloud\Cos\Client as CosClient;
use app\common\service\TcVod;
use Qcloud\Cos\Exception\ServiceResponseException;

class Attachment extends Base
{
    // 开启自动写入时间戳字段
    protected $autoWriteTimestamp = true;
    protected $allowImageExt = ['png', 'jpg', 'jpeg'];
    protected $allowAudioExt = ['mp3', 'wav'];
    protected $allowVideoExt = ['mp4'];
    protected $allowFileExt = ['zip', 'rar', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'pdf', 'pem'];
    
    // 文件大小限制（字节）
    protected $maxImageSize = 2 * 1024 * 1024; // 2MB
    protected $maxAudioSize = 10 * 1024 * 1024; // 10MB
    protected $maxVideoSize = 100 * 1024 * 1024; // 100MB
    protected $maxFileSize = 50 * 1024 * 1024; // 50MB

        // 附件驱动映射
    protected $driverMap = [
        'local' => '本地',
        'oss' => '阿里云OSS',
        'cos' => '腾讯云COS',
        'tcvod' => '腾讯VOD',
    ];

    // 预签名失败时的具体原因（诊断用）
    protected $directSignErr = '';

    /**
     * 获取最近一次预签名失败的具体原因（诊断用）
     * @return string
     */
    public function getDirectSignErr()
    {
        return $this->directSignErr;
    }

    public function setUploadtimeAttr($value)
    {
        return strtotime($value);
    }

    /**
     * 通用上传
     *
     * @param      <type>  $files   The files
     * @param      string  $type    The type
     * @param      array   $params  The parameters
     *
     * @return     <type>  ( description_of_the_return_value )
     */
    public function upload($shopid, $files, $type = "file", $uid = 0, $enforce = 'auto', $filename = '')
    {
        if ($type == 'file') {
            $result = $this->file($shopid, $files, $enforce, $filename, $uid);
        }
        if ($type == 'avatar') {
            $result = $this->avatar($shopid, $files, $uid);
        }
        if ($type == 'base64') {
            $result = $this->base64($shopid, $files, $uid);
        }

        return $result;
    }

    /**
     * 文件上传
     * @param      <type>         $shopid 店铺ID
     * @param      <type>         $files  The files
     * @param      string         $enforce 存储驱动强制选项
     * @param      string         $filename 自定义文件名
     * @param      int            $uid 用户ID
     * @return     array|boolean  ( description_of_the_return_value )
     */
    public function file($shopid, $files, $enforce  = 'auto', $filename = '', $uid = 0)
    {
        if (empty($files)) {
            return false;
        }

        foreach ($files as $file) {
            //判断是否已经存在
            $sha1 = $file->hash('sha1');
            //处理已存在
            $file_info = $this->where(['sha1' => $sha1])->find();

            if (!empty($file_info)) {
                $file_res = [];
                $data = $file_info->toArray();
                $file_res['code'] = 200;
                $file_res['filename'] = $data['filename'];
                $file_res['type'] = $data['type'];
                $file_res['ext'] = $data['ext'];
                $file_res['size'] = $data['size'];
                $file_res['duration'] = $data['duration'];
                $file_res['attachment'] = $data['attachment'];
                $file_res['url'] = get_attachment_src($data['attachment']);
            } else {

                //构建返回数据
                $data['filename'] = $file->getOriginalName();
                if (!empty($filename)) {
                    $data['filename'] = $filename;
                }
                $data['ext'] = $file->getOriginalExtension();
                $data['md5'] = $file->hash('md5');
                $data['sha1'] = $file->hash('sha1');
                $data['size'] = $file->getSize();
                $data['mime'] = $file->getMime();
                $data['type'] = 'file';  // 类型用字符串 image file audio video
                // 根据不同mimeType放入不同目录
                $mime_arr = explode('/', $data['mime']);
                $mime_type = $mime_arr[0];

                // 严格的文件内容类型检查
                $filePath = $file->getPathname();
                
                // 验证图片类型
                if ($mime_type === 'image') {
                    if (!in_array($data['ext'], $this->allowImageExt)) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '不允许的图片类型';
                        return $false_result;
                    }
                    // 验证图片文件的真实内容
                    $imageInfo = getimagesize($filePath);
                    if (!$imageInfo) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '无效的图片文件';
                        return $false_result;
                    }
                    // 验证MIME类型
                    if (strpos($imageInfo['mime'], 'image/') !== 0) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '图片类型不匹配';
                        return $false_result;
                    }
                    // 验证文件大小
                    if ($data['size'] > $this->maxImageSize) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '图片文件大小超过限制（最大2MB）';
                        return $false_result;
                    }
                    $file_dir = 'images';
                    $driver = config('extend.PICTURE_UPLOAD_DRIVER');
                } 
                // 验证音频类型
                elseif ($mime_type === 'audio') {
                    if (!in_array($data['ext'], $this->allowAudioExt)) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '不允许的音频类型';
                        return $false_result;
                    }
                    // 验证音频文件的真实内容（检查文件头）
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $realMime = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    if (strpos($realMime, 'audio/') !== 0) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '音频类型不匹配';
                        return $false_result;
                    }
                    // 验证文件大小
                    if ($data['size'] > $this->maxAudioSize) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '音频文件大小超过限制（最大10MB）';
                        return $false_result;
                    }
                    $file_dir = 'audio';
                    $driver = config('extend.FILE_UPLOAD_DRIVER');
                } 
                // 验证视频类型
                elseif ($mime_type === 'video') {
                    if (!in_array($data['ext'], $this->allowVideoExt)) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '不允许的视频类型';
                        return $false_result;
                    }
                    // 验证视频文件的真实内容（检查文件头）
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $realMime = finfo_file($finfo, $filePath);
                    finfo_close($finfo);
                    if (strpos($realMime, 'video/') !== 0) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '视频类型不匹配';
                        return $false_result;
                    }
                    // 验证文件大小
                    if ($data['size'] > $this->maxVideoSize) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '视频文件大小超过限制（最大100MB）';
                        return $false_result;
                    }
                    $file_dir = 'video';
                    $driver = config('extend.FILE_UPLOAD_DRIVER');
                } 
                // 验证其他文件类型
                else {
                    if (!in_array($data['ext'], $this->allowFileExt)) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '不允许的文件类型';
                        return $false_result;
                    }
                    // 验证文件大小
                    if ($data['size'] > $this->maxFileSize) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '文件大小超过限制（最大50MB）';
                        return $false_result;
                    }
                    $file_dir = 'file';
                    $driver = config('extend.FILE_UPLOAD_DRIVER');
                }

                // 传shopid写入对应SHOPID目录
                if (!empty($shopid)) {
                    // 验证shopid安全性，防止路径遍历
                    $shopid = intval($shopid);
                    if ($shopid <= 0) {
                        $false_result['code'] = 0;
                        $false_result['msg'] = '无效的店铺ID';
                        return $false_result;
                    }
                    $file_dir = $shopid . DIRECTORY_SEPARATOR . $file_dir;
                }

                // 强制本地驱动
                if ($enforce == 'local') {
                    $driver = 'local';
                }

                $data['type'] = $mime_arr[0];
                $data['driver'] = $driver;

                // 处理文件名 - 使用安全的哈希文件名
                $name =  $file->hashName();
                // 确保文件名包含正确的扩展名
                $safeExt = strtolower($data['ext']);
                if (!empty($safeExt)) {
                    $name = substr($name, 0, strrpos($name, '.') + 1) . $safeExt;
                } elseif (!empty($mime_arr[1])) {
                    $name = $name . '.' . strtolower($mime_arr[1]);
                }

                // 确保目录结构安全，防止路径遍历
                $file_dir = str_replace(['../', '..' . DIRECTORY_SEPARATOR, '/..', DIRECTORY_SEPARATOR . '..'], '', $file_dir);

                $savename = Filesystem::disk('public')->putFileAs($file_dir, $file, $name);

                // 成功上传后 获取上传信息
                $data['attachment'] = $savename;
                $data['attachment'] = str_replace("\\", "/", $data['attachment']);

                // 获取音视频时长
                $data['duration'] = null;
                if ($data['type'] == 'video' || $data['type'] == 'audio') {
                    $local_path = app()->getRootPath() . 'public/attachment/' . $data['attachment'];
                    $duration = $this->getMediaDuration($local_path);
                    if ($duration) {
                        $data['duration'] = $duration['second'];
                    }
                }

                // 本地
                if ($driver == 'local') {
                    // 本地无需处理
                    if ($mime_type == 'image') {
                        try {
                            $this->checkHex($data['attachment']);
                        } catch (\Exception $e) {
                            $this->removFile($data['attachment']);
                            return false;
                        }
                    }
                }

                // 阿里云OSS
                if ($driver == 'aliyun') {
                    $oss_res = $this->ossUpload('attachment/' . $data['attachment'], $file->getPathname());

                    // 上传成功
                    if ($oss_res === true) {
                        // 删除本地文件
                        $attachment_path = app()->getRootPath() . 'public/attachment';
                        $file_path = $attachment_path . '/' . $data['attachment'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                        $data['driver'] = 'oss';
                    } else {
                        // 上传失败：清理本地临时文件并中断，避免产生不可访问的脏记录
                        $this->removFile($data['attachment']);
                        return [
                            'code' => 0,
                            'msg' => '阿里云OSS上传失败：' . $oss_res
                        ];
                    }
                }
                // 腾讯云COS
                if ($driver == 'tencent') {
                    $cos_res = $this->cosUpload('attachment/' . $data['attachment'], $file->getPathname());
                    // 上传成功
                    if ($cos_res === true) {
                        // 删除本地文件
                        $attachment_path = app()->getRootPath() . 'public/attachment';
                        $file_path = $attachment_path . '/' . $data['attachment'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                        $data['driver'] = 'cos';
                    } else {
                        // 上传失败：清理本地临时文件并中断，避免产生不可访问的脏记录
                        $this->removFile($data['attachment']);
                        $msg = is_string($cos_res) && $cos_res !== '' ? $cos_res : '未知错误';
                        return [
                            'code' => 0,
                            'msg' => '腾讯云COS上传失败：' . $msg
                        ];
                    }
                }

                // 写入数据库 - 记录上传用户
                $data['uid'] = $uid;
                $this->save($data);
                // 返回数据
                $file_res = [];
                $file_res['code'] = 200;
                $file_res['filename'] = $data['filename'];
                $file_res['ext'] = $data['ext'];
                $file_res['size'] = $data['size'];
                $file_res['duration'] = $data['duration'];
                $file_res['attachment'] = $data['attachment'];
                $file_res['url'] = get_attachment_src($data['attachment']);
                if ($enforce == 'local') {
                    $file_res['url'] = get_attachment_src($data['attachment'], 'local');
                }
            }
        }
        return $file_res;
    }

    /**
     * @return array|false
     */
    public function getMediaDuration($loacl_path)
    {
        include_once(root_path() . 'extend/getid3/getid3.php');
        $getid3 = new \getID3();

        $mediaInfo = $getid3->analyze($loacl_path);
        if (!empty($mediaInfo) && isset($mediaInfo['playtime_seconds'])) {
            // 时长 分/秒
            $time['minute_second'] = $mediaInfo['playtime_string'] ?? '0';
            // 时长 秒
            $time['second'] = $mediaInfo['playtime_seconds'] ?? '0:0';
            return $time;
        } else {
            return false;
        }
    }

    /**
     * 头像上传
     *
     * @param      <type>         $files  The files
     * @return     array|boolean  ( description_of_the_return_value )
     */
    private function Avatar($shopid, $files, $uid)
    {
        if (empty($files)) {
            return false;
        }

        foreach ($files as $file) {
            //判断是否已经存在
            $sha1 = $file->hash('sha1');
            //处理已存在图片
            $pic_info = $this->where(['sha1' => $sha1])->find();
            
            if (!empty($pic_info)) {
                $avatar = [];
                $data = $pic_info->toArray();
                $avatar['filename'] = $data['filename'];
                $avatar['ext'] = $data['ext'];
                $avatar['size'] = $data['size'];
                $avatar['attachment'] = $data['attachment'];
                $avatar['url'] = get_attachment_src($data['attachment']);
            } else {
                $data['filename'] = $file->getOriginalName();
                $data['ext'] = $file->getOriginalExtension();
                $data['md5'] = $file->hash('md5');
                $data['sha1'] = $file->hash('sha1');
                $data['size'] = $file->getSize();
                $data['mime'] = $file->getMime();
                $data['type'] = 'image';  // 类型用字符串 pic file audio video
                
                // 根据不同mimeType
                $mime_arr = explode('/', $data['mime']);
                $mime_type = $mime_arr[0];
                if ($mime_type != 'image') {
                    return false;
                }

                if (!empty($data['ext']) && !in_array($data['ext'], $this->allowImageExt)) {
                    return false;
                }

                // 传shopid写入对应SHOPID目录
                $file_dir = 'avatar';
                if (!empty($shopid)) {
                    $file_dir = $shopid . DIRECTORY_SEPARATOR . 'avatar';
                }
                $savename = Filesystem::disk('public')->putFile($file_dir . DIRECTORY_SEPARATOR . $uid, $file);
                // 成功上传后 获取上传信息
                $data['attachment'] = $savename;
                $data['attachment'] = str_replace("\\", "/", $data['attachment']);

                //获取上传驱动
                $driver = config('extend.PICTURE_UPLOAD_DRIVER');
                if ($driver == 'local') {
                    // 本地无需处理
                    $data['driver'] = 'local';
                    try {
                        $this->checkHex($data['attachment']);
                    } catch (\Exception $e) {
                        $this->removFile($data['attachment']);
                        return false;
                    }
                }
                // 阿里云OSS
                if ($driver == 'aliyun') {
                    $data['driver'] = 'oss';
                    $oss_res = $this->ossUpload('attachment/' . $data['attachment'], $file->getPathname());
                    // 上传成功
                    if ($oss_res === true) {
                        // 删除本地文件
                        $attachment_path = app()->getRootPath() . 'public/attachment';
                        $file_path = $attachment_path . '/' . $data['attachment'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                }
                // 腾讯云COS
                if ($driver == 'tencent') {
                    $data['driver'] = 'cos';
                    $cos_res = $this->cosUpload('attachment/' . $data['attachment'], $file->getPathname());
                    // 上传成功
                    if ($cos_res === true) {
                        // 删除本地文件
                        $attachment_path = app()->getRootPath() . 'public/attachment';
                        $file_path = $attachment_path . '/' . $data['attachment'];
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                    }
                }

                // 写入数据库 - 记录上传用户
                $data['uid'] = $uid;
                $this->save($data);
                // 返回数据
                $avatar = [];
                $avatar['filename'] = $data['filename'];
                $avatar['ext'] = $data['ext'];
                $avatar['size'] = $data['size'];
                $avatar['attachment'] = $data['attachment'];
                $avatar['url'] = get_attachment_src($data['attachment']);
            }
        }
        return $avatar;
    }

    /**
     * base64上传功能
     * @param  int $shopid 店铺ID
     * @param  string $files base64编码的文件数据
     * @param  int $uid 用户ID
     * @return array|boolean 上传结果
     */
    public function base64($shopid, $files, $uid = 0)
    {
        if (empty($files)) {
            $false_result['code'] = 0;
            $false_result['msg'] = '无文件数据';
            return $false_result;
        }

        // 处理base64数据
        $base64Data = $files;
        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $ext = $matches[1];
            if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
                $false_result['code'] = 0;
                $false_result['msg'] = '不支持的图片格式';
                return $false_result;
            }
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
            $base64Data = str_replace(' ', '+', $base64Data);
            $fileData = base64_decode($base64Data);

            if (!$fileData) {
                $false_result['code'] = 0;
                $false_result['msg'] = 'base64解码失败';
                return $false_result;
            }

            // 验证文件大小
            $fileSize = strlen($fileData);
            if ($fileSize > $this->maxImageSize) {
                $false_result['code'] = 0;
                $false_result['msg'] = '图片文件大小超过限制（最大2MB）';
                return $false_result;
            }

            // 创建临时文件
            $tmpFile = tempnam(sys_get_temp_dir(), 'base64_') . '.' . $ext;
            file_put_contents($tmpFile, $fileData);

            // 验证图片文件的真实内容
            $imageInfo = getimagesize($tmpFile);
            if (!$imageInfo || strpos($imageInfo['mime'], 'image/') !== 0) {
                unlink($tmpFile);
                $false_result['code'] = 0;
                $false_result['msg'] = '无效的图片文件';
                return $false_result;
            }

            // 计算文件哈希值
            $sha1 = sha1_file($tmpFile);
            $md5 = md5_file($tmpFile);

            // 检查文件是否已存在
            $file_info = $this->where(['sha1' => $sha1])->find();
            if (!empty($file_info)) {
                unlink($tmpFile);
                $data = $file_info->toArray();
                return [
                    'code' => 200,
                    'filename' => $data['filename'],
                    'type' => $data['type'],
                    'ext' => $data['ext'],
                    'size' => $data['size'],
                    'attachment' => $data['attachment'],
                    'url' => get_attachment_src($data['attachment'])
                ];
            }

            // 构建文件目录
            $file_dir = 'images';
            if (!empty($shopid)) {
                $shopid = intval($shopid);
                if ($shopid <= 0) {
                    unlink($tmpFile);
                    $false_result['code'] = 0;
                    $false_result['msg'] = '无效的店铺ID';
                    return $false_result;
                }
                $file_dir = $shopid . DIRECTORY_SEPARATOR . $file_dir;
            }

            // 生成安全的文件名
            $name = md5(uniqid()) . '.' . $ext;
            $filePath = $file_dir . '/' . $name;
            
            // 上传文件 - 读取临时文件内容并写入目标位置
            $content = file_get_contents($tmpFile);
            $bytesWritten = Filesystem::disk('public')->put($filePath, $content);
            unlink($tmpFile);

            if (!$bytesWritten) {
                $false_result['code'] = 0;
                $false_result['msg'] = '文件保存失败';
                return $false_result;
            }
            // 设置正确的文件路径
            $savename = $filePath;

            // 构建返回数据
            $data = [
                'filename' => 'base64_upload.' . $ext,
                'ext' => $ext,
                'md5' => $md5,
                'sha1' => $sha1,
                'size' => $fileSize,
                'mime' => 'image/' . $ext,
                'type' => 'image',
                'attachment' => str_replace('\\', '/', $savename),
                'driver' => config('extend.PICTURE_UPLOAD_DRIVER'),
                'uid' => $uid
            ];

            // 写入数据库
            $this->save($data);

            return [
                'code' => 200,
                'filename' => $data['filename'],
                'type' => $data['type'],
                'ext' => $data['ext'],
                'size' => $data['size'],
                'attachment' => $data['attachment'],
                'url' => get_attachment_src($data['attachment'])
            ];
        } else {
            $false_result['code'] = 0;
            $false_result['msg'] = '无效的base64格式';
            return $false_result;
        }
    }


    /**
     * 阿里云OSS上传
     * $object 文件名
     * $filepath 文件路径
     */
    public function ossUpload($object, $filePath)
    {
        // 阿里云主账号AccessKey拥有所有API的访问权限，风险很高。强烈建议您创建并使用RAM账号进行API访问或日常运维，请登录RAM控制台创建RAM账号。
        $accessKeyId = config('extend.OSS_ALIYUN_ACCESSKEYID');
        $accessKeySecret = config('extend.OSS_ALIYUN_ACCESSKEYSECRET');
        // Endpoint以杭州为例，其它Region请按实际情况填写。
        $endpoint = config('extend.OSS_ALIYUN_ENDPOINT');
        // 设置存储空间名称。
        $bucket = config('extend.OSS_ALIYUN_BUCKET');
        // 设置文件名称。
        //$object = $file->getOriginalName();
        // <yourLocalFile>由本地文件路径加文件名包括后缀组成，例如/users/local/myfile.txt。
        //$filePath = $file->getPathname();

        try {
            $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $result = $ossClient->uploadFile($bucket, $object, $filePath);

            return true;
        } catch (OssException $e) {
            //printf(__FUNCTION__ . ": FAILED\n");
            //printf($e->getMessage() . "\n");
            return $e->getMessage();
        }
    }

    /**
     * 腾讯云COS上传
     */
    protected function cosUpload($object, $filePath)
    {
        // SECRETID和SECRETKEY请登录访问管理控制台进行查看和管理
        $secretId = config('extend.COS_TENCENT_SECRETID'); //"云 API 密钥 SecretId";
        $secretKey = config('extend.COS_TENCENT_SECRETKEY'); //"云 API 密钥 SecretKey";
        $region = config('extend.COS_TENCENT_REGION'); //设置一个默认的存储桶地域
        $cosClient = new CosClient([
            'region' => $region,
            'schema' => 'http', //协议头部，默认为http
            'credentials' => [
                'secretId'  => $secretId,
                'secretKey' => $secretKey
            ]
        ]);

        try {
            $bucket = config('extend.COS_TENCENT_BUCKET'); //存储桶名称 格式：BucketName-APPID
            $key = $object; //此处的 key 为对象键，对象键是对象在存储桶中的唯一标识
            $srcPath = $filePath; //本地文件绝对路径
            $file = fopen($srcPath, "rb");
            if ($file) {
                $result = $cosClient->putObject(array(
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'Body' => $file
                ));

                return true;
            } else {
                return '无法读取本地文件';
            }
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    /** 
     * 获取缩微图
     * @param $filename
     * @param int $width
     * @param string $height
     * @param int $type
     * @param bool $replace
     * @return mixed|string
     */
    public function getThumbImage($attachment, $width = 100, $height = 'auto', $replace = false)
    {
        // 获取图片存储类型
        //$driver = config('extend.PICTURE_UPLOAD_DRIVER');
        $driver = $attachment['driver'];
        if (strtolower($driver) == 'local' || strtolower($driver) == 'loacal' || strtolower($driver) == '') {
            $info = $this->localThumb($attachment['attachment'], $width, $height, $replace);
            return $info;
        } else {
            // 远程图片处理
            if (strtolower($driver) == 'oss' || strtolower($driver) == 'tencent') {
                $src = config('extend.OSS_ALIYUN_BUCKET_DOMAIN') . '/attachment/' . $attachment['attachment'] . '?x-oss-process=image/resize,m_fill,h_' . $height . ',w_' . $width;
                $info['src'] = $src;
            }

            if (strtolower($driver) == 'cos' || strtolower($driver) == 'aliyun') {
                $src = config('extend.COS_TENCENT_BUCKET_DOMAIN') . '/attachment/' . $attachment['attachment'] . '?imageView2/1/w/' . $width . '/h/' . $height;
                $info['src'] = $src;
            }

            return $info;
        }
    }

    /**
     * 本地缩微图处理
     */
    public function localThumb($attachment, $width = 100, $height = 'auto', $replace = false)
    {
        $UPLOAD_URL = '';
        $UPLOAD_PATH = PUBLIC_PATH . '/attachment/';
        $attachment = str_ireplace($UPLOAD_URL, '', $attachment); //将URL转化为本地地址
        $info = pathinfo($attachment);

        $oldFile = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . $info['extension'];
        $thumbFile = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '_' . $width . '_' . $height . '.' . $info['extension'];

        $oldFile = str_replace('\\', '/', $oldFile);
        $thumbFile = str_replace('\\', '/', $thumbFile);

        $filename = ltrim($attachment, '/');
        $oldFile = ltrim($oldFile, '/');
        $thumbFile = ltrim($thumbFile, '/');

        if (!file_exists($UPLOAD_PATH . $oldFile)) {
            //原图不存在直接返回
            @unlink($UPLOAD_PATH . $thumbFile);
            $info['src'] = $oldFile;
            $info['width'] = intval($width);
            $info['height'] = intval($height);
            return $info;
        } elseif (file_exists($UPLOAD_PATH . $thumbFile) && !$replace) {
            //缩图已存在并且  replace替换为false
            $imageinfo = getimagesize($UPLOAD_PATH . $thumbFile);
            $info['src'] = request()->domain() . '/attachment/' . str_replace('//', '/', $thumbFile);
            $info['width'] = intval($imageinfo[0]);
            $info['height'] = intval($imageinfo[1]);
            return $info;
        } else {
            //执行缩图操作
            // 获取原图尺寸
            $oldimageinfo = getimagesize($UPLOAD_PATH . $oldFile);
            if ($oldimageinfo) {
                $old_image_width = intval($oldimageinfo[0]);
                $old_image_height = intval($oldimageinfo[1]);
                if ($old_image_width <= $width && $old_image_height <= $height) {
                    @unlink($UPLOAD_PATH . $thumbFile);
                    @copy($UPLOAD_PATH . $oldFile, $UPLOAD_PATH . $thumbFile);
                    $info['src'] = request()->domain() . '/attachment/' . str_replace('//', '/', $thumbFile);;
                    $info['width'] = $old_image_width;
                    $info['height'] = $old_image_height;

                    return $info;
                } else {
                    if ($height == "auto") $height = $old_image_height * $width / $old_image_width;
                    if ($width == "auto") $width = $old_image_width * $width / $old_image_height;
                    if (intval($height) == 0 || intval($width) == 0) {
                        return 0;
                    }
                    // 打开图片并处理
                    $thumb = Image::open($UPLOAD_PATH . $filename);
                    //默认裁切类型标识缩略图居中裁剪类型，先写死，后续版本增加后台设置
                    $thumb->thumb($width, $height, Image::THUMB_CENTER);
                    $thumb->save($UPLOAD_PATH . $thumbFile);
                    $info['src'] = request()->domain() . '/attachment/' . str_replace('//', '/', $thumbFile);
                    $info['width'] = $old_image_width;
                    $info['height'] = $old_image_height;

                    return $info;
                }
            }
        }
    }

    /** 
     * 裁切图片
     * @return mixed|string
     */
    public function cropImage($attachment, $crop)
    {
        $UPLOAD_PATH = PUBLIC_PATH . '/attachment/';
        $info = pathinfo($attachment);
        $file_path = $info['dirname'] . DIRECTORY_SEPARATOR . $info['filename'] . '.' . $info['extension'];
        $file_path = str_replace("\\", "/", $file_path);
        $file_path = ltrim($file_path, '/');
        $file_path = $UPLOAD_PATH . $file_path;

        //如果不裁剪，则发生错误
        if (!$crop) {
            return $attachment;
        }

        //解析crop参数
        $crop = explode(',', $crop);
        $x = $crop[0];
        $y = $crop[1];
        $w = $crop[2];
        $h = $crop[3];

        $driver = config('extend.PICTURE_UPLOAD_DRIVER');
        if (strtolower($driver) == 'local') {
            //本地图片处理
            $image = Image::open($file_path);
            //生成将单位换算成为像素
            //$x = $x * $image->width();
            //$y = $y * $image->height();
            //$w = $w * $image->width();
            //$h = $h * $image->height();

            //如果宽度和高度近似相等，则令宽和高一样
            if (abs($h - $w) < $h * 0.01) {
                $h = min($h, $w);
                $w = $h;
            }
            //调用组件裁剪
            $image->crop($w, $h, $x, $y);
            $image->save($file_path);
        } else {
            // 远程图片处理
            if (strtolower($driver) == 'aliyun') {
                $attachment = config('extend.OSS_ALIYUN_BUCKET_DOMAIN') . '/attachment/' . $attachment . '?x-oss-process=image/crop,x_' . $x . ',y_' . $y . ',w_' . $w . ',h_' . $h;
            }

            if (strtolower($driver) == 'tencent') {
                $attachment = config('extend.COS_TENCENT_BUCKET_DOMAIN') . '/attachment/' . $attachment . '?imageMogr2/cut/' . $w . 'x' . $h . 'x' . $x . 'x' . $y;
            }
        }

        //返回新文件的路径
        return  $attachment;
    }

    /**
     * 云点播媒体文件处理
     */
    public function vodMediaHandle($file_id, $attachment)
    {
        $TcVodService = new TcVod();
        // 云点播key防盗链开关
        $vod_key_switch = config('extend.VOD_TENCENT_KEY_SWITCH');

        $data = [];
        if ($vod_key_switch == 1) {
            $data['psign'] = $TcVodService->getPsign($file_id);
            $data['all_media_url'] = $TcVodService->getKeyMediaUrl($attachment);
        }else{
            $data['psign'] = '';
            $data['all_media_url'] = $attachment;
        }

        return $data;
    }

    /**
     * 获取文件名
     */
    public function getFileName($attachment)
    {
        $filename = $this->where('attachment', $attachment)->value('filename');

        return $filename;
    }

    /**
     * 获取文件file_id
     */
    public function getFileID($attachment)
    {
        $file_id = $this->where('attachment', $attachment)->value('file_id');
        if (!empty($file_id)) {
            return $file_id;
        }

        return '';
    }

    /**
     * 根据附件标识获取附件数据
     * @param string $attachment 附件标识
     * @return array|false 返回附件数据数组，未找到返回false
     */
    public function getAttachmentData($attachment)
    {
        $data = $this->where('attachment', $attachment)->find();
        if($data) return $data;
        
        return false;
    }

    /**
     * 删除指定的附件文件
     * 
     * @param string $attachment 附件路径（相对于public/attachment目录）
     * @return bool 删除成功返回true，文件不存在返回false
     */
    public function removFile($attachment, $driver = 'local')
    {
        // 删除本地文件（若仍存在）
        $attachment_save_path = app()->getRootPath() . 'public/attachment';
        $attachment_all_path = $attachment_save_path . '/' . $attachment;
        $local_ok = false;
        if (file_exists($attachment_all_path)) {
            unlink($attachment_all_path);
            $local_ok = true;
        }

        // 删除对象存储文件（直传/中转上云后本地已删除，必须联动清理云对象，避免孤儿文件计费）
        if ($driver == 'oss' || $driver == 'aliyun') {
            return $this->deleteOssObject('attachment/' . $attachment);
        }
        if ($driver == 'cos' || $driver == 'tencent') {
            return $this->deleteCosObject('attachment/' . $attachment);
        }

        return $local_ok;
    }

    /**
     * 删除阿里云OSS对象
     * @param string $object 对象键
     * @return bool
     */
    public function deleteOssObject($object)
    {
        $accessKeyId = config('extend.OSS_ALIYUN_ACCESSKEYID');
        $accessKeySecret = config('extend.OSS_ALIYUN_ACCESSKEYSECRET');
        $endpoint = config('extend.OSS_ALIYUN_ENDPOINT');
        $bucket = config('extend.OSS_ALIYUN_BUCKET');
        if (empty($accessKeyId) || empty($accessKeySecret) || empty($endpoint) || empty($bucket)) {
            return false;
        }
        try {
            $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
            $ossClient->deleteObject($bucket, $object);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 删除腾讯云COS对象
     * @param string $object 对象键
     * @return bool
     */
    public function deleteCosObject($object)
    {
        $secretId = config('extend.COS_TENCENT_SECRETID');
        $secretKey = config('extend.COS_TENCENT_SECRETKEY');
        $region = config('extend.COS_TENCENT_REGION');
        $bucket = config('extend.COS_TENCENT_BUCKET');
        if (empty($secretId) || empty($secretKey) || empty($region) || empty($bucket)) {
            return false;
        }
        try {
            $cosClient = new CosClient([
                'region' => $region,
                'schema' => 'https',
                'credentials' => [
                    'secretId'  => $secretId,
                    'secretKey' => $secretKey,
                ]
            ]);
            $cosClient->deleteObject(['Bucket' => $bucket, 'Key' => $object]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查文件十六进制内容是否包含恶意脚本
     * 
     * 该方法通过读取文件头部和尾部的512字节（或全部内容小于512字节时），
     * 将其转换为十六进制字符串后，使用正则表达式匹配常见的PHP标签、脚本标签等危险内容。
     * 若检测到潜在恶意代码，则抛出异常。
     * 
     * @param string $image 要检查的文件路径（相对于附件目录）
     * @throws Exception 当检测到非法内容时抛出
     * @access private
     */
    private function checkHex($image)
    {
        $attachment_path = app()->getRootPath() . 'public/attachment';
        $image = $attachment_path . '/' . $image;

        if (file_exists($image)) {
            $resource = fopen($image, 'rb');
            $fileSize = filesize($image);
            fseek($resource, 0); //把文件指针移到文件的开头
            if ($fileSize > 512) { // 取头和尾
                $hexCode = bin2hex(fread($resource, 512));
                fseek($resource, $fileSize - 512);
                $hexCode .= bin2hex(fread($resource, 512));
            } else { // 取全部
                $hexCode = bin2hex(fread($resource, $fileSize));
            }
            fclose($resource);

            /* 匹配16进制中的 <% ( ) %> */
            /* 匹配16进制中的 <? ( ) ?> */
            /* 匹配16进制中的 <script | /script> 大小写亦可*/
            /* 通过匹配十六进制代码检测是否存在木马脚本*/
            if (preg_match("/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is", $hexCode)) {
                throw new Exception('非法文件');
            }
        }
    }

    /**
     * 获取直传上传规则（音视频）
     *
     * 支持通过 extend 配置覆盖默认值：
     *  UPLOAD_VIDEO_EXTS     视频扩展名，逗号分隔，如 mp4,mov
     *  UPLOAD_VIDEO_MAXSIZE  视频最大体积（MB）
     *  UPLOAD_AUDIO_EXTS     音频扩展名，逗号分隔，如 mp3,wav,m4a
     *  UPLOAD_AUDIO_MAXSIZE  音频最大体积（MB）
     *
     * @param string $type video|audio
     * @return array ['ext' => [], 'max' => int(byte), 'dir' => string]
     */
    public function getUploadRule($type)
    {
        // 直传默认限制（区别于本地中转上传，不经过 PHP 限制可放大到对象存储上限）
        $default = [
            'video' => ['ext' => $this->allowVideoExt, 'max' => 2048 * 1024 * 1024, 'dir' => 'video'],
            'audio' => ['ext' => $this->allowAudioExt, 'max' => 512 * 1024 * 1024, 'dir' => 'audio'],
        ];
        if (!isset($default[$type])) {
            return ['ext' => [], 'max' => 0, 'dir' => 'file'];
        }
        $rule = $default[$type];
        $cfg_exts = '';
        $cfg_max = '';
        if ($type == 'video') {
            $cfg_exts = config('extend.UPLOAD_VIDEO_EXTS');
            $cfg_max = config('extend.UPLOAD_VIDEO_MAXSIZE');
        } elseif ($type == 'audio') {
            $cfg_exts = config('extend.UPLOAD_AUDIO_EXTS');
            $cfg_max = config('extend.UPLOAD_AUDIO_MAXSIZE');
        }
        if (!empty($cfg_exts)) {
            $rule['ext'] = array_values(array_filter(array_map('strtolower', explode(',', str_replace('，', ',', (string)$cfg_exts)))));
        }
        if (!empty($cfg_max) && intval($cfg_max) > 0) {
            $rule['max'] = intval($cfg_max) * 1024 * 1024;
        }
        return $rule;
    }

    /**
     * 根据扩展名推断 MIME 类型
     * @param string $ext 扩展名（小写）
     * @return string
     */
    public function getMimeByExt($ext)
    {
        $map = [
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            'm4v' => 'video/x-m4v', 'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
            'flv' => 'video/x-flv', 'ts' => 'video/mp2t',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4',
            'aac' => 'audio/aac', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac', 'wma' => 'audio/x-ms-wma',
        ];
        return $map[strtolower($ext)] ?? 'application/octet-stream';
    }

    /**
     * 生成对象存储预签名直传 URL
     *
     * @param string $provider cos|oss
     * @param string $object  对象键，如 attachment/video/xxxx.mp4
     * @param int    $expireSec 有效期（秒）
     * @return string|false 预签名 PUT URL，配置缺失或签名失败返回 false
     */
    public function directSignUrl($provider, $object, $expireSec = 3600, $mime = '')
    {
        $this->directSignErr = '';
        if ($provider == 'oss') {
            $accessKeyId = config('extend.OSS_ALIYUN_ACCESSKEYID');
            $accessKeySecret = config('extend.OSS_ALIYUN_ACCESSKEYSECRET');
            $endpoint = config('extend.OSS_ALIYUN_ENDPOINT');
            $bucket = config('extend.OSS_ALIYUN_BUCKET');
            $empty = [];
            if (empty($accessKeyId)) $empty[] = 'OSS_ALIYUN_ACCESSKEYID';
            if (empty($accessKeySecret)) $empty[] = 'OSS_ALIYUN_ACCESSKEYSECRET';
            if (empty($endpoint)) $empty[] = 'OSS_ALIYUN_ENDPOINT';
            if (empty($bucket)) $empty[] = 'OSS_ALIYUN_BUCKET';
            if (!empty($empty)) {
                $this->directSignErr = '阿里云OSS配置缺失：' . implode(',', $empty);
                return false;
            }
            // 浏览器直传必须是 HTTPS（后台部署为 HTTPS 时会拦截 HTTP），endpoint 未带协议时补 https://
            if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
                $endpoint = 'https://' . $endpoint;
            }
            try {
                // OSS URL 签名会按请求实际携带的 Content-Type 参与 StringToSign 校验，
                // 而浏览器 PUT 会自动带 Content-Type，因此必须把将发送的 Content-Type 一起签进 URL，
                // 否则浏览器直传必然 SignatureDoesNotMatch(403)。
                $signOptions = [];
                if ($mime !== '') {
                    $signOptions[OssClient::OSS_CONTENT_TYPE] = $mime;
                }
                $ossClient = new OssClient($accessKeyId, $accessKeySecret, $endpoint);
                return $ossClient->signUrl($bucket, $object, $expireSec, OssClient::OSS_HTTP_PUT, $signOptions ?: null);
            } catch (\Throwable $e) {
                $this->directSignErr = $e->getMessage();
                return false;
            }
        }
        if ($provider == 'cos') {
            $secretId = config('extend.COS_TENCENT_SECRETID');
            $secretKey = config('extend.COS_TENCENT_SECRETKEY');
            $region = config('extend.COS_TENCENT_REGION');
            $bucket = config('extend.COS_TENCENT_BUCKET');
            $empty = [];
            if (empty($secretId)) $empty[] = 'COS_TENCENT_SECRETID';
            if (empty($secretKey)) $empty[] = 'COS_TENCENT_SECRETKEY';
            if (empty($region)) $empty[] = 'COS_TENCENT_REGION';
            if (empty($bucket)) $empty[] = 'COS_TENCENT_BUCKET';
            if (!empty($empty)) {
                $this->directSignErr = '腾讯云COS配置缺失：' . implode(',', $empty);
                return false;
            }
            try {
                $cosClient = new CosClient([
                    'region' => $region,
                    'schema' => 'https', // 浏览器直传必须 HTTPS，避免 mixed content 被拦截
                    'credentials' => [
                        'secretId'  => $secretId,
                        'secretKey' => $secretKey,
                    ]
                ]);
                // COS SDK 对 PutObject 强校验 Body/SourceFile 必须非空，预签名无需真实数据，传空串占位即可
                $url = $cosClient->getPresignedUrl('PutObject', [
                    'Bucket' => $bucket,
                    'Key' => $object,
                    'Body' => '',
                ], '+' . intval($expireSec) . ' seconds');
                return $url->__toString();
            } catch (\Throwable $e) {
                $this->directSignErr = $e->getMessage();
                return false;
            }
        }
        $this->directSignErr = '未知存储驱动：' . $provider;
        return false;
    }

    /**
     * 直传完成后写附件记录（COS/OSS 预签名直传配套）
     *
     * @param array $policy 直传凭证数据（含 uid/shopid/type/filename/ext/size/driver/attachment/mime）
     * @return array 与 Attachment::file() 一致的返回结构；失败返回 ['code'=>0,'msg'=>...]
     */
    public function completeDirect($policy)
    {
        // 幂等处理：同一对象键已入库则直接复用（重复回调/刷新页面兜底）
        $exist = $this->where('attachment', '=', $policy['attachment'])->find();
        if (!empty($exist)) {
            $data = $exist->toArray();
            return [
                'code' => 200,
                'filename' => $data['filename'],
                'type' => $data['type'],
                'ext' => $data['ext'],
                'size' => $data['size'],
                'duration' => $data['duration'],
                'attachment' => $data['attachment'],
                'url' => get_attachment_src($data['attachment']),
            ];
        }

        $duration = isset($policy['duration']) ? max(0, intval($policy['duration'])) : 0;
        $data = [
            'shopid' => isset($policy['shopid']) ? intval($policy['shopid']) : 0,
            'uid' => isset($policy['uid']) ? intval($policy['uid']) : 0,
            'filename' => isset($policy['filename']) ? $policy['filename'] : '',
            'ext' => isset($policy['ext']) ? strtolower($policy['ext']) : '',
            'size' => isset($policy['size']) ? intval($policy['size']) : 0,
            'mime' => isset($policy['mime']) ? $policy['mime'] : $this->getMimeByExt($policy['ext'] ?? ''),
            'type' => isset($policy['type']) ? $policy['type'] : 'file',
            'driver' => isset($policy['driver']) ? $policy['driver'] : 'local',
            'attachment' => isset($policy['attachment']) ? $policy['attachment'] : '',
            'duration' => $duration > 0 ? $duration : null,
            // 浏览器直传拿不到文件服务端哈希，md5/sha1 列 NOT NULL DEFAULT ''，缺省写空串而非 null
            'md5' => !empty($policy['md5']) ? $policy['md5'] : '',
            'sha1' => !empty($policy['sha1']) ? $policy['sha1'] : '',
        ];

        $res = $this->save($data);
        if ($res === false) {
            return ['code' => 0, 'msg' => '附件记录保存失败'];
        }

        return [
            'code' => 200,
            'filename' => $data['filename'],
            'type' => $data['type'],
            'ext' => $data['ext'],
            'size' => $data['size'],
            'duration' => $data['duration'],
            'attachment' => $data['attachment'],
            'url' => get_attachment_src($data['attachment']),
        ];
    }

    /**
     * 处理附件数据
     * 
     * 根据用户ID设置用户信息，若uid为0则设置为系统信息，否则查询用户信息
     * 同时格式化创建时间和更新时间
     * 
     * @param array $data 附件数据数组
     * @return array 处理后的附件数据
     */
    public function handle($data)
    {
        if ($data['uid'] == 0) {
            $avatar = request()->domain() . '/static/common/images/message_icon/system.png';
            // uid为0时属系统
            $data['user_info'] = [
                'nickname' => '系统',
                'avatar' => $avatar,
                'avatar64' => $avatar,
                'avatar128' => $avatar,
                'avatar256' => $avatar,
                'avatar512' => $avatar,
            ];
        } else {
            // 包含uid时为用户附件
            $data['user_info'] = query_user($data['uid'], ['nickname', 'avatar']);
        }

        // 根据驱动类型获取完整URL
        $data['url'] = get_attachment_src($data['attachment']);

        // 图片类型附件生成一组缩略图
        if ($data['type'] == 'image') {
            $data['thumb'] = thumb_group($data['attachment']);
        }

        // 根据driver的值返回driver的文字描述值
        $data['driver_str'] = $this->driverMap[$data['driver']] ?? $data['driver'];

        //时间戳格式化
        $data['create_time_str'] = time_format($data['create_time']);
        $data['update_time_str'] = time_format($data['update_time']);

        return $data;
    }
}
