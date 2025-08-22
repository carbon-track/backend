<?php

declare(strict_types=1);

echo "=== 路由冲突检查 ===\n\n";

// 模拟路由注册过程来检查冲突
$routes = [];

function addRoute($method, $path, $description) {
    global $routes;
    
    // 检查路径是否与现有路由冲突
    foreach ($routes as $existingRoute) {
        if (conflicts($existingRoute['path'], $path)) {
            echo "⚠️  路由冲突检测到:\n";
            echo "   现有路由: {$existingRoute['method']} {$existingRoute['path']} ({$existingRoute['description']})\n";
            echo "   新路由: {$method} {$path} ({$description})\n";
            echo "   建议: 将更具体的路由放在更通用的路由之前\n\n";
        }
    }
    
    $routes[] = [
        'method' => $method,
        'path' => $path,
        'description' => $description
    ];
}

function conflicts($path1, $path2) {
    // 简单的冲突检测逻辑
    // 如果两个路径在某个点上有冲突，返回true
    
    // 将路径分割成段
    $segments1 = explode('/', trim($path1, '/'));
    $segments2 = explode('/', trim($path2, '/'));
    
    $minLength = min(count($segments1), count($segments2));
    
    for ($i = 0; $i < $minLength; $i++) {
        $seg1 = $segments1[$i];
        $seg2 = $segments2[$i];
        
        // 如果两个段都包含变量，可能冲突
        if (strpos($seg1, '{') !== false && strpos($seg2, '{') !== false) {
            return true;
        }
        
        // 如果一个段是静态的，另一个是变量，且静态段在变量段之后，可能冲突
        if (strpos($seg1, '{') === false && strpos($seg2, '{') !== false) {
            if ($i === count($segments1) - 1) {
                // 静态路径是完整路径，变量路径可能匹配它
                return true;
            }
        }
        
        // 如果两个段都是静态的且不同，则不会冲突
        if (strpos($seg1, '{') === false && strpos($seg2, '{') === false && $seg1 !== $seg2) {
            break;
        }
    }
    
    return false;
}

// 模拟路由注册
echo "检查路由冲突...\n\n";

// 根路径
addRoute('GET', '/', '健康检查');

// API v1 路由
addRoute('GET', '/api/v1', 'API根路径');

// Auth 路由
addRoute('POST', '/api/v1/auth/register', '用户注册');
addRoute('POST', '/api/v1/auth/login', '用户登录');

// User 路由
addRoute('GET', '/api/v1/users/me', '获取当前用户');
addRoute('PUT', '/api/v1/users/me', '更新当前用户');
addRoute('GET', '/api/v1/users/{id}', '获取用户');

// Avatar 路由
addRoute('GET', '/api/v1/avatars', '获取头像列表');
addRoute('GET', '/api/v1/avatars/categories', '获取头像分类');

// Carbon activities 路由
addRoute('GET', '/api/v1/carbon-activities', '获取碳活动列表');
addRoute('GET', '/api/v1/carbon-activities/{id}', '获取碳活动详情');

// Carbon tracking 路由
addRoute('POST', '/api/v1/carbon-track/calculate', '计算碳节省');
addRoute('GET', '/api/v1/carbon-track/transactions', '获取用户记录');
addRoute('GET', '/api/v1/carbon-track/transactions/{id}', '获取记录详情');

// Admin 路由
addRoute('GET', '/api/v1/admin/users', '获取用户列表');
addRoute('PUT', '/api/v1/admin/users/{id}', '更新用户');
addRoute('DELETE', '/api/v1/admin/users/{id}', '删除用户');

// Carbon activities management (admin)
addRoute('GET', '/api/v1/admin/carbon-activities', '获取碳活动列表(管理员)');
addRoute('POST', '/api/v1/admin/carbon-activities', '创建碳活动');
addRoute('GET', '/api/v1/admin/carbon-activities/statistics', '获取碳活动统计');
addRoute('PUT', '/api/v1/admin/carbon-activities/sort-orders', '更新排序');
addRoute('PUT', '/api/v1/admin/carbon-activities/{id}', '更新碳活动');
addRoute('DELETE', '/api/v1/admin/carbon-activities/{id}', '删除碳活动');
addRoute('POST', '/api/v1/admin/carbon-activities/{id}/restore', '恢复碳活动');
addRoute('GET', '/api/v1/admin/carbon-activities/{id}/statistics', '获取单个活动统计');

// Avatar management (admin)
addRoute('GET', '/api/v1/admin/avatars', '获取头像列表(管理员)');
addRoute('POST', '/api/v1/admin/avatars', '创建头像');
addRoute('PUT', '/api/v1/admin/avatars/sort-orders', '更新头像排序');
addRoute('GET', '/api/v1/admin/avatars/usage-stats', '获取头像使用统计');
addRoute('POST', '/api/v1/admin/avatars/upload', '上传头像文件');
addRoute('GET', '/api/v1/admin/avatars/{id}', '获取头像详情');
addRoute('PUT', '/api/v1/admin/avatars/{id}', '更新头像');
addRoute('DELETE', '/api/v1/admin/avatars/{id}', '删除头像');
addRoute('POST', '/api/v1/admin/avatars/{id}/restore', '恢复头像');
addRoute('PUT', '/api/v1/admin/avatars/{id}/set-default', '设置默认头像');

echo "✅ 路由冲突检查完成！\n";
echo "如果上面显示了冲突警告，请按照建议调整路由顺序。\n";
echo "一般原则：将更具体的静态路由放在更通用的变量路由之前。\n";
