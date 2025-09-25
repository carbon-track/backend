-- phpMyAdmin SQL Dump
-- version 5.0.2
-- https://www.phpmyadmin.net/
--
-- 主机： localhost:3306
-- 生成日期： 2025-09-16 09:14:07
-- 服务器版本： 5.6.51-log
-- PHP 版本： 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- 数据库： `dev_api_carbontr`
--

-- --------------------------------------------------------

--
-- 替换视图以便查看 `admin_operations`
-- （参见下面的实际视图）
--
CREATE TABLE `admin_operations` (
`id` int(11)
,`user_id` int(11)
,`actor_type` enum('user','admin','system')
,`action` varchar(100)
,`data` longtext
,`old_data` longtext
,`new_data` longtext
,`affected_table` varchar(100)
,`affected_id` int(11)
,`status` enum('success','failed','pending')
,`response_code` int(11)
,`session_id` varchar(255)
,`referrer` varchar(512)
,`operation_category` varchar(100)
,`operation_subtype` varchar(100)
,`change_type` enum('create','update','delete','read','other')
,`ip_address` varchar(45)
,`user_agent` varchar(512)
,`request_method` varchar(10)
,`endpoint` varchar(512)
,`created_at` datetime
,`admin_username` varchar(255)
,`admin_email` varchar(255)
);

-- --------------------------------------------------------

--
-- 表的结构 `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `actor_type` enum('user','admin','system') NOT NULL DEFAULT 'user',
  `action` varchar(100) NOT NULL COMMENT 'Specific action name (e.g., user_login, admin_user_update)',
  `data` longtext COMMENT 'Original request/response data as JSON',
  `old_data` longtext COMMENT 'Previous state data before operation as JSON',
  `new_data` longtext COMMENT 'New state data after operation as JSON',
  `affected_table` varchar(100) DEFAULT NULL COMMENT 'Database table affected by the operation',
  `affected_id` int(11) DEFAULT NULL COMMENT 'Primary key of affected record',
  `status` enum('success','failed','pending') NOT NULL DEFAULT 'success',
  `response_code` int(11) DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `referrer` varchar(512) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `operation_category` varchar(100) DEFAULT NULL COMMENT 'High-level category (e.g., authentication, user_management, carbon_calculation)',
  `operation_subtype` varchar(100) DEFAULT NULL COMMENT 'Specific subtype of operation',
  `change_type` enum('create','update','delete','read','other') DEFAULT 'other' COMMENT 'Type of data change performed',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `request_method` varchar(10) DEFAULT NULL,
  `endpoint` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `avatars`
--

CREATE TABLE `avatars` (
  `id` int(11) NOT NULL,
  `uuid` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `thumbnail_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'default',
  `sort_order` int(11) DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `is_default` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- --------------------------------------------------------

--
-- 结构 `achievement_badges`
--

CREATE TABLE `achievement_badges` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `code` varchar(100) DEFAULT NULL,
  `name_zh` varchar(150) NOT NULL,
  `name_en` varchar(150) NOT NULL,
  `description_zh` text DEFAULT NULL,
  `description_en` text DEFAULT NULL,
  `icon_path` varchar(255) NOT NULL,
  `icon_thumbnail_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `auto_grant_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `auto_grant_criteria` longtext DEFAULT NULL,
  `message_title_zh` varchar(255) DEFAULT NULL,
  `message_title_en` varchar(255) DEFAULT NULL,
  `message_body_zh` longtext DEFAULT NULL,
  `message_body_en` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_achievement_badges_uuid` (`uuid`),
  UNIQUE KEY `uniq_achievement_badges_code` (`code`),
  KEY `idx_achievement_badges_active` (`is_active`),
  KEY `idx_achievement_badges_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 结构 `user_badges`
--

CREATE TABLE `user_badges` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `badge_id` int(11) NOT NULL,
  `status` enum('awarded','revoked') NOT NULL DEFAULT 'awarded',
  `awarded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `awarded_by` int(11) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` int(11) DEFAULT NULL,
  `source` enum('manual','auto','trigger') NOT NULL DEFAULT 'manual',
  `notes` text DEFAULT NULL,
  `meta` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_badge` (`user_id`,`badge_id`),
  KEY `idx_user_badges_badge` (`badge_id`),
  KEY `idx_user_badges_status` (`status`),
  KEY `idx_user_badges_awarded_at` (`awarded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

-- --------------------------------------------------------

--
-- 表的结构 `carbon_activities`
--

CREATE TABLE `carbon_activities` (
  `id` char(36) NOT NULL,
  `name_zh` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `carbon_factor` decimal(10,4) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `description_zh` text,
  `description_en` text,
  `icon` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `carbon_activities`
--

INSERT INTO `carbon_activities` (`id`, `name_zh`, `name_en`, `category`, `carbon_factor`, `unit`, `description_zh`, `description_en`, `icon`, `is_active`, `sort_order`, `created_at`, `updated_at`, `deleted_at`) VALUES
('550e8400-e29b-41d4-a716-446655440001', '购物时自带袋子', 'Bring your own bag when shopping', 'daily', '0.0190', 'times', NULL, NULL, 'shopping-bag', 1, 1, '2025-08-16 14:11:05', '2025-08-16 14:11:05', NULL),
('550e8400-e29b-41d4-a716-446655440002', '早睡觉一小时', 'Sleep an hour earlier', 'daily', '0.0950', 'times', NULL, NULL, 'moon', 1, 2, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440003', '刷牙时关掉水龙头', 'Turn off the tap while brushing teeth', 'daily', '0.0090', 'times', NULL, NULL, 'water-drop', 1, 3, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440004', '出门自带水杯', 'Bring your own water bottle', 'daily', '0.0400', 'times', NULL, NULL, 'bottle', 1, 4, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440005', '垃圾分类', 'Sort waste properly', 'daily', '0.0004', 'times', NULL, NULL, 'recycle', 1, 5, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440006', '减少打印纸', 'Reduce unnecessary printing paper', 'daily', '0.0040', 'sheets', NULL, NULL, 'printer', 1, 6, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440007', '减少使用一次性餐盒', 'Reduce disposable meal boxes', 'daily', '0.1900', 'times', NULL, NULL, 'takeaway-box', 1, 7, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440008', '简易包装礼物', 'Use minimal gift wrapping', 'daily', '0.1400', 'times', NULL, NULL, 'gift', 1, 8, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440009', '夜跑', 'Night running', 'daily', '0.0950', 'times', NULL, NULL, 'running', 1, 9, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440010', '自然风干湿发', 'Air-dry wet hair', 'daily', '0.1520', 'times', NULL, NULL, 'hair-dryer', 1, 10, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440011', '点外卖选择\"无需餐具\"', 'Choose No-Cutlery when ordering delivery', 'daily', '0.0540', 'times', NULL, NULL, 'cutlery', 1, 11, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440012', '下班时关电脑和灯', 'Turn off computer and lights when off-duty', 'daily', '0.1660', 'times', NULL, NULL, 'power-off', 1, 12, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440013', '晚上睡觉全程关灯', 'Keep lights off at night', 'daily', '0.1100', 'times', NULL, NULL, 'light-bulb', 1, 13, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440014', '快速洗澡', 'Take a quick shower', 'daily', '0.1200', 'times', NULL, NULL, 'shower', 1, 14, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440015', '阳光晾晒衣服', 'Sun-dry clothes', 'daily', '0.3230', 'times', NULL, NULL, 'clothes-line', 1, 15, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440016', '夏天空调调至26°C以上', 'Set AC to above 78°F during Summer', 'daily', '0.2190', 'times', NULL, NULL, 'air-conditioner', 1, 16, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440017', '攒够一桶衣服再洗', 'Save and wash a full load of clothes', 'daily', '0.4730', 'times', NULL, NULL, 'washing-machine', 1, 17, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440018', '化妆品用完购买替代装', 'Buy refillable cosmetics or toiletries', 'consumption', '0.0850', 'times', NULL, NULL, 'cosmetics', 1, 18, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440019', '购买本地应季水果', 'Buy local seasonal fruits', 'consumption', '2.9800', 'kg', NULL, NULL, 'fruit', 1, 19, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440020', '自己做饭', 'Cook at home', 'consumption', '0.1900', 'times', NULL, NULL, 'cooking', 1, 20, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440021', '吃一顿轻食', 'Have a light meal', 'consumption', '0.3600', 'times', NULL, NULL, 'salad', 1, 21, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440022', '吃完水果蔬菜', 'Finish all fruits and vegetables', 'consumption', '0.0163', 'times', NULL, NULL, 'vegetables', 1, 22, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440023', '光盘行动', 'Finish all food on the plate', 'consumption', '0.0163', 'times', NULL, NULL, 'clean-plate', 1, 23, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440024', '喝燕麦奶或植物基食品', 'Drink oat milk or plant-based food', 'consumption', '0.6430', 'times', NULL, NULL, 'plant-milk', 1, 24, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440025', '公交地铁通勤', 'Use public transport', 'transport', '0.1005', 'km', NULL, NULL, 'bus', 1, 25, '2025-08-16 14:11:05', '2025-08-16 14:11:05', NULL),
('550e8400-e29b-41d4-a716-446655440026', '骑行探索城市', 'Explore the city by bike', 'transport', '0.1490', 'km', NULL, NULL, 'bicycle', 1, 26, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440027', '乘坐快轨去机场', 'Take high-speed rail to the airport', 'transport', '3.8700', 'times', NULL, NULL, 'train', 1, 27, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440028', '拼车', 'Carpool', 'transport', '0.0450', 'km', NULL, NULL, 'carpool', 1, 28, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440029', '自行车出行', 'Travel by bike', 'transport', '0.1490', 'km', NULL, NULL, 'bike-travel', 1, 29, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440030', '种一棵树', 'Plant a tree', 'environmental', '10.0000', 'trees', NULL, NULL, 'tree', 1, 30, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440031', '购买二手书', 'Buy a second-hand book', 'consumption', '2.8800', 'books', NULL, NULL, 'book', 1, 31, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440032', '旅行时自备洗漱用品', 'Bring your own toiletries when traveling', 'travel', '0.0470', 'times', NULL, NULL, 'toiletries', 1, 32, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440033', '旧物改造', 'Repurpose old items', 'consumption', '0.7700', 'items', NULL, NULL, 'recycle-item', 1, 33, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440034', '购买一级能效家电', 'Buy an energy-efficient appliance', 'consumption', '2.1500', 'appliances', NULL, NULL, 'energy-star', 1, 34, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440035', '购买白色或浅色衣物', 'Buy white or light-colored clothes', 'consumption', '3.4300', 'items', NULL, NULL, 'white-clothes', 1, 35, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440036', '花一天享受户外', 'Spend a full day outdoors', 'lifestyle', '0.7570', 'days', NULL, NULL, 'outdoor', 1, 36, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440037', '自己种菜并吃', 'Grow and eat your own vegetables', 'environmental', '0.0250', 'kg', NULL, NULL, 'garden', 1, 37, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440038', '减少使用手机时间', 'Reduce screen time', 'lifestyle', '0.0003', 'minutes', NULL, NULL, 'phone-time', 1, 38, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440039', '节约用电1度', 'Save 1 kWh electricity', 'energy', '1.0000', 'kWh', NULL, NULL, 'electricity', 1, 39, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440040', '节约用水1L', 'Save 1L water', 'water', '1.0000', 'liters', NULL, NULL, 'water-save', 1, 40, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL),
('550e8400-e29b-41d4-a716-446655440041', '垃圾分类1次', 'Sort waste once', 'waste', '145.0000', 'times', NULL, NULL, 'waste-sort', 1, 41, '2025-08-16 22:24:01', '2025-08-16 22:24:01', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `carbon_records`
--

CREATE TABLE `carbon_records` (
  `id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_id` char(36) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `carbon_saved` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `points_earned` int(11) NOT NULL DEFAULT '0',
  `date` date NOT NULL,
  `description` text,
  `images` longtext,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `error_logs`
--

CREATE TABLE `error_logs` (
  `id` int(11) NOT NULL,
  `error_type` varchar(50) DEFAULT NULL,
  `error_message` text,
  `error_file` varchar(255) DEFAULT NULL,
  `error_line` int(11) DEFAULT NULL,
  `error_time` datetime DEFAULT NULL,
  `script_name` varchar(255) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `client_get` text,
  `client_post` text,
  `client_files` text,
  `client_cookie` text,
  `client_session` text,
  `client_server` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `files`
--

CREATE TABLE `files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sha256` varchar(64) NOT NULL,
  `file_path` varchar(191) NOT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `original_name` varchar(255) DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_count` int(10) UNSIGNED NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- 表的结构 `idempotency_records`
--

CREATE TABLE `idempotency_records` (
  `id` int(11) NOT NULL,
  `idempotency_key` varchar(36) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `request_method` varchar(10) NOT NULL,
  `request_uri` varchar(512) NOT NULL,
  `request_body` longtext,
  `response_status` int(11) NOT NULL,
  `response_body` longtext NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- 表的结构 `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `receiver_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `content` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `points_transactions`
--

CREATE TABLE `points_transactions` (
  `username` text,
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `time` datetime NOT NULL,
  `img` varchar(512) DEFAULT NULL,
  `points` decimal(10,2) NOT NULL,
  `auth` varchar(50) DEFAULT NULL,
  `raw` decimal(10,2) NOT NULL,
  `act` varchar(255) DEFAULT NULL,
  `uid` int(11) NOT NULL,
  `activity_id` char(36) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `notes` text,
  `activity_date` date DEFAULT NULL,
  `status` enum('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `point_exchanges`
--

CREATE TABLE `point_exchanges` (
  `id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT '1',
  `points_used` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_price` int(11) NOT NULL,
  `delivery_address` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `notes` text,
  `status` enum('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  `tracking_number` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `products`
--

CREATE TABLE `products` (
  `name` text NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `category_slug` varchar(160) DEFAULT NULL,
  `id` int(11) NOT NULL,
  `points_required` int(10) NOT NULL,
  `description` text NOT NULL,
  `image_path` text NOT NULL,
  `images` longtext COMMENT 'JSON images',
  `stock` int(11) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `product_tags`
--

CREATE TABLE `product_tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(160) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_tags_slug` (`slug`),
  KEY `idx_product_tags_name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `product_tag_map`
--

CREATE TABLE `product_tag_map` (
  `product_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_product_tag` (`product_id`,`tag_id`),
  KEY `idx_product_tag_tag_id` (`tag_id`),
  KEY `idx_product_tag_product_id` (`product_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(160) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_product_categories_slug` (`slug`),
  KEY `idx_product_categories_name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 替换视图以便查看 `recent_audit_activities`
-- （参见下面的实际视图）
--
CREATE TABLE `recent_audit_activities` (
`id` int(11)
,`actor_type` enum('user','admin','system')
,`user_id` int(11)
,`action` varchar(100)
,`operation_category` varchar(100)
,`operation_subtype` varchar(100)
,`change_type` enum('create','update','delete','read','other')
,`status` enum('success','failed','pending')
,`ip_address` varchar(45)
,`created_at` datetime
);

-- --------------------------------------------------------

--
-- 表的结构 `schools`
--

CREATE TABLE `schools` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `school_classes`
--

CREATE TABLE `school_classes` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `spec_points_transactions`
--

CREATE TABLE `spec_points_transactions` (
  `username` text,
  `id` int(11) NOT NULL,
  `email` text NOT NULL,
  `time` text NOT NULL,
  `img` text NOT NULL,
  `points` double DEFAULT NULL,
  `auth` text,
  `raw` double NOT NULL,
  `act` text NOT NULL,
  `uid` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `request_id` varchar(32) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `duration_ms` decimal(10,2) DEFAULT NULL,
  `request_body` mediumtext,
  `response_body` mediumtext,
  `server_meta` mediumtext,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `points_spent` double NOT NULL,
  `transaction_time` text NOT NULL,
  `product_id` int(11) NOT NULL,
  `user_email` text NOT NULL,
  `school` text,
  `location` text
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 表的结构 `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `lastlgn` datetime DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `points` decimal(10,2) NOT NULL DEFAULT '0.00',
  `school` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `class_name` varchar(100) DEFAULT NULL,
  `school_id` int(11) DEFAULT NULL,
  `avatar_id` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 视图结构 `admin_operations`
--
DROP TABLE IF EXISTS `admin_operations`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `admin_operations`  AS  select `al`.`id` AS `id`,`al`.`user_id` AS `user_id`,`al`.`actor_type` AS `actor_type`,`al`.`action` AS `action`,`al`.`data` AS `data`,`al`.`old_data` AS `old_data`,`al`.`new_data` AS `new_data`,`al`.`affected_table` AS `affected_table`,`al`.`affected_id` AS `affected_id`,`al`.`status` AS `status`,`al`.`response_code` AS `response_code`,`al`.`session_id` AS `session_id`,`al`.`referrer` AS `referrer`,`al`.`operation_category` AS `operation_category`,`al`.`operation_subtype` AS `operation_subtype`,`al`.`change_type` AS `change_type`,`al`.`ip_address` AS `ip_address`,`al`.`user_agent` AS `user_agent`,`al`.`request_method` AS `request_method`,`al`.`endpoint` AS `endpoint`,`al`.`created_at` AS `created_at`,`u`.`username` AS `admin_username`,`u`.`email` AS `admin_email` from (`audit_logs` `al` left join `users` `u` on((`al`.`user_id` = `u`.`id`))) where (`al`.`actor_type` = 'admin') order by `al`.`created_at` desc ;

-- --------------------------------------------------------

--
-- 视图结构 `recent_audit_activities`
--
DROP TABLE IF EXISTS `recent_audit_activities`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `recent_audit_activities`  AS  select `al`.`id` AS `id`,`al`.`actor_type` AS `actor_type`,`al`.`user_id` AS `user_id`,`al`.`action` AS `action`,`al`.`operation_category` AS `operation_category`,`al`.`operation_subtype` AS `operation_subtype`,`al`.`change_type` AS `change_type`,`al`.`status` AS `status`,`al`.`ip_address` AS `ip_address`,`al`.`created_at` AS `created_at` from `audit_logs` `al` where (`al`.`created_at` >= (now() - interval 7 day)) order by `al`.`created_at` desc ;

--
-- 转储表的索引
--

--
-- 表的索引 `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_logs_user` (`user_id`),
  ADD KEY `idx_audit_logs_created_at` (`created_at`),
  ADD KEY `idx_audit_logs_actor_type` (`actor_type`),
  ADD KEY `idx_audit_logs_endpoint` (`endpoint`(191)),
  ADD KEY `idx_audit_logs_affected_table` (`affected_table`),
  ADD KEY `idx_audit_logs_status` (`status`),
  ADD KEY `idx_audit_logs_user_id_actor` (`user_id`,`actor_type`),
  ADD KEY `idx_audit_logs_operation_category` (`operation_category`),
  ADD KEY `idx_audit_logs_change_type` (`change_type`),
  ADD KEY `idx_audit_logs_request_id` (`request_id`);

--
-- 表的索引 `avatars`
--
ALTER TABLE `avatars`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uuid` (`uuid`);

--
-- 表的索引 `carbon_activities`
--
ALTER TABLE `carbon_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_carbon_activities_category` (`category`),
  ADD KEY `idx_carbon_activities_is_active` (`is_active`),
  ADD KEY `idx_carbon_activities_sort_order` (`sort_order`);

--
-- 表的索引 `carbon_records`
--
ALTER TABLE `carbon_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_carbon_records_user` (`user_id`),
  ADD KEY `idx_carbon_records_activity` (`activity_id`),
  ADD KEY `idx_carbon_records_status` (`status`),
  ADD KEY `idx_carbon_records_date` (`date`);

--
-- 表的索引 `error_logs`
--
ALTER TABLE `error_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_error_logs_request_id` (`request_id`);

--
-- 表的索引 `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `files_file_path_unique` (`file_path`),
  ADD UNIQUE KEY `uniq_files_sha256` (`sha256`),
  ADD KEY `files_sha256_index` (`sha256`);

--
-- 表的索引 `idempotency_records`
--
ALTER TABLE `idempotency_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_request_uri` (`request_uri`(191));

--
-- 表的索引 `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `points_transactions`
--
ALTER TABLE `points_transactions`
  ADD UNIQUE KEY `id_3` (`id`),
  ADD UNIQUE KEY `id_4` (`id`),
  ADD UNIQUE KEY `id_5` (`id`),
  ADD KEY `id` (`id`),
  ADD KEY `id_2` (`id`),
  ADD KEY `points` (`points`),
  ADD KEY `points_2` (`points`),
  ADD KEY `points_3` (`points`),
  ADD KEY `points_4` (`points`),
  ADD KEY `id_6` (`id`),
  ADD KEY `id_7` (`id`),
  ADD KEY `idx_activity_date` (`activity_date`),
  ADD KEY `idx_points_transactions_status` (`status`),
  ADD KEY `idx_points_transactions_approved_by` (`approved_by`),
  ADD KEY `idx_points_transactions_approved_at` (`approved_at`),
  ADD KEY `idx_points_transactions_deleted_at` (`deleted_at`),
  ADD KEY `idx_points_transactions_created_at` (`created_at`),
  ADD KEY `idx_points_transactions_type` (`type`),
  ADD KEY `idx_points_transactions_activity_id` (`activity_id`);

--
-- 表的索引 `point_exchanges`
--
ALTER TABLE `point_exchanges`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_point_exchanges_user_id` (`user_id`),
  ADD KEY `idx_point_exchanges_product_id` (`product_id`),
  ADD KEY `idx_point_exchanges_status` (`status`);

--
-- 表的索引 `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_products_status` (`status`),
  ADD KEY `idx_products_category` (`category`),
  ADD KEY `idx_products_sort_order` (`sort_order`);

--
-- 表的索引 `schools`
--
ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`);

--
-- 表的索引 `school_classes`
--
ALTER TABLE `school_classes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_school_class_name` (`school_id`,`name`),
  ADD KEY `idx_school_classes_school_id` (`school_id`),
  ADD KEY `idx_school_classes_name` (`name`),
  ADD KEY `idx_school_classes_deleted_at` (`deleted_at`);

--
-- 表的索引 `spec_points_transactions`
--
ALTER TABLE `spec_points_transactions`
  ADD UNIQUE KEY `id_3` (`id`),
  ADD UNIQUE KEY `id_4` (`id`),
  ADD UNIQUE KEY `id_5` (`id`),
  ADD KEY `id` (`id`),
  ADD KEY `id_2` (`id`),
  ADD KEY `points` (`points`),
  ADD KEY `points_2` (`points`),
  ADD KEY `points_3` (`points`),
  ADD KEY `points_4` (`points`),
  ADD KEY `id_6` (`id`),
  ADD KEY `id_7` (`id`);

--
-- 表的索引 `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_system_logs_created_at` (`created_at`),
  ADD KEY `idx_system_logs_status_code` (`status_code`),
  ADD KEY `idx_system_logs_method` (`method`),
  ADD KEY `idx_system_logs_user_id` (`user_id`),
  ADD KEY `idx_system_logs_path` (`path`(100));

--
-- 表的索引 `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id_2` (`id`),
  ADD UNIQUE KEY `id_4` (`id`),
  ADD KEY `id` (`id`),
  ADD KEY `id_3` (`id`);

--
-- 表的索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `id` (`id`),
  ADD UNIQUE KEY `idx_users_email_unique` (`email`),
  ADD KEY `idx_users_username` (`username`),
  ADD KEY `idx_users_deleted_at` (`deleted_at`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_is_admin` (`is_admin`),
  ADD KEY `idx_users_created_at` (`created_at`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `avatars`
--
ALTER TABLE `avatars`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `error_logs`
--
ALTER TABLE `error_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `files`
--
ALTER TABLE `files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `idempotency_records`
--
ALTER TABLE `idempotency_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `points_transactions`
--
ALTER TABLE `points_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `product_tags`
--
ALTER TABLE `product_tags`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `schools`
--
ALTER TABLE `schools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `school_classes`
--
ALTER TABLE `school_classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `spec_points_transactions`
--
ALTER TABLE `spec_points_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用表AUTO_INCREMENT `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
