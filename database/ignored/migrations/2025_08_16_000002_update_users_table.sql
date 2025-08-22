-- Update users table to support soft deletes, better data types, and additional fields

-- First, add new columns (old users table may lack these columns)
ALTER TABLE `users`
ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
ADD COLUMN `deleted_at` DATETIME NULL,
ADD COLUMN `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT '0';

-- Modify existing columns for better data types and constraints
ALTER TABLE `users`
MODIFY COLUMN `username` VARCHAR(255) DEFAULT NULL,
MODIFY COLUMN `password` VARCHAR(255) NOT NULL,
MODIFY COLUMN `lastlgn` DATETIME NULL,
MODIFY COLUMN `email` VARCHAR(255) NOT NULL,
MODIFY COLUMN `points` DECIMAL(10, 2) NOT NULL DEFAULT '0.00',
MODIFY COLUMN `school` VARCHAR(255) NULL,
MODIFY COLUMN `location` VARCHAR(255) NULL;

-- Add unique constraint on email if not exists
-- MySQL 5.6 不支持 IF NOT EXISTS 的索引，这里在失败时忽略错误由迁移器处理
ALTER TABLE `users` ADD UNIQUE KEY `idx_users_email_unique` (`email`);

-- Add indexes for better performance
-- 普通索引创建（若已存在请忽略报错）
CREATE INDEX `idx_users_username` ON `users` (`username`);
CREATE INDEX `idx_users_deleted_at` ON `users` (`deleted_at`);
CREATE INDEX `idx_users_status` ON `users` (`status`);
CREATE INDEX `idx_users_is_admin` ON `users` (`is_admin`);
CREATE INDEX `idx_users_created_at` ON `users` (`created_at`);

-- Update existing admin users based on email list
UPDATE `users` SET `is_admin` = 1 
WHERE `email` IN (
    'lyuzn.jeffery2023@gdhfi.com',
    '2116403107@qq.com',
    'cpurescuerhu@outlook.com',
    'yangyangzhouyh@outlook.com',
    '2964608199@qq.com',
    'tangke_0225@qq.com',
    'ruoxuan.gao@hotmail.com',
    'guzhou218@gmail.com',
    'Michealjiang@hamdenhall.org'
);

