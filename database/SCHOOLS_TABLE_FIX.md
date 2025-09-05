# 数据库结构修复记录

## 问题描述

在测试学校创建功能时遇到以下错误：
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'updated_at' in 'field list' 
(SQL: insert into `schools` (`is_active`, `name`, `location`, `updated_at`, `created_at`) values (1, SchoolTest, ?, 2025-09-05 11:42:01, 2025-09-05 11:42:01))
```

## 根本原因

`schools` 表缺少 Eloquent ORM 期望的时间戳字段：
- `created_at` - 记录创建时间
- `updated_at` - 记录更新时间

## 解决方案

### 1. 创建了升级脚本

**文件**: `backend/upgrade_schools_table.php`

该脚本会：
- 检查当前表结构
- 如果缺少时间戳字段，重建表并保留现有数据
- 为现有记录设置合理的默认时间戳

### 2. 更新了 SQL 结构文档

**文件**: `backend/database/localhost.sql`

更新了 `schools` 表定义，添加了：
```sql
`created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
```

### 3. 修复后的表结构

```sql
CREATE TABLE `schools` (
  `id` int(11) NOT NULL,
  `name` text NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;
```

## 验证结果

✅ 直接 SQL 插入正常
✅ 带时间戳插入正常  
✅ 模拟 Eloquent 插入格式正常
✅ 后端综合测试通过（111 个路由正常）

## 影响范围

- **修复了**: 学校创建和更新功能
- **影响功能**: 
  - 用户注册时选择学校
  - Profile 页面修改学校
  - Onboarding 页面学校选择
  - 管理员学校管理
- **兼容性**: 保留了所有现有数据，向后兼容

## 部署说明

在生产环境部署时，需要：
1. 备份 `schools` 表数据
2. 执行 `upgrade_schools_table.php` 脚本
3. 验证表结构和数据完整性
4. 测试学校创建功能

修复时间：2025-09-05
修复人员：AI Assistant