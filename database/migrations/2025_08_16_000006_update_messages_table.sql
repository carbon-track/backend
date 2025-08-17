-- Update messages table to support comprehensive messaging system

-- First, check if table exists and create if not
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NULL COMMENT 'Sender user ID (NULL for system messages)',
  `receiver_id` int(11) NOT NULL COMMENT 'Receiver user ID',
  `title` varchar(255) NOT NULL COMMENT 'Message title',
  `content` text NOT NULL COMMENT 'Message content',
  `type` enum('system','notification','approval','rejection','exchange','welcome','reminder') NOT NULL DEFAULT 'notification' COMMENT 'Message type',
  `priority` enum('low','normal','high','urgent') NOT NULL DEFAULT 'normal' COMMENT 'Message priority',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Whether message has been read',
  `read_at` datetime NULL COMMENT 'When message was read',
  `related_entity_type` varchar(50) NULL COMMENT 'Related entity type (points_transaction, exchange_transaction, etc.)',
  `related_entity_id` int(11) NULL COMMENT 'Related entity ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime NULL COMMENT 'Soft delete timestamp',
  PRIMARY KEY (`id`),
  KEY `idx_messages_sender_id` (`sender_id`),
  KEY `idx_messages_receiver_id` (`receiver_id`),
  KEY `idx_messages_type` (`type`),
  KEY `idx_messages_priority` (`priority`),
  KEY `idx_messages_is_read` (`is_read`),
  KEY `idx_messages_created_at` (`created_at`),
  KEY `idx_messages_deleted_at` (`deleted_at`),
  KEY `idx_messages_related_entity` (`related_entity_type`, `related_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='System messages and notifications';

-- If table already exists, add missing columns
-- Check and add columns one by one to avoid errors

-- ==== Legacy structure migration (from localhost.sql) ====
-- Rename legacy primary key message_id -> id
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'message_id') > 0,
  'ALTER TABLE `messages` CHANGE `message_id` `id` int(11) NOT NULL AUTO_INCREMENT',
  'SELECT "message_id does not exist"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Convert legacy sender_id TEXT -> INT
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'sender_id') > 0,
  'ALTER TABLE `messages` MODIFY COLUMN `sender_id` int(11) NULL',
  'SELECT "sender_id missing, will be created by CREATE TABLE if needed"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Convert legacy receiver_id TEXT -> INT
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'receiver_id') > 0,
  'ALTER TABLE `messages` MODIFY COLUMN `receiver_id` int(11) NOT NULL',
  'SELECT "receiver_id missing, will be created by CREATE TABLE if needed"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Rename legacy send_time -> created_at
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'send_time') > 0,
  'ALTER TABLE `messages` CHANGE `send_time` `created_at` datetime NOT NULL',
  'SELECT "send_time does not exist"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add type column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'type') > 0,
  'SELECT "Column type already exists"',
  'ALTER TABLE `messages` ADD COLUMN `type` enum(\'system\',\'notification\',\'approval\',\'rejection\',\'exchange\',\'welcome\',\'reminder\') NOT NULL DEFAULT \'notification\' COMMENT \'Message type\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add title column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'title') > 0,
  'SELECT "Column title already exists"',
  'ALTER TABLE `messages` ADD COLUMN `title` varchar(255) NOT NULL DEFAULT "" COMMENT "Message title"'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add priority column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'priority') > 0,
  'SELECT "Column priority already exists"',
  'ALTER TABLE `messages` ADD COLUMN `priority` enum(\'low\',\'normal\',\'high\',\'urgent\') NOT NULL DEFAULT \'normal\' COMMENT \'Message priority\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_read column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'is_read') > 0,
  'SELECT "Column is_read already exists"',
  'ALTER TABLE `messages` ADD COLUMN `is_read` tinyint(1) NOT NULL DEFAULT \'0\' COMMENT \'Whether message has been read\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add read_at column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'read_at') > 0,
  'SELECT "Column read_at already exists"',
  'ALTER TABLE `messages` ADD COLUMN `read_at` datetime NULL COMMENT \'When message was read\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add related_entity_type column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'related_entity_type') > 0,
  'SELECT "Column related_entity_type already exists"',
  'ALTER TABLE `messages` ADD COLUMN `related_entity_type` varchar(50) NULL COMMENT \'Related entity type (points_transaction, exchange_transaction, etc.)\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add related_entity_id column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'related_entity_id') > 0,
  'SELECT "Column related_entity_id already exists"',
  'ALTER TABLE `messages` ADD COLUMN `related_entity_id` int(11) NULL COMMENT \'Related entity ID\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add created_at column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'created_at') > 0,
  'SELECT "Column created_at already exists"',
  'ALTER TABLE `messages` ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add updated_at column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'updated_at') > 0,
  'SELECT "Column updated_at already exists"',
  'ALTER TABLE `messages` ADD COLUMN `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add deleted_at column if not exists
SET @sql = (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
   WHERE table_name = 'messages' 
   AND table_schema = DATABASE()
   AND column_name = 'deleted_at') > 0,
  'SELECT "Column deleted_at already exists"',
  'ALTER TABLE `messages` ADD COLUMN `deleted_at` datetime NULL COMMENT \'Soft delete timestamp\''
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Modify existing columns to ensure proper data types
ALTER TABLE `messages` 
MODIFY COLUMN `title` varchar(255) NOT NULL COMMENT 'Message title',
MODIFY COLUMN `content` text NOT NULL COMMENT 'Message content';

-- Make sender_id nullable for system messages
ALTER TABLE `messages` 
MODIFY COLUMN `sender_id` int(11) NULL COMMENT 'Sender user ID (NULL for system messages)';

-- Add indexes if they don't exist
-- MySQL 5.6 无 IF NOT EXISTS，若已存在请忽略报错
CREATE INDEX `idx_messages_type` ON `messages` (`type`);
CREATE INDEX `idx_messages_priority` ON `messages` (`priority`);
CREATE INDEX `idx_messages_is_read` ON `messages` (`is_read`);
CREATE INDEX `idx_messages_created_at` ON `messages` (`created_at`);
CREATE INDEX `idx_messages_deleted_at` ON `messages` (`deleted_at`);
CREATE INDEX `idx_messages_related_entity` ON `messages` (`related_entity_type`, `related_entity_id`);

-- Add foreign key constraints if they don't exist
-- Note: MySQL 5.6 doesn't support IF NOT EXISTS for foreign keys, so we'll use a different approach

-- 旧库多为 MyISAM，外键不稳定；此处跳过外键，仅通过应用层保证一致性
-- 外键跳过

