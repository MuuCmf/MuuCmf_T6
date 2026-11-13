<?php

/**
 * 百度富文本编辑器
 */
function ueditor($id = 'myeditor', $name = 'content', $default='', $config='', $style='')
{
    $url = url("api/file/ueditor");

    if($config == 'mini'){
        $config = "
            toolbars:[
                    [
                        'source','|',
                        'bold',
                        'italic',
                        'underline',
                        'fontsize',
                        'forecolor',
                        'fontfamily',
                        'blockquote',
                        'backcolor','|',
                        'simpleupload',
                        'insertimage',
                        'insertcode',
                        'link',
                        'emotion',
                        'fullscreen'
                    ]
            ],
            autoHeightEnabled: false,
            autoFloatEnabled: false,
            initialFrameWidth: null,
            initialFrameHeight: 350
        ";
    }
    if($config == 'all') {
        $config = "
        
        ";
    }
    if($config == '') {
        $config = "
            toolbars:[
                [
                    'fullscreen', 'source', '|', 'undo', 'redo', '|',
                    'bold', 'italic', 'underline', 'fontborder', 'strikethrough', 'superscript', 'subscript', 'removeformat', 'formatmatch', 'autotypeset', 'blockquote', 'pasteplain', '|', 'forecolor', 'backcolor', 'insertorderedlist', 'insertunorderedlist', 'selectall', 'cleardoc', '|',
                    'rowspacingtop', 'rowspacingbottom', 'lineheight', '|',
                    'customstyle', 'paragraph', 'fontfamily', 'fontsize', '|',
                    'directionalityltr', 'directionalityrtl', 'indent', '|',
                    'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|', 'touppercase', 'tolowercase', '|',
                    'link', 'unlink', 'anchor', '|', 'imagenone', 'imageleft', 'imageright', 'imagecenter', '|',
                    'simpleupload', 'insertimage', 'insertvideo', 'music', 'attachment', 'insertcode', 'template', '|',
                    'horizontal', 'date', 'time', 'spechars', 'snapscreen', 'wordimage', '|',
                    'inserttable', 'deletetable', 'insertparagraphbeforetable', 'insertrow', 'deleterow', 'insertcol', 'deletecol', 'mergecells', 'mergeright', 'mergedown', 'splittocells', 'splittorows', 'splittocols', 'charts', '|',
                    'searchreplace', 'drafts'
                ]
            ],
            autoHeightEnabled: false,
            autoFloatEnabled: false,
            initialFrameWidth: null,
            initialFrameHeight: 350
        ";
    }

    $UMconfig = "{
        serverUrl :'$url',
        $config
    }";

    // script 属于 raw text 元素，只有 </script 能提前闭合并注入脚本；
    // 不能整体 htmlspecialchars（UEditor 会读到转义后的字面文本），只中和该序列。
    $safeDefault = str_ireplace('</script', '<\/script', (string)$default);

    $tmp = '
    <script type="text/plain" name="'.$name.'" id="'.$id.'" style="'.$style.';">'.$safeDefault.'</script>
    <script type="text/javascript" charset="utf-8" src="/static/common/lib/ueditor/ueditor.config.js"></script>
    <script type="text/javascript" charset="utf-8" src="/static/common/lib/ueditor/ueditor.all.min.js"></script>
    <script>
        var ue_'.$id.';
        $(function () {
            var config = '. $UMconfig .';
            ue_'.$id.' = UE.getEditor("'.$id.'", config);
        });
    </script>';

    echo $tmp;
}