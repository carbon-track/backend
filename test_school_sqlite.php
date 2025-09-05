<?php

declare(strict_types=1);

echo "=== 测试学校创建功能 (SQLite) ===\n\n";

try {
    // 直接连接 SQLite 数据库
    $pdo = new PDO('sqlite:test.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "1. 测试直接 SQL 插入...\n";
    $stmt = $pdo->prepare("INSERT INTO schools (name, location, is_active) VALUES (?, ?, ?)");
    $result = $stmt->execute(['测试学校直接插入', '测试地址', 1]);
    
    if ($result) {
        $schoolId = $pdo->lastInsertId();
        echo "✓ 直接 SQL 插入成功，学校 ID: $schoolId\n";
        
        // 查询验证
        $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  学校信息: " . json_encode($school, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "✗ 直接 SQL 插入失败\n";
    }
    
    echo "\n2. 测试带时间戳的插入...\n";
    $stmt = $pdo->prepare("INSERT INTO schools (name, location, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    $result = $stmt->execute(['测试学校带时间戳', '带时间戳地址', 1, $now, $now]);
    
    if ($result) {
        $schoolId = $pdo->lastInsertId();
        echo "✓ 带时间戳插入成功，学校 ID: $schoolId\n";
        
        // 查询验证
        $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  学校信息: " . json_encode($school, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "✗ 带时间戳插入失败\n";
    }
    
    echo "\n3. 查看所有学校...\n";
    $stmt = $pdo->query("SELECT * FROM schools ORDER BY id");
    $schools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "总共 " . count($schools) . " 所学校:\n";
    foreach ($schools as $school) {
        $isActive = $school['is_active'] ? '是' : '否';
        echo "  - ID: {$school['id']}, 名称: {$school['name']}, 地址: {$school['location']}, 活跃: $isActive\n";
        echo "    创建时间: {$school['created_at']}, 更新时间: {$school['updated_at']}\n";
    }
    
    echo "\n4. 测试模拟 Eloquent 插入格式...\n";
    // 模拟 Eloquent 会执行的 SQL
    $stmt = $pdo->prepare("INSERT INTO schools (is_active, name, location, updated_at, created_at) VALUES (?, ?, ?, ?, ?)");
    $now = date('Y-m-d H:i:s');
    $result = $stmt->execute([1, 'SchoolTest', null, $now, $now]);
    
    if ($result) {
        $schoolId = $pdo->lastInsertId();
        echo "✓ 模拟 Eloquent 插入成功，学校 ID: $schoolId\n";
        
        // 查询验证
        $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  学校信息: " . json_encode($school, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "✗ 模拟 Eloquent 插入失败\n";
    }
    
    echo "\n🎉 学校创建功能测试完成！数据库结构修复成功！\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "错误详情: " . $e->getTraceAsString() . "\n";
    exit(1);
}