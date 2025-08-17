-- Upgrade script to transform an existing legacy database (from localhost.sql)
-- into a schema compatible with the current backend. Tested for MySQL 5.6.
-- Notes:
-- - This script avoids foreign keys to be compatible with MyISAM legacy tables.
-- - It uses dynamic SQL checks for columns to be idempotent where possible.
-- - Safe to run multiple times; ignore 'duplicate' errors on indexes if they appear.

SET NAMES utf8mb4;
SET time_zone = "+00:00";

-- === USERS TABLE ===
-- Add new columns and adjust data types
ALTER TABLE `users`
  MODIFY COLUMN `username` VARCHAR(255) DEFAULT NULL,
  MODIFY COLUMN `password` VARCHAR(255) NOT NULL,
  MODIFY COLUMN `lastlgn` DATETIME NULL,
  MODIFY COLUMN `email` VARCHAR(255) NOT NULL,
  MODIFY COLUMN `points` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
  MODIFY COLUMN `school` VARCHAR(255) NULL,
  MODIFY COLUMN `location` VARCHAR(255) NULL;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'SELECT "users.created_at exists"',
  'ALTER TABLE `users` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='updated_at')>0,
  'SELECT "users.updated_at exists"',
  'ALTER TABLE `users` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='deleted_at')>0,
  'SELECT "users.deleted_at exists"',
  'ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='status')>0,
  'SELECT "users.status exists"',
  "ALTER TABLE `users` ADD COLUMN `status` ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active'"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='is_admin')>0,
  'SELECT "users.is_admin exists"',
  'ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='class_name')>0,
  'SELECT "users.class_name exists"',
  'ALTER TABLE `users` ADD COLUMN `class_name` VARCHAR(100) NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='school_id')>0,
  'SELECT "users.school_id exists"',
  'ALTER TABLE `users` ADD COLUMN `school_id` INT(11) NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='users' AND table_schema=DATABASE() AND column_name='avatar_id')>0,
  'SELECT "users.avatar_id exists"',
  'ALTER TABLE `users` ADD COLUMN `avatar_id` INT(11) NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Helpful indexes (idempotent)
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_email_unique'
),
  'SELECT "idx_users_email_unique exists"',
  'ALTER TABLE `users` ADD UNIQUE KEY `idx_users_email_unique` (`email`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_username'
),
  'SELECT "idx_users_username exists"',
  'CREATE INDEX `idx_users_username` ON `users` (`username`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_deleted_at'
),
  'SELECT "idx_users_deleted_at exists"',
  'CREATE INDEX `idx_users_deleted_at` ON `users` (`deleted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_status'
),
  'SELECT "idx_users_status exists"',
  'CREATE INDEX `idx_users_status` ON `users` (`status`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_is_admin'
),
  'SELECT "idx_users_is_admin exists"',
  'CREATE INDEX `idx_users_is_admin` ON `users` (`is_admin`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_created_at'
),
  'SELECT "idx_users_created_at exists"',
  'CREATE INDEX `idx_users_created_at` ON `users` (`created_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === SCHOOLS TABLE ===
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='schools' AND table_schema=DATABASE() AND column_name='deleted_at')>0,
  'SELECT "schools.deleted_at exists"',
  'ALTER TABLE `schools` ADD COLUMN `deleted_at` DATETIME NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='schools' AND table_schema=DATABASE() AND column_name='location')>0,
  'SELECT "schools.location exists"',
  'ALTER TABLE `schools` ADD COLUMN `location` VARCHAR(255) NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='schools' AND table_schema=DATABASE() AND column_name='is_active')>0,
  'SELECT "schools.is_active exists"',
  'ALTER TABLE `schools` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === PRODUCTS TABLE ===
-- Normalize products table and add columns used by backend
-- (If legacy had product_id as PK)
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='product_id')>0,
  'ALTER TABLE `products` CHANGE `product_id` `id` INT(11) NOT NULL AUTO_INCREMENT',
  'SELECT "products.id ok"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='category')>0,
  'SELECT "products.category exists"',
  'ALTER TABLE `products` ADD COLUMN `category` VARCHAR(100) NULL AFTER `name`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='images')>0,
  'SELECT "products.images exists"',
  'ALTER TABLE `products` ADD COLUMN `images` LONGTEXT NULL COMMENT "JSON images" AFTER `image_path`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='status')>0,
  'SELECT "products.status exists"',
  "ALTER TABLE `products` ADD COLUMN `status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `stock`"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='sort_order')>0,
  'SELECT "products.sort_order exists"',
  'ALTER TABLE `products` ADD COLUMN `sort_order` INT(11) NOT NULL DEFAULT 0 AFTER `status`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'SELECT "products.created_at exists"',
  'ALTER TABLE `products` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='updated_at')>0,
  'SELECT "products.updated_at exists"',
  'ALTER TABLE `products` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='products' AND table_schema=DATABASE() AND column_name='deleted_at')>0,
  'SELECT "products.deleted_at exists"',
  'ALTER TABLE `products` ADD COLUMN `deleted_at` DATETIME NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'idx_products_status'
),
  'SELECT "idx_products_status exists"',
  'CREATE INDEX `idx_products_status` ON `products` (`status`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'idx_products_category'
),
  'SELECT "idx_products_category exists"',
  'CREATE INDEX `idx_products_category` ON `products` (`category`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'products' AND index_name = 'idx_products_sort_order'
),
  'SELECT "idx_products_sort_order exists"',
  'CREATE INDEX `idx_products_sort_order` ON `products` (`sort_order`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === CARBON ACTIVITIES ===
CREATE TABLE IF NOT EXISTS `carbon_activities` (
  `id` char(36) NOT NULL,
  `name_zh` varchar(255) NOT NULL,
  `name_en` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `carbon_factor` decimal(10,4) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `description_zh` text NULL,
  `description_en` text NULL,
  `icon` varchar(100) NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_carbon_activities_category` (`category`),
  KEY `idx_carbon_activities_is_active` (`is_active`),
  KEY `idx_carbon_activities_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Align indexes with migrations: add deleted_at index if missing
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'carbon_activities' AND index_name = 'idx_carbon_activities_deleted_at'
),
  'SELECT "idx_carbon_activities_deleted_at exists"',
  'CREATE INDEX `idx_carbon_activities_deleted_at` ON `carbon_activities` (`deleted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed a minimal set of activities if table is empty
INSERT INTO `carbon_activities` (`id`, `name_zh`, `name_en`, `category`, `carbon_factor`, `unit`, `icon`, `sort_order`)
SELECT * FROM (
  SELECT '550e8400-e29b-41d4-a716-446655440001', '购物时自带袋子', 'Bring your own bag when shopping', 'daily', 0.0190, 'times', 'shopping-bag', 1 UNION ALL
  SELECT '550e8400-e29b-41d4-a716-446655440025', '公交地铁通勤', 'Use public transport', 'transport', 0.1005, 'km', 'bus', 25
) AS t
WHERE NOT EXISTS (SELECT 1 FROM carbon_activities LIMIT 1);

-- === CARBON RECORDS (new table) ===
CREATE TABLE IF NOT EXISTS `carbon_records` (
  `id` char(36) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_id` char(36) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `carbon_saved` decimal(12,4) NOT NULL DEFAULT 0,
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `date` date NOT NULL,
  `description` text NULL,
  `images` longtext NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) NULL,
  `reviewed_at` datetime NULL,
  `review_note` text NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_carbon_records_user` (`user_id`),
  KEY `idx_carbon_records_activity` (`activity_id`),
  KEY `idx_carbon_records_status` (`status`),
  KEY `idx_carbon_records_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- === POINT EXCHANGES ===
CREATE TABLE IF NOT EXISTS `point_exchanges` (
  `id` char(36) NOT NULL,
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
  KEY `idx_point_exchanges_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add missing created_at index to align with migrations
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'point_exchanges' AND index_name = 'idx_point_exchanges_created_at'
),
  'SELECT "idx_point_exchanges_created_at exists"',
  'CREATE INDEX `idx_point_exchanges_created_at` ON `point_exchanges` (`created_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === POINTS TRANSACTIONS (augment legacy table) ===
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='status')>0,
  'SELECT "points_transactions.status exists"',
  "ALTER TABLE `points_transactions` ADD COLUMN `status` ENUM('pending','approved','rejected','deleted') NOT NULL DEFAULT 'pending'"));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='approved_by')>0,
  'SELECT "points_transactions.approved_by exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `approved_by` INT(11) NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='approved_at')>0,
  'SELECT "points_transactions.approved_at exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `approved_at` DATETIME NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'SELECT "points_transactions.created_at exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='updated_at')>0,
  'SELECT "points_transactions.updated_at exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='deleted_at')>0,
  'SELECT "points_transactions.deleted_at exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `deleted_at` DATETIME NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE `points_transactions`
  MODIFY COLUMN `email` VARCHAR(255) NOT NULL,
  MODIFY COLUMN `time` DATETIME NOT NULL,
  MODIFY COLUMN `img` VARCHAR(512) NULL,
  MODIFY COLUMN `points` DECIMAL(10,2) NOT NULL,
  MODIFY COLUMN `raw` DECIMAL(10,2) NOT NULL,
  MODIFY COLUMN `act` VARCHAR(255) NULL,
  MODIFY COLUMN `type` VARCHAR(50) NULL,
  MODIFY COLUMN `notes` TEXT NULL,
  MODIFY COLUMN `activity_date` DATE NULL,
  MODIFY COLUMN `uid` INT(11) NOT NULL,
  MODIFY COLUMN `auth` VARCHAR(50) NULL;

-- Add activity_id and map from act text (best-effort)
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='points_transactions' AND table_schema=DATABASE() AND column_name='activity_id')>0,
  'SELECT "points_transactions.activity_id exists"',
  'ALTER TABLE `points_transactions` ADD COLUMN `activity_id` CHAR(36) NULL AFTER `uid`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_status'
),
  'SELECT "idx_points_transactions_status exists"',
  'CREATE INDEX `idx_points_transactions_status` ON `points_transactions` (`status`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_approved_by'
),
  'SELECT "idx_points_transactions_approved_by exists"',
  'CREATE INDEX `idx_points_transactions_approved_by` ON `points_transactions` (`approved_by`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_approved_at'
),
  'SELECT "idx_points_transactions_approved_at exists"',
  'CREATE INDEX `idx_points_transactions_approved_at` ON `points_transactions` (`approved_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_deleted_at'
),
  'SELECT "idx_points_transactions_deleted_at exists"',
  'CREATE INDEX `idx_points_transactions_deleted_at` ON `points_transactions` (`deleted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_created_at'
),
  'SELECT "idx_points_transactions_created_at exists"',
  'CREATE INDEX `idx_points_transactions_created_at` ON `points_transactions` (`created_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_type'
),
  'SELECT "idx_points_transactions_type exists"',
  'CREATE INDEX `idx_points_transactions_type` ON `points_transactions` (`type`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'points_transactions' AND index_name = 'idx_points_transactions_activity_id'
),
  'SELECT "idx_points_transactions_activity_id exists"',
  'CREATE INDEX `idx_points_transactions_activity_id` ON `points_transactions` (`activity_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Example mappings (extend as needed; safe to run)
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440001' WHERE `act` LIKE '%购物时自带袋子%';
UPDATE `points_transactions` SET `activity_id` = '550e8400-e29b-41d4-a716-446655440025' WHERE `act` LIKE '%公交地铁通勤%';

-- Mark existing pending to approved if they were historical
UPDATE `points_transactions` SET `status` = 'approved', `approved_at` = `time` WHERE `status` = 'pending';

-- === MESSAGES (upgrade legacy structure) ===
-- Create new table if not exists
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NULL,
  `receiver_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `type` enum('system','notification','approval','rejection','exchange','welcome','reminder') NOT NULL DEFAULT 'notification',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` datetime NULL,
  `related_entity_type` varchar(50) NULL,
  `related_entity_id` int(11) NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messages_receiver_id` (`receiver_id`),
  KEY `idx_messages_type` (`type`),
  KEY `idx_messages_priority` (`priority`),
  KEY `idx_messages_is_read` (`is_read`),
  KEY `idx_messages_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If legacy columns exist, migrate them (best-effort)
-- Rename message_id -> id
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='message_id')>0,
  'ALTER TABLE `messages` CHANGE `message_id` `id` int(11) NOT NULL AUTO_INCREMENT',
  'SELECT "messages.message_id not found"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add missing indexes to align with migrations
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_sender_id'
),
  'SELECT "idx_messages_sender_id exists"',
  'CREATE INDEX `idx_messages_sender_id` ON `messages` (`sender_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_deleted_at'
),
  'SELECT "idx_messages_deleted_at exists"',
  'CREATE INDEX `idx_messages_deleted_at` ON `messages` (`deleted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_related_entity'
),
  'SELECT "idx_messages_related_entity exists"',
  'CREATE INDEX `idx_messages_related_entity` ON `messages` (`related_entity_type`, `related_entity_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_receiver_id'
),
  'SELECT "idx_messages_receiver_id exists"',
  'CREATE INDEX `idx_messages_receiver_id` ON `messages` (`receiver_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_type'
),
  'SELECT "idx_messages_type exists"',
  'CREATE INDEX `idx_messages_type` ON `messages` (`type`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_priority'
),
  'SELECT "idx_messages_priority exists"',
  'CREATE INDEX `idx_messages_priority` ON `messages` (`priority`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_is_read'
),
  'SELECT "idx_messages_is_read exists"',
  'CREATE INDEX `idx_messages_is_read` ON `messages` (`is_read`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'messages' AND index_name = 'idx_messages_created_at'
),
  'SELECT "idx_messages_created_at exists"',
  'CREATE INDEX `idx_messages_created_at` ON `messages` (`created_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Ensure sender_id/receiver_id are INT
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='sender_id')>0,
  'ALTER TABLE `messages` MODIFY COLUMN `sender_id` int(11) NULL',
  'SELECT "messages.sender_id will be created by CREATE TABLE if needed"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='receiver_id')>0,
  'ALTER TABLE `messages` MODIFY COLUMN `receiver_id` int(11) NOT NULL',
  'SELECT "messages.receiver_id will be created by CREATE TABLE if needed"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Ensure created_at/updated_at/deleted_at exist on legacy messages table
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'SELECT "messages.created_at exists"',
  'ALTER TABLE `messages` ADD COLUMN `created_at` datetime NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='updated_at')>0,
  'SELECT "messages.updated_at exists"',
  'ALTER TABLE `messages` ADD COLUMN `updated_at` datetime NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='deleted_at')>0,
  'SELECT "messages.deleted_at exists"',
  'ALTER TABLE `messages` ADD COLUMN `deleted_at` datetime NULL'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill created_at from send_time if present
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='send_time')>0
  AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'UPDATE `messages` SET created_at = send_time WHERE created_at IS NULL',
  'SELECT "messages.send_time or created_at not found"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- If created_at missing but legacy send_time exists, rename it to created_at
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='send_time')>0
  AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='created_at')=0,
  'ALTER TABLE `messages` CHANGE `send_time` `created_at` datetime NOT NULL',
  'SELECT "skip send_time rename"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- If both exist, drop legacy send_time to align with migrations
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='send_time')>0
  AND (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='created_at')>0,
  'ALTER TABLE `messages` DROP COLUMN `send_time`',
  'SELECT "send_time not found or already handled"'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Create title if missing
SET @sql = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name='messages' AND table_schema=DATABASE() AND column_name='title')>0,
  'SELECT "messages.title exists"',
  'ALTER TABLE `messages` ADD COLUMN `title` varchar(255) NOT NULL DEFAULT "" AFTER `receiver_id`'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Attempt to normalize legacy text IDs to integers
UPDATE `messages` SET receiver_id = CASE WHEN receiver_id REGEXP '^[0-9]+$' THEN receiver_id ELSE 0 END;
UPDATE `messages` SET sender_id = CASE WHEN sender_id REGEXP '^[0-9]+$' THEN sender_id ELSE NULL END;

-- === AVATARS TABLE ===
CREATE TABLE IF NOT EXISTS `avatars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL UNIQUE,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `thumbnail_path` varchar(500) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'default',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `is_default` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add helpful indexes for avatars to align with migrations
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'avatars' AND index_name = 'idx_avatars_category'
),
  'SELECT "idx_avatars_category exists"',
  'CREATE INDEX `idx_avatars_category` ON `avatars` (`category`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'avatars' AND index_name = 'idx_avatars_active'
),
  'SELECT "idx_avatars_active exists"',
  'CREATE INDEX `idx_avatars_active` ON `avatars` (`is_active`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'avatars' AND index_name = 'idx_avatars_sort'
),
  'SELECT "idx_avatars_sort exists"',
  'CREATE INDEX `idx_avatars_sort` ON `avatars` (`sort_order`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'avatars' AND index_name = 'idx_avatars_deleted'
),
  'SELECT "idx_avatars_deleted exists"',
  'CREATE INDEX `idx_avatars_deleted` ON `avatars` (`deleted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed default avatar if table empty
INSERT INTO `avatars` (`uuid`,`name`,`file_path`,`category`,`sort_order`,`is_active`,`is_default`)
SELECT 
  '550e8400-e29b-41d4-a716-446655440001' AS `uuid`,
  '默认头像1' AS `name`,
  '/avatars/default/avatar_01.png' AS `file_path`,
  'default' AS `category`,
  1 AS `sort_order`,
  1 AS `is_active`,
  1 AS `is_default`
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM avatars LIMIT 1);

-- Ensure users.avatar_id exists and set default
UPDATE `users` SET `avatar_id` = (SELECT id FROM avatars WHERE is_default=1 LIMIT 1) WHERE `avatar_id` IS NULL;

-- Add users.avatar_id index to align with migrations
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_avatar_id'
),
  'SELECT "idx_users_avatar_id exists"',
  'CREATE INDEX `idx_users_avatar_id` ON `users` (`avatar_id`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === IDEMPOTENCY & SECURITY TABLES ===
CREATE TABLE IF NOT EXISTS `idempotency_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `idempotency_key` varchar(36) NOT NULL,
  `user_id` int(11) NULL,
  `request_method` varchar(10) NOT NULL,
  `request_uri` varchar(512) NOT NULL,
  `request_body` longtext NULL,
  `response_status` int(11) NOT NULL,
  `response_body` longtext NOT NULL,
  `ip_address` varchar(45) NULL,
  `user_agent` varchar(512) NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_idempotency_key` (`idempotency_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_request_uri` (`request_uri`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `user_agent` varchar(500) DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add helpful indexes for login_attempts to align with migrations
SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_login_attempts_username'
),
  'SELECT "idx_login_attempts_username exists"',
  'CREATE INDEX `idx_login_attempts_username` ON `login_attempts` (`username`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_login_attempts_ip'
),
  'SELECT "idx_login_attempts_ip exists"',
  'CREATE INDEX `idx_login_attempts_ip` ON `login_attempts` (`ip_address`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_login_attempts_time'
),
  'SELECT "idx_login_attempts_time exists"',
  'CREATE INDEX `idx_login_attempts_time` ON `login_attempts` (`attempted_at`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(EXISTS(
  SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS 
  WHERE table_schema = DATABASE() AND table_name = 'login_attempts' AND index_name = 'idx_login_attempts_success'
),
  'SELECT "idx_login_attempts_success exists"',
  'CREATE INDEX `idx_login_attempts_success` ON `login_attempts` (`success`)'));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- === AUDIT LOGS ===
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NULL,
  `action` varchar(100) NOT NULL,
  `data` longtext NULL,
  `ip_address` varchar(45) NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user` (`user_id`),
  KEY `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- End of upgrade


