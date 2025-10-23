CREATE TABLE IF NOT EXISTS `message_broadcasts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `request_id` varchar(64) DEFAULT NULL,
    `audit_log_id` int(11) DEFAULT NULL,
    `system_log_id` int(11) DEFAULT NULL,
    `error_log_ids` longtext DEFAULT NULL,
    `title` varchar(255) NOT NULL,
    `content` longtext NOT NULL,
    `priority` varchar(20) NOT NULL DEFAULT 'normal',
    `scope` varchar(50) NOT NULL DEFAULT 'all',
    `target_count` int(11) NOT NULL DEFAULT 0,
    `sent_count` int(11) NOT NULL DEFAULT 0,
    `invalid_user_ids` longtext DEFAULT NULL,
    `failed_user_ids` longtext DEFAULT NULL,
    `message_ids_snapshot` longtext DEFAULT NULL,
    `message_map_snapshot` longtext DEFAULT NULL,
    `message_id_count` int(11) DEFAULT NULL,
    `content_hash` varchar(64) DEFAULT NULL,
    `email_delivery_snapshot` longtext DEFAULT NULL,
    `filters_snapshot` longtext DEFAULT NULL,
    `meta` longtext DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `message_broadcasts`
    ADD INDEX `idx_message_broadcasts_request_id` (`request_id`),
    ADD INDEX `idx_message_broadcasts_audit_log_id` (`audit_log_id`),
    ADD INDEX `idx_message_broadcasts_system_log_id` (`system_log_id`),
    ADD INDEX `idx_message_broadcasts_created_by` (`created_by`),
    ADD INDEX `idx_message_broadcasts_created_at` (`created_at`);
