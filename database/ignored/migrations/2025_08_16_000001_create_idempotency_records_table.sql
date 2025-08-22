-- Create idempotency_records table for ensuring request idempotency
-- This table stores request/response pairs to prevent duplicate processing

CREATE TABLE `idempotency_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(36) NOT NULL COMMENT 'UUID provided by client',
  `user_id` int(11) NULL COMMENT 'User ID if authenticated',
  `request_method` varchar(10) NOT NULL COMMENT 'HTTP method (POST, PUT, etc.)',
  `request_uri` varchar(512) NOT NULL COMMENT 'Request URI path',
  `request_body` longtext NULL COMMENT 'JSON encoded request body',
  `response_status` int(11) NOT NULL COMMENT 'HTTP response status code',
  `response_body` longtext NOT NULL COMMENT 'JSON response body',
  `ip_address` varchar(45) NULL COMMENT 'Client IP address',
  `user_agent` varchar(512) NULL COMMENT 'Client User-Agent string',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_request_uri` (`request_uri`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stores request/response pairs for idempotency checking';

