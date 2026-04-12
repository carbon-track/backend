CREATE TABLE `cron_tasks` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` varchar(64) NOT NULL,
  `task_name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `interval_minutes` int(11) NOT NULL DEFAULT '5',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `next_run_at` datetime DEFAULT NULL,
  `last_started_at` datetime DEFAULT NULL,
  `last_finished_at` datetime DEFAULT NULL,
  `last_status` varchar(32) NOT NULL DEFAULT 'idle',
  `last_error` text DEFAULT NULL,
  `last_duration_ms` int(11) DEFAULT NULL,
  `consecutive_failures` int(11) NOT NULL DEFAULT '0',
  `lock_token` varchar(64) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `settings_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_cron_tasks_task_key` (`task_key`),
  KEY `idx_cron_tasks_enabled_next_run` (`enabled`,`next_run_at`),
  KEY `idx_cron_tasks_last_status` (`last_status`),
  KEY `idx_cron_tasks_locked_at` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `cron_runs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_key` varchar(64) NOT NULL,
  `trigger_source` varchar(32) NOT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `result_json` longtext DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cron_runs_task_key_created_at` (`task_key`,`created_at`),
  KEY `idx_cron_runs_status` (`status`),
  KEY `idx_cron_runs_trigger_source` (`trigger_source`),
  KEY `idx_cron_runs_request_id` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cron_tasks` (
  `task_key`,
  `task_name`,
  `description`,
  `interval_minutes`,
  `enabled`,
  `next_run_at`,
  `last_status`,
  `consecutive_failures`,
  `settings_json`
)
SELECT
  'support_sla_sweep',
  'Support SLA Sweep',
  'Inspect unresolved support tickets, update SLA status, and reroute escalated tickets.',
  1,
  1,
  CURRENT_TIMESTAMP,
  'idle',
  0,
  '{}'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `cron_tasks`
  WHERE `task_key` = 'support_sla_sweep'
);

INSERT INTO `cron_tasks` (
  `task_key`,
  `task_name`,
  `description`,
  `interval_minutes`,
  `enabled`,
  `next_run_at`,
  `last_status`,
  `consecutive_failures`,
  `settings_json`
)
SELECT
  'badge_auto_award',
  'Badge Auto Award',
  'Evaluate active users against badge auto-grant rules and award newly qualified badges.',
  5,
  1,
  CURRENT_TIMESTAMP,
  'idle',
  0,
  '{}'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `cron_tasks`
  WHERE `task_key` = 'badge_auto_award'
);

INSERT INTO `cron_tasks` (
  `task_key`,
  `task_name`,
  `description`,
  `interval_minutes`,
  `enabled`,
  `next_run_at`,
  `last_status`,
  `consecutive_failures`,
  `settings_json`
)
SELECT
  'leaderboard_refresh',
  'Leaderboard Refresh',
  'Refresh the main points leaderboard cache for global, regional, and school rankings.',
  10,
  1,
  CURRENT_TIMESTAMP,
  'idle',
  0,
  '{}'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `cron_tasks`
  WHERE `task_key` = 'leaderboard_refresh'
);

INSERT INTO `cron_tasks` (
  `task_key`,
  `task_name`,
  `description`,
  `interval_minutes`,
  `enabled`,
  `next_run_at`,
  `last_status`,
  `consecutive_failures`,
  `settings_json`
)
SELECT
  'streak_leaderboard_refresh',
  'Streak Leaderboard Refresh',
  'Refresh the streak leaderboard cache for current and longest check-in streak rankings.',
  10,
  1,
  CURRENT_TIMESTAMP,
  'idle',
  0,
  '{}'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `cron_tasks`
  WHERE `task_key` = 'streak_leaderboard_refresh'
);
