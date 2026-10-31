<?php

namespace app\common\service;

/**
 * HTML 净化服务
 * 用于管理端富文本原样输出到公网页面前的兜底：按白名单移除 script / 事件属性 / 危险协议
 */
class Html
{
    /**
     * 富文本允许使用的样式类前缀
     * 仅保留带该前缀的 class，其他 class 一律移除，避免任意 class 影响页面布局
     * 与 appstore PC 端详情页 .pd-content 下的预设样式前缀保持一致
     */
    const ALLOWED_CLASS_PREFIX = 'pd-custom-rich-';

    /**
     * 净化富文本
     * @param string $html 原始 HTML
     * @return string 净化后的 HTML；净化失败时退化为转义文本
     */
    public static function sanitize($html)
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        try {
            $config = \HTMLPurifier_Config::createDefault();
            // 定义缓存落到 runtime 目录，避免写入 vendor 目录
            $cache_path = runtime_path() . 'htmlpurifier';
            if (!is_dir($cache_path)) {
                @mkdir($cache_path, 0755, true);
            }
            $config->set('Cache.SerializerPath', $cache_path);
            // 白名单标签与属性：保留常见排版与预设样式类，去掉脚本 / 事件属性 / iframe 等
            $config->set('HTML.Allowed', 'p[class|style],br,strong[class],b,em,i,u,s,ul[class|style],ol[class|style],li[class|style],h1[class|style],h2[class|style],h3[class|style],h4[class|style],h5[class|style],h6[class|style],blockquote[class|style],pre[class|style],code[class|style],hr[class],dl[class|style],dt[class|style],dd[class|style],span[class|style],div[class|style],a[class|href|title|target|rel],img[class|src|alt|title|width|height],table[class|style],thead[class],tbody[class],tfoot[class],tr[class|style],th[class|style],td[class|style]');
            // 先放开 class 属性，净化结束后再按前缀过滤（Attr.AllowedClasses 仅支持精确匹配）
            $config->set('Attr.AllowedClasses', null);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetBlank', true);
            // 允许安全的行内样式，避免后台富文本的排版/配色在净化后丢失
            // 开启 Tricky / Proprietary 以支持 display、overflow、opacity、border-radius 等常用排版属性
            $config->set('CSS.AllowTricky', true);
            $config->set('CSS.Proprietary', true);
            $config->set('CSS.AllowedProperties', 'color,background-color,text-align,font-size,font-weight,font-style,font-variant,text-decoration,text-decoration-line,text-decoration-style,text-decoration-color,line-height,letter-spacing,word-spacing,text-indent,text-transform,white-space,vertical-align,font-family,list-style-type,list-style-position,margin,margin-top,margin-right,margin-bottom,margin-left,padding,padding-top,padding-right,padding-bottom,padding-left,border,border-top,border-right,border-bottom,border-left,border-width,border-style,border-color,border-radius,border-collapse,border-spacing,width,height,min-width,min-height,max-width,max-height,aspect-ratio,float,clear,display,overflow,opacity,table-layout,caption-side');

            $purified = (new \HTMLPurifier($config))->purify($html);

            return self::filterClassPrefix($purified, self::ALLOWED_CLASS_PREFIX);
        } catch (\Throwable $e) {
            // 净化组件异常时退化为纯文本，避免原样输出不可信 HTML
            return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
        }
    }

    /**
     * 按前缀过滤标签中的 class 属性
     * 仅处理标签片段，避免误改正文文本；过滤后为空则移除 class 属性
     * @param string $html 已净化的 HTML
     * @param string $prefix 允许的类名前缀
     * @return string 过滤后的 HTML
     */
    private static function filterClassPrefix($html, $prefix)
    {
        return (string) preg_replace_callback('/<[^>]*>/', static function ($tag) use ($prefix) {
            return (string) preg_replace_callback(
                '/\sclass\s*=\s*(["\'])(.*?)\1/is',
                static function ($matches) use ($prefix) {
                    $classes = preg_split('/\s+/', trim($matches[2])) ?: [];
                    $classes = array_filter($classes, static function ($class) use ($prefix) {
                        return $class !== '' && strpos($class, $prefix) === 0;
                    });

                    return $classes ? ' class="' . implode(' ', $classes) . '"' : '';
                },
                $tag[0]
            );
        }, $html);
    }
}
