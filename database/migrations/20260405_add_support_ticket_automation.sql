ALTER TABLE `support_tickets`
  ADD COLUMN `assignment_source` varchar(32) DEFAULT NULL AFTER `assigned_to`,
  ADD COLUMN `assigned_rule_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `assignment_source`;

CREATE TABLE `support_ticket_tags` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `color` varchar(32) NOT NULL DEFAULT 'emerald',
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_ticket_tags_slug` (`slug`),
  KEY `idx_support_ticket_tags_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_ticket_tag_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `tag_id` bigint(20) UNSIGNED NOT NULL,
  `source_type` varchar(32) NOT NULL DEFAULT 'rule',
  `rule_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_ticket_tag_assignments_ticket_tag` (`ticket_id`,`tag_id`),
  KEY `idx_support_ticket_tag_assignments_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_tag_assignments_tag_id` (`tag_id`),
  KEY `idx_support_ticket_tag_assignments_rule_id` (`rule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_ticket_automation_rules` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `match_category` varchar(64) DEFAULT NULL,
  `match_priority` varchar(32) DEFAULT NULL,
  `match_weekdays` text DEFAULT NULL,
  `match_time_start` char(5) DEFAULT NULL,
  `match_time_end` char(5) DEFAULT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Asia/Shanghai',
  `assign_to` int(11) DEFAULT NULL,
  `add_tag_ids` text DEFAULT NULL,
  `stop_processing` tinyint(1) NOT NULL DEFAULT '0',
  `trigger_count` int(11) NOT NULL DEFAULT '0',
  `last_triggered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_ticket_automation_rules_active` (`is_active`),
  KEY `idx_support_ticket_automation_rules_assign_to` (`assign_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
