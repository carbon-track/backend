<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load test environment variables
if (file_exists(__DIR__ . '/../.env.testing')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.testing');
    $dotenv->load();
} elseif (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Set test environment variables if not already set
$_ENV['APP_ENV'] = $_ENV['APP_ENV'] ?? 'testing';
$_ENV['DB_DATABASE'] = $_ENV['DB_DATABASE'] ?? 'carbontrack_test';
$_ENV['JWT_SECRET'] = $_ENV['JWT_SECRET'] ?? 'test-jwt-secret-key';
$_ENV['JWT_EXPIRES_IN'] = $_ENV['JWT_EXPIRES_IN'] ?? '3600';

// Disable error reporting for cleaner test output
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

// Set timezone
date_default_timezone_set('UTC');

// Helper to create a Slim PSR-7 Request with sane defaults
if (!function_exists('makeRequest')) {
    function makeRequest(string $method, string $path, array $parsedBody = null, array $queryParams = null, array $headers = []): \Slim\Psr7\Request {
        $uri = new \Slim\Psr7\Uri('http', 'localhost', null, $path);
        $slimHeaders = new \Slim\Psr7\Headers($headers);
        $serverParams = [];
        $stream = new \Slim\Psr7\Stream(fopen('php://temp', 'r+'));
        $request = new \Slim\Psr7\Request($method, $uri, $slimHeaders, [], $serverParams, $stream);
        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }
        if ($queryParams !== null) {
            $request = $request->withQueryParams($queryParams);
        }
        return $request;
    }
}

