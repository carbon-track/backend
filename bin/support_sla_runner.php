<?php

declare(strict_types=1);

use CarbonTrack\Services\SupportRoutingEngineService;
use DI\Container;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    if (method_exists($dotenv, 'safeLoad')) {
        $dotenv->safeLoad();
    } else {
        $dotenv->load();
    }
} catch (Throwable) {
    // Ignore environment bootstrap failures and continue with defaults.
}

$container = new Container();
$dependencies = require __DIR__ . '/../src/dependencies.php';
$dependencies($container);

/** @var SupportRoutingEngineService $engine */
$engine = $container->get(SupportRoutingEngineService::class);
$result = $engine->runSlaSweep();

fwrite(STDOUT, json_encode([
    'success' => true,
    'data' => $result,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

exit(0);
