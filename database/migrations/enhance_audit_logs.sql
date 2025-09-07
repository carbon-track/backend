-- Migration to enhance audit_logs table for detailed user and admin operation logging
-- Run this after backing up your database

ALTER TABLE `audit_logs` 
ADD COLUMN `actor_type` ENUM('user', 'admin', 'system') NOT NULL DEFAULT 'user' AFTER `user_id`,
ADD COLUMN `user_agent` VARCHAR(512) DEFAULT NULL AFTER `ip_address`,
ADD COLUMN `request_method` VARCHAR(10) DEFAULT NULL AFTER `user_agent`,
ADD COLUMN `endpoint` VARCHAR(512) DEFAULT NULL AFTER `request_method`,
ADD COLUMN `old_data` LONGTEXT AFTER `data`,
ADD COLUMN `new_data` LONGTEXT AFTER `old_data`,
ADD COLUMN `affected_table` VARCHAR(100) DEFAULT NULL AFTER `new_data`,
ADD COLUMN `affected_id` INT(11) DEFAULT NULL AFTER `affected_table`,
ADD COLUMN `status` ENUM('success', 'failed', 'pending') NOT NULL DEFAULT 'success' AFTER `affected_id`,
ADD COLUMN `response_code` INT(11) DEFAULT NULL AFTER `status`,
ADD COLUMN `session_id` VARCHAR(255) DEFAULT NULL AFTER `response_code`,
ADD COLUMN `referrer` VARCHAR(512) DEFAULT NULL AFTER `session_id`,
ADD COLUMN `operation_category` VARCHAR(100) DEFAULT NULL AFTER `referrer`,
ADD COLUMN `operation_subtype` VARCHAR(100) DEFAULT NULL AFTER `operation_category`,
ADD COLUMN `change_type` ENUM('create', 'update', 'delete', 'read', 'other') DEFAULT 'other' AFTER `operation_subtype`,
ADD INDEX `idx_audit_logs_actor_type` (`actor_type`),
ADD INDEX `idx_audit_logs_endpoint` (`endpoint`),
ADD INDEX `idx_audit_logs_affected_table` (`affected_table`),
ADD INDEX `idx_audit_logs_status` (`status`),
ADD INDEX `idx_audit_logs_created_at_desc` (`created_at` DESC),
ADD INDEX `idx_audit_logs_user_id_actor` (`user_id`, `actor_type`),
ADD INDEX `idx_audit_logs_operation_category` (`operation_category`),
ADD INDEX `idx_audit_logs_change_type` (`change_type`);

-- Add comments for better documentation
ALTER TABLE `audit_logs` 
MODIFY `action` VARCHAR(100) NOT NULL COMMENT 'Specific action name (e.g., user_login, admin_user_update)',
MODIFY `data` LONGTEXT COMMENT 'Original request/response data as JSON',
MODIFY `old_data` LONGTEXT COMMENT 'Previous state data before operation as JSON',
MODIFY `new_data` LONGTEXT COMMENT 'New state data after operation as JSON',
MODIFY `affected_table` VARCHAR(100) DEFAULT NULL COMMENT 'Database table affected by the operation',
MODIFY `affected_id` INT(11) DEFAULT NULL COMMENT 'Primary key of affected record',
MODIFY `operation_category` VARCHAR(100) DEFAULT NULL COMMENT 'High-level category (e.g., authentication, user_management, carbon_calculation)',
MODIFY `operation_subtype` VARCHAR(100) DEFAULT NULL COMMENT 'Specific subtype of operation',
MODIFY `change_type` ENUM('create', 'update', 'delete', 'read', 'other') DEFAULT 'other' COMMENT 'Type of data change performed';

-- Create a view for easier querying of recent activities
CREATE OR REPLACE VIEW `recent_audit_activities` AS
SELECT 
    al.id,
    al.actor_type,
    al.user_id,
    al.action,
    al.operation_category,
    al.operation_subtype,
    al.change_type,
    al.status,
    al.ip_address,
    al.created_at
FROM audit_logs al 
WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
ORDER BY al.created_at DESC;

-- Create a view for admin activities only
CREATE OR REPLACE VIEW `admin_operations` AS
SELECT 
    al.*,
    u.username as admin_username,
    u.email as admin_email
FROM audit_logs al 
LEFT JOIN users u ON al.user_id = u.id 
WHERE al.actor_type = 'admin'
ORDER BY al.created_at DESC;
