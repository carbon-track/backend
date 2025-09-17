-- -----------------------------------------------------
-- Migration: create achievement badges and user badges
-- -----------------------------------------------------

CREATE TABLE IF NOT EXISTS `achievement_badges` (
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

CREATE TABLE IF NOT EXISTS `user_badges` (
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
