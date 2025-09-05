-- 升级 schools 表结构，添加缺失的时间戳字段
-- 需要在数据库中执行此脚本来修复学校创建功能

USE `dev_api_carbontr`;

-- 添加 created_at 和 updated_at 字段到 schools 表
ALTER TABLE `schools` 
ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`,
ADD COLUMN `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- 为现有记录设置合理的时间戳（如果表中有数据）
UPDATE `schools` SET 
    `created_at` = '2025-01-01 00:00:00',
    `updated_at` = NOW()
WHERE `created_at` IS NULL OR `updated_at` IS NULL;

-- 验证表结构
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'dev_api_carbontr' AND TABLE_NAME = 'schools'
ORDER BY ORDINAL_POSITION;