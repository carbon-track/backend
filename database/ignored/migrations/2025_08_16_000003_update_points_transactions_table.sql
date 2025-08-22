-- Update points_transactions table to support soft deletes, approval workflow, and better data types

-- Add new columns for approval workflow and soft deletes
ALTER TABLE `points_transactions`
ADD COLUMN `status` ENUM('pending', 'approved', 'rejected', 'deleted') NOT NULL DEFAULT 'pending',
ADD COLUMN `approved_by` INT(11) NULL COMMENT 'Admin user ID who approved/rejected',
ADD COLUMN `approved_at` DATETIME NULL COMMENT 'When the transaction was approved/rejected',
ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD COLUMN `deleted_at` DATETIME NULL;

-- Modify existing columns for better data types
ALTER TABLE `points_transactions`
MODIFY COLUMN `email` VARCHAR(255) NOT NULL,
MODIFY COLUMN `time` DATETIME NOT NULL,
MODIFY COLUMN `img` VARCHAR(512) NULL COMMENT 'Cloudflare R2 image URL',
MODIFY COLUMN `points` DECIMAL(10, 2) NOT NULL,
MODIFY COLUMN `raw` DECIMAL(10, 2) NOT NULL,
MODIFY COLUMN `act` VARCHAR(255) NOT NULL,
MODIFY COLUMN `type` VARCHAR(50) NULL,
MODIFY COLUMN `notes` TEXT NULL,
MODIFY COLUMN `activity_date` DATE NULL,
MODIFY COLUMN `uid` INT(11) NOT NULL,
MODIFY COLUMN `auth` VARCHAR(50) NULL;

-- Add foreign key constraints
-- 原始库使用 MyISAM，无法添加外键。为兼容旧库，这里不加外键，只保留索引。

-- Add indexes for better performance
CREATE INDEX `idx_points_transactions_status` ON `points_transactions` (`status`);
CREATE INDEX `idx_points_transactions_approved_by` ON `points_transactions` (`approved_by`);
CREATE INDEX `idx_points_transactions_approved_at` ON `points_transactions` (`approved_at`);
CREATE INDEX `idx_points_transactions_deleted_at` ON `points_transactions` (`deleted_at`);
CREATE INDEX `idx_points_transactions_created_at` ON `points_transactions` (`created_at`);
CREATE INDEX `idx_points_transactions_type` ON `points_transactions` (`type`);

-- Update existing records to approved status (assuming they were manually approved before)
UPDATE `points_transactions` SET `status` = 'approved', `approved_at` = `time` WHERE `status` = 'pending';

