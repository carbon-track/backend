CREATE TABLE IF NOT EXISTS `admin_ai_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` varchar(64) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `last_message_preview` varchar(255) DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_activity_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_admin_ai_conversations_conversation_id` (`conversation_id`),
  KEY `idx_admin_ai_conversations_admin_id` (`admin_id`),
  KEY `idx_admin_ai_conversations_last_activity_at` (`last_activity_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_ai_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` varchar(64) NOT NULL,
  `kind` varchar(32) NOT NULL,
  `role` varchar(20) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'success',
  `content` longtext,
  `request_id` varchar(64) DEFAULT NULL,
  `response_code` int(11) DEFAULT NULL,
  `meta_json` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_ai_messages_conversation_id` (`conversation_id`),
  KEY `idx_admin_ai_messages_kind` (`kind`),
  KEY `idx_admin_ai_messages_status` (`status`),
  KEY `idx_admin_ai_messages_action` (`action`),
  KEY `idx_admin_ai_messages_created_at` (`created_at`),
  KEY `idx_admin_ai_messages_conversation_kind_created` (`conversation_id`,`kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
