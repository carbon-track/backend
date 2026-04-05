CREATE TABLE `support_ticket_transfer_requests` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` bigint(20) UNSIGNED NOT NULL,
  `requested_by` int(11) NOT NULL,
  `from_assignee` int(11) DEFAULT NULL,
  `to_assignee` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `review_note` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_support_ticket_transfer_requests_ticket_id` (`ticket_id`),
  KEY `idx_support_ticket_transfer_requests_requested_by` (`requested_by`),
  KEY `idx_support_ticket_transfer_requests_to_assignee` (`to_assignee`),
  KEY `idx_support_ticket_transfer_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
