-- Migration: create system_logs table
-- Apply in MySQL (or MariaDB) environment. For SQLite dev, adjust AUTO_INCREMENT & indexes accordingly.
-- Run this after ensuring you have a backup.

CREATE TABLE IF NOT EXISTS `system_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `request_id` VARCHAR(32) DEFAULT NULL,
  `method` VARCHAR(10) DEFAULT NULL,
  `path` VARCHAR(255) DEFAULT NULL,
  `status_code` INT(11) DEFAULT NULL,
  `user_id` INT(11) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(512) DEFAULT NULL,
  `duration_ms` DECIMAL(10,2) DEFAULT NULL,
  `request_body` MEDIUMTEXT,
  `response_body` MEDIUMTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_logs_created_at` (`created_at`),
  KEY `idx_system_logs_status_code` (`status_code`),
  KEY `idx_system_logs_method` (`method`),
  KEY `idx_system_logs_user_id` (`user_id`),
  KEY `idx_system_logs_path` (`path`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
