<?php
// +----------------------------------------------------------------------
// | Sitemap 生成配置
// +----------------------------------------------------------------------
// 由 app\common\service\Sitemap 读取，产出结果落盘 public 目录（静态文件，可直接被
// Nginx 命中，无需额外路由）。
//
// 「框架本身」的可索引页面写在本文件的 core 清单里；「已安装应用」的页面由各应用
// 自行提供，约定为 app/{模块}/service/Sitemap.php 中的 urls() 方法，核心服务会按
// muucmf_module.is_setup=1 自动发现并汇总，未提供该类的应用不贡献任何 URL。
//
// 条目书写约定：
//   - 字符串：按相对路径（以 / 开头）或绝对地址（http/https 开头）处理；
//   - 数组：支持 loc / lastmod / changefreq / priority 四个键，loc 规则同上；
//   - 只收录可公开索引的页面，禁止收录后台（admin）、接口（api）、
//     用户中心（ucenter）、登录/支付等需要登录或不宜索引的页面。
return [
    // 单个 sitemap 文件条数上限，超出后自动拆分为分片文件 + sitemapindex 索引
    'file_limit' => 50000,

    // 索引文件名（写于 public 目录；分片文件为 sitemap-{分组}.xml）
    'index_file' => 'sitemap.xml',

    // 框架核心页面（静态清单，如需增删直接改这里）
    'core' => [
        ['loc' => '/', 'changefreq' => 'daily', 'priority' => 1.0],
        ['loc' => '/appstore/framework', 'changefreq' => 'weekly', 'priority' => 0.8],
        ['loc' => '/appstore/products/lists', 'changefreq' => 'weekly', 'priority' => 0.8],
        ['loc' => '/appstore/version', 'changefreq' => 'weekly', 'priority' => 0.6],
    ],
];