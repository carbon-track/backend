CREATE TABLE IF NOT EXISTS `multipart_uploads` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `upload_id` varchar(191) NOT NULL,
  `file_path` varchar(191) NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_multipart_uploads_upload_id` (`upload_id`),
  KEY `idx_multipart_uploads_user_id` (`user_id`),
  KEY `idx_multipart_uploads_file_path` (`file_path`),
  KEY `idx_multipart_uploads_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC;
