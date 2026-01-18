-- Add user_groups table
CREATE TABLE `user_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `config` longtext COMMENT 'JSON config for quotas',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_groups_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add usage stats table for rate limiting and quotas
CREATE TABLE `user_usage_stats` (
  `user_id` int(11) NOT NULL,
  `resource_key` varchar(50) NOT NULL COMMENT 'e.g. llm_daily, llm_rate',
  `counter` decimal(10,4) NOT NULL DEFAULT '0.0000',
  `last_updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reset_at` datetime DEFAULT NULL COMMENT 'When the counter should reset (for daily/monthly quotas)',
  PRIMARY KEY (`user_id`, `resource_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Modify users table to link to groups and allow overrides
ALTER TABLE `users`
ADD COLUMN `group_id` int(11) DEFAULT NULL,
ADD COLUMN `quota_override` longtext COMMENT 'JSON overrides for quotas',
ADD COLUMN `admin_notes` text,
ADD CONSTRAINT `fk_users_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups` (`id`) ON DELETE SET NULL;

-- Create default groups
INSERT INTO `user_groups` (`name`, `code`, `config`, `is_default`, `notes`) VALUES
('Free', 'free', '{"llm": {"daily_limit": 10, "rate_limit": 60}}', 1, 'Default free tier'),
('Premium', 'premium', '{"llm": {"daily_limit": 100, "rate_limit": 60}}', 0, 'Premium tier');
