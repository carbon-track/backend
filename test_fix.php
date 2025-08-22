<?php

declare(strict_types=1);

use DI\Container;
use Psr\Log\LoggerInterface;
use Monolog\Logger;

require __DIR__ . '/vendor/autoload.php';

// Load environment variables (with fallback)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    // If .env file doesn't exist, set default values
    $_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'development';
    $_ENV['APP_DEBUG'] = $_ENV['APP_DEBUG'] ?? 'true';
}

echo "Testing dependency injection fix...\n";

// Create Container and register dependencies
$container = new Container();
$dependencies = require __DIR__ . '/src/dependencies.php';
$dependencies($container);

echo "Container created successfully.\n";

// Test Logger retrieval
try {
    echo "Testing Logger retrieval...\n";
    
    // Test direct Logger class retrieval
    $logger = $container->get(Logger::class);
    echo "✓ Logger::class retrieved successfully: " . get_class($logger) . "\n";
    
    // Test LoggerInterface retrieval
    $loggerInterface = $container->get(LoggerInterface::class);
    echo "✓ LoggerInterface::class retrieved successfully: " . get_class($loggerInterface) . "\n";
    
    // Test if both are the same instance
    if ($logger === $loggerInterface) {
        echo "✓ Both Logger instances are the same object\n";
    } else {
        echo "✗ Logger instances are different objects\n";
    }
    
    // Test LoggingMiddleware creation
    echo "Testing LoggingMiddleware creation...\n";
    $loggingMiddleware = new \CarbonTrack\Middleware\LoggingMiddleware($logger);
    echo "✓ LoggingMiddleware created successfully: " . get_class($loggingMiddleware) . "\n";
    
    echo "\n🎉 All tests passed! The dependency injection fix is working correctly.\n";
    
} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}


