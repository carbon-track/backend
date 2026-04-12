CREATE TABLE `support_ticket_feedback` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `rated_user_id` int(11) NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_ticket_feedback_ticket_user_rated` (`ticket_id`,`user_id`,`rated_user_id`),
  KEY `idx_support_ticket_feedback_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_feedback_user_id` (`user_id`),
  KEY `idx_support_ticket_feedback_rated_user_id` (`rated_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
