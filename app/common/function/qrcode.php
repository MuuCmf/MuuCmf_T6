<?php

if (!function_exists('qrcode')) {
    /**
     * @param $data 二维码包含的文字内容
     * @param $filename 保存二维码输出的文件名称，*.png
     * @param bool $picPath 二维码输出的路径
     * @param bool $logo 二维码中包含的LOGO图片路径
     * @param string $size 二维码的大小
     * @param string $level 二维码编码纠错级别：L、M、Q、H
     * @param int $padding 二维码边框的间距
     * @param bool $saveandprint 是否保存到文件并在浏览器直接输出，true:同时保存和输出，false:只保存文件
     * return string
     */
    function qrcode($data, $filename, $picPath = false, $logo = false, $size = '4', $level = 'L', $padding = 2, $saveandprint = false)
    {

        // 下面注释了把二维码图片保存到本地的代码,如果要保存图片,用$fileName替换第二个参数false
        $path = $picPath ? $picPath : PUBLIC_PATH . DIRECTORY_SEPARATOR . "attachment" . DIRECTORY_SEPARATOR . "images" . DIRECTORY_SEPARATOR . "QRcode"; //图片输出路径
        if (!is_dir($path)) {
            // 目录创建失败不抛错（避免 Warning 混入图片输出流），仅在确实需要落盘时由后续 file_put_contents 显式失败
            @mkdir($path, 0777, true);
        }

        //在二维码上面添加LOGO
        if (empty($logo) || $logo === false) { //不包含LOGO
            if ($filename == false) {
                \PHPQRCode\QRcode::png($data, false, $level, $size, $padding, $saveandprint); //直接输出到浏览器，不含LOGO
            } else {
                $filename = $path . '/' . $filename; //合成路径
                \PHPQRCode\QRcode::png($data, $filename, $level, $size, $padding, $saveandprint); //直接输出到浏览器，不含LOGO
            }
        } else { //包含LOGO
            if ($filename == false) {
                //$filename=tempnam('','').'.png';//生成临时文件
                die(lang('_PARAMETER_ERROR_'));
            } else {
                //生成二维码,保存到文件
                $filename = $path . '\\' . $filename; //合成路径
            }
            \PHPQRCode\QRcode::png($data, $filename, $level, $size, $padding);

            $QR = imagecreatefromstring(file_get_contents($filename));
            $logo = imagecreatefromstring(file_get_contents($logo));
            $QR_width = imagesx($QR);
            $QR_height = imagesy($QR);
            $logo_width = imagesx($logo);
            $logo_height = imagesy($logo);
            $logo_qr_width = $QR_width / 5;
            $scale = $logo_width / $logo_qr_width;
            $logo_qr_height = $logo_height / $scale;
            $from_width = ($QR_width - $logo_qr_width) / 2;
            imagecopyresampled($QR, $logo, $from_width, $from_width, 0, 0, $logo_qr_width, $logo_qr_height, $logo_width, $logo_height);
            if ($filename === false) {
                Header("Content-type: image/png");
                imagepng($QR);
            } else {
                if ($saveandprint === true) {
                    imagepng($QR, $filename);
                    header("Content-type: image/png"); //输出到浏览器
                    imagepng($QR);
                } else {
                    imagepng($QR, $filename);
                }
            }
        }

        return $filename;
    }
}
