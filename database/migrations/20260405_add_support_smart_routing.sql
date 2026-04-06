ALTER TABLE `support_ticket_automation_rules`
  ADD COLUMN `score_boost` decimal(10,2) NOT NULL DEFAULT '0.00' AFTER `assign_to`,
  ADD COLUMN `required_agent_level` tinyint(3) UNSIGNED DEFAULT NULL AFTER `score_boost`,
  ADD COLUMN `skill_hints_json` text DEFAULT NULL AFTER `required_agent_level`;

UPDATE `support_ticket_automation_rules`
SET `score_boost` = CASE
  WHEN `assign_to` IS NOT NULL THEN 20.00
  WHEN `add_tag_ids` IS NOT NULL AND `add_tag_ids` <> '' THEN 8.00
  ELSE 0.00
END
WHERE `score_boost` = 0.00;

ALTER TABLE `support_tickets`
  ADD COLUMN `assignment_locked` tinyint(1) NOT NULL DEFAULT '0' AFTER `assigned_rule_id`,
  ADD COLUMN `first_support_response_at` datetime DEFAULT NULL AFTER `assignment_locked`,
  ADD COLUMN `first_response_due_at` datetime DEFAULT NULL AFTER `first_support_response_at`,
  ADD COLUMN `resolution_due_at` datetime DEFAULT NULL AFTER `first_response_due_at`,
  ADD COLUMN `sla_status` varchar(32) NOT NULL DEFAULT 'pending' AFTER `resolution_due_at`,
  ADD COLUMN `escalation_level` int(11) NOT NULL DEFAULT '0' AFTER `sla_status`,
  ADD COLUMN `last_routing_run_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `escalation_level`;

CREATE TABLE `support_assignee_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `level` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `skills_json` text DEFAULT NULL,
  `languages_json` text DEFAULT NULL,
  `max_active_tickets` int(11) NOT NULL DEFAULT '10',
  `is_auto_assignable` tinyint(1) NOT NULL DEFAULT '1',
  `weight_overrides_json` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_assignee_profiles_user_id` (`user_id`),
  KEY `idx_support_assignee_profiles_status` (`status`),
  KEY `idx_support_assignee_profiles_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_routing_settings` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ai_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `ai_timeout_ms` int(11) NOT NULL DEFAULT '12000',
  `due_soon_minutes` int(11) NOT NULL DEFAULT '30',
  `weights_json` longtext DEFAULT NULL,
  `fallback_json` longtext DEFAULT NULL,
  `defaults_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_ticket_routing_runs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `trigger` varchar(32) NOT NULL DEFAULT 'created',
  `used_ai` tinyint(1) NOT NULL DEFAULT '0',
  `fallback_reason` varchar(255) DEFAULT NULL,
  `triage_json` longtext DEFAULT NULL,
  `matched_rule_ids_json` longtext DEFAULT NULL,
  `candidate_scores_json` longtext DEFAULT NULL,
  `winner_user_id` int(11) DEFAULT NULL,
  `winner_score` decimal(12,2) DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_ticket_routing_runs_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_routing_runs_trigger` (`trigger`),
  KEY `idx_support_ticket_routing_runs_winner_user_id` (`winner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `support_routing_settings` (`ai_enabled`, `ai_timeout_ms`, `due_soon_minutes`, `weights_json`, `fallback_json`, `defaults_json`)
VALUES (
  1,
  12000,
  30,
  JSON_OBJECT(
    'group_weight', 15,
    'priority_weight', 18,
    'severity_weight', 24,
    'escalation_weight', 10,
    'rule_weight', 20,
    'skill_weight', 16,
    'level_weight', 10,
    'feedback_weight', 8,
    'overdue_weight', 18,
    'load_penalty_weight', 22
  ),
  JSON_OBJECT(
    'use_priority_as_severity', true,
    'default_feedback_rating', 3.5
  ),
  JSON_OBJECT(
    'first_response_minutes', 240,
    'resolution_minutes', 1440,
    'routing_weight', 1,
    'min_agent_level', 1,
    'overdue_boost', 1,
    'tier_label', 'standard'
  )
);

INSERT INTO `support_assignee_profiles` (`user_id`, `level`, `skills_json`, `languages_json`, `max_active_tickets`, `is_auto_assignable`, `weight_overrides_json`, `status`)
SELECT
  `id`,
  CASE
    WHEN `is_admin` = 1 THEN 5
    WHEN `role` = 'admin' THEN 5
    WHEN `role` = 'support' THEN 2
    ELSE 1
  END,
  JSON_ARRAY(),
  JSON_ARRAY(),
  10,
  1,
  NULL,
  CASE
    WHEN `status` = 'active' THEN 'active'
    ELSE 'offline'
  END
FROM `users`
WHERE `deleted_at` IS NULL
  AND (`is_admin` = 1 OR `role` IN ('support', 'admin'));
