-- Create point_exchanges table used by ProductController

CREATE TABLE IF NOT EXISTS `point_exchanges` (
  `id` char(36) NOT NULL COMMENT 'UUID primary key',
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `points_used` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_price` int(11) NOT NULL,
  `delivery_address` varchar(255) NULL,
  `contact_phone` varchar(50) NULL,
  `notes` text NULL,
  `status` enum('pending','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
  `tracking_number` varchar(100) NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_point_exchanges_user_id` (`user_id`),
  KEY `idx_point_exchanges_product_id` (`product_id`),
  KEY `idx_point_exchanges_status` (`status`),
  KEY `idx_point_exchanges_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Product exchange records';


