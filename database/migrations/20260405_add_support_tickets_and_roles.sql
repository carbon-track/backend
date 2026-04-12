ALTER TABLE `users`
  ADD COLUMN `role` enum('user','support','admin') NOT NULL DEFAULT 'user' AFTER `is_admin`;

ALTER TABLE `audit_logs`
  MODIFY COLUMN `actor_type` enum('user','support','admin','system') NOT NULL DEFAULT 'user';

UPDATE `users`
SET `role` = CASE
  WHEN `is_admin` = 1 THEN 'admin'
  ELSE 'user'
END
WHERE `role` IS NULL OR `role` = '';

CREATE TABLE `support_tickets` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `category` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `priority` varchar(32) NOT NULL DEFAULT 'normal',
  `assigned_to` int(11) DEFAULT NULL,
  `last_replied_at` datetime DEFAULT NULL,
  `last_reply_by_role` varchar(32) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_tickets_user_id` (`user_id`),
  KEY `idx_support_tickets_status` (`status`),
  KEY `idx_support_tickets_assigned_to` (`assigned_to`),
  KEY `idx_support_tickets_last_replied_at` (`last_replied_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_ticket_messages` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `sender_id` int(11) DEFAULT NULL,
  `sender_role` varchar(32) NOT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_ticket_messages_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_messages_sender_id` (`sender_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `support_ticket_attachments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `file_id` bigint(20) UNSIGNED DEFAULT NULL,
  `file_path` varchar(191) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(128) DEFAULT NULL,
  `size` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `entity_type` varchar(64) NOT NULL DEFAULT 'support_ticket_message',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_ticket_attachments_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_attachments_message_id` (`message_id`),
  KEY `idx_support_ticket_attachments_file_id` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
