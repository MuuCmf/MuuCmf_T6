SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

--
-- 表的结构 `muucmf_action`
--
DROP TABLE IF EXISTS `muucmf_action`;
CREATE TABLE IF NOT EXISTS `muucmf_action` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `name` char(30) NOT NULL DEFAULT '' COMMENT '行为唯一标识',
  `title` char(80) NOT NULL DEFAULT '' COMMENT '行为说明',
  `remark` char(140) NOT NULL DEFAULT '' COMMENT '行为描述',
  `rule` text NOT NULL COMMENT '行为规则',
  `log` text NOT NULL COMMENT '日志规则',
  `type` tinyint(2) UNSIGNED NOT NULL DEFAULT '1' COMMENT '类型',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '修改时间',
  `module` varchar(20) NOT NULL DEFAULT '' COMMENT '应用模块',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='系统行为表' AUTO_INCREMENT=1000;

--
-- 转存表中的数据 `muucmf_action`
--

INSERT INTO `muucmf_action` (`id`, `name`, `title`, `remark`, `rule`, `log`, `type`, `status`, `create_time`, `update_time`, `module`) VALUES
(1, 'reg', '用户注册', '用户注册', '', '', 1, 1, 0, 1426070545, ''),
(2, 'input_password', '输入密码', '记录输入密码的次数。', '', '', 1, 1, 0, 1426122119, ''),
(3, 'user_login', '用户登录', '积分+10，每天一次', '[{"table":"member","field":"1","rule":"10","cycle":"24","max":"1"}]', '[user|get_nickname]在[time|time_format]登录了账号', 1, 1, 0, 1428397656, ''),
(4, 'update_config', '更新配置', '新增或修改或删除配置', '', '', 1, 1, 0, 1383294988, ''),
(7, 'update_channel', '更新导航', '新增或修改或删除导航', '', '', 1, 1, 0, 1383296301, ''),
(8, 'update_menu', '更新菜单', '新增或修改或删除菜单', '', '', 1, 1, 0, 1383296392, '');

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_action_limit`
--

DROP TABLE IF EXISTS `muucmf_action_limit`;
CREATE TABLE IF NOT EXISTS `muucmf_action_limit` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '标题',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '名称',
  `frequency` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '频率',
  `time_number` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '时间',
  `time_unit` varchar(50) NOT NULL COMMENT '时间单位',
  `punish` text NOT NULL,
  `if_message` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否发送消息提醒',
  `message_content` text NOT NULL COMMENT '消息内容',
  `action_list` text NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '0',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `module` varchar(20) NOT NULL DEFAULT '' COMMENT '应用模块',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='行为限制表' ROW_FORMAT=COMPACT;

--
-- 转存表中的数据 `muucmf_action_limit`
--

INSERT INTO `muucmf_action_limit` (`id`, `title`, `name`, `frequency`, `time_number`, `time_unit`, `punish`, `if_message`, `message_content`, `action_list`, `status`, `create_time`, `module`) VALUES
(1, 'reg', '注册限制', 1, 1, 'minute', 'warning', 0, '', '[reg]', 1, 0, 'all'),
(2, 'input_password', '输密码', 3, 1, 'minute', 'warning', 0, '', '[input_password]', 1, 0, 'all');

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_action_log`
--

DROP TABLE IF EXISTS `muucmf_action_log`;
CREATE TABLE IF NOT EXISTS `muucmf_action_log` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `action_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '行为id',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '执行用户id',
  `action_ip` varchar(128) NOT NULL DEFAULT '' COMMENT '执行行为者ip',
  `model` varchar(50) NOT NULL DEFAULT '' COMMENT '触发行为的表',
  `record_id` varchar(64) NOT NULL DEFAULT '' COMMENT '触发行为的数据id',
  `remark` text NULL DEFAULT NULL COMMENT '日志备注',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '状态',
  `create_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '执行行为的时间',
  PRIMARY KEY (`id`),
  KEY `action_ip_ix` (`action_ip`),
  KEY `action_id_ix` (`action_id`),
  KEY `user_id_ix` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='行为日志表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_address`
--
DROP TABLE IF EXISTS `muucmf_address`;
CREATE TABLE IF NOT EXISTS `muucmf_address` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'id',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '平台ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户ID',
  `name` varchar(25) NOT NULL DEFAULT '' COMMENT '收件人姓名',
  `phone` varchar(11) NOT NULL DEFAULT '' COMMENT '收件人电话号码',
  `address` varchar(200) NOT NULL DEFAULT '' COMMENT '详细地址',
  `pos_province` varchar(64) NOT NULL DEFAULT '' COMMENT '省份',
  `pos_city` varchar(64) NOT NULL DEFAULT '' COMMENT '城市',
  `pos_district` varchar(64) NOT NULL DEFAULT '' COMMENT '区、县',
  `first` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否默认地址',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户收货地址' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_announce`
--

DROP TABLE IF EXISTS `muucmf_announce`;
CREATE TABLE IF NOT EXISTS `muucmf_announce` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '操作用户ID',
  `teminal` CHAR(32) NOT NULL DEFAULT 'mobile' COMMENT '终端：mobile,pc',
  `type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '公告类型 1、图片 0、文字',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '公告标题',
  `content` text COMMENT '公告描述',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '公告图片',
  `url` varchar(255) DEFAULT '' COMMENT '公告链接',
  `link_to` text COMMENT '连接至',
  `sort` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_attachment`
--
DROP TABLE IF EXISTS `muucmf_attachment`;
CREATE TABLE IF NOT EXISTS `muucmf_attachment` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户ID',
  `filename` char(128) NOT NULL DEFAULT '' COMMENT '附件显示名',
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT '附件类型',
  `attachment` varchar(160) NOT NULL DEFAULT '' COMMENT '路径',
  `mime` char(128) NOT NULL DEFAULT '' COMMENT 'mimeType',
  `ext` char(20) NOT NULL DEFAULT '' COMMENT '扩展名',
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '附件大小',
  `duration` DOUBLE NULL COMMENT '音视频时长，单位：秒',
  `md5` varchar(255) NOT NULL DEFAULT '' COMMENT 'MD5',
  `sha1` varchar(255) NOT NULL DEFAULT '' COMMENT 'SHA1',
  `driver` varchar(32) NOT NULL DEFAULT '' COMMENT '上传驱动 local\\oss\\cos\\tcvod',
  `file_id` varchar(64) NOT NULL DEFAULT '' COMMENT '文件ID,仅云点播支持',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `attachment` (`attachment`),
  KEY `mime` (`mime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='附件表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_author`
--
DROP TABLE IF EXISTS `muucmf_author`;
CREATE TABLE IF NOT EXISTS `muucmf_author` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '绑定用户ID',
  `group_id` INT(11) UNSIGNED NOT NULL COMMENT '分组ID',
  `name` varchar(18) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `description` varchar(140) NOT NULL DEFAULT '' COMMENT '简短描述',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '图片',
  `professional` VARCHAR(64) NULL DEFAULT '' COMMENT '职称',
  `avatar_card` varchar(255) NOT NULL DEFAULT '' COMMENT '手持身份证照片',
  `certificate` varchar(255) NOT NULL DEFAULT '' COMMENT '资格证书',
  `content` text NULL COMMENT '详情',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序值',
  `view` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '阅读量',
  `verify` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '认证类型ID',
  `auth` text NULL COMMENT '功能权限 json格式',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '数据状态',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '拒绝审核原因',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE,
  KEY `uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色用户表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_author_group`
--
DROP TABLE IF EXISTS `muucmf_author_group`;
CREATE TABLE IF NOT EXISTS `muucmf_author_group` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title` varchar(64) NOT NULL COMMENT '角色分组标题',
  `status` TINYINT(2) NOT NULL COMMENT '状态',
  `create_time` int(11) NOT NULL COMMENT '创建时间',
  `update_time` int(11) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色分组' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_author_follow`
--

DROP TABLE IF EXISTS `muucmf_author_follow`;
CREATE TABLE IF NOT EXISTS `muucmf_author_follow` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) unsigned NOT NULL COMMENT '平台ID',
  `uid` int(11) unsigned NOT NULL COMMENT '谁关注',
  `author_id` int(11) unsigned NOT NULL COMMENT '关注谁',
  `status` TINYINT(2) NOT NULL COMMENT '状态',
  `create_time` int(11) unsigned NOT NULL COMMENT '创建时间',
  `update_time` int(11) unsigned NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='创造者关注表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_auth_group`
--

DROP TABLE IF EXISTS `muucmf_auth_group`;
CREATE TABLE IF NOT EXISTS `muucmf_auth_group` (
  `id` mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户组id,自增主键',
  `module` varchar(20) NOT NULL DEFAULT '' COMMENT '用户组所属模块',
  `type` tinyint(4) NOT NULL DEFAULT '0' COMMENT '组类型',
  `title` char(20) NOT NULL DEFAULT '' COMMENT '用户组中文名称',
  `description` varchar(80) NOT NULL DEFAULT '' COMMENT '描述信息',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '用户组状态：为1正常，为0禁用,-1为删除',
  `rules` text NOT NULL COMMENT '用户组拥有的规则id，多个规则 , 隔开',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ROW_FORMAT=COMPACT;

--
-- 转存表中的数据 `muucmf_auth_group`
--

INSERT INTO `muucmf_auth_group` (`id`, `module`, `type`, `title`, `description`, `status`, `rules`) VALUES
(1, 'admin', 1, '普通用户', '', 1, ''),
(2, 'admin', 1, 'VIP', '', 1, ''),
(3, 'admin', 1, '管理员', '', 1, '');

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_auth_group_access`
--
DROP TABLE IF EXISTS `muucmf_auth_group_access`;
CREATE TABLE IF NOT EXISTS `muucmf_auth_group_access` (
  `uid` int(10) UNSIGNED NOT NULL COMMENT '用户id',
  `group_id` mediumint(8) UNSIGNED NOT NULL COMMENT '用户组id',
  UNIQUE KEY `uid_group_id` (`uid`,`group_id`),
  KEY `uid` (`uid`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `muucmf_auth_group_access`
--

INSERT INTO `muucmf_auth_group_access` (`uid`, `group_id`) VALUES
(1, 3);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_auth_rule`
--
DROP TABLE IF EXISTS `muucmf_auth_rule`;
CREATE TABLE IF NOT EXISTS `muucmf_auth_rule` (
  `id` mediumint(8) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '规则id,自增主键',
  `module` varchar(20) NOT NULL COMMENT '规则所属module',
  `type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1-url;2-主菜单',
  `name` char(80) NOT NULL DEFAULT '' COMMENT '规则唯一英文标识',
  `title` char(20) NOT NULL DEFAULT '' COMMENT '规则中文描述',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否有效(0:无效,1:有效)',
  `condition` varchar(300) NOT NULL DEFAULT '' COMMENT '规则附加条件',
  PRIMARY KEY (`id`),
  KEY `module` (`module`,`status`,`type`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=10000 ;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `muucmf_baidu_mp_config`;
CREATE TABLE IF NOT EXISTS `muucmf_baidu_mp_config` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '规则id,自增主键',
  `shopid` int(11) NOT NULL COMMENT '商户ID',
  `title` varchar(20) NOT NULL COMMENT '小程序名称',
  `description` varchar(500) NOT NULL COMMENT '描述',
  `appid` varchar(40) NOT NULL COMMENT '应用ID',
  `appkey` varchar(128) NOT NULL COMMENT 'appkey',
  `secret` varchar(60) NOT NULL COMMENT '应用密匙',
  `pay_appid` varchar(128) DEFAULT NULL COMMENT '支付服务APP ID',
  `pay_appkey` varchar(128) DEFAULT NULL COMMENT '支付服务APP KEY',
  `dealId` varchar(128) DEFAULT NULL COMMENT '支付服务 dealId',
  `rsa_public_key` text COMMENT '平台公钥',
  `rsa_private_key` text COMMENT '验签私钥',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='百度小程序配置表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_capital_flow`
--

DROP TABLE IF EXISTS `muucmf_capital_flow`;
CREATE TABLE IF NOT EXISTS `muucmf_capital_flow` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(40) NOT NULL DEFAULT '' COMMENT '应用标识',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `flow_no` varchar(64) NOT NULL DEFAULT '' COMMENT '流水号',
  `order_no` varchar(64) NOT NULL DEFAULT '' COMMENT '订单编号',
  `channel` varchar(20) NULL DEFAULT '' COMMENT '渠道',
  `source` varchar(40) NOT NULL DEFAULT '' COMMENT '资金来源',
  `type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '资金进出类型，1：支出 2：收入',
  `price` int(11) NOT NULL DEFAULT '0' COMMENT '金额（单位：分）',
  `remark` varchar(1000) NOT NULL DEFAULT '' COMMENT '备注',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '流水状态，1完成 0进行中',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新日期',
  PRIMARY KEY (`id`),
  KEY `flow_no` (`flow_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流水记录表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_channel`
--
DROP TABLE IF EXISTS `muucmf_channel`;
CREATE TABLE IF NOT EXISTS `muucmf_channel` (
  `id` varchar(64) NOT NULL COMMENT '频道ID',
  `block` varchar(32) NOT NULL DEFAULT '' COMMENT '位置 navbar：顶部 footer:底部',
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT '链接类型',
  `app` varchar(64) NOT NULL DEFAULT '' COMMENT '模块标识，空：自定义链接',
  `title` char(30) NOT NULL DEFAULT '' COMMENT '频道标题',
  `url` char(100) NOT NULL DEFAULT '' COMMENT '频道连接',
  `sort` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '导航排序',
  `create_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `target` tinyint(2) UNSIGNED NOT NULL DEFAULT '0' COMMENT '新窗口打开',
  `color` varchar(30) NOT NULL DEFAULT '' COMMENT '颜色',
  `icon` varchar(20) NOT NULL DEFAULT '' COMMENT '图标',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台导航配置' ROW_FORMAT=COMPACT;

--
-- 转存表中的数据 `muucmf_channel`
--

INSERT INTO `muucmf_channel` (`id`, `block`, `type`, `app`, `title`, `url`, `sort`, `create_time`, `update_time`, `status`, `target`, `color`, `icon`) VALUES
('023499C2-E0B4-412A-3119-43565348BA15', 'navbar', '_custom', 'author', '关于我们', 'https://www.muucmf.cc/muu/about/index', 4, 0, 0, 1, 0, '1', '1'),
('412C65DC-7392-A844-7CB7-F653F6C0B3A5', 'navbar', '_custom', 'author', '授权查询', 'https://www.muucmf.cc/muu/nslookup/index', 3, 0, 0, 1, 0, '1', '1'),
('568C89B5-5717-FB43-94B9-26E487CD444C', 'navbar', '_custom', 'author', '开发框架', 'https://www.muucmf.cc/muu/frame/index', 2, 0, 0, 1, 0, '1', '1'),
('6DB50ECF-710A-7EE9-A597-B3CA193D52CF', 'navbar', '_custom', 'author', 'Muu+系列应用', 'https://www.muucmf.cc/muu/product/index', 1, 0, 0, 1, 0, '1', '1'),
('762F77AF-777F-1831-B62F-33E9AF4E012D', 'footer', '_custom', 'micro', '资料下载', '/muu/download/index.html', 5, 0, 0, 1, 0, '', ''),
('789806EC-EB41-01A0-FDCD-2F998B506332', 'footer', '_custom', 'author', '云课堂', '/muu/classroom/index', 0, 0, 0, 1, 0, '', ''),
('84B7D3CB-9A58-5198-73E1-40FCCBCE34C9', 'footer', '_custom', 'author', '云小店', '/muu/cloudstore/index', 1, 0, 0, 1, 0, '', ''),
('9A9F91A9-2683-7CE2-2449-DD6626FA6442', 'navbar', '_custom', 'author', '首页', '/', 0, 0, 0, 1, 0, '1', '1'),
('AD8125E6-7284-C6B5-F255-85389C8CFE2A', 'footer', '_custom', 'author', '线下活动', '/muu/offlineactivity/index', 4, 0, 0, 1, 0, '', ''),
('F1613D3F-35C1-8B5E-72F4-FFA2770F0ACB', 'footer', '_custom', 'author', '题库考试', '/muu/itembankexam/index', 2, 0, 0, 1, 0, '', ''),
('FB1BADA5-17FF-5649-E4DC-65D28025895C', 'footer', '_custom', 'author', '付费社群', '/muu/forum/index', 3, 0, 0, 1, 0, '', '');

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_comment`
--
DROP TABLE IF EXISTS `muucmf_comment`;
CREATE TABLE IF NOT EXISTS `muucmf_comment` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '平台ID',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `to_uid` INT(11) NOT NULL DEFAULT '0' COMMENT '回复至uid',
  `app` varchar(60) NOT NULL DEFAULT '' COMMENT '关联应用的唯一标识',
  `pid` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '上级评论ID',
  `info_id` bigint(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `info_type` varchar(64) NOT NULL COMMENT '数据类型',
  `content` text NOT NULL COMMENT '评论内容',
  `images` text COMMENT '图片',
  `support` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '点赞数量',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  `reason` varchar(255) DEFAULT NULL COMMENT '审核失败原因',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公共评论表';

--
-- 表的结构 `muucmf_config`
--
DROP TABLE IF EXISTS `muucmf_config`;
CREATE TABLE IF NOT EXISTS `muucmf_config` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '配置名称',
  `type` char(32) NOT NULL DEFAULT '' COMMENT '配置类型',
  `title` varchar(50) NOT NULL DEFAULT '' COMMENT '配置说明',
  `group` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '配置分组',
  `extra` varchar(255) NOT NULL DEFAULT '' COMMENT '配置值',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '配置说明',
  `create_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `value` text NOT NULL COMMENT '配置值',
  `sort` smallint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`) USING BTREE,
  KEY `type` (`type`) USING BTREE,
  KEY `group` (`group`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT '配置' AUTO_INCREMENT=100;

--
-- 转存表中的数据 `muucmf_config`
--

INSERT INTO `muucmf_config` (`id`, `name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
(100, 'SITE_CLOSE', 'select', '关闭站点', 4, '0:关闭,1:开启', '站点关闭后其他用户不能访问，管理员可以正常访问', 1378898976, 1640252032, 1, '1', 3),
(102, 'CONFIG_GROUP_LIST', 'entity', '配置分组', 4, '', '配置分组', 1379228036, 1645424501, 1, '1:基本\r\n2:服务\r\n3:用户\r\n4:系统\r\n5:邮件\r\n6:版权', 15),
(104, 'AUTH_CONFIG', 'entity', 'Auth配置', 4, '', '自定义Auth.class.php类配置', 1379409310, 1630895923, 1, 'AUTH_ON:1\r\nAUTH_TYPE:2', 16),
(112, 'DEVELOP_MODE', 'select', '开启开发者模式', 4, '0:关闭\r\n1:开启', '是否开启开发者模式', 1383105995, 1630895355, 1, '0', 8),
(115, 'ADMIN_ALLOW_IP', 'textarea', '后台允许访问IP', 4, '', '多个用逗号分隔，如果不配置表示不限制IP访问', 1387165454, 1630895868, 1, '', 27),
(117, 'MAIL_TYPE', 'select', '邮件类型', 5, '1:SMTP 模块发送\r\n2:mail() 函数发送', '如果您选择了采用服务器内置的 Mail 服务，您不需要填写下面的内容', 1388332882, 1630895456, 1, '1', 1),
(118, 'MAIL_SMTP_HOST', 'string', 'SMTP 服务器', 5, '', 'SMTP服务器', 1388332932, 1630896154, 1, '', 3),
(119, 'MAIL_SMTP_PORT', 'num', 'SMTP服务器端口', 5, '', '默认25 ', 1388332975, 1640243664, 1, '465', 0),
(120, 'MAIL_SMTP_USER', 'string', 'SMTP服务器用户名', 5, '', '填写完整用户名', 1388333010, 1630894629, 1, '', 0),
(121, 'MAIL_SMTP_PASS', 'password', 'SMTP服务器密码', 5, '', '填写您的密码', 1388333057, 1630894645, 1, '', 0),
(124, 'COUNT_DAY', 'num', '后台首页统计用户增长天数', 4, '', '默认统计最近半个月的用户数增长情况', 1420791945, 1630999185, 1, '10', 0),
(126, 'USER_NAME_BAOLIU', 'textarea', '保留用户名和昵称', 3, '', '禁止注册用户名和昵称，包含这些即无法注册,用\" , \"号隔开，用户只能是英文，下划线_，数字等', 1388845937, 1631278499, 1, '管理员,测试,admin,垃圾', 200),
(128, 'VERIFY_OPEN', 'checkbox', '验证码配置', 3, 'reg:注册显示\r\nlogin:登陆显示\r\nreset:密码重置', '验证码配置', 1388500332, 1631320314, 1, '', 3),
(129, 'VERIFY_TYPE', 'select', '验证码类型', 4, '1:中文\r\n2:英文\r\n3:数字\r\n4:英文+数字', '验证码类型', 1388500873, 1630895014, 1, '4', 0),
(132, 'COUNT_CODE', 'textarea', '统计代码', 1, '', '用于统计网站访问量的第三方代码，推荐百度统计', 1403058890, 1653285401, 1, '', 12),
(135, 'DEFUALT_APP', 'string', '系统默认应用', 4, '', '留空默认index', 1417509438, 1640251531, 1, 'index', 1),
(137, 'SITE_CLOSE_HINT', 'textarea', '关站提示文字', 4, '', '站点关闭后的提示文字。', 1433731248, 1640252044, 1, '网站正在更新维护，请稍候再试。', 4),
(140, 'MAIL_SMTP_CE', 'string', '邮件发送测试', 5, '', '填写测试邮件地址', 1388334529, 1630895967, 1, '', 11),
(1000, 'USER_REG_SWITCH', 'checkbox', '用户注册开关', 3, 'username:用户名\r\nemail:邮箱\r\nmobile:手机号', '用户注册开关', 1531177781, 1631278401, 1, 'username,email,mobile', 1),
(1001, 'WEB_SITE_NAME', 'string', '站点名称', 1, '', '站点名称', 1530883729, 1630895072, 1, 'MuuCmf T6 开源低代码应用开发框架', 0),
(1002, 'WEB_SITE_ICP', 'string', 'ICP备案', 1, '', 'ICP备案', 1530883729, 1640309012, 1, '京ICP备12345XXXx号', 20),
(1003, 'WEB_SITE_LOGO', 'pic', '站点LOGO', 1, '', '站点LOGO', 1530883729, 1640325297, 1, '', 2),
(10012, 'USER_LEVEL', 'entity', '用户等级设置', 3, '', '', 1531177781, 1631278482, 1, '0:Lv1 实习\r\n50:Lv2 试用\r\n100:Lv3 转正\r\n200:Lv4 助理\r\n400:Lv5 经理\r\n800:Lv6 董事\r\n1600:Lv7 董事长', 255),
(10013, 'USER_NICKNAME_MIN_LENGTH', 'num', '昵称长度最小值', 3, '', '昵称长度最小值', 1531177781, 1631278545, 1, '2', 20),
(10014, 'USER_NICKNAME_MAX_LENGTH', 'num', '昵称长度最大值', 3, '', '昵称长度最大值', 1531177781, 1631278555, 1, '32', 21),
(10053, 'MAIL_SMTP_SSL', 'select', '启用SSL验证功能', 5, '0:关闭\r\n1:开启', '是否启用SMTP验证功能', 0, 1631757419, 1, '1', 0),
(10135, 'USER_LOGIN_SWITCH', 'checkbox', '用户登录开关', 3, 'username:用户名\r\nemail:邮箱\r\nmobile:手机号\r\nqrcode:扫码（需正确配置公众号）', '允许用户登录的方式', 0, 1631278375, 1, 'username,email,mobile', 2),
(10136, 'USER_USERNAME_MIN_LENGTH', 'num', '用户名长度最小值', 3, '', '用户名长度最小值', 0, 1631522578, 1, '2', 10),
(10137, 'USER_USERNAME_MAX_LENGTH', 'num', '用户名长度最大值', 3, '', '用户名长度最大值', 0, 1631522594, 1, '32', 11),
(10138, 'OPEN_QUICK_LOGIN', 'radio', '用户快捷登录', 3, '1:启用\r\n0:关闭', '开启后在页面弹出快捷登陆框', 0, 1658731272, 1, '1', 3),
(10139, 'USER_NICKNAME_SWITCH', 'radio', '用户注册昵称开关', 3, '1:开启\r\n0:关闭', '用户注册时是否可直接设置自己的昵称', 0, 0, 1, '0', 5),
(10140, 'USER_NICKNAME_PREFIX', 'string', '用户昵称前缀', 3, '', '系统自动生成用户昵称时的前缀', 0, 1631522685, 1, '', 6),
(10141, 'USER_REG_AGREEMENT', 'editor', '用户注册服务协议', 3, '', '用户注册服务协议', 0, 0, 1, '<p style=\"text-align: center;\"><span style=\"font-size: 36px;\">用户注册服务协议</span></p><p><br/></p><hr/><p>ThinkPHP\r\n 是一个免费开源的，快速、简单的面向对象的 轻量级PHP开发框架 \r\n，创立于2006年初，遵循Apache2开源协议发布，是为了敏捷WEB应用开发和简化企业应用开发而诞生的。ThinkPHP从诞生以来一直秉承简洁实用的设计原则，在保持出色的性能和至简的代码的同时，也注重易用性。并且拥有众多的原创功能和特性，在社区团队的积极参与下，在易用性、扩展性和性能方面不断优化和改进，已经成长为国内最领先和最具影响力的WEB应用开发框架，众多的典型案例确保可以稳定用于商业以及门户级的开发。</p>', 999),
(10142, 'SERVICE_TEL', 'string', '联系电话', 2, '', '联系电话', 0, 1640230429, 1, '139xxxxxxxx', 0),
(10143, 'WEB_SITE_GICP', 'string', '公安备案', 1, '', '公安备案', 0, 1640308908, 1, '京公网安备12345XXXx号', 21),
(10144, 'SERVICE_CONSULT', 'string', '咨询&服务', 2, '', '咨询&服务Eamil|电话号码', 0, 0, 1, 'service@hoomuu.cn', 2),
(10145, 'SERVICE_BUSINESS', 'string', '商务合作', 2, '', '商务合作邮箱或电话号码', 0, 0, 1, 'business@hoomuu.cn', 3),
(10146, 'SERVICE_KF_QRCODE', 'pic', '客服二维码', 2, '', '客服二维码', 0, 1653285638, 1, '', 4),
(10149, 'WEB_SITE_DESCRIPTION', 'textarea', '站点简短描述', 1, '', '请完善站点简短描述', 0, 1640325379, 1, '<p>MuuCmf T6 开源低代码应用开发框架</p>\r\n<p>北京火木科技有限公司 版权所有并提供技术支持</p>', 1),
(10150, 'SERVICE_WEIXIN_QRCODE', 'pic', '公众号二维码', 2, '', '关注公众号二维码', 0, 1653285668, 1, '', 5),
(10151, 'COPYRIGHT_MAIN', 'string', '版权主体', 6, '', '版权归属主体名称', 0, 0, 1, '北京火木科技有限公司', 0),
(10152, 'COPYRIGHT_WEBSITE', 'string', '主体官网', 6, '', '版权主体的官方网站地址', 0, 0, 1, 'https://www.muucmf.cn', 0),
(10153, 'USER_MOBILE_BIND', 'radio', '手机号绑定开关', 3, '1:开启\r\n0:关闭', '手机号绑定开关', 0, 1658731662, 1, '1', 4),
(10154, 'SITE_ACCESS_TYPE', 'select', '站点访问类型', 4, '0:开放访问,1:登录访问', '是否强制用户访问站点任何页面都需要登录', 1724115948, 1724115948, 1, '0', 5),
(10156, 'USER_PRIVACY', 'editor', '用户隐私条款', 3, '', '用户隐私条款', 1754539145, 1754539145, 1, '用户隐私条款', 999);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_count_active`
--
DROP TABLE IF EXISTS `muucmf_count_active`;
CREATE TABLE IF NOT EXISTS `muucmf_count_active` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `type` varchar(10) NOT NULL DEFAULT '' COMMENT '类型:''day'',''week'',''month''',
  `date` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `num` int(11) NOT NULL DEFAULT '0' COMMENT '活跃人数',
  `total` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '总人数',
  PRIMARY KEY (`id`),
  UNIQUE KEY `date` (`date`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='活跃统计表' AUTO_INCREMENT=1;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_crontab`
--

DROP TABLE IF EXISTS `muucmf_crontab`;
CREATE TABLE IF NOT EXISTS `muucmf_crontab` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `title` varchar(50) NOT NULL DEFAULT '' COMMENT '标题',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '描述',
  `execute` varchar(200) NOT NULL DEFAULT '' COMMENT '调用执行路径',
  `cycle` varchar(20) NOT NULL DEFAULT '' COMMENT 'hour:每小时day:每天week:每星期month:每月minute-n:N分钟hour-n:N小时day-n:N天',
  `day` tinyint(1) NOT NULL DEFAULT '0' COMMENT '天',
  `hour` tinyint(1) NOT NULL DEFAULT '0' COMMENT '小时',
  `minute` tinyint(1) NOT NULL DEFAULT '0' COMMENT '分钟',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '-1删除 0禁用 1启用',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务表';



--
-- 转存表中的数据 `muucmf_crontab`
--

INSERT INTO `muucmf_crontab` (`id`, `shopid`, `title`, `description`, `execute`, `cycle`, `day`, `hour`, `minute`, `status`, `create_time`, `update_time`) VALUES
(1, 0, '云小店订单自动确认收货', '订单收货确认用户未处理，系统默认7天后自动处理', 'app\\minishop\\crontab\\Receive', 'minute-n', 1, 1, 1, 1, 1645689347, 1645782464),
(2, 0, '订单自动评价', '订单收货后用户未评价，系统默认7天后自动好评', 'app\\common\\crontab\\Evaluate', 'minute-n', 1, 1, 1, 1, 1645782483, 1645782483),
(5, 0, '订单24小时自动取消', '订单下单后24小时内未支付，系统自动取消', 'app\\common\\crontab\\Orders', 'hour-n', 1, 1, 1, 1, 1745882710, 1745882794);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_crontab_log`
--
DROP TABLE IF EXISTS `muucmf_crontab_log`;
CREATE TABLE IF NOT EXISTS `muucmf_crontab_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '平台ID',
  `cid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '任务ID',
  `description` varchar(500) NOT NULL DEFAULT '' COMMENT '描述',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='计划任务执行日志表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_douyin_mp_config`
--

DROP TABLE IF EXISTS `muucmf_douyin_mp_config`;
CREATE TABLE  IF NOT EXISTS `muucmf_douyin_mp_config` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL COMMENT '商户ID',
  `title` varchar(20) NOT NULL COMMENT '小程序名称',
  `description` varchar(500) NOT NULL COMMENT '描述',
  `appid` varchar(40) NOT NULL COMMENT '应用ID',
  `secret` varchar(60) NOT NULL COMMENT '应用密匙',
  `weixin_merchant_uid` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '进件完成微信支付商户号',
  `alipay_merchant_uid` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '进件完成支付宝商户号',
  `token` varchar(128) NOT NULL DEFAULT '' COMMENT 'token',
  `salt` varchar(128) NOT NULL DEFAULT '' COMMENT 'salt',
  `tmplmsg` varchar(500) NOT NULL DEFAULT '' COMMENT '模板消息',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抖音小程序配置表' ROW_FORMAT=COMPACT;


--
-- 表的结构 `muucmf_douyin_mp_settle`
--
DROP TABLE IF EXISTS `muucmf_douyin_mp_settle`;
CREATE TABLE  IF NOT EXISTS `muucmf_douyin_mp_settle` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL COMMENT '平台ID',
  `settle_no` varchar(64) NOT NULL COMMENT '结算单号',
  `order_no` varchar(64) NOT NULL COMMENT '关联订单号',
  `price` int(11) NOT NULL COMMENT '金额',
  `douyin_settle_no` varchar(128) NOT NULL DEFAULT '' COMMENT '平台生成分账单号',
  `status` tinyint(2) NOT NULL COMMENT '分账状态 0已发起 1已完成',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日志',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日志',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='抖音结算分账表';

--
-- 表的结构 `muucmf_evaluate`
--

DROP TABLE IF EXISTS `muucmf_evaluate`;
CREATE TABLE IF NOT EXISTS `muucmf_evaluate` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(20) NOT NULL COMMENT '应用标识',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户ID',
  `type` varchar(255) NOT NULL DEFAULT '' COMMENT '商品类型：商品：goods 闪电购：live_goods',
  `type_id` int(11) UNSIGNED NOT NULL COMMENT '商品ID',
  `order_no` varchar(255) NOT NULL COMMENT '订单号',
  `images` varchar(600) DEFAULT '' NULL COMMENT '评价晒图',
  `value` decimal(3,2) NOT NULL COMMENT '评分',
  `content` varchar(600) NOT NULL DEFAULT ''  COMMENT '评价内容',
  `add_content` varchar(600) NOT NULL DEFAULT '' COMMENT '追加评论 json格式{images:content:}',
  `reply` text NULL COMMENT '评价回复',
  `reply_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '回复时间',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单评价表' ROW_FORMAT=COMPACT;


--
-- 表的结构 `muucmf_extend_config`
--
DROP TABLE IF EXISTS `muucmf_extend_config`;
CREATE TABLE IF NOT EXISTS `muucmf_extend_config` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置ID',
  `name` varchar(100) NOT NULL COMMENT '配置名称',
  `type` char(32) NOT NULL COMMENT '配置类型',
  `title` varchar(50) NOT NULL DEFAULT '' COMMENT '配置说明',
  `group` VARCHAR(32) NOT NULL DEFAULT '' COMMENT '配置分组',
  `extra` varchar(255) NOT NULL DEFAULT '' COMMENT '配置值',
  `remark` varchar(500) NOT NULL DEFAULT '' COMMENT '配置说明',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `value` text NOT NULL COMMENT '配置值',
  `sort` smallint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`) USING BTREE,
  KEY `type` (`type`) USING BTREE,
  KEY `group` (`group`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='扩展配置' ROW_FORMAT=COMPACT;

--
-- 转存表中的数据 `muucmf_extend_config`
--
INSERT INTO `muucmf_extend_config` (`id`, `name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
(1, 'GROUP_LIST', 'entity', '扩展分组', 'public', '', '分组后便于管理参数', 0, 1769571772, 1, 'public:公共配置项\r\naliyun_oss:阿里云OSS\r\ntencent_cos:腾讯云COS\r\naliyun_sms:阿里云短信\r\ntencent_sms:腾讯云短信\r\nweixinpay:微信支付\r\nalipay:支付宝支付\r\ntencent_vod:腾讯云点播\r\nstore:存储配置\r\nsms:短信配置\r\nvod:点播配置\r\npay:支付配置\r\nwithdraw:提现配置\r\nmuuagent:MuuAgent', 0),
(3, 'PICTURE_UPLOAD_DRIVER', 'select', '图片上传驱动', 'store', 'local:本地\r\ntencent:腾讯云COS\r\naliyun:阿里云OSS', '', 0, 1649315854, 1, 'local', 0),
(4, 'FILE_UPLOAD_DRIVER', 'select', '文件上传驱动', 'store', 'local:本地\r\ntencent:腾讯云COS\r\naliyun:阿里云OSS', '', 0, 1649315862, 1, 'local', 0),
(6, 'OSS_ALIYUN_ACCESSKEYID', 'string', 'AccessKeyID', 'aliyun_oss', '', 'Access Key ID是您访问阿里云API的密钥，具有该账户完全的权限，请您妥善保管.', 1630910114, 1630918767, 1, '', 0),
(7, 'OSS_ALIYUN_ACCESSKEYSECRET', 'string', 'AccessKeySecret', 'aliyun_oss', '', 'Access Key Secret是您访问阿里云API的密钥，具有该账户完全的权限，请您妥善保管.', 1630910174, 1630918648, 1, '', 0),
(8, 'OSS_ALIYUN_ENDPOINT', 'string', 'Endpoint', 'aliyun_oss', '', '如：oss-cn-beijing.aliyuncs.com', 1630910253, 1630918691, 1, '', 0),
(9, 'OSS_ALIYUN_BUCKET', 'string', 'Bucket', 'aliyun_oss', '', 'Bucket', 0, 1630918732, 1, 't6-muu', 0),
(11, 'OSS_ALIYUN_BUCKET_DOMAIN', 'string', 'Bucket域名', 'aliyun_oss', '', 'Bucket域名', 0, 1630918755, 1, '', 0),
(13, 'COS_TENCENT_SECRETID', 'string', 'SecretID', 'tencent_cos', '', 'SecretID 是您项目的安全密钥，具有该账户完全的权限，请妥善保管.', 0, 0, 1, '', 0),
(14, 'COS_TENCENT_SECRETKEY', 'string', 'SecretKEY', 'tencent_cos', '', 'SecretKEY 是您项目的安全密钥，具有该账户完全的权限，请妥善保管.', 0, 0, 1, '', 0),
(15, 'COS_TENCENT_BUCKET', 'string', 'Bucket', 'tencent_cos', '', 'Bucket.', 0, 0, 1, '', 0),
(16, 'COS_TENCENT_REGION', 'string', 'Region', 'tencent_cos', '', 'Bucket所在区域.', 0, 1630985517, 1, 'ap-beijing', 0),
(17, 'COS_TENCENT_BUCKET_DOMAIN', 'string', 'Bucket域名', 'tencent_cos', '', '腾讯云支持用户自定义访问域名。注：url开头加http://或https://结尾不加 ‘/’例：http://abc.com.', 0, 0, 1, '', 0),
(18, 'SMS_TENCENT_APPID', 'string', '腾讯云SDKAppID', 'tencent_sms', '', 'SDK AppID是短信应用的唯一标识，调用短信API接口时，需要提供该参数', 0, 0, 1, '', 0),
(20, 'SMS_TENCENT_SIGN', 'string', '短信签名', 'tencent_sms', '', '请使用真实的已申请的签名，签名参数使用的是`签名内容`，而不是`签名ID`', 0, 0, 1, '', 0),
(21, 'SMS_TENCENT_TEMPLATEID', 'string', '短信模板', 'tencent_sms', '', '短信模板ID，应严格按\"模板ID\"填写', 0, 0, 1, '', 0),
(22, 'SMS_SEND_DRIVER', 'select', '短信发送平台', 'sms', 'aliyun:阿里云\r\ntencent:腾讯云', '请选择短信发送第三方平台', 0, 0, 1, 'tencent', 0),
(23, 'SMS_RESEND', 'num', '验证码有效期', 'sms', '', '验证码有效期', 0, 0, 1, '60', 0),
(24, 'SMS_ALIYUN_ACCESSKEYID', 'string', 'AccessKeyID', 'aliyun_sms', '', 'Access Key ID是您访问阿里云API的密钥，具有该账户完全的权限，请您妥善保管', 0, 0, 1, '', 0),
(25, 'SMS_ALIYUN_ACCESSKEYSECRET', 'string', 'AccessKeySecret', 'aliyun_sms', '', 'Access Key Secret是您访问阿里云API的密钥，具有该账户完全的权限，请您妥善保管', 0, 0, 1, '', 0),
(26, 'SMS_ALIYUN_SIGN', 'string', '短信签名', 'aliyun_sms', '', '应严格按\"签名名称\"填写，请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/sign', 0, 0, 1, '', 0),
(27, 'SMS_ALIYUN_TEMPLATEID', 'string', '短信模板', 'aliyun_sms', '', '短信模板Code，应严格按\"模板CODE\"填写, 请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/template', 0, 0, 1, '', 0),
(28, 'SMS_TENCENT_SECRETID', 'string', 'SecretID', 'tencent_sms', '', 'SecretID 是您项目的安全密钥，具有该账户完全的权限，请妥善保管', 0, 0, 1, '', 0),
(29, 'SMS_TENCENT_SECRETKEY', 'string', 'SecretKEY', 'tencent_sms', '', 'SecretKEY 是您项目的安全密钥，具有该账户完全的权限，请妥善保管', 0, 0, 1, '', 0),
(30, 'SMS_TENCENT_REGION', 'string', 'Region', 'tencent_sms', '', '地域参数，格式 如：ap-beijing.', 0, 0, 1, 'ap-beijing', 0),
(31, 'SMS_ALIYUN_REGION', 'string', 'Region', 'aliyun_sms', '', '地域参数，格式 如：cn-beijing.', 0, 0, 1, 'cn-beijing', 0),
(32, 'WX_PAY_MCH_ID', 'string', '微信商户ID', 'weixinpay', '', 'Mch ID是您微信商户的商 户ID，请您妥善保管.', 0, 0, 1, '', 0),
(33, 'WX_PAY_KEY_SECRET', 'string', '微信商户API密钥', 'weixinpay', '', 'Key Secret是您微信商户的API密钥，请您妥善保管.', 0, 0, 1, '', 0),
(34, 'VOD_UPLOAD_DRIVER', 'select', '云点播上传驱动', 'vod', 'disable:不启用\r\ntencent:腾讯云点播', '云点播上传驱动', 0, 0, 1, 'disable', 0),
(35, 'VOD_TENCENT_SECRETID', 'string', 'SecretID', 'tencent_vod', '', 'SecretID 是您项目的安全密钥', 0, 1664100236, 1, '', 0),
(36, 'VOD_TENCENT_SECRETKEY', 'string', 'SecretKEY', 'tencent_vod', '', 'SecretKEY 是您项目的安全密钥', 0, 1664100208, 1, '', 0),
(37, 'VOD_TENCENT_SUBAPPID', 'string', 'SubAppId', 'tencent_vod', '', 'SubAppId 是您云点播平台子应用ID', 0, 1664100162, 1, '', 0),
(38, 'WITHDRAW_STATUS', 'select', '提现开关', 'withdraw', '0:关闭\r\n1:开启', '如有特殊情况，可暂时关闭提现', 0, 1645410598, 1, '1', 1),
(39, 'WITHDRAW_TAX_RATE', 'num', '提现税率', 'withdraw', '', ' 默认千分之五（千分比）', 0, 1645410586, 1, '5', 2),
(40, 'WITHDRAW_DAY_NUM', 'num', '每日可提现次数', 'withdraw', '', '一天最多可提现多少次', 0, 0, 1, '5', 3),
(41, 'WITHDRAW_MIN_PRICE', 'num', '单次最小提现金额（单位：元）', 'withdraw', '', '一次最少提现金额', 0, 1659669533, 1, '1', 4),
(42, 'WITHDRAW_MAX_PRICE', 'num', '单次最大提现金额（单位：元）', 'withdraw', '', '一次最大提现金额', 0, 1659669547, 1, '500', 5),
(43, 'WX_PAY_CERT', 'file', '微信支付cert证书', 'weixinpay', '', '', 0, 0, 1, '', 0),
(44, 'WX_PAY_KEY', 'file', '微信支付Key证书', 'weixinpay', '', '', 0, 0, 1, '', 0),
(45, 'VOD_TENCENT_KEY_SWITCH', 'radio', 'key防盗链开关', 'tencent_vod', '0:不启用\r\n1:启用', '腾讯云点播key防盗链开关', 0, 0, 1, '0', 0),
(46, 'VOD_TENCENT_KEY_VALUE', 'string', '防盗链 Key', 'tencent_vod', '', '腾讯云点播 防盗链Key值', 0, 0, 1, '', 0),
(47, 'WX_PAY_CERT_SERIAL', 'string', '微信支付商户API证书序列号', 'weixinpay', '', '微信支付商户API证书序列号', 0, 0, 1, '', 0),
(48, 'VOD_TENCENT_PROCEDURE', 'radio', '自适应转码加密任务流', 'tencent_vod', '0:不启用\r\n1:启用', '启用后会触发系统预置自适应码流加密任务SimpleAesEncryptPreset.', 0, 1679403460, 1, '1', 0),
(49, 'VOD_TENCENT_PLAYER_KEY', 'string', '播放秘钥', 'tencent_vod', '', '分发播放设置-默认分发配置信息内播放秘钥，仅启用KEY防盗链后有效.', 0, 0, 1, '', 0),
(50, 'WX_PAY_WITHDRAW_API', 'select', '提现接口选择', 'withdraw', 'v2:企业付款到零钱v2\r\nv3:商家转账到零钱v3', '', 0, 0, 1, 'v3', 0),
(51, 'WX_PAY_WITHDRAW_PLATFORM_SERIAL', 'string', '平台证书序列号', 'weixinpay', '', '平台证书模式下需填写', 0, 0, 1, '', 5),
(55, 'VOD_TENCENT_PROCEDURE_NAME', 'string', '自适应转码任务流名称', 'tencent_vod', '', '', 0, 1733203026, 1, 'SimpleAesEncryptPreset', 0),
(56, 'WITHDRAW_TRANSFER_SCENE_ID', 'string', '商家转账场景ID', 'withdraw', '', '', 0, 0, 1, '1005', 0),
(57, 'VOD_TENCENT_LICENSE_URL', 'string', '播放器licenseUrl', 'tencent_vod', '', '播放器 License 地址', 0, 0, 1, '', 0),
(58, 'VOD_TENCENT_LICENSE_TYPE', 'radio', '播放器license版本', 'tencent_vod', '0:基础版\r\n1:高级版', '请勾选创建的播放器license版本', 0, 0, 1, '0', 0),
(59, 'WX_PAY_PLATFORM_MODE', 'radio', '支付验证模式', 'weixinpay', 'cert:平台证书\npublic_key:微信支付公钥', '商户接收APIv3的请求应答、回调时验签使用，以下两种方式只能使用一种', 1779748605, 1779749269, 1, 'cert', 0),
(60, 'WX_PAY_PLATFORM_PUBLIC_KEY', 'file', '微信支付公钥', 'weixinpay', '', '微信支付公钥用于验证微信支付身份', 1779748780, 1779749421, 1, '', 8),
(61, 'WX_PAY_PLATFORM_PUBLIC_KEY_SERIAL', 'string', '微信支付公钥ID', 'weixinpay', '', '微信支付公钥模式下需填写', 1779749202, 1779749911, 1, '', 10),
(62, 'MUUAGENT_BASE_URL', 'string', '接口路径', 'muuagent', '', '接口路径', 1780744852, 1780744852, 1, '', 0),
(63, 'MUUAGENT_API_KEY', 'string', 'ApiKey', 'muuagent', '', '请输入应用ApiKey', 1780744943, 1780744943, 1, '', 0),
(64, 'MUUAGENT_APP_CODE', 'string', '应用标识', 'muuagent', '', '请输入应用唯一标识', 1780744974, 1780744974, 1, '', 0),
(65, 'MUUAGENT_CLIENT_ID', 'string', '客户端ID', 'muuagent', '', '请输入客户端ID', 1780745009, 1780745009, 1, '', 0),
(66, 'MUUAGENT_CLIENT_SECRET', 'string', '客户端secret', 'muuagent', '', '请输入客户端secret', 1780745053, 1780745053, 1, '', 0);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_favorites`
--

DROP TABLE IF EXISTS `muucmf_favorites`;
CREATE TABLE IF NOT EXISTS `muucmf_favorites` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` bigint(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '订单所属店铺ID',
  `app` varchar(60) NOT NULL DEFAULT '' COMMENT '关联应用的唯一标识',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `info_type` varchar(32) NOT NULL COMMENT '关联模型，如：classroom:知识内容，column：专栏',
  `info_id` bigint(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '订单状态，1，正常，0，禁用，-1，已删除',
  `metadata` text NOT NULL COMMENT '元数据',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户收藏表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_feedback`
--

DROP TABLE IF EXISTS `muucmf_feedback`;
CREATE TABLE IF NOT EXISTS `muucmf_feedback` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '平台ID',
  `app` varchar(60) NOT NULL DEFAULT '' COMMENT '关联应用模板name',
  `type` char(32) NOT NULL DEFAULT '' COMMENT '反馈类型',
  `content` varchar(255) DEFAULT NULL COMMENT '反馈内容',
  `images` varchar(255) DEFAULT NULL COMMENT '反馈图片，支持多张',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户反馈表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_field`
--
DROP TABLE IF EXISTS `muucmf_field`;
CREATE TABLE IF NOT EXISTS `muucmf_field` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0',
  `field_id` int(11) NOT NULL DEFAULT '0',
  `field_data` varchar(1000) NOT NULL DEFAULT '',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1 ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_field_group`
--
DROP TABLE IF EXISTS `muucmf_field_group`;
CREATE TABLE IF NOT EXISTS `muucmf_field_group` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `profile_name` varchar(25) NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT '1',
  `sort` tinyint(4) NOT NULL DEFAULT '0' COMMENT '排序',
  `visiable` tinyint(4) NOT NULL DEFAULT '1',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 转存表中的数据 `muucmf_field_group`
--

INSERT INTO `muucmf_field_group` (`id`, `profile_name`, `status`, `sort`, `visiable`, `create_time`) VALUES
(1, '个人资料', 1, 0, 1, 1403847366),
(2, '开发者资料', 1, 0, 0, 1423537648);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_field_setting`
--
DROP TABLE IF EXISTS `muucmf_field_setting`;
CREATE TABLE IF NOT EXISTS `muucmf_field_setting` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `field_name` varchar(25) NOT NULL COMMENT '字段名称',
  `field_alias` varchar(32) NOT NULL COMMENT '字段描述',
  `group_id` int(11) NOT NULL COMMENT '分组ID',
  `visiable` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否公开',
  `required` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否必填',
  `sort` int(11) NOT NULL COMMENT '排序',
  `form_type` varchar(25) NOT NULL COMMENT '表单类型',
  `form_default_value` varchar(200) NOT NULL COMMENT '默认值',
  `validation` varchar(25) NOT NULL COMMENT '验证规则',
  `status` tinyint(4) NOT NULL DEFAULT '1' COMMENT '状态',
  `input_tips` varchar(100) NOT NULL COMMENT '输入提示',
  `create_time` int(11) NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPACT;


--
-- 表的结构 `muucmf_follow`
--
DROP TABLE IF EXISTS `muucmf_follow`;
CREATE TABLE IF NOT EXISTS `muucmf_follow` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `follow_who` int(11) NOT NULL COMMENT '关注谁',
  `who_follow` int(11) NOT NULL COMMENT '谁关注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `alias` varchar(40) DEFAULT NULL COMMENT '备注',
  `group_id` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '分组ID',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='关注表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_history`
--
DROP TABLE IF EXISTS `muucmf_history`;
CREATE TABLE IF NOT EXISTS `muucmf_history` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(60) NOT NULL COMMENT '关联的应用唯一标识',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `info_id` bigint(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `info_type` varchar(255) NOT NULL COMMENT '关联模型',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `metadata` text NOT NULL COMMENT '元数据',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='内容浏览记录' ROW_FORMAT=COMPACT;


--
-- 表的结构 `muucmf_jobs`
--

DROP TABLE IF EXISTS `muucmf_jobs`;
CREATE TABLE IF NOT EXISTS `muucmf_jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 表的结构 `muucmf_keywords`
--

DROP TABLE IF EXISTS `muucmf_keywords`;
CREATE TABLE IF NOT EXISTS `muucmf_keywords` (
  `id` bigint(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'UID',
  `keyword` varchar(90) NOT NULL COMMENT '关键词',
  `sort` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  `recommend` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否推荐',
  `ip` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ip',
  `status` tinyint(2) NOT NULL COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='热门关键词统计' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_member`
--
DROP TABLE IF EXISTS `muucmf_member`;
CREATE TABLE IF NOT EXISTS `muucmf_member` (
  `uid` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `username` varchar(32) DEFAULT '' COMMENT '用户名',
  `email` varchar(50) DEFAULT '' COMMENT '邮箱',
  `mobile` varchar(18) DEFAULT '' COMMENT '手机号',
  `realname` varchar(18) DEFAULT '' COMMENT '真实姓名',
  `nickname` char(64) NOT NULL DEFAULT '' COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '登录密码',
  `sex` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '性别',
  `avatar` varchar(255) DEFAULT '' COMMENT '用户头像',
  `birthday` date NOT NULL DEFAULT '0000-00-00' COMMENT '生日',
  `qq` char(10) DEFAULT '' COMMENT 'qq号',
  `login` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '登录次数',
  `signature` text NULL COMMENT '个性签名',
  `score1` int(11) NOT NULL DEFAULT '0' COMMENT '积分',
  `score2` int(11) NOT NULL DEFAULT '0' COMMENT 'score2',
  `score3` int(11) NOT NULL DEFAULT '0' COMMENT 'score3',
  `score4` int(11) NOT NULL DEFAULT '0' COMMENT 'score4',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '会员状态',
  `reg_ip` varchar(128) NOT NULL DEFAULT '' COMMENT '注册IP',
  `reg_channel` VARCHAR(32) NULL DEFAULT '' COMMENT '注册渠道',
  `last_login_time` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `last_login_ip` varchar(128) NOT NULL DEFAULT '0' COMMENT '最后登录IP',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '注册创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`uid`),
  KEY `status` (`status`),
  KEY `name` (`nickname`),
  KEY `username` (`username`),
  KEY `email` (`email`),
  KEY `mobile` (`mobile`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='会员表' AUTO_INCREMENT=100 ;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_member_authentication`
--
DROP TABLE IF EXISTS `muucmf_member_authentication`;
CREATE TABLE IF NOT EXISTS `muucmf_member_authentication` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `name` varchar(64) NOT NULL COMMENT '真实姓名',
  `card_no` varchar(128) NOT NULL COMMENT '证件号码',
  `card_type` tinyint(2) NOT NULL COMMENT '证件类型',
  `front` varchar(255) NOT NULL COMMENT '证件正面',
  `back` varchar(255) NOT NULL COMMENT '证件背面',
  `status` tinyint(2) NOT NULL COMMENT '-1审核未通过 0未认证 1待审核 2已认证',
  `reason` varchar(255) DEFAULT NULL COMMENT '审核未通过原因',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户实名认证表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_member_sync`
--

DROP TABLE IF EXISTS `muucmf_member_sync`;
CREATE TABLE IF NOT EXISTS `muucmf_member_sync` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `openid` varchar(60) NOT NULL COMMENT '三方用户ID',
  `unionid` varchar(60) NULL COMMENT '三方平台ID',
  `type` varchar(40) NOT NULL COMMENT 'weixin_h5微信公众号 weixin_app微信小程序',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员同步';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_member_wallet`
--

DROP TABLE IF EXISTS `muucmf_member_wallet`;
CREATE TABLE IF NOT EXISTS `muucmf_member_wallet` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `balance` int(11) NOT NULL DEFAULT '0' COMMENT '用户余额',
  `freeze` int(11) NOT NULL DEFAULT '0' COMMENT '冻结资金',
  `revenue` int(11) NOT NULL DEFAULT '0' COMMENT '累计收益',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户钱包表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_menu`
--
DROP TABLE IF EXISTS `muucmf_menu`;
CREATE TABLE IF NOT EXISTS `muucmf_menu` (
  `id` varchar(36) NOT NULL COMMENT 'UUID',
  `title` varchar(50) NOT NULL DEFAULT '' COMMENT '标题',
  `pid` varchar(36) NOT NULL DEFAULT '0' COMMENT '上级分类ID',
  `sort` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序（同级有效）',
  `url` char(255) NOT NULL DEFAULT '' COMMENT '链接地址',
  `hide` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '是否隐藏',
  `type` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '菜单类型 1；模块、0：系统',
  `tip` varchar(255) NOT NULL DEFAULT '' COMMENT '提示',
  `group` varchar(50) DEFAULT '' COMMENT '分组',
  `is_dev` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '是否仅开发者模式可见',
  `icon` varchar(20) NULL COMMENT '导航图标',
  `module` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `muucmf_menu`
--
INSERT INTO `muucmf_menu` (`id`, `title`, `pid`, `sort`, `url`, `hide`, `type`, `tip`, `group`, `is_dev`, `icon`, `module`) VALUES
('017966CD-EA9F-1D79-B126-85CC3DE9FA6B', '字段列表', '3C011249-2C51-D40B-19EC-2A8CA4DCFE51', 0, 'admin/field/list', 0, 0, '', '', 0, '', 'admin'),
('0B25C961-E014-46C4-2F7E-DE3EEF90D9F0', '添加、编辑角色用户', 'A7DA37AC-E001-7C55-083F-E03A03FA5CEC', 0, 'admin/Role/edit', 0, 0, '', '', 0, '', 'admin'),
('0CC7C474-337A-476B-8F70-6837990AA884', '顶部导航', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 60, 'admin/Pc/navbar', 0, 0, '', 'PC管理', 0, 'sitemap', 'admin'),
('0DB5F050-66EA-03D0-CE04-895DB8C50982', '系统升级', '167253B8-B360-E5C8-3F94-F0502E971DAF', 999, 'admin/Update/index', 0, 0, '', '系统升级', 0, 'cloud-download', 'admin'),
('11482681-D521-66FA-FF90-2B5556191EA3', '微信小程序配置', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 20, 'admin/WechatMiniProgram/config', 0, 0, '', '微信小程序', 0, 'comments', 'admin'),
('167253B8-B360-E5C8-3F94-F0502E971DAF', '系统', '0', 6, 'admin/Config/group', 0, 0, '', '', 0, 'gear', 'admin'),
('1A21DBCD-FA7C-C84A-99A5-DA457DE56F01', '支付配置', '167253B8-B360-E5C8-3F94-F0502E971DAF', 6, 'admin/extend/payment', 0, 0, '', '第三方扩展', 0, 'jpy', 'admin'),
('1B23C61A-3B9E-07DC-6FC8-DA8A2F7A80D0', '浏览记录', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 91, 'admin/History/list', 0, 0, '', '用户互动', 0, 'eye', 'admin'),
('1E10322E-B5A4-6CFE-6F33-F629B1D72A6F', '关键字列表', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 3, 'admin/Keywords/list', 0, 0, '', '搜索关键字', 0, 'search', 'admin'),
('20216DCF-1138-09A3-346A-C92E08E33677', '应用管理', '7BE5FA0B-7009-AB46-FE7B-A9364ACAF687', 5, 'admin/Module/list', 0, 0, '', '本地', 0, 'laptop', 'admin'),
('25583213-2F69-FB2D-3AE0-D522569BBCD3', '新增、编辑Seo规则', '5962E368-3742-4699-E35A-6D268A73CA2A', 0, 'admin/Seo/edit', 0, 0, '', '', 0, '', 'admin'),
('27C2D3F8-D178-28DE-EC82-A96B6726D5F0', '新增、编辑公告', 'DAF83BB8-F5C2-0CBB-AD8B-FEBB44D12FA3', 0, 'admin/Announce/edit', 0, 0, '', '', 0, '', 'admin'),
('294859E5-C33E-098A-EDEB-B88928EF4DEC', '编辑', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/member/edit', 0, 0, '', '', 0, '', 'admin'),
('2F0E1A33-6AC2-DDC1-5744-8E3C4FE1F9EB', '系统配置参数', '167253B8-B360-E5C8-3F94-F0502E971DAF', 2, 'admin/config/list', 0, 0, '', '系统配置', 0, 'list-ul', 'admin'),
('3C011249-2C51-D40B-19EC-2A8CA4DCFE51', '扩展资料', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 9, 'admin/field/group', 0, 0, '', '用户管理', 0, 'expand', 'admin'),
('40EE3F01-B5B0-9821-D6E9-F327562C5D29', 'Seo规则状态设置', '5962E368-3742-4699-E35A-6D268A73CA2A', 0, 'admin/Seo/status', 0, 0, '', '', 0, '', 'admin'),
('4B3DFCDA-258E-0CBC-66D5-BB95CE81B9D5', '跳转小程序列表', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 25, 'admin/tominiprogram/list', 0, 0, '', '微信小程序', 0, 'external-link', 'admin'),
('4B6E56C7-715A-DEBB-F335-9B290240F781', '消息类型', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 10, 'admin/Message/type', 0, 0, '', '消息管理', 0, 'commenting', 'admin'),
('4C00F562-3EFB-A592-CD21-4F430BF9079F', '删除', '4862F745-C29C-BEF5-4F35-11328C5ADF51', 0, 'admin/Database/del', 0, 0, '删除备份文件', '', 0, '', 'admin'),
('4E0C013B-C00F-449F-324E-473115528F00', '新增、编辑用户组', 'E62328CE-8E20-DD00-E0E8-832D1C8E3B65', 0, 'admin/Auth/groupEdit', 0, 0, '编辑用户组名称和描述', '', 0, '', 'admin'),
('4EF1FF5B-F390-89DF-B9FE-30030977CC89', '发送消息', '4B6E56C7-715A-DEBB-F335-9B290240F781', 0, 'admin/Message/send', 0, 0, '', '', 0, '', 'admin'),
('54C76B51-A313-477E-8FA9-CBD63D456610', '添加、编辑字段', '3C011249-2C51-D40B-19EC-2A8CA4DCFE51', 0, 'admin/field/editField', 0, 0, '', '', 0, '', 'admin'),
('5962E368-3742-4699-E35A-6D268A73CA2A', 'Seo规则', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 63, 'admin/Seo/list', 0, 0, '', 'PC管理', 0, 'file-text', 'admin'),
('5C72BF11-A945-6D28-D7C2-66FE3D812DA6', '用户导航', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 62, 'admin/Pc/user', 0, 0, '', 'PC管理', 0, 'user-plus', 'admin'),
('5DDD95BA-9BB4-1340-5E62-D83C83A9F881', '存储设置', '167253B8-B360-E5C8-3F94-F0502E971DAF', 3, 'admin/extend/store', 0, 0, '', '第三方扩展', 0, 'plus-square', 'admin'),
('60F1F173-0196-BEEE-7852-00F71939360A', '新增、编辑消息内容', 'D15A9C87-9B85-DE05-ADCF-EAAD05BD94FD', 0, 'admin/Message/contentEdit', 0, 0, '', '', 0, '', 'admin'),
('67FFBEB1-E521-A9F6-89C6-EEB5E7020704', '重置用户密码', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/Member/initpass', 1, 0, '', '', 0, '', 'admin'),
('68121540-2C69-EAC2-F2EF-B7ADBBE74C09', '状态管理', 'E62328CE-8E20-DD00-E0E8-832D1C8E3B65', 0, 'admin/Auth/groupStatus', 0, 0, '用户组状态管理', '', 0, '', 'admin'),
('6B092E46-1EF5-846D-1587-C9E35E0BEF0B', '开始升级', '0DB5F050-66EA-03D0-CE04-895DB8C50982', 0, 'admin/Update/start', 1, 0, '', '', 0, '', 'admin'),
('6C28F552-78A7-209F-A6AA-82C7212A6DCA', '访问授权', 'E62328CE-8E20-DD00-E0E8-832D1C8E3B65', 0, 'admin/Auth/access', 0, 0, '\"后台 \\ 用户 \\ 权限管理\"列表页的\"访问授权\"操作按钮', '', 0, '', 'admin'),
('6E50C73A-CA2B-7CA8-889E-99B25D578164', '公众号配置', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 0, 'admin/WechatOfficial/config', 0, 0, '', '微信公众号/H5', 0, 'comments', 'admin'),
('6E7257F5-44DA-009D-548F-47B895DDC1CB', '系统权限菜单', '167253B8-B360-E5C8-3F94-F0502E971DAF', 80, 'admin/menu/list', 0, 0, '', '权限管理', 0, 'navicon', 'admin'),
('7571F78A-534D-A2F8-C56E-97CF56A9CEC4', '恢复', '4862F745-C29C-BEF5-4F35-11328C5ADF51', 0, 'admin/Database/import', 0, 0, '数据库恢复', '', 0, '', 'admin'),
('7692A0E4-AE15-9632-FD7F-6475E0A29399', '模块安装', '20216DCF-1138-09A3-346A-C92E08E33677', 3, 'admin/Module/install', 0, 0, '', '本地', 0, '', 'admin'),
('782D3C8F-D46D-35F2-D778-31FD2DCAB7F5', '自动回复', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 6, 'admin/WechatOfficial/autoReply', 0, 0, '', '微信公众号/H5', 0, 'comment-o', 'admin'),
('7BE5FA0B-7009-AB46-FE7B-A9364ACAF687', '应用', '0', 5, 'admin/Module/list', 0, 0, '', '', 0, 'cloud', 'admin'),
('7D9E934B-1047-3CFE-BD5E-788AA6DB483F', '优化表', '2B76BD2C-80AB-319C-CE6E-1B0E6930B3CC', 0, 'admin/Database/optimize', 0, 0, '优化数据表', '', 0, '', 'admin'),
('80310884-97ED-4A43-3AA7-D0F5B32855F8', '导入', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/import', 0, 0, '', '', 0, '', 'admin'),
('8AEC0A7D-555F-8708-90B0-5528A2542F2F', '新增、编辑', 'D1B92885-12EA-9403-F367-E8978A4650DE', 0, 'admin/extend/edit', 0, 0, '', '', 0, '', 'admin'),
('8CE9FEC6-99F2-E8FC-87B6-089E8C17FB93', '成员授权', 'E62328CE-8E20-DD00-E0E8-832D1C8E3B65', 0, 'admin/Auth/user', 0, 0, '\"后台 \\ 用户 \\ 权限管理\"列表页的\"成员授权\"操作按钮', '', 0, '', 'admin'),
('8F5C83E0-3753-C731-4EEF-5D004137B11D', '运营', '0', 3, 'admin/Announce/list', 0, 0, '', '', 0, 'area-chart', 'admin'),
('8FBC28D8-1385-5D72-41DA-1F3D80C00021', '新增、编辑关键字', '1E10322E-B5A4-6CFE-6F33-F629B1D72A6F', 0, 'admin/Keywords/edit', 0, 0, '', '', 0, '', 'admin'),
('9046DAB8-73C9-74CF-B95B-6110A41BF43D', '计划任务', '167253B8-B360-E5C8-3F94-F0502E971DAF', 70, 'admin/crontab/list', 0, 0, '', '异步任务', 0, 'clock-o', 'admin'),
('91377A3B-2DF4-9334-E70C-6240DB520341', '新增、编辑跳转小程序', '4B3DFCDA-258E-0CBC-66D5-BB95CE81B9D5', 0, 'admin/tominiprogram/edit', 1, 0, '', '跳转小程序', 0, '', 'admin'),
('5F56DFBD-99CA-3695-EAFA-0DB0F5FA2240', '状态管理', '4B3DFCDA-258E-0CBC-66D5-BB95CE81B9D5', 0, 'admin/Tominiprogram/status', 0, 0, '', '', 0, '', 'admin'),
('9609CE49-7E15-17F7-E43E-1078AB4C23C3', '设置显示隐藏', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/toogleHide', 1, 0, '', '', 0, '', 'admin'),
('97727426-BDFD-EE96-8B0C-A3911B812391', '排序', '2F0E1A33-6AC2-DDC1-5744-8E3C4FE1F9EB', 0, 'admin/Config/sort', 1, 0, '', '', 0, '', 'admin'),
('9D3627DF-27CD-D480-5354-8C570B4DF3AC', '新增/编辑自动回复', '782D3C8F-D46D-35F2-D778-31FD2DCAB7F5', 0, 'admin/WechatOfficial/editAutoReply', 0, 0, '', '', 0, '', 'admin'),
('9E841904-2E51-0F7D-61BB-247E05AD6526', '删除菜单', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/del', 1, 0, '', '', 0, '', 'admin'),
('A4650B98-DAD4-8194-030C-1B2AB4F35CBA', '渠道', '0', 4, 'admin/WechatOfficial/config', 0, 0, '', '', 0, 'cubes', 'admin'),
('A53BEFBB-17F7-56CD-ADF9-3D6754061E70', '积分日志', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 51, 'admin/Score/log', 0, 0, '', '积分管理', 0, 'calendar', 'admin'),
('A7DA37AC-E001-7C55-083F-E03A03FA5CEC', '角色用户列表', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 5, 'admin/Role/list', 0, 0, '', '角色管理', 0, 'user-plus', 'admin'),
('A8DBCB14-BA8A-7888-824F-AF15DF7AF84D', '控制台', 'DA900619-B54E-49E7-8027-21C94ECD6FAC', 0, 'admin/index/index', 0, 0, '', '控制台', 0, 'cog', 'admin'),
('AA5E505D-8F99-EFBD-F434-D099109DF291', '提现管理', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 12, 'admin/withdraw/list', 0, 0, '', '提现管理', 0, 'credit-card', 'admin'),
('AABA580B-E97E-69C3-BA9B-34694408DDBA', '积分类型列表', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 50, 'admin/Score/type', 0, 0, '', '积分管理', 0, 'sticky-note', 'admin'),
('AB79B0D0-8E8A-523C-42A4-5482BC4DD610', '编辑', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/edit', 0, 0, '', '', 0, '', 'admin'),
('AD4E0A9E-FD52-2FA9-EC0B-4B6D15D13951', '新增、编辑任务', '9046DAB8-73C9-74CF-B95B-6110A41BF43D', 0, 'admin/crontab/edit', 1, 0, '', '', 0, '', 'admin'),
('AD5E02C5-B1C6-7555-D8A7-09189710DD57', '点播配置', '167253B8-B360-E5C8-3F94-F0502E971DAF', 5, 'admin/extend/vod', 0, 0, '', '第三方扩展', 0, 'cloud-upload', 'admin'),
('ADFEFF44-F130-2291-C359-7801CDDF6F63', '编辑', '2F0E1A33-6AC2-DDC1-5744-8E3C4FE1F9EB', 0, 'admin/config/edit', 0, 0, '新增编辑和保存配置', '', 0, '', 'admin'),
('B0798577-F1CB-669D-6DE8-BD04145FFDE9', '行为限制启用、禁用、删除', '05929499-FF7F-7615-C021-EDECADD02115', 0, 'admin/ActionLimit/limitStatus', 1, 0, '', '', 0, '', 'admin'),
('B4CCC48C-113F-4A31-9378-F9F77EEA9F4B', '用户反馈', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 90, 'admin/Feedback/list', 0, 0, '', '用户互动', 0, 'exchange', 'admin'),
('BD492FD0-4A96-2B3C-6278-9C3FB20DB2AB', '删除', '2F0E1A33-6AC2-DDC1-5744-8E3C4FE1F9EB', 0, 'admin/Config/del', 0, 0, '删除配置', '', 0, '', 'admin'),
('C6E01098-793A-2CF1-3873-4B54374774D8', '添加、编辑分组', '3C011249-2C51-D40B-19EC-2A8CA4DCFE51', 0, 'admin/field/editGroup', 0, 0, '', '', 0, '', 'admin'),
('C8862310-A0B1-3BF0-3195-D92236CD0A5C', '短信配置', '167253B8-B360-E5C8-3F94-F0502E971DAF', 4, 'admin/extend/sms', 0, 0, '', '第三方扩展', 0, 'mobile', 'admin'),
('D15A9C87-9B85-DE05-ADCF-EAAD05BD94FD', '消息发送记录', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 11, 'admin/Message/list', 0, 0, '', '消息管理', 0, 'comments', 'admin'),
('D18841ED-C034-2E7A-D0B2-92D0AC647179', '用户', '0', 2, 'admin/member/list', 0, 0, '', '', 0, 'user', 'admin'),
('D1B92885-12EA-9403-F367-E8978A4650DE', '扩展配置参数', '167253B8-B360-E5C8-3F94-F0502E971DAF', 999, 'admin/extend/list', 0, 0, '', '第三方扩展', 0, 'list-ul', 'admin'),
('D273258A-2842-7AF2-802C-6AC91C98CB9A', '权限菜单', '20216DCF-1138-09A3-346A-C92E08E33677', 8, 'admin/Module/menu', 0, 0, '', '本地', 0, '', 'admin'),
('D70961D9-8B55-4608-70DB-DD18FA89D866', '卸载模块', '20216DCF-1138-09A3-346A-C92E08E33677', 7, 'admin/module/uninstall', 0, 0, '', '本地', 0, '', 'admin'),
('D792BB36-9033-4A54-F4D4-1B14854CDB0E', '新增', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/add', 0, 0, '', '系统设置', 0, '', 'admin'),
('D7AC6473-7E00-7B96-9361-511E06A89F27', '新增、编辑消息类型', '4B6E56C7-715A-DEBB-F335-9B290240F781', 0, 'admin/Message/typeEdit', 0, 0, '', '', 0, '', 'admin'),
('DA4333DF-D814-819B-D657-401FE5153AB4', '用户信息', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 2, 'admin/member/list', 0, 0, '', '用户管理', 0, 'user', 'admin'),
('DA900619-B54E-49E7-8027-21C94ECD6FAC', '控制台', '0', 1, 'admin/index/index', 0, 0, '后台首页', '', 0, 'home', 'admin'),
('DABA8BF4-83F7-9354-895B-DAAC65A11CB1', '新增、编辑权限菜单', '20216DCF-1138-09A3-346A-C92E08E33677', 9, 'admin/module/menuEdit', 0, 0, '', '本地', 0, '', 'admin'),
('DAF83BB8-F5C2-0CBB-AD8B-FEBB44D12FA3', '公告管理', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 2, 'admin/Announce/list', 0, 0, '', '公告管理', 0, 'bullhorn', 'admin'),
('E2D95A37-3B56-F463-C7B6-D9B1559BD46C', '底部导航', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 61, 'admin/Pc/footer', 0, 0, '', 'PC管理', 0, 'th-list', 'admin'),
('E400FF60-A02F-1A24-A28F-CF687914B8D3', '消息队列', '167253B8-B360-E5C8-3F94-F0502E971DAF', 80, 'admin/queue/list', 0, 0, '', '异步任务', 0, 'exchange', 'admin'),
('E4F0C69B-FD7F-67F5-D5AE-BC32CDC9F22A', '解除授权', 'E62328CE-8E20-DD00-E0E8-832D1C8E3B65', 0, 'admin/Auth/removeFromGroup', 0, 0, '\"成员授权\"列表页内的解除授权操作按钮', '', 0, '', 'admin'),
('E62328CE-8E20-DD00-E0E8-832D1C8E3B65', '用户组管理', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 3, 'admin/Auth/group', 0, 0, '', '权限组', 0, 'users', 'admin'),
('E63964B6-14E4-AB35-BED5-AB0DF454DA27', '排序', '6E7257F5-44DA-009D-548F-47B895DDC1CB', 0, 'admin/Menu/sort', 1, 0, '', '', 0, '', 'admin'),
('EBBE670F-8CAB-3C4B-BD2A-7F238F75B7A4', '设置积分状态', 'AABA580B-E97E-69C3-BA9B-34694408DDBA', 0, 'admin/Score/typeStatus', 0, 0, '', '', 0, '', 'admin'),
('ED10E1E1-ECB7-CC4C-BC81-C6D77ACED85B', '系统配置', '167253B8-B360-E5C8-3F94-F0502E971DAF', 1, 'admin/Config/group', 0, 0, '', '系统配置', 0, 'cog', 'admin'),
('EF0B4FAF-6F3E-7212-A8A1-F59138D95EBA', '保存', '2F0E1A33-6AC2-DDC1-5744-8E3C4FE1F9EB', 0, 'admin/Config/save', 0, 0, '保存配置', '', 0, '', 'admin'),
('F66F609F-AB60-31E2-77EA-9214069E9D2A', '新增/编辑类型', 'AABA580B-E97E-69C3-BA9B-34694408DDBA', 0, 'admin/Score/typeEdit', 1, 0, '', '', 0, '', 'admin'),
('F67EED4F-4F43-1002-BCE3-FF76E698928E', '修复表', '2B76BD2C-80AB-319C-CE6E-1B0E6930B3CC', 0, 'admin/Database/repair', 0, 0, '修复数据表', '', 0, '', 'admin'),
('F8C08807-1339-95ED-FDEC-8E5D853FC1D8', '菜单管理', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 1, 'admin/WechatOfficial/menu', 0, 0, '', '微信公众号/H5', 0, 'bars', 'admin'),
('F8D89EF4-792E-B14F-8456-09394EDE7C72', '编辑模块', '20216DCF-1138-09A3-346A-C92E08E33677', 0, 'admin/Module/edit', 1, 0, '', '模块管理', 0, '', 'admin'),
('FAD7E420-2621-D50C-48AF-BAEEA2F361F5', '应用商店', '7BE5FA0B-7009-AB46-FE7B-A9364ACAF687', 0, 'admin/Appcloud/index', 0, 0, '', '云端', 0, 'cloud', 'admin'),
('FD6493CE-98CB-A5A7-3236-080E11318831', '备份', '2B76BD2C-80AB-319C-CE6E-1B0E6930B3CC', 0, 'admin/Database/export', 0, 0, '备份数据库', '', 0, '', 'admin'),
('8553C20D-7FCB-4252-15F4-5ECFC0A56092', '角色类型', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 4, 'admin/Role/group', 0, 0, '', '角色管理', 0, 'window-restore', 'admin'),
('8BBDA16F-8A60-47CE-F4BD-0C3AC4AC7677', '添加、编辑分组', '8553C20D-7FCB-4252-15F4-5ECFC0A56092', 0, 'admin/Role/groupEdit', 0, 0, '', '', 0, '', 'admin'),
('7472E236-A4ED-6AC7-712E-3479158D5E21', '抖音小程序配置', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 30, 'admin/DouyinMiniprogram/config', 0, 0, '', '抖音小程序', 0, '', 'admin'),
('835882C7-20C1-B7B3-1373-330D0F3E9262', '未结算订单', 'A59BE3B9-EDD9-673E-3FAC-D7AD8FC39F38', 0, 'admin/DouyinMiniprogram/orders', 0, 0, '', '', 0, '', 'admin'),
('A59BE3B9-EDD9-673E-3FAC-D7AD8FC39F38', '结算分账', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 31, 'admin/DouyinMiniprogram/settle', 0, 0, '', '抖音小程序', 0, 'jpy', 'admin'),
('EDE0967F-0156-2219-F43D-6412CB252638', '状态管理', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/Member/status', 0, 0, '', '', 0, '', 'admin'),
('E3F348B0-8DA2-C62A-062E-5C406B09D922', '状态管理', '8553C20D-7FCB-4252-15F4-5ECFC0A56092', 0, 'admin/Role/groupStatus', 0, 0, '', '', 0, '', 'admin'),
('7C934436-0EFC-A814-C5FB-521A764E75BA', '清空日志', 'A53BEFBB-17F7-56CD-ADF9-3D6754061E70', 0, 'admin/Score/clear', 0, 0, '', '', 0, '', 'admin'),
('44C36AB5-BF84-8C51-B097-FE7730A22BC3', '公告状态管理', 'DAF83BB8-F5C2-0CBB-AD8B-FEBB44D12FA3', 0, 'admin/Announce/status', 0, 0, '', '', 0, '', 'admin'),
('72483346-528E-3DFD-C1F6-5054030E78C7', '关键字状态管理', '1E10322E-B5A4-6CFE-6F33-F629B1D72A6F', 0, 'admin/Keywords/status', 0, 0, '', '', 0, '', 'admin'),
('1AF87B7B-BCC4-4493-39EE-F726D6A8DB0E', '状态管理', '4B6E56C7-715A-DEBB-F335-9B290240F781', 0, 'admin/Message/typeStatus', 0, 0, '', '', 0, '', 'admin'),
('83FB02F1-F7B5-E5B9-2D40-DED038ABE5EE', '消息状态管理', 'D15A9C87-9B85-DE05-ADCF-EAAD05BD94FD', 0, 'admin/Message/messageStatus', 0, 0, '', '', 0, '', 'admin'),
('E8197AF4-11D1-F0B1-0153-AD74521C3283', '状态管理', 'B4CCC48C-113F-4A31-9378-F9F77EEA9F4B', 0, 'admin/Feedback/status', 0, 0, '', '', 0, '', 'admin'),
('759FD998-08F4-A420-337C-55C0D5C31B0A', '状态管理', '1B23C61A-3B9E-07DC-6FC8-DA8A2F7A80D0', 0, 'admin/History/status', 0, 0, '', '', 0, '', 'admin'),
('D3E5BCC8-BAED-D970-9E40-6EB17CAFF94A', '用户详情', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/Member/detail', 0, 0, '', '', 0, '', 'admin'),
('06AEED1A-9E0E-97B9-6395-EA9959B2BE6E', '状态管理', 'A7DA37AC-E001-7C55-083F-E03A03FA5CEC', 0, 'admin/Role/status', 0, 0, '', '', 0, '', 'admin'),
('C3F2915A-B874-B3DB-3FD2-8743E5ABF242', '企业微信配置', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 26, 'admin/WechatWork/config', 0, 0, '', '企业微信', 0, 'comments', 'admin'),
('FF8D1924-A5EC-7830-B6BA-C0DA6C8B9CBF', '用户选择', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/Member/chooseUser', 0, 0, '', '', 0, '', 'admin'),
('867B8F71-CFBE-BB93-2B91-8D03FAA827F6', '状态管理', 'BA26CFE2-13FE-6D73-F21F-EB74D4CC4E74', 0, 'admin/Favorites/status', 0, 0, '', '', 0, '', 'admin'),
('BA26CFE2-13FE-6D73-F21F-EB74D4CC4E74', '收藏记录', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 92, 'admin/Favorites/list', 0, 0, '', '用户互动', 0, 'sticky-note', 'admin'),
('034108E8-15E1-4538-AADD-9B6516C6F5C0', '实名认证', 'D18841ED-C034-2E7A-D0B2-92D0AC647179', 8, 'admin/Authentication/list', 0, 0, '', '用户管理', 0, 'id-card', 'admin'),
('072BD3D3-A601-D26D-D54A-0BA4CB2D0DE4', '实名认证审核', '034108E8-15E1-4538-AADD-9B6516C6F5C0', 0, 'admin/Authentication/verify', 0, 0, '', '', 0, '', 'admin'),
('B2920D70-1301-4758-89C0-5CA6B9E73C44', '删除附件', 'F06C84A8-2A34-902F-2A88-A25925465036', 0, 'admin/Attachment/del', 0, 0, '', '', 0, '', 'admin'),
('F06C84A8-2A34-902F-2A88-A25925465036', '附件列表', '167253B8-B360-E5C8-3F94-F0502E971DAF', 50, 'admin/Attachment/list', 0, 0, '', '附件管理', 0, 'th-large', 'admin'),
('BDE6A6B2-3EA1-BD31-A9D4-44B442156839', '上传用户头像', 'DA4333DF-D814-819B-D657-401FE5153AB4', 0, 'admin/Member/avatar', 0, 0, '', '', 0, '', 'admin');

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_message`
--

DROP TABLE IF EXISTS `muucmf_message`;
CREATE TABLE IF NOT EXISTS `muucmf_message` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发送用户UID',
  `to_uid` int(11) UNSIGNED NOT NULL COMMENT '接收用户UID',
  `type_id` int(11) NOT NULL COMMENT '消息类型ID',
  `content_id` int(11) NOT NULL COMMENT '消息内容ID',
  `is_read` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否已读',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_message_content`
--
DROP TABLE IF EXISTS `muucmf_message_content`;
CREATE TABLE IF NOT EXISTS `muucmf_message_content` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `title` varchar(500) NOT NULL COMMENT '消息标题',
  `description` varchar(128) NOT NULL COMMENT '简短描述',
  `content` text NOT NULL COMMENT '消息内容',
  `args` text NULL COMMENT '消息参数 json格式',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息内容';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_message_type`
--

DROP TABLE IF EXISTS `muucmf_message_type`;
CREATE TABLE IF NOT EXISTS `muucmf_message_type` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `title` varchar(64) NOT NULL COMMENT '类型标题',
  `description` varchar(255) NOT NULL COMMENT '类型描述',
  `icon` varchar(255) NULL COMMENT '类型图标',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息类型';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_module`
--
DROP TABLE IF EXISTS `muucmf_module`;
CREATE TABLE IF NOT EXISTS `muucmf_module` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `name` varchar(30) NOT NULL COMMENT '模块名',
  `icon` varchar(500) NULL COMMENT '图标',
  `alias` varchar(30) NOT NULL COMMENT '中文名',
  `version` varchar(20) NOT NULL COMMENT '版本号',
  `summary` varchar(200) NOT NULL COMMENT '简介',
  `developer` varchar(50) NULL COMMENT '开发者',
  `website` varchar(200) NULL COMMENT '网址',
  `entry` varchar(50) NULL COMMENT '管理端入口',
  `is_setup` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否已安装',
  `sort` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '模块排序',
  `source` varchar(16) NOT NULL DEFAULT 'local' COMMENT '来源 local/cloud',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='模块管理表';

-- --------------------------------------------------------


--
-- 表的结构 `muucmf_orders`
--

DROP TABLE IF EXISTS `muucmf_orders`;
CREATE TABLE IF NOT EXISTS `muucmf_orders` (
  `id` bigint(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(60) NOT NULL COMMENT '关联应用唯一标识',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `order_no` varchar(128) NOT NULL COMMENT '订单号',
  `paid` tinyint(4) NOT NULL DEFAULT '0' COMMENT '是否支付 1:已支付 0：未支付',
  `paid_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '支付时间',
  `type` varchar(32) DEFAULT NULL COMMENT '订单类型',
  `order_info_type` varchar(32) NOT NULL COMMENT '商品类型关键字，如：knowledge:知识内容，column：专栏内容，关联不同模型',
  `order_info_id` bigint(11) UNSIGNED NOT NULL COMMENT '商品ID',
  `channel` varchar(128) NOT NULL COMMENT '渠道',
  `pay_channel` varchar(32) NOT NULL COMMENT '支付渠道',
  `paid_fee` int(11) NOT NULL COMMENT '实际支付金额',
  `price` int(11) UNSIGNED NOT NULL COMMENT '订单价格',
  `products` text NOT NULL COMMENT '商品详情json数据',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '订单状态，1，正常，0，禁用，-1，已删除',
  `evaluate` tinyint(2) NOT NULL DEFAULT '0' COMMENT '评价状态',
  `author_id` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '内容拥有者ID',
  `discount_fee` int(11) NOT NULL DEFAULT '0' COMMENT '已优惠的金额，如优惠卷、限时折扣等，单位：分',
  `delivery_fee` int(11) NOT NULL DEFAULT '0' COMMENT '邮费金额，单位：分',
  `logistic` text NULL COMMENT '物流数据 json',
  `logistic_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发货时间',
  `address_id` int(11) NOT NULL DEFAULT '0' COMMENT '收货地址',
  `refund` tinyint(2) NOT NULL DEFAULT '0' COMMENT '收货退款状态',
  `refund_to` tinyint(4) NOT NULL DEFAULT '1' COMMENT '退款至 0：用户余额 1：原路退回',
  `refund_no` varchar(128) DEFAULT NULL COMMENT '退款单号',
  `refund_result` TEXT NULL COMMENT '退款成功平台返回数据',
  `remark` varchar(500) DEFAULT NULL COMMENT '备注',
  `receipt` varchar(255) DEFAULT NULL COMMENT '发票抬头',
  `ip` varchar(128) NOT NULL DEFAULT '' COMMENT 'ip地址',
  `metadata` text NULL COMMENT '元数据',
  `settle` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否结算分账',
  `form_id` varchar(255) NULL COMMENT 'formId 表单ID',
  `end_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '订单有效期结束时间戳',
  `agreed_time` varchar(128) NULL DEFAULT '' COMMENT '约定时间，如约定日期时间发货或服务',
  `agreed_start_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '约定开始时间戳',
  `agreed_end_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '约定结束时间戳',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

DROP TABLE IF EXISTS `muucmf_qrcode_login`;
CREATE TABLE IF NOT EXISTS `muucmf_qrcode_login` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `scene_key` varchar(100) NOT NULL COMMENT '场景值',
  `metadata` text COMMENT '元数据',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='扫码登录' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_score_log`
--
DROP TABLE IF EXISTS `muucmf_score_log`;
CREATE TABLE IF NOT EXISTS `muucmf_score_log` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `ip` varchar(32) NOT NULL COMMENT 'IP',
  `type` int(11) NOT NULL COMMENT '积分类型ID',
  `action` varchar(20) NOT NULL COMMENT '方向',
  `value` double NOT NULL DEFAULT '0' COMMENT '值',
  `finally_value` double NOT NULL COMMENT '最终结果值',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `model` varchar(20) NOT NULL COMMENT '触发模型（即将弃用）',
  `record_id` int(11) UNSIGNED NOT NULL COMMENT '行为ID',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='积分日志' AUTO_INCREMENT=100 ;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_score_type`
--
DROP TABLE IF EXISTS `muucmf_score_type`;
CREATE TABLE IF NOT EXISTS `muucmf_score_type` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title` varchar(50) NOT NULL COMMENT '名称',
  `status` tinyint(4) NOT NULL COMMENT '状态',
  `unit` varchar(20) NOT NULL COMMENT '单位',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=100 ;

--
-- 转存表中的数据 `muucmf_score_type`
--

INSERT INTO `muucmf_score_type` (`id`, `title`, `status`, `unit`) VALUES
(1, '积分', 1, '分'),
(2, '威望', 1, '点');


--
-- 表的结构 `muucmf_search`
--
DROP TABLE IF EXISTS `muucmf_search`;
CREATE TABLE IF NOT EXISTS  `muucmf_search` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL COMMENT '店铺ID',
  `app` varchar(64) NOT NULL COMMENT '应用标识',
  `info_id` BIGINT(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `info_type` varchar(64) NOT NULL COMMENT '数据类型',
  `content` text NOT NULL COMMENT '内容json',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='搜索索引数据';

--
-- 表的结构 `muucmf_seo_rule`
--
DROP TABLE IF EXISTS `muucmf_seo_rule`;
CREATE TABLE IF NOT EXISTS `muucmf_seo_rule` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title` varchar(128) NOT NULL COMMENT '标题',
  `app` varchar(128) NOT NULL COMMENT '应用标识',
  `controller` varchar(64) NOT NULL DEFAULT '' COMMENT '控制器',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT '方法',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `seo_keywords` text COMMENT '关键字',
  `seo_description` text COMMENT '描述',
  `seo_title` text COMMENT 'TITLE',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `summary` varchar(500) NOT NULL DEFAULT '' COMMENT 'seo变量介绍',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=10000 ;

--
-- 表的结构 `muucmf_share`
--

DROP TABLE IF EXISTS `muucmf_share`;
CREATE TABLE IF NOT EXISTS `muucmf_share` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` bigint(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '订单所属店铺ID',
  `app` varchar(60) NOT NULL DEFAULT '' COMMENT '关联应用的唯一标识',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户ID',
  `to_uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '至用户ID',
  `info_type` varchar(32) NOT NULL COMMENT '关联模型，如：classroom:知识内容，column：专栏',
  `info_id` bigint(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态，1，正常，0，禁用，-1，已删除',
  `metadata` mediumtext NOT NULL COMMENT '元数据',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户分享表' ROW_FORMAT=COMPACT;

--
-- 表的结构 `muucmf_support`
--

DROP TABLE IF EXISTS `muucmf_support`;
CREATE TABLE IF NOT EXISTS `muucmf_support` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(20) NOT NULL COMMENT '应用标识',
  `uid` int(11) UNSIGNED NOT NULL COMMENT '用户ID',
  `info_type` varchar(32) NOT NULL COMMENT '内容类型，关联不同模型',
  `info_id` bigint(20) UNSIGNED NOT NULL COMMENT '数据ID',
  `status` tinyint(4) NOT NULL COMMENT '订单状态，1，正常，0，禁用，-1，已删除',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞表' ROW_FORMAT=COMPACT;


--
-- 表的结构 `muucmf_tominiprogram`
--

DROP TABLE IF EXISTS `muucmf_tominiprogram`;
CREATE TABLE IF NOT EXISTS `muucmf_tominiprogram` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `appid` varchar(128) NOT NULL COMMENT 'appid',
  `title` varchar(255) NOT NULL COMMENT '小程序名称',
  `qrcode` varchar(500) NULL COMMENT '小程序二维码',
  `type` varchar(40) NULL COMMENT '小程序类型',
  `status` TINYINT(2) NOT NULL DEFAULT '1' COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='跳转小程序配置表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_user_nav`
--
DROP TABLE IF EXISTS `muucmf_user_nav`;
CREATE TABLE IF NOT EXISTS `muucmf_user_nav` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '频道ID',
  `type` varchar(32) NOT NULL,
  `app` varchar(64) NOT NULL,
  `title` char(30) NOT NULL COMMENT '频道标题',
  `url` char(100) NOT NULL COMMENT '频道连接',
  `sort` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '导航排序',
  `create_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  `status` tinyint(4) NOT NULL DEFAULT '0' COMMENT '状态',
  `target` tinyint(2) UNSIGNED NOT NULL DEFAULT '0' COMMENT '新窗口打开',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

--
-- 转存表中的数据 `muucmf_user_nav`
--

INSERT INTO `muucmf_user_nav` (`id`, `type`, `app`, `title`, `url`, `sort`, `create_time`, `update_time`, `status`, `target`) VALUES
(1, '_custom', 'ucenter', '用户设置', 'ucenter/config/index', 0, 0, 0, 1, 0);

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_user_token`
--
DROP TABLE IF EXISTS `muucmf_user_token`;
CREATE TABLE IF NOT EXISTS `muucmf_user_token` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int(11) UNSIGNED NOT NULL,
  `token` varchar(255) NOT NULL,
  `expire_time` int(11) UNSIGNED NOT NULL COMMENT '过期时间',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `uid` (`uid`),
  KEY `token` (`token`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=100;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_verify`
--
DROP TABLE IF EXISTS `muucmf_verify`;
CREATE TABLE IF NOT EXISTS `muucmf_verify` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uid` int(11) UNSIGNED NOT NULL,
  `account` varchar(255) NOT NULL,
  `type` varchar(20) NOT NULL,
  `verify` varchar(50) NOT NULL,
  `create_time` int(11) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_vip`
--

DROP TABLE IF EXISTS `muucmf_vip`;
CREATE TABLE IF NOT EXISTS `muucmf_vip` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(60) NOT NULL COMMENT '应用唯一标识',
  `uid` int(11) NOT NULL COMMENT '开通用户ID',
  `card_id` int(11) NOT NULL COMMENT '卡项ID',
  `card_no` varchar(128) NOT NULL DEFAULT '' COMMENT '会员卡号',
  `end_time` int(11) NOT NULL COMMENT '到期时间',
  `status` tinyint(2) NOT NULL COMMENT '会员状态',
  `order_no` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '开通时对应订单号',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='vip会员表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_vip_card`
--

DROP TABLE IF EXISTS `muucmf_vip_card`;
CREATE TABLE IF NOT EXISTS `muucmf_vip_card` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `app` varchar(255) NOT NULL COMMENT '应用唯一标识',
  `title` varchar(64) NOT NULL COMMENT 'VIP会员名',
  `description` varchar(255) NOT NULL COMMENT '简短描述',
  `cover` varchar(255) NOT NULL COMMENT '图标',
  `card_bg` varchar(255) NOT NULL DEFAULT '' COMMENT '会员卡背景',
  `category_ids` text NOT NULL COMMENT '支持的分类IDS',
  `discount` double NOT NULL COMMENT '折扣 0免费 1:1折',
  `month_price` int(11) UNSIGNED NOT NULL COMMENT '月份价格，单位:分',
  `quarter_price` int(11) UNSIGNED NOT NULL COMMENT '季度价格 单位：分',
  `year_price` int(11) UNSIGNED NOT NULL COMMENT '年费价格 单位：分',
  `year_two_price` INT(11) UNSIGNED NOT NULL COMMENT '两年价格 单位：分',
  `year_three_price` INT(11) UNSIGNED NOT NULL COMMENT '三年价格 单位：分',
  `year_five_price` INT(11) UNSIGNED NOT NULL COMMENT '五年价格 单位：分',
  `forever_price` int(11) UNSIGNED NOT NULL COMMENT '永久会员价格',
  `content` text NOT NULL COMMENT '会员权益描述',
  `config` text NOT NULL COMMENT '卡片配置，如背景色，文字色等',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  `sort` int(11) NOT NULL COMMENT '排序值',
  `status` tinyint(2) NOT NULL COMMENT '状态',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='vip会员类型表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_wechat_auto_reply`
--

DROP TABLE IF EXISTS `muucmf_wechat_auto_reply`;
CREATE TABLE IF NOT EXISTS `muucmf_wechat_auto_reply` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `keyword` varchar(100) NOT NULL COMMENT '关键字',
  `text` varchar(500) NOT NULL COMMENT '回复文本',
  `remark` varchar(255) NOT NULL COMMENT '备注',
  `sort` int(11) NOT NULL COMMENT '排序',
  `type` tinyint(4) NOT NULL COMMENT '0：关注回复 1：自动回复',
  `msg_type` varchar(20) NOT NULL COMMENT '消息类型 text文本 news 图文 image图片 voice音频 video视频',
  `material_json` text NOT NULL COMMENT '素材json',
  `media_id` varchar(100) NOT NULL COMMENT '媒体资源 ID',
  `status` tinyint(4) NOT NULL COMMENT '状态',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='微信自动回复';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_wechat_config`
--

DROP TABLE IF EXISTS `muucmf_wechat_config`;
CREATE TABLE IF NOT EXISTS `muucmf_wechat_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `title` varchar(24) NOT NULL COMMENT '应用标题',
  `desc` varchar(255) NOT NULL COMMENT '描述',
  `cover` varchar(500) NOT NULL COMMENT '应用封面',
  `qrcode` varchar(500) NOT NULL COMMENT '二维码',
  `appid` varchar(18) NOT NULL COMMENT '应用的独立标识',
  `secret` varchar(60) NOT NULL COMMENT '应用密匙',
  `encoding_aes_key` varchar(60) NOT NULL COMMENT '消息加解密密钥',
  `token` varchar(40) NOT NULL COMMENT 'token',
  `menu_json` text NULL COMMENT '公众号菜单配置',
  `tmplmsg` varchar(1000) NOT NULL DEFAULT '' COMMENT '模板消息配置',
  `auth_login` TINYINT(2) NOT NULL DEFAULT '1' COMMENT '是否启用网页授权登录',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公众号配置表';

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_wechat_mp_config`
--

DROP TABLE IF EXISTS `muucmf_wechat_mp_config`;
CREATE TABLE IF NOT EXISTS `muucmf_wechat_mp_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL COMMENT '店铺ID',
  `title` varchar(20) NOT NULL COMMENT '小程序名称',
  `description` varchar(500) NOT NULL COMMENT '描述',
  `appid` varchar(40) NOT NULL COMMENT '应用ID',
  `secret` varchar(60) NOT NULL COMMENT '应用密匙',
  `originalid` varchar(40) NOT NULL COMMENT '原始ID',
  `tmplmsg` varchar(500) NOT NULL DEFAULT '' COMMENT '模板消息',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建日期',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='小程序配置表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_wechat_work_config`
--
DROP TABLE IF EXISTS `muucmf_wechat_work_config`;
CREATE TABLE IF NOT EXISTS `muucmf_wechat_work_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) NOT NULL COMMENT '商户ID',
  `title` varchar(64) NOT NULL COMMENT '企业名称',
  `description` varchar(500) NOT NULL COMMENT '描述',
  `corp_id` varchar(128) NOT NULL COMMENT '企业ID',
  `agent_id` varchar(40) NOT NULL COMMENT '应用ID',
  `secret` varchar(60) NOT NULL COMMENT '应用密匙',
  `encoding_aes_key` varchar(64) NOT NULL DEFAULT '' COMMENT '消息加密',
  `token` varchar(64) NOT NULL DEFAULT '' COMMENT '验证token',
  `create_time` int(11) NOT NULL COMMENT '创建日期',
  `update_time` int(11) NOT NULL COMMENT '更新日期',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='企业微信配置表' ROW_FORMAT=COMPACT;

-- --------------------------------------------------------

--
-- 表的结构 `muucmf_withdraw`
--

DROP TABLE IF EXISTS `muucmf_withdraw`;
CREATE TABLE IF NOT EXISTS `muucmf_withdraw` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) NOT NULL COMMENT '用户ID',
  `order_no` varchar(64) NOT NULL COMMENT '订单号',
  `price` int(11) NOT NULL COMMENT '金额',
  `real_price` int(11) NOT NULL COMMENT '实际到账金额',
  `channel` varchar(64) NOT NULL COMMENT '渠道',
  `pay_channel` varchar(64) NOT NULL COMMENT '支付渠道',
  `info` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `paid` tinyint(2) NOT NULL COMMENT '状态0未提现 1已提现',
  `paid_time` int(11) UNSIGNED NOT NULL  DEFAULT '0' COMMENT '提现时间',
  `error` tinyint(2) NOT NULL COMMENT '提现失败 1失败 0未失败',
  `error_msg` text NULL COMMENT '错误消息',
  `create_time` int(11) UNSIGNED NOT NULL COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='提现表' ROW_FORMAT=COMPACT;