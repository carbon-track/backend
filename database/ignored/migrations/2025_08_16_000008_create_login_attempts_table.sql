-- 创建登录尝试记录表（防暴力破解）
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL COMMENT '尝试登录的用户名',
  `ip_address` varchar(45) NOT NULL COMMENT 'IP地址',
  `success` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否成功',
  `user_agent` varchar(500) DEFAULT NULL COMMENT '用户代理',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '尝试时间',
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_username` (`username`),
  KEY `idx_login_attempts_ip` (`ip_address`),
  KEY `idx_login_attempts_time` (`attempted_at`),
  KEY `idx_login_attempts_success` (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录尝试记录表';

-- 创建清理过期记录的事件（如果MySQL支持事件调度器）
-- SET GLOBAL event_scheduler = ON;
-- 
-- CREATE EVENT IF NOT EXISTS `cleanup_login_attempts`
-- ON SCHEDULE EVERY 1 HOUR
-- DO
--   DELETE FROM `login_attempts` WHERE `attempted_at` < DATE_SUB(NOW(), INTERVAL 24 HOUR);

