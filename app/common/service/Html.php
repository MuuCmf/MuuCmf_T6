<?php

namespace app\common\service;

/**
 * HTML 净化服务
 * 用于管理端富文本原样输出到公网页面前的兜底：按白名单移除 script / 事件属性 / 危险协议
 */
class Html
{
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
            // 白名单标签与属性：保留常见排版，去掉脚本 / 事件属性 / iframe 等
            $config->set('HTML.Allowed', 'p[style],br,strong,b,em,i,u,s,ul,ol,li[style],h1[style],h2[style],h3[style],h4[style],h5[style],h6[style],blockquote,pre,code,hr,span[style],div[style],a[href|title|target|rel],img[src|alt|title|width|height],table[style],thead,tbody,tr,th[style],td[style]');
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetBlank', true);
            // 允许安全的行内样式，避免后台富文本的排版/配色在净化后丢失
            $config->set('CSS.AllowedProperties', 'color,background-color,text-align,font-size,font-weight,font-style,text-decoration,line-height');

            return (new \HTMLPurifier($config))->purify($html);
        } catch (\Throwable $e) {
            // 净化组件异常时退化为纯文本，避免原样输出不可信 HTML
            return nl2br(htmlspecialchars($html, ENT_QUOTES, 'UTF-8'));
        }
    }
}
