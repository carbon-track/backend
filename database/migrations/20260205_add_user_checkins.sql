-- Add user check-ins table and default makeup quotas

CREATE TABLE IF NOT EXISTS `user_checkins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `checkin_date` date NOT NULL,
  `source` enum('record','makeup','system') NOT NULL DEFAULT 'record',
  `record_id` char(36) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_checkin_date` (`user_id`, `checkin_date`),
  KEY `idx_user_checkins_user` (`user_id`),
  KEY `idx_user_checkins_date` (`checkin_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill check-ins from existing carbon record submissions (submission date)
INSERT IGNORE INTO `user_checkins` (`user_id`, `checkin_date`, `source`, `record_id`, `created_at`)
SELECT
  `user_id`,
  DATE(`created_at`) AS `checkin_date`,
  'record' AS `source`,
  MIN(`id`) AS `record_id`,
  MIN(`created_at`) AS `created_at`
FROM `carbon_records`
WHERE `deleted_at` IS NULL
GROUP BY `user_id`, DATE(`created_at`);

-- Add default makeup quota to user groups (safe string append if missing)
UPDATE `user_groups`
SET `config` = CASE
  WHEN `config` IS NULL OR `config` = '' THEN '{"llm":{"daily_limit":10,"rate_limit":60},"checkin_makeup":{"monthly_limit":2}}'
  WHEN `config` NOT LIKE '%checkin_makeup%' THEN CONCAT(TRIM(TRAILING '}' FROM `config`), ',"checkin_makeup":{"monthly_limit":2}}')
  ELSE `config`
END
WHERE `code` = 'free';

UPDATE `user_groups`
SET `config` = CASE
  WHEN `config` IS NULL OR `config` = '' THEN '{"llm":{"daily_limit":100,"rate_limit":60},"checkin_makeup":{"monthly_limit":5}}'
  WHEN `config` NOT LIKE '%checkin_makeup%' THEN CONCAT(TRIM(TRAILING '}' FROM `config`), ',"checkin_makeup":{"monthly_limit":5}}')
  ELSE `config`
END
WHERE `code` = 'premium';
