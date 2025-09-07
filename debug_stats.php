<?php
require __DIR__ . '/vendor/autoload.php';
use DI\Container;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

$_ENV['APP_ENV'] = 'testing';

$container = new Container();
($dependencies = require __DIR__ . '/src/dependencies.php')($container);

$adminController = $container->get(\CarbonTrack\Controllers\AdminController::class);
$authService = $container->get(\CarbonTrack\Services\AuthService::class);

// Create an admin user directly in DB if not exists
$pdo = $container->get(PDO::class);
$pdo->exec("INSERT INTO users (id, username, email, password_hash, is_admin, status, points, created_at) VALUES (9999,'admin_debug','admin_debug@example.com','x',1,'active',0,datetime('now')) ON CONFLICT(id) DO NOTHING");

// Fake request with token header if needed (we bypass inside getStats by setting current user via AuthService expectations? Actually getCurrentUser will parse token.)
// Simulate token by generating via AuthService if method exists.
$token = method_exists($authService,'generateJwtToken') ? $authService->generateJwtToken(['id'=>9999,'is_admin'=>1,'email'=>'admin_debug@example.com']) : '';

$requestFactory = new ServerRequestFactory();
$request = $requestFactory->createServerRequest('GET','/api/v1/admin/stats');
if ($token) { $request = $request->withHeader('Authorization','Bearer '.$token); }
$response = (new ResponseFactory())->createResponse();

$response = $adminController->getStats($request,$response);

echo "Status: ".$response->getStatusCode()."\n";
echo (string)$response->getBody()."\n";
