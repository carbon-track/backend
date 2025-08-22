-- 创建头像表
CREATE TABLE IF NOT EXISTS `avatars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL UNIQUE,
  `name` varchar(100) NOT NULL COMMENT '头像名称',
  `description` varchar(255) DEFAULT NULL COMMENT '头像描述',
  `file_path` varchar(500) NOT NULL COMMENT '头像文件路径',
  `thumbnail_path` varchar(500) DEFAULT NULL COMMENT '缩略图路径',
  `category` varchar(50) DEFAULT 'default' COMMENT '头像分类',
  `sort_order` int(11) DEFAULT 0 COMMENT '排序顺序',
  `is_active` tinyint(1) DEFAULT 1 COMMENT '是否启用',
  `is_default` tinyint(1) DEFAULT 0 COMMENT '是否为默认头像',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL COMMENT '软删除时间',
  PRIMARY KEY (`id`),
  KEY `idx_avatars_category` (`category`),
  KEY `idx_avatars_active` (`is_active`),
  KEY `idx_avatars_sort` (`sort_order`),
  KEY `idx_avatars_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='预设头像表';

-- 更新用户表，添加头像ID字段
-- 旧 users 表可能没有 avatar_url 字段，直接添加 avatar_id 到末尾
ALTER TABLE `users` 
ADD COLUMN `avatar_id` int(11) DEFAULT NULL COMMENT '头像ID';
CREATE INDEX `idx_users_avatar_id` ON `users` (`avatar_id`);

-- 添加外键约束（可选，根据需要决定是否启用）
-- ALTER TABLE `users` 
-- ADD CONSTRAINT `fk_users_avatar_id` 
-- FOREIGN KEY (`avatar_id`) REFERENCES `avatars` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- 插入默认头像数据
INSERT INTO `avatars` (`uuid`, `name`, `description`, `file_path`, `thumbnail_path`, `category`, `sort_order`, `is_active`, `is_default`) VALUES
('550e8400-e29b-41d4-a716-446655440001', '默认头像1', '简约风格默认头像', '/avatars/default/avatar_01.png', '/avatars/default/thumb_avatar_01.png', 'default', 1, 1, 1),
('550e8400-e29b-41d4-a716-446655440002', '默认头像2', '可爱风格头像', '/avatars/default/avatar_02.png', '/avatars/default/thumb_avatar_02.png', 'default', 2, 1, 0),
('550e8400-e29b-41d4-a716-446655440003', '默认头像3', '商务风格头像', '/avatars/default/avatar_03.png', '/avatars/default/thumb_avatar_03.png', 'default', 3, 1, 0),
('550e8400-e29b-41d4-a716-446655440004', '默认头像4', '运动风格头像', '/avatars/default/avatar_04.png', '/avatars/default/thumb_avatar_04.png', 'default', 4, 1, 0),
('550e8400-e29b-41d4-a716-446655440005', '默认头像5', '艺术风格头像', '/avatars/default/avatar_05.png', '/avatars/default/thumb_avatar_05.png', 'default', 5, 1, 0),
('550e8400-e29b-41d4-a716-446655440006', '环保头像1', '绿色环保主题', '/avatars/eco/avatar_eco_01.png', '/avatars/eco/thumb_avatar_eco_01.png', 'eco', 6, 1, 0),
('550e8400-e29b-41d4-a716-446655440007', '环保头像2', '地球保护主题', '/avatars/eco/avatar_eco_02.png', '/avatars/eco/thumb_avatar_eco_02.png', 'eco', 7, 1, 0),
('550e8400-e29b-41d4-a716-446655440008', '环保头像3', '清洁能源主题', '/avatars/eco/avatar_eco_03.png', '/avatars/eco/thumb_avatar_eco_03.png', 'eco', 8, 1, 0),
('550e8400-e29b-41d4-a716-446655440009', '学生头像1', '校园风格头像', '/avatars/student/avatar_student_01.png', '/avatars/student/thumb_avatar_student_01.png', 'student', 9, 1, 0),
('550e8400-e29b-41d4-a716-446655440010', '学生头像2', '青春活力头像', '/avatars/student/avatar_student_02.png', '/avatars/student/thumb_avatar_student_02.png', 'student', 10, 1, 0);

-- 为现有用户设置默认头像（如果avatar_id为空）
-- 原始 users 表无 deleted_at 列，这里不加该条件
UPDATE `users` 
SET `avatar_id` = (SELECT `id` FROM `avatars` WHERE `is_default` = 1 AND `deleted_at` IS NULL LIMIT 1)
WHERE `avatar_id` IS NULL;

