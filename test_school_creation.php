<?php

declare(strict_types=1);

echo "=== 测试学校创建功能 ===\n\n";

// 加载依赖
require __DIR__ . '/vendor/autoload.php';

try {
    // 初始化容器和依赖
    $container = new \DI\Container();
    $dependencies = require __DIR__ . '/src/dependencies.php';
    $dependencies($container);
    
    // 获取数据库连接
    $db = $container->get(\CarbonTrack\Services\DatabaseService::class)->getConnection()->getPdo();
    
    echo "1. 测试直接 SQL 插入...\n";
    $stmt = $db->prepare("INSERT INTO schools (name, location, is_active) VALUES (?, ?, ?)");
    $result = $stmt->execute(['测试学校直接插入', '测试地址', 1]);
    
    if ($result) {
        $schoolId = $db->lastInsertId();
        echo "✓ 直接 SQL 插入成功，学校 ID: $schoolId\n";
        
        // 查询验证
        $stmt = $db->prepare("SELECT * FROM schools WHERE id = ?");
        $stmt->execute([$schoolId]);
        $school = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "  学校信息: " . json_encode($school, JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "✗ 直接 SQL 插入失败\n";
    }
    
    echo "\n2. 测试 Eloquent 模型创建...\n";
    
    // 创建学校模型实例
    $school = new \CarbonTrack\Models\School();
    $newSchool = $school->create([
        'name' => '测试学校Eloquent',
        'location' => 'Eloquent测试地址',
        'is_active' => true
    ]);
    
    if ($newSchool) {
        echo "✓ Eloquent 模型创建成功，学校 ID: {$newSchool->id}\n";
        echo "  学校信息: " . json_encode($newSchool->toArray(), JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "✗ Eloquent 模型创建失败\n";
    }
    
    echo "\n3. 测试学校 API 创建功能...\n";
    
    // 模拟 schoolAPI.createOrFetchSchool 的逻辑
    $schoolName = '测试学校API';
    
    // 首先检查是否已存在
    $existing = \CarbonTrack\Models\School::where('name', 'LIKE', "%{$schoolName}%")->first();
    
    if ($existing) {
        echo "✓ 找到现有学校: {$existing->name} (ID: {$existing->id})\n";
    } else {
        // 创建新学校
        $apiSchool = \CarbonTrack\Models\School::create([
            'name' => $schoolName,
            'location' => 'API测试地址',
            'is_active' => true
        ]);
        
        if ($apiSchool) {
            echo "✓ API 创建学校成功，学校 ID: {$apiSchool->id}\n";
            echo "  学校信息: " . json_encode($apiSchool->toArray(), JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "✗ API 创建学校失败\n";
        }
    }
    
    echo "\n4. 查看所有学校...\n";
    $allSchools = \CarbonTrack\Models\School::all();
    echo "总共 {$allSchools->count()} 所学校:\n";
    foreach ($allSchools as $school) {
        echo "  - ID: {$school->id}, 名称: {$school->name}, 地址: {$school->location}, 活跃: " . ($school->is_active ? '是' : '否') . "\n";
    }
    
    echo "\n🎉 学校创建功能测试完成！\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    echo "错误详情: " . $e->getTraceAsString() . "\n";
    exit(1);
}