<?php

declare(strict_types=1);

echo "=== 升级 schools 表结构 ===\n\n";

try {
    // 连接到 SQLite 数据库
    $pdo = new PDO('sqlite:test.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 检查当前 schools 表结构
    echo "检查当前 schools 表结构...\n";
    $stmt = $pdo->query('PRAGMA table_info(schools)');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        echo "schools 表不存在，创建新表...\n";
        $sql = "
        CREATE TABLE schools (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            location VARCHAR(255),
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME DEFAULT NULL
        )";
        $pdo->exec($sql);
        echo "✓ schools 表创建成功\n";
    } else {
        echo "当前表结构:\n";
        foreach ($columns as $col) {
            echo "  - {$col['name']}: {$col['type']}\n";
        }
        
        // 检查是否缺少时间戳字段
        $hasCreatedAt = false;
        $hasUpdatedAt = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'created_at') {
                $hasCreatedAt = true;
            }
            if ($col['name'] === 'updated_at') {
                $hasUpdatedAt = true;
            }
        }
        
        if (!$hasCreatedAt || !$hasUpdatedAt) {
            echo "缺少时间戳字段，需要重建表...\n";
            
            // SQLite 不支持直接添加带默认值的列，需要重建表
            $pdo->exec('BEGIN TRANSACTION');
            
            // 备份现有数据
            $pdo->exec('CREATE TABLE schools_backup AS SELECT * FROM schools');
            
            // 删除原表
            $pdo->exec('DROP TABLE schools');
            
            // 创建新表
            $sql = "
            CREATE TABLE schools (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                location VARCHAR(255),
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME DEFAULT NULL
            )";
            $pdo->exec($sql);
            
            // 恢复数据
            $pdo->exec("
                INSERT INTO schools (id, name, location, is_active, deleted_at, created_at, updated_at)
                SELECT id, name, location, is_active, deleted_at,
                       '2025-01-01 00:00:00',
                       datetime('now')
                FROM schools_backup
            ");
            
            // 删除备份表
            $pdo->exec('DROP TABLE schools_backup');
            
            $pdo->exec('COMMIT');
            
            echo "✓ schools 表结构升级成功\n";
        } else {
            echo "✓ 表结构已经正确\n";
        }
    }
    
    // 验证最终结构
    echo "\n最终表结构:\n";
    $stmt = $pdo->query('PRAGMA table_info(schools)');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "  {$col['name']}: {$col['type']} (NOT NULL: {$col['notnull']}, DEFAULT: {$col['dflt_value']})\n";
    }
    
    echo "\n🎉 schools 表结构升级完成！\n";

} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}