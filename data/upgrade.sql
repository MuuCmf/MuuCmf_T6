
ALTER TABLE `muucmf_keywords` ADD `ip` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'ip' AFTER `recommend`;
UPDATE `muucmf_menu` SET `url` = 'admin/Action/list' WHERE `muucmf_menu`.`id` = '4B50BE30-7560-B98B-EA20-7EDBD8E18806';
UPDATE `muucmf_action` SET `rule` = '[{\"table\":\"member\",\"field\":\"1\",\"rule\":\"10\",\"cycle\":\"24\",\"max\":\"1\"}]' WHERE `muucmf_action`.`id` = 3;

UPDATE `muucmf_extend_config` SET `type` = 'select', `value` = 'v3',  `extra` = 'v2:企业付款到零钱v2\r\nv3:商家转账到零钱v3' WHERE `muucmf_extend_config`.`id` = 50;

DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'D8BE4559-1E66-2C78-E87B-A707E8C1CF7E';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'D225B61F-B50D-97F4-B331-7259015713FC';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'B2B58A3F-7DE2-FFC1-C8CC-92667421754E';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'B0798577-F1CB-669D-6DE8-BD04145FFDE9';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'A996C9AD-FF89-E8C9-55FB-2F682AC473EA';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '522792D6-A201-8A8A-ADA2-BCFC57263904';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '4B50BE30-7560-B98B-EA20-7EDBD8E18806';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '3AEDD7F6-0783-715A-9DBB-8C1E8758D616';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '113D646E-6D67-CF09-8C2C-4B10D57A6902';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '0F3D6CB1-0C7E-4292-CF19-6E32FC9D2F8D';
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = '05929499-FF7F-7615-C021-EDECADD02115';

UPDATE `muucmf_menu` SET `pid` = '8F5C83E0-3753-C731-4EEF-5D004137B11D', `sort` = '50' WHERE `muucmf_menu`.`id` = 'AABA580B-E97E-69C3-BA9B-34694408DDBA';
UPDATE `muucmf_menu` SET `pid` = '8F5C83E0-3753-C731-4EEF-5D004137B11D', `sort` = '51' WHERE `muucmf_menu`.`id` = 'A53BEFBB-17F7-56CD-ADF9-3D6754061E70';

ALTER TABLE `muucmf_member` DROP COLUMN `authentication`;
DELETE FROM muucmf_menu WHERE `muucmf_menu`.`id` = 'C51CEC8C-4E4C-B606-F397-3C0D3ED8C0E0';

UPDATE `muucmf_menu` SET `url` = 'admin/menu/list' WHERE `muucmf_menu`.`id` = '6E7257F5-44DA-009D-548F-47B895DDC1CB';
UPDATE `muucmf_menu` SET `url` = 'admin/Module/list' WHERE `muucmf_menu`.`id` = '7BE5FA0B-7009-AB46-FE7B-A9364ACAF687';
UPDATE `muucmf_menu` SET `url` = 'admin/Module/list' WHERE `muucmf_menu`.`id` = '20216DCF-1138-09A3-346A-C92E08E33677';
UPDATE `muucmf_menu` SET `url` = 'admin/member/list' WHERE `muucmf_menu`.`id` = 'DA4333DF-D814-819B-D657-401FE5153AB4';
UPDATE `muucmf_menu` SET `url` = 'admin/member/list' WHERE `muucmf_menu`.`id` = 'D18841ED-C034-2E7A-D0B2-92D0AC647179';

UPDATE `muucmf_extend_config` SET `sort` = '5' WHERE `muucmf_extend_config`.`id` = 51;

INSERT INTO `muucmf_extend_config` (`id`, `name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
(59, 'WX_PAY_PLATFORM_MODE', 'radio', '支付验证模式', 'weixinpay', 'cert:平台证书\npublic_key:微信支付公钥', '商户接收APIv3的请求应答、回调时验签使用，以下两种方式只能使用一种', 1779748605, 1779749269, 1, 'cert', 0),
(60, 'WX_PAY_PLATFORM_PUBLIC_KEY', 'file', '微信支付公钥', 'weixinpay', '', '微信支付公钥用于验证微信支付身份', 1779748780, 1779749421, 1, '', 8),
(61, 'WX_PAY_PLATFORM_PUBLIC_KEY_SERIAL', 'string', '微信支付公钥ID', 'weixinpay', '', '微信支付公钥模式下需填写', 1779749202, 1779749911, 1, '', 10);

UPDATE `muucmf_extend_config` SET `value` = 'public:公共配置项\r\naliyun_oss:阿里云OSS\r\ntencent_cos:腾讯云COS\r\naliyun_sms:阿里云短信\r\ntencent_sms:腾讯云短信\r\nweixinpay:微信支付\r\nalipay:支付宝支付\r\ntencent_vod:腾讯云点播\r\nstore:存储配置\r\nsms:短信配置\r\nvod:点播配置\r\npay:支付配置\r\nwithdraw:提现配置\r\nmuuagent:MuuAgent' WHERE `muucmf_extend_config`.`id` = 1;

INSERT INTO `muucmf_extend_config` (`id`, `name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
(62, 'MUUAGENT_BASE_URL', 'string', '接口路径', 'muuagent', '', '接口路径', 1780744852, 1780744852, 1, '', 0),
(63, 'MUUAGENT_API_KEY', 'string', 'ApiKey', 'muuagent', '', '请输入应用ApiKey', 1780744943, 1780744943, 1, '', 0),
(64, 'MUUAGENT_APP_CODE', 'string', '应用标识', 'muuagent', '', '请输入应用唯一标识', 1780744974, 1780744974, 1, '', 0),
(65, 'MUUAGENT_CLIENT_ID', 'string', '客户端ID', 'muuagent', '', '请输入客户端ID', 1780745009, 1780745009, 1, '', 0),
(66, 'MUUAGENT_CLIENT_SECRET', 'string', '客户端secret', 'muuagent', '', '请输入客户端secret', 1780745053, 1780745053, 1, '', 0);

UPDATE `muucmf_extend_config` SET `remark` = '平台证书模式下需填写' WHERE `muucmf_extend_config`.`id` = 51;

INSERT INTO `muucmf_menu` (`id`, `title`, `pid`, `sort`, `url`, `hide`, `type`, `tip`, `group`, `is_dev`, `icon`, `module`) VALUES
('30DC271B-AFF8-467F-5A1D-B54B7D0C52A3', 'MuuAgent', '167253B8-B360-E5C8-3F94-F0502E971DAF', 10, 'admin/extend/muuagent', 0, 0, 'MuuAgent配置项', '第三方扩展', 0, 'microchip', 'admin');

ALTER TABLE `muucmf_articles_category` ADD `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '简短描述' AFTER `title`;

INSERT INTO `muucmf_config` (`id`, `name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
(10157, 'WEB_SITE_URL', 'string', '站点域名', 1, '', '用于需要绝对地址的场景，需含 http:// 或 https://，如 https://www.example.com（定时任务下无请求域名时使用）', 1759104000, 1759104000, 1, '', 0);

INSERT INTO `muucmf_menu` (`id`, `title`, `pid`, `sort`, `url`, `hide`, `type`, `tip`, `group`, `is_dev`, `icon`, `module`) VALUES
('E1F2A3B4-C5D6-4E7F-8A9B-0C1D2E3F4A5B', 'Sitemap生成', 'A4650B98-DAD4-8194-030C-1B2AB4F35CBA', 64, 'admin/sitemap/index', 0, 0, '', 'PC管理', 0, 'sitemap', 'admin'),
('F2A3B4C5-D6E7-4F8A-9B0C-1D2E3F4A5B6C', '手动生成Sitemap', 'E1F2A3B4-C5D6-4E7F-8A9B-0C1D2E3F4A5B', 0, 'admin/sitemap/generate', 1, 0, '', '', 0, '', 'admin');

INSERT INTO `muucmf_crontab` (`id`, `shopid`, `title`, `description`, `execute`, `cycle`, `day`, `hour`, `minute`, `status`, `create_time`, `update_time`) VALUES
(6, 0, 'Sitemap自动生成', '汇总框架核心页面与已安装应用页面生成 public/sitemap.xml，并同步 robots.txt 的 Sitemap 声明', 'app\\common\\crontab\\Sitemap', 'day', 0, 3, 30, 1, 1759104000, 1759104000);

-- 1. 提现管理分组更名为资金管理分组（分组由 group 字段决定）
UPDATE `muucmf_menu`
SET `group` = '资金管理'
WHERE `url` = 'admin/withdraw/list'
  AND `module` = 'admin'
  AND `group` = '提现管理';

-- 2. 新增"资金流水管理"菜单（运营顶级菜单 8F5C83E0-3753-C731-4EEF-5D004137B11D 下）
INSERT INTO `muucmf_menu` (`id`, `title`, `pid`, `sort`, `url`, `hide`, `type`, `tip`, `group`, `is_dev`, `icon`, `module`) VALUES
('8FBEC7E3-0F92-4558-91D1-5B9071BE2015', '资金流水', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 13, 'admin/capitalflow/list', 0, 0, '', '资金管理', 0, 'exchange-alt', 'admin')
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `pid` = VALUES(`pid`),
  `sort` = VALUES(`sort`),
  `url` = VALUES(`url`),
  `group` = VALUES(`group`),
  `icon` = VALUES(`icon`),
  `module` = VALUES(`module`);

-- 3. 新增订单优惠券相关字段
ALTER TABLE `muucmf_orders` ADD `coupon_id` INT(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '优惠券模板ID' AFTER `discount_fee`;
ALTER TABLE `muucmf_orders` ADD `coupon_user_id` INT(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户优惠券记录ID' AFTER `coupon_id`;
ALTER TABLE `muucmf_orders` ADD `coupon_fee` INT(11) NOT NULL DEFAULT '0' COMMENT '优惠券优惠金额（分）' AFTER `coupon_user_id`;
ALTER TABLE `muucmf_orders` ADD `coupon_title` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '优惠券标题快照' AFTER `coupon_fee`;
ALTER TABLE `muucmf_orders` ADD `coupon_rule` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '优惠券规则快照' AFTER `coupon_title`;

-- 拼团：拼团团实例ID，0非拼团
ALTER TABLE `muucmf_orders` ADD `group_id` INT(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '拼团团实例ID，0非拼团' AFTER `coupon_rule`;
ALTER TABLE `muucmf_orders` ADD `groupbuy_id` INT(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '拼团活动ID，0非拼团' AFTER `group_id`;
ALTER TABLE `muucmf_orders` ADD `group_fee` INT(11) NOT NULL DEFAULT '0' COMMENT '拼团优惠金额（分）' AFTER `groupbuy_id`;
ALTER TABLE `muucmf_orders` ADD `group_title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '拼团活动标题快照' AFTER `group_fee`;

-- ============================================================
-- 统一售后中心 P1：退款单、状态流转日志、订单退款汇总字段
-- 注意：执行后必须刷新数据库字段缓存（php think clear 或强制 getSchemaInfo），
--       否则 fields_cache 会过滤掉 orders 新增的 refund_fee/refund_status。
-- ============================================================
CREATE TABLE IF NOT EXISTS `muucmf_refund` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_no` varchar(64) NOT NULL DEFAULT '' COMMENT '业务退款单号（幂等键）',
  `order_no` varchar(128) NOT NULL DEFAULT '' COMMENT '订单号',
  `order_id` int(11) NOT NULL DEFAULT '0' COMMENT '订单ID',
  `app` varchar(50) NOT NULL DEFAULT '' COMMENT '应用标识',
  `shopid` int(11) NOT NULL DEFAULT '0' COMMENT '站点ID',
  `uid` int(11) NOT NULL DEFAULT '0' COMMENT '用户ID',
  `type` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1整单退 2部分退',
  `status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '0待审核 1已同意 2已拒绝 3打款中 4已完成 5已关闭 6退货中',
  `order_fee` int(11) NOT NULL DEFAULT '0' COMMENT '订单实付金额',
  `refund_fee` int(11) NOT NULL DEFAULT '0' COMMENT '本次退款金额',
  `refund_to` tinyint(2) NOT NULL DEFAULT '0' COMMENT '0退余额 1原路退回 2线下退款 3退积分',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '退款原因/备注',
  `source` tinyint(2) NOT NULL DEFAULT '2' COMMENT '1用户申请 2后台手动 3自动退款',
  `out_refund_no` varchar(64) DEFAULT NULL COMMENT '渠道退款单号（原路退款）',
  `gateway_result` text COMMENT '渠道返回结果JSON',
  `return_company` varchar(64) NOT NULL DEFAULT '' COMMENT '退货物流公司',
  `return_no` varchar(64) NOT NULL DEFAULT '' COMMENT '退货物流单号',
  `return_time` int(11) NOT NULL DEFAULT '0' COMMENT '退货物流填写时间',
  `complete_time` int(11) NOT NULL DEFAULT '0' COMMENT '完成时间',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refund_no` (`refund_no`),
  KEY `idx_order_no` (`order_no`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后退款单';

CREATE TABLE IF NOT EXISTS `muucmf_refund_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `refund_no` varchar(64) NOT NULL DEFAULT '' COMMENT '业务退款单号',
  `order_no` varchar(128) NOT NULL DEFAULT '' COMMENT '订单号',
  `from_status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '原状态',
  `to_status` tinyint(2) NOT NULL DEFAULT '0' COMMENT '新状态',
  `action` varchar(64) NOT NULL DEFAULT '' COMMENT '动作说明',
  `operator_type` tinyint(2) NOT NULL DEFAULT '0' COMMENT '0系统 1用户 2管理员',
  `operator_id` int(11) NOT NULL DEFAULT '0' COMMENT '操作人ID',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注/失败原因',
  `create_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_refund_no` (`refund_no`),
  KEY `idx_order_no` (`order_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='售后退款状态流转日志';

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_orders' AND COLUMN_NAME = 'refund_fee');
SET @sql := IF(@col = 0,
  'ALTER TABLE `muucmf_orders` ADD COLUMN `refund_fee` int(11) NOT NULL DEFAULT ''0'' COMMENT ''已退金额'' AFTER `refund_result`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_orders' AND COLUMN_NAME = 'refund_status');
SET @sql := IF(@col = 0,
  'ALTER TABLE `muucmf_orders` ADD COLUMN `refund_status` tinyint(2) NOT NULL DEFAULT ''0'' COMMENT ''退款状态 0未退 1部分退 2退款中 3已全退'' AFTER `refund_fee`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 统一售后中心：退货物流回填字段（用户端售后详情/退货使用）
-- ============================================================
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_refund' AND COLUMN_NAME = 'return_company');
SET @sql := IF(@col = 0,
  'ALTER TABLE `muucmf_refund` ADD COLUMN `return_company` varchar(64) NOT NULL DEFAULT '''' COMMENT ''退货物流公司'' AFTER `gateway_result`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_refund' AND COLUMN_NAME = 'return_no');
SET @sql := IF(@col = 0,
  'ALTER TABLE `muucmf_refund` ADD COLUMN `return_no` varchar(64) NOT NULL DEFAULT '''' COMMENT ''退货物流单号'' AFTER `return_company`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_refund' AND COLUMN_NAME = 'return_time');
SET @sql := IF(@col = 0,
  'ALTER TABLE `muucmf_refund` ADD COLUMN `return_time` int(11) NOT NULL DEFAULT ''0'' COMMENT ''退货物流填写时间'' AFTER `return_no`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- 统一售后中心 P4/P5：用户申请售后、超时自动关闭（默认 12 小时）、打款滞留对账（30 分钟）、
-- 退货超时关闭（7 天），以及遗留订单只读巡检（dry-run 对账，不自动补建、不触发打款）
-- 说明：巡检只覆盖 id 升序单批 200 单；全量对账用 php think refund:reconcile 按 --after-id 续跑
-- ============================================================
INSERT INTO `muucmf_crontab` (`id`, `shopid`, `title`, `description`, `execute`, `cycle`, `day`, `hour`, `minute`, `status`, `create_time`, `update_time`) VALUES
(7, 0, '售后退款超时关闭、滞留对账与遗留巡检', '申请售后超过 12 小时未审核自动关闭退款单；打款滞留超过 30 分钟回置为已同意待重试；退货超时 7 天未收到商品自动关闭；遗留订单只读巡检（dry-run 对账 orders.refund 与 muucmf_refund，不一致只告警，不自动补建、不触发打款，补建需人工确认后执行 php think refund:reconcile --apply）', 'app\\common\\crontab\\Refund', 'hour-n', 1, 1, 1, 1, 1793880000, 1793880000)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`),
  `description` = VALUES(`description`),
  `execute` = VALUES(`execute`),
  `cycle` = VALUES(`cycle`),
  `status` = VALUES(`status`);


-- ========================================================
-- A4 统一消息推送中心（v1.4 首版范围）
-- 说明：本段所有结构变更为守卫式执行，重复升级不会产生致命错误
-- ========================================================

-- ---------- 1. 队列失败任务表（failed.type=database 前置） ----------
CREATE TABLE IF NOT EXISTS `muucmf_failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `connection` varchar(255) NOT NULL DEFAULT '' COMMENT '队列连接',
  `queue` varchar(255) NOT NULL DEFAULT '' COMMENT '队列名',
  `payload` longtext NULL COMMENT '原始任务载荷',
  `exception` longtext NULL COMMENT '异常堆栈',
  `fail_time` datetime DEFAULT NULL COMMENT '失败时间',
  PRIMARY KEY (`id`),
  KEY `idx_queue_time` (`queue`,`fail_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='队列失败任务表';

-- ---------- 2. 消息中心 6 张新表 ----------
CREATE TABLE IF NOT EXISTS `muucmf_message_template` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID，0为平台模板',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '模板编码 app.scene',
  `app` varchar(32) NOT NULL DEFAULT 'common' COMMENT '归属应用',
  `scene` varchar(64) NOT NULL DEFAULT '' COMMENT '业务场景',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '模板名称',
  `description` varchar(255) NOT NULL DEFAULT '' COMMENT '模板说明',
  `level` varchar(16) NOT NULL DEFAULT 'marketing' COMMENT 'transaction事务 marketing营销 security安全',
  `variables` text NULL COMMENT '变量声明JSON',
  `default_vars` text NULL COMMENT '默认变量JSON',
  `fallback` varchar(255) NOT NULL DEFAULT 'inbox' COMMENT '兜底渠道链JSON，系统强制追加inbox',
  `always_inbox` tinyint(2) NOT NULL DEFAULT '0' COMMENT '1站内信必发（链首额外发送）',
  `frequency_rule` text NULL COMMENT '频控规则JSON对象 max_times/window_hours',
  `is_system` tinyint(2) NOT NULL DEFAULT '0' COMMENT '1系统预置',
  `version` int(11) UNSIGNED NOT NULL DEFAULT '1' COMMENT '模板版本',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `sort` smallint(5) UNSIGNED NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_code` (`shopid`,`code`),
  KEY `idx_shop_app_status` (`shopid`,`app`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息模板表';

CREATE TABLE IF NOT EXISTS `muucmf_message_template_channel` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `template_id` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '模板ID',
  `template_code` varchar(64) NOT NULL DEFAULT '' COMMENT '模板编码冗余',
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT 'inbox/email/sms（P1：wechat_oa/wechat_mp）',
  `title` varchar(255) NULL COMMENT '标题/主题；NULL=未配置',
  `content` text NULL COMMENT '正文内容，含{{变量}}；NULL=未配置',
  `extra` text NULL COMMENT '渠道扩展JSON：服务商模板ID/字段映射/跳转/签名等',
  `third_template_id` varchar(64) NOT NULL DEFAULT '' COMMENT '单服务商兼容模板ID',
  `var_map` text NULL COMMENT '渠道变量映射JSON',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1启用 0停用',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tpl_channel` (`template_id`,`channel`),
  KEY `idx_shop_code_channel` (`shopid`,`template_code`,`channel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息模板渠道内容表';

CREATE TABLE IF NOT EXISTS `muucmf_message_task` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `template_code` varchar(64) NOT NULL DEFAULT '' COMMENT '模板编码',
  `template_version` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '模板版本',
  `title` varchar(128) NOT NULL DEFAULT '' COMMENT '任务标题',
  `level` varchar(16) NOT NULL DEFAULT '' COMMENT '消息级别',
  `channels` varchar(128) NOT NULL DEFAULT '' COMMENT '渠道集合JSON',
  `target_type` varchar(16) NOT NULL DEFAULT 'uids' COMMENT 'uids/groups',
  `target_payload` mediumtext NULL COMMENT '目标定义JSON（可序列化，含批次游标）',
  `target_hash` char(32) NOT NULL DEFAULT '' COMMENT '目标摘要MD5',
  `user_total` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '目标用户数（去重）',
  `user_done` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '已处理用户数',
  `user_success` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '成功用户数',
  `user_failed` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '失败用户数',
  `user_blocked` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '策略拦截用户数',
  `record_total` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道记录总数',
  `record_success` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道记录成功数',
  `record_failed` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道记录失败数',
  `record_blocked` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道记录拦截数',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT '任务状态',
  `send_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '计划发送时间，0立即',
  `start_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '开始时间',
  `finish_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '结束时间',
  `operator_uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '操作人UID',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_shop_status` (`shopid`,`status`),
  KEY `idx_shop_code_time` (`shopid`,`template_code`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息发送任务表';

-- 存量库的 target_payload 仍是 TEXT（64KB），万级 uid 的批量任务会写入失败，升级为 MEDIUMTEXT
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_task' AND COLUMN_NAME = 'target_payload' AND DATA_TYPE <> 'mediumtext');
SET @sql := IF(@exist > 0, 'ALTER TABLE `muucmf_message_task` MODIFY COLUMN `target_payload` MEDIUMTEXT NULL COMMENT "目标定义JSON（可序列化，含批次游标）"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `muucmf_message_record` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `task_id` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '任务ID',
  `template_code` varchar(64) NOT NULL DEFAULT '' COMMENT '模板编码',
  `template_version` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '模板版本',
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT '渠道',
  `channel_index` tinyint(2) UNSIGNED NOT NULL DEFAULT '0' COMMENT '渠道链序号',
  `from_channel` varchar(16) NOT NULL DEFAULT '' COMMENT '由哪个渠道降级而来',
  `scheduled_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '顺延/定时发送时间',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '接收用户UID',
  `inbox_id` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '站内信ID',
  `biz_key` varchar(64) NOT NULL DEFAULT '' COMMENT '业务幂等键',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '渲染后标题',
  `content_digest` varchar(255) NOT NULL DEFAULT '' COMMENT '渲染内容摘要',
  `third_msg_id` varchar(128) NOT NULL DEFAULT '' COMMENT '第三方消息ID',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT '记录状态',
  `block_reason` varchar(32) NOT NULL DEFAULT '' COMMENT '拦截/顺延原因',
  `delay_to` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '顺延目标时间',
  `error_code` varchar(64) NOT NULL DEFAULT '' COMMENT '错误码',
  `error_msg` varchar(500) NOT NULL DEFAULT '' COMMENT '错误信息',
  `attempts` tinyint(2) UNSIGNED NOT NULL DEFAULT '0' COMMENT '尝试次数',
  `is_test` tinyint(2) NOT NULL DEFAULT '0' COMMENT '是否测试发送',
  `send_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '发送时间',
  `receipt_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '回执时间',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_code_uid_biz_channel` (`shopid`,`template_code`,`uid`,`biz_key`,`channel`),
  KEY `idx_shop_task` (`shopid`,`task_id`),
  KEY `idx_shop_uid_time` (`shopid`,`uid`,`create_time`),
  KEY `idx_shop_status_time` (`shopid`,`status`,`create_time`),
  KEY `idx_status_scheduled` (`status`,`scheduled_time`),
  KEY `idx_shop_channel_time` (`shopid`,`channel`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息发送记录表';

CREATE TABLE IF NOT EXISTS `muucmf_message_subscription` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户UID',
  `template_code` varchar(64) NOT NULL DEFAULT '' COMMENT '模板编码，*表示整店',
  `app` varchar(32) NOT NULL DEFAULT '' COMMENT '应用',
  `channel` varchar(16) NOT NULL DEFAULT '' COMMENT '渠道，*表示全部',
  `subscribed` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1订阅 0退订',
  `unsubscribe_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '退订时间',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_app_uid_code_channel` (`shopid`,`app`,`uid`,`template_code`,`channel`),
  KEY `idx_shop_uid` (`shopid`,`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户消息订阅偏好表';

CREATE TABLE IF NOT EXISTS `muucmf_message_suppression` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `shopid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '店铺ID',
  `uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '用户UID',
  `type` varchar(16) NOT NULL DEFAULT 'manual' COMMENT 'manual/refund/complaint',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '原因',
  `operator_uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '操作人',
  `status` tinyint(2) NOT NULL DEFAULT '1' COMMENT '1生效 0已解黑',
  `expire_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '失效时间，0永久',
  `release_uid` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '解黑人UID',
  `release_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '解黑时间',
  `create_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) UNSIGNED NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_shop_uid` (`shopid`,`uid`),
  KEY `idx_shop_status_expire` (`shopid`,`status`,`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息黑名单表';

-- ---------- 3. 老表改造：message_content 增加跳转/模板/变量 ----------
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_content' AND COLUMN_NAME = 'link');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_content` ADD COLUMN `link` VARCHAR(500) NOT NULL DEFAULT "" COMMENT "跳转链接"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_content' AND COLUMN_NAME = 'template_code');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_content` ADD COLUMN `template_code` VARCHAR(64) NOT NULL DEFAULT "" COMMENT "模板编码"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_content' AND COLUMN_NAME = 'variables');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_content` ADD COLUMN `variables` TEXT NULL COMMENT "渲染变量JSON"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 4. 老表改造：message_type 增加 code/sort 并回填 code ----------
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_type' AND COLUMN_NAME = 'code');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_type` ADD COLUMN `code` VARCHAR(32) NOT NULL DEFAULT "" COMMENT "类型编码"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_type' AND COLUMN_NAME = 'sort');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_type` ADD COLUMN `sort` SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0 COMMENT "排序"', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 回填 code：每个店铺每类只保留一条，其余用 type_{id} 保证唯一
UPDATE `muucmf_message_type` t SET t.`code` = 'system'
WHERE t.`code` = '' AND t.`title` LIKE '%系统%'
AND t.`id` = (SELECT MIN(x.`id`) FROM (SELECT `id`,`shopid`,`title` FROM `muucmf_message_type` WHERE `code` = '' AND `title` LIKE '%系统%') x WHERE x.`shopid` = t.`shopid`);

UPDATE `muucmf_message_type` t SET t.`code` = 'order'
WHERE t.`code` = '' AND (t.`title` LIKE '%订单%' OR t.`title` LIKE '%交易%')
AND t.`id` = (SELECT MIN(x.`id`) FROM (SELECT `id`,`shopid`,`title` FROM `muucmf_message_type` WHERE `code` = '' AND (`title` LIKE '%订单%' OR `title` LIKE '%交易%')) x WHERE x.`shopid` = t.`shopid`);

UPDATE `muucmf_message_type` t SET t.`code` = 'marketing'
WHERE t.`code` = '' AND (t.`title` LIKE '%营销%' OR t.`title` LIKE '%活动%')
AND t.`id` = (SELECT MIN(x.`id`) FROM (SELECT `id`,`shopid`,`title` FROM `muucmf_message_type` WHERE `code` = '' AND (`title` LIKE '%营销%' OR `title` LIKE '%活动%')) x WHERE x.`shopid` = t.`shopid`);

UPDATE `muucmf_message_type` SET `code` = CONCAT('type_', `id`) WHERE `code` = '';

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_type' AND INDEX_NAME = 'uk_shop_code');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_type` ADD UNIQUE KEY `uk_shop_code` (`shopid`,`code`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 4.1 初始数据：平台消息类型/模板/渠道（shopid=0，幂等） ----------
-- 店铺级数据仍由 MessageSeed 按 shopid 补齐；此处仅保证平台默认数据随升级落库
INSERT INTO `muucmf_message_type` (`shopid`, `title`, `description`, `icon`, `code`, `sort`, `status`, `create_time`, `update_time`)
SELECT 0, '系统通知', '系统公告与账号相关通知', '', 'system', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_type` WHERE `shopid` = 0 AND `code` = 'system') `t1`);

INSERT INTO `muucmf_message_type` (`shopid`, `title`, `description`, `icon`, `code`, `sort`, `status`, `create_time`, `update_time`)
SELECT 0, '订单通知', '订单、支付、退款等交易通知', '', 'order', 2, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_type` WHERE `shopid` = 0 AND `code` = 'order') `t2`);

INSERT INTO `muucmf_message_type` (`shopid`, `title`, `description`, `icon`, `code`, `sort`, `status`, `create_time`, `update_time`)
SELECT 0, '营销活动', '活动、优惠券与会员提醒', '', 'marketing', 3, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_type` WHERE `shopid` = 0 AND `code` = 'marketing') `t3`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'common.custom_message', 'common', 'custom_message', '自定义消息', '后台自由编辑标题与正文的通用消息，供旧入口复用', 'transaction', '["title","description","content"]', '[]', '["inbox"]', 1, NULL, 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'common.custom_message') `t4`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'common.verify_code', 'common', 'verify_code', '验证码', '登录、注册、改密、绑定手机号/邮箱验证码，安全级别，不参与免打扰与频控', 'security', '["code","site_name","expire_minutes"]', '{"expire_minutes":"15"}', '["sms"]', 0, NULL, 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'common.verify_code') `t5`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'order.pay_success', 'order', 'pay_success', '支付成功通知', '订单支付成功站内信', 'transaction', '["order_no","amount","product_name","paid_time"]', '[]', '["inbox"]', 1, NULL, 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'order.pay_success') `t6`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'order.pay_success_admin', 'order', 'pay_success_admin', '支付成功运营通知', '支付成功后通知店铺运营', 'transaction', '["order_no","amount","paid_time"]', '[]', '["inbox"]', 1, NULL, 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'order.pay_success_admin') `t7`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'order.refund_success', 'order', 'refund_success', '退款到账通知', '售后退款成功后通知用户', 'transaction', '["order_no","amount"]', '[]', '["inbox"]', 1, NULL, 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'order.refund_success') `t8`);

INSERT INTO `muucmf_message_template` (`shopid`, `code`, `app`, `scene`, `name`, `description`, `level`, `variables`, `default_vars`, `fallback`, `always_inbox`, `frequency_rule`, `is_system`, `version`, `status`, `sort`, `create_time`, `update_time`)
SELECT 0, 'svip.expire_remind', 'svip', 'expire_remind', '会员到期提醒', '会员到期前 7/3/1 天提醒', 'marketing', '["nickname","expire_time"]', '[]', '["inbox"]', 1, '{"max_times":2,"window_hours":168}', 1, 1, 1, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `muucmf_message_template` WHERE `shopid` = 0 AND `code` = 'svip.expire_remind') `t9`);

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'inbox', '{{title}}', '{{content}}', '{"type_code":"system"}', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'inbox') `c1` ON `c1`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'common.custom_message' AND `c1`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'email', '{{title}}', '{{content}}', '[]', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'email') `c2` ON `c2`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'common.custom_message' AND `c2`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'sms', '', '{{content}}', '[]', '', '{"content":"content"}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'sms') `c3` ON `c3`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'common.custom_message' AND `c3`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'sms', '', '验证码：{{code}}', '{"provider_template_id":{"aliyun":"","tencent":""},"sign_name":""}', '', '{"code":"code"}', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'sms') `c4` ON `c4`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'common.verify_code' AND `c4`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'email', '【{{site_name}}】验证码', '<p>{{site_name}} 验证码：<strong>{{code}}</strong></p><p>该验证码 {{expire_minutes}} 分钟内有效，请勿泄露给他人。如非本人操作，请忽略本邮件。</p>', '[]', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'email') `c5` ON `c5`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'common.verify_code' AND `c5`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'inbox', '支付成功', '<p>您的订单 {{order_no}} 已支付成功。</p><p>商品：{{product_name}}</p><p>金额：{{amount}} 元</p><p>支付时间：{{paid_time}}</p>', '{"type_code":"order","description":"订单 {{order_no}} 支付成功"}', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'inbox') `c6` ON `c6`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'order.pay_success' AND `c6`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'inbox', '有新的支付订单', '<p>订单 {{order_no}} 支付成功，金额 {{amount}} 元，时间 {{paid_time}}。</p>', '{"type_code":"order"}', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'inbox') `c7` ON `c7`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'order.pay_success_admin' AND `c7`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'inbox', '退款到账', '<p>订单 {{order_no}} 的退款 {{amount}} 元已到账，请注意查收。</p>', '{"type_code":"order"}', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'inbox') `c8` ON `c8`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'order.refund_success' AND `c8`.`template_id` IS NULL;

INSERT INTO `muucmf_message_template_channel` (`shopid`, `template_id`, `template_code`, `channel`, `title`, `content`, `extra`, `third_template_id`, `var_map`, `status`, `create_time`, `update_time`)
SELECT t.`shopid`, t.`id`, t.`code`, 'inbox', '会员即将到期', '<p>{{nickname}}，您的会员将于 {{expire_time}} 到期，续费可继续享受会员权益。</p>', '{"type_code":"marketing"}', '', NULL, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM `muucmf_message_template` t
LEFT JOIN (SELECT `template_id` FROM `muucmf_message_template_channel` WHERE `channel` = 'inbox') `c9` ON `c9`.`template_id` = t.`id`
WHERE t.`shopid` = 0 AND t.`code` = 'svip.expire_remind' AND `c9`.`template_id` IS NULL;

-- ---------- 4.2 模板默认接收人：targets 字段与存量回填 ----------
SET @exist := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message_template' AND COLUMN_NAME = 'targets');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message_template` ADD COLUMN `targets` TEXT NULL COMMENT "模板默认接收人JSON" AFTER `frequency_rule`', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 存量店铺的运营通知模板原先由业务代码硬编码发给 uid=1，升级时按原口径回填，避免通知静默丢失
UPDATE `muucmf_message_template`
SET `targets` = '{"type":"uids","value":[1]}', `update_time` = UNIX_TIMESTAMP()
WHERE `code` = 'order.pay_success_admin' AND (`targets` IS NULL OR `targets` = '');

-- ---------- 5. 老表改造：message 索引 ----------
SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message' AND INDEX_NAME = 'idx_shop_to_uid');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message` ADD KEY `idx_shop_to_uid` (`shopid`,`to_uid`,`status`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message' AND INDEX_NAME = 'idx_shop_type');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message` ADD KEY `idx_shop_type` (`shopid`,`type_id`,`status`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message' AND INDEX_NAME = 'idx_shop_uid_read');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message` ADD KEY `idx_shop_uid_read` (`shopid`,`to_uid`,`is_read`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'muucmf_message' AND INDEX_NAME = 'idx_content_id');
SET @sql := IF(@exist = 0, 'ALTER TABLE `muucmf_message` ADD KEY `idx_content_id` (`content_id`)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------- 6. 扩展配置项（幂等） ----------
INSERT IGNORE INTO `muucmf_extend_config` (`name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
('MESSAGE_QUIET_ENABLED', 'radio', '免打扰开关', 'message', '0:关闭\r\n1:开启', '开启后营销消息在免打扰时段顺延发送', 0, 0, 1, '0', 20),
('MESSAGE_QUIET_START', 'string', '免打扰开始时间', 'message', '', '格式 HH:MM，支持跨天（如 21:00）', 0, 0, 1, '21:00', 21),
('MESSAGE_QUIET_END', 'string', '免打扰结束时间', 'message', '', '格式 HH:MM，起止相同视为关闭', 0, 0, 1, '09:00', 22),
('MESSAGE_BATCH_SIZE', 'num', '消息批次大小', 'message', '', '单批处理用户数，默认 500', 0, 0, 1, '500', 23),
('MESSAGE_RETRY_MAX', 'num', '消息重试次数', 'message', '', '渠道发送失败后的最大重试次数，默认 3', 0, 0, 1, '3', 24);

-- ---------- 7. 菜单（DELETE + INSERT，保证多次执行一致） ----------
DELETE FROM `muucmf_menu` WHERE `id` IN (
 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023','B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134',
 'C6E3F405-1627-4C38-8D49-E5FA0B1C3245','F9162738-495A-4F6B-9A7C-182D3E4F6578',
 '0A273849-5A6B-4A7C-8B8D-293E4F5A7689','1B38495A-6B7C-4B8D-9C9E-3A4F5A6B879A',
 '2C495A6B-7C8D-4C9E-8DAF-4B5A6B7C98AB','3D5A6B7C-8D9E-4DAF-9EB0-5C6B7C8D9ABC',
 '4E6B7C8D-9EAF-4EB0-8FC1-6D7C8D9EABCD','FA273849-5A6B-4A7C-8B8D-293E4F5A7690',
 '5F7C8D9E-AFB0-4FC1-9AD2-7E8D9EAFBCDE','6A8D9EAF-B0C1-4AD2-8BE3-8F9EAFB0CDEF',
 '7B9EAFB0-C1D2-4BE3-9CF4-9A0FB0C1DEFA','8CAFB0C1-D2E3-4CF4-8A05-0B10C1D2EFAB',
 '0B38495A-6B7C-4B8D-9C9E-3A4F5A6B879B','AEB1C2D3-F405-4E16-8C27-2D32E3F4ABCD',
 'BFC2D3E4-0516-4F27-9D38-3E43F4A5BCDE','D2E3F4A5-2738-4C49-AF6B-7A1C2D3E4F50',
 'C1D2E3F4-1627-4B38-9E5A-6F0B1C2D3E4F','E3F4A5B6-3849-4D5A-B07C-8B2D3E4F5061',
 '9DAFB0C1-D2E3-4CF4-8A05-0B10C1D2EFAC'
);

INSERT INTO `muucmf_menu` (`id`, `title`, `pid`, `sort`, `url`, `hide`, `type`, `tip`, `group`, `is_dev`, `icon`, `module`) VALUES
('A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', '消息模板', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 20, 'admin/messagecenter/template', 0, 0, '', '消息中心', 0, 'commenting', 'admin'),
('B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134', '发送任务', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 21, 'admin/messagecenter/task', 0, 0, '', '消息中心', 0, 'send', 'admin'),
('C6E3F405-1627-4C38-8D49-E5FA0B1C3245', '发送记录', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 22, 'admin/messagecenter/record', 0, 0, '', '消息中心', 0, 'list-alt', 'admin'),
('F9162738-495A-4F6B-9A7C-182D3E4F6578', '触达设置', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 23, 'admin/messagecenter/setting', 0, 0, '', '消息中心', 0, 'sliders', 'admin'),
('0A273849-5A6B-4A7C-8B8D-293E4F5A7689', '模板详情', 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', 0, 'admin/messagecenter/templatedetail', 1, 0, '', '消息中心', 0, '', 'admin'),
('1B38495A-6B7C-4B8D-9C9E-3A4F5A6B879A', '模板保存', 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', 1, 'admin/messagecenter/templatesave', 1, 0, '', '消息中心', 0, '', 'admin'),
('2C495A6B-7C8D-4C9E-8DAF-4B5A6B7C98AB', '模板启停', 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', 2, 'admin/messagecenter/templatestatus', 1, 0, '', '消息中心', 0, '', 'admin'),
('3D5A6B7C-8D9E-4DAF-9EB0-5C6B7C8D9ABC', '模板复制', 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', 3, 'admin/messagecenter/templatecopy', 1, 0, '', '消息中心', 0, '', 'admin'),
('4E6B7C8D-9EAF-4EB0-8FC1-6D7C8D9EABCD', '模板测试发送', 'A4C1D2E3-F405-4A16-8B27-C3D8E9FA1023', 4, 'admin/messagecenter/templatetest', 1, 0, '', '消息中心', 0, '', 'admin'),
('FA273849-5A6B-4A7C-8B8D-293E4F5A7690', '新建任务', 'B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134', 5, 'admin/messagecenter/taskcreate', 1, 0, '', '消息中心', 0, '', 'admin'),
('5F7C8D9E-AFB0-4FC1-9AD2-7E8D9EAFBCDE', '任务详情', 'B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134', 6, 'admin/messagecenter/taskdetail', 1, 0, '', '消息中心', 0, '', 'admin'),
('6A8D9EAF-B0C1-4AD2-8BE3-8F9EAFB0CDEF', '任务取消', 'B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134', 7, 'admin/messagecenter/taskcancel', 1, 0, '', '消息中心', 0, '', 'admin'),
('7B9EAFB0-C1D2-4BE3-9CF4-9A0FB0C1DEFA', '任务重试', 'B5D2E3F4-0516-4B27-9C38-D4E9FA0B2134', 8, 'admin/messagecenter/taskretry', 1, 0, '', '消息中心', 0, '', 'admin'),
('8CAFB0C1-D2E3-4CF4-8A05-0B10C1D2EFAB', '单条重试', 'C6E3F405-1627-4C38-8D49-E5FA0B1C3245', 9, 'admin/messagecenter/recordretry', 1, 0, '', '消息中心', 0, '', 'admin'),
('0B38495A-6B7C-4B8D-9C9E-3A4F5A6B879B', '记录导出', 'C6E3F405-1627-4C38-8D49-E5FA0B1C3245', 10, 'admin/messagecenter/recordexport', 1, 0, '', '消息中心', 0, '', 'admin'),
('AEB1C2D3-F405-4E16-8C27-2D32E3F4ABCD', '设置保存', 'F9162738-495A-4F6B-9A7C-182D3E4F6578', 11, 'admin/messagecenter/settingsave', 1, 0, '', '消息中心', 0, '', 'admin'),
('BFC2D3E4-0516-4F27-9D38-3E43F4A5BCDE', '黑名单管理', 'F9162738-495A-4F6B-9A7C-182D3E4F6578', 12, 'admin/messagecenter/blacklist', 1, 0, '', '消息中心', 0, '', 'admin'),
('C1D2E3F4-1627-4B38-9E5A-6F0B1C2D3E4F', '消息类型', '8F5C83E0-3753-C731-4EEF-5D004137B11D', 24, 'admin/messagecenter/type', 0, 0, '', '消息中心', 0, 'tags', 'admin'),
('D2E3F4A5-2738-4C49-AF6B-7A1C2D3E4F50', '类型保存', 'C1D2E3F4-1627-4B38-9E5A-6F0B1C2D3E4F', 0, 'admin/messagecenter/typesave', 1, 0, '', '消息中心', 0, '', 'admin'),
('E3F4A5B6-3849-4D5A-B07C-8B2D3E4F5061', '类型启停', 'C1D2E3F4-1627-4B38-9E5A-6F0B1C2D3E4F', 1, 'admin/messagecenter/typestatus', 1, 0, '', '消息中心', 0, '', 'admin'),
('9DAFB0C1-D2E3-4CF4-8A05-0B10C1D2EFAC', '记录删除', 'C6E3F405-1627-4C38-8D49-E5FA0B1C3245', 11, 'admin/messagecenter/recorddelete', 1, 0, '', '消息中心', 0, '', 'admin');


-- ---------- 9. 定时任务（幂等） ----------
INSERT IGNORE INTO `muucmf_crontab` (`id`, `shopid`, `title`, `description`, `execute`, `cycle`, `day`, `hour`, `minute`, `status`, `create_time`, `update_time`) VALUES
(8, 0, '消息重试与补偿', '重试失败记录、投递到期顺延记录、重置超时未推进的任务', 'app\\common\\crontab\\MessageRetry', 'minute-n', 0, 0, 5, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
(9, 0, '消息数据清理', '清理超过保留期的发送记录、失效黑名单与队列失败任务', 'app\\common\\crontab\\MessageClean', 'day', 0, 4, 0, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());

-- A4 消息中心：触达设置补充配置项（幂等）
INSERT IGNORE INTO `muucmf_extend_config` (`name`, `type`, `title`, `group`, `extra`, `remark`, `create_time`, `update_time`, `status`, `value`, `sort`) VALUES
('MESSAGE_CHANNEL_EMAIL', 'radio', '启用邮件渠道', 'message', '0:关闭\r\n1:开启', '关闭后邮件渠道不再进入发送链路', 0, 0, 1, '1', 25),
('MESSAGE_CHANNEL_SMS', 'radio', '启用短信渠道', 'message', '0:关闭\r\n1:开启', '关闭后短信渠道不再进入发送链路', 0, 0, 1, '1', 26),
('MESSAGE_DEFAULT_CHAIN', 'string', '默认兜底链', 'message', '', '逗号分隔，如 sms,inbox；仅影响未单独配置兜底链的模板', 0, 0, 1, 'sms', 27),
('MESSAGE_FREQ_MAX', 'num', '默认频控次数', 'message', '', '营销消息在同一时间窗口内最多发送次数', 0, 0, 1, '3', 28),
('MESSAGE_FREQ_WINDOW', 'num', '默认频控窗口(小时)', 'message', '', '营销消息频控统计窗口', 0, 0, 1, '24', 29),
('MESSAGE_RECORD_RETAIN_DAYS', 'num', '发送记录保留天数', 'message', '', '默认 180 天，与幂等有效期一致', 0, 0, 1, '180', 30),
('MESSAGE_CHANNEL_WECHAT_OA', 'radio', '启用公众号模板消息渠道', 'message', '0:关闭\r\n1:开启', '需配置公众号 AppID/Secret；开启前须下线历史模板消息入口避免双发', 0, 0, 1, '0', 31),
('MESSAGE_CHANNEL_WECHAT_MP', 'radio', '启用小程序订阅消息渠道', 'message', '0:关闭\r\n1:开启', '需配置小程序 AppID/Secret 且用户已授权订阅；默认关闭', 0, 0, 1, '0', 32);

-- A4 消息中心：扩展配置新增「消息推送」分组，并把消息中心相关配置项迁入（幂等）
UPDATE `muucmf_extend_config`
SET `value` = CONCAT(`value`, '\r\nmessage:消息推送')
WHERE `name` = 'GROUP_LIST' AND `value` NOT LIKE '%message:消息推送%';

UPDATE `muucmf_extend_config` SET `group` = 'message'
WHERE `name` IN (
 'MESSAGE_QUIET_ENABLED','MESSAGE_QUIET_START','MESSAGE_QUIET_END','MESSAGE_BATCH_SIZE','MESSAGE_RETRY_MAX',
 'MESSAGE_CHANNEL_EMAIL','MESSAGE_CHANNEL_SMS','MESSAGE_DEFAULT_CHAIN','MESSAGE_FREQ_MAX','MESSAGE_FREQ_WINDOW',
 'MESSAGE_RECORD_RETAIN_DAYS','MESSAGE_CHANNEL_WECHAT_OA','MESSAGE_CHANNEL_WECHAT_MP'
);

-- A4 阶段一：下线旧站内信入口（幂等，可回滚）
-- 仅隐藏后台菜单，旧页面、权限节点与历史数据全部保留，观察一个版本后再进入阶段二：
--   阶段二才删除 admin/Message/* 权限节点与后台群发控制器方法（届时再补 SQL）
-- 保留 admin/Message/send 权限节点，仍被「会员列表 → 发消息」组件调用（非超管缺少该节点会 401）
UPDATE `muucmf_menu` SET `hide` = 1
WHERE `module` = 'admin' AND `url` IN (
 'admin/Message/type','admin/Message/list','admin/Message/send','admin/Message/typeEdit',
 'admin/Message/typeStatus','admin/Message/contentEdit','admin/Message/messageStatus'
);

-- A4 短信模板 ID 下线（阶段 Cv1：软下线，幂等）
-- 代码已不再读取全局「短信模板」，模板 ID 只认「消息中心 → 消息模板 → 短信渠道」里的配置；
-- 置 status=0 使配置项从后台「短信配置」页与 config('extend.*') 中消失，如需回滚改回 1 即可。
UPDATE `muucmf_extend_config` SET `status` = 0
WHERE `name` IN ('SMS_ALIYUN_TEMPLATEID', 'SMS_TENCENT_TEMPLATEID');

--
-- 附件表（muucmf_attachment）列表查询索引
-- 场景：admin/attachment/list —— WHERE shopid = 0 [AND driver = ?] [AND type = ?]
--       ORDER BY create_time DESC LIMIT ?，原表仅有 attachment/mime 索引，
--       导致 shopid 过滤与 create_time 排序均需全表扫描 + filesort。
-- 说明：MySQL 8.0 之前不支持 ADD INDEX IF NOT EXISTS，重复执行会报
--       Duplicate key name，可忽略；建议按需在存量库各执行一次。
--
ALTER TABLE `muucmf_attachment` ADD INDEX `idx_shopid_create` (`shopid`, `create_time`);
ALTER TABLE `muucmf_attachment` ADD INDEX `idx_shopid_driver_create` (`shopid`, `driver`, `create_time`);
ALTER TABLE `muucmf_attachment` ADD INDEX `idx_shopid_type_create` (`shopid`, `type`, `create_time`);
ALTER TABLE `muucmf_attachment` ADD INDEX `idx_uid` (`uid`);
