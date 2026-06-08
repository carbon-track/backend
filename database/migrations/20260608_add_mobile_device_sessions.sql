-- Migration: 20260608 add mobile device sessions
-- Purpose: Persist revocable mobile refresh-token sessions with rotation/reuse detection.

CREATE TABLE IF NOT EXISTS `mobile_device_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `refresh_token_hash` CHAR(64) NOT NULL,
    `previous_refresh_token_hash` CHAR(64) DEFAULT NULL,
    `device_id` VARCHAR(191) DEFAULT NULL,
    `device_name` VARCHAR(191) DEFAULT NULL,
    `platform` VARCHAR(64) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `ip_address` VARCHAR(64) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `revoked_reason` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_mobile_device_sessions_refresh_hash` (`refresh_token_hash`),
    KEY `idx_mobile_device_sessions_user_id` (`user_id`),
    KEY `idx_mobile_device_sessions_device_id` (`device_id`),
    KEY `idx_mobile_device_sessions_previous_hash` (`previous_refresh_token_hash`),
    KEY `idx_mobile_device_sessions_active` (`user_id`, `revoked_at`, `expires_at`),
    CONSTRAINT `fk_mobile_device_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
