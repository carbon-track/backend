<?php

declare(strict_types=1);

echo "设置日志目录和权限...\n";

$logDir = __DIR__ . '/logs';
$logFile = $logDir . '/app.log';

echo "日志目录: {$logDir}\n";
echo "日志文件: {$logFile}\n";

// 创建日志目录
if (!is_dir($logDir)) {
    echo "创建日志目录...\n";
    if (mkdir($logDir, 0755, true)) {
        echo "✓ 日志目录创建成功\n";
    } else {
        echo "✗ 无法创建日志目录\n";
        exit(1);
    }
} else {
    echo "✓ 日志目录已存在\n";
}

// 检查目录权限
if (is_writable($logDir)) {
    echo "✓ 日志目录可写\n";
} else {
    echo "✗ 日志目录不可写，尝试修改权限...\n";
    if (chmod($logDir, 0755)) {
        echo "✓ 权限修改成功\n";
    } else {
        echo "✗ 无法修改权限\n";
        exit(1);
    }
}

// 创建日志文件
if (!file_exists($logFile)) {
    echo "创建日志文件...\n";
    if (touch($logFile)) {
        echo "✓ 日志文件创建成功\n";
    } else {
        echo "✗ 无法创建日志文件\n";
        exit(1);
    }
} else {
    echo "✓ 日志文件已存在\n";
}

// 设置文件权限
if (chmod($logFile, 0644)) {
    echo "✓ 文件权限设置成功\n";
} else {
    echo "✗ 无法设置文件权限\n";
}

// 测试写入
echo "测试写入权限...\n";
$testContent = "测试日志写入 - " . date('Y-m-d H:i:s') . "\n";
if (file_put_contents($logFile, $testContent, FILE_APPEND | LOCK_EX) !== false) {
    echo "✓ 写入测试成功\n";
} else {
    echo "✗ 写入测试失败\n";
}

echo "\n🎉 日志设置完成！\n";
echo "现在可以重新启动应用程序了。\n";
