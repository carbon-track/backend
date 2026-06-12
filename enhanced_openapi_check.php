<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use CarbonTrack\Support\OpenApiContractChecker;

try {
    $checker = new OpenApiContractChecker(__DIR__);
    $result = $checker->check();
    fwrite(STDOUT, OpenApiContractChecker::renderReport($result));

    exit(!empty($result['ok']) ? 0 : 1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Enhanced OpenAPI check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
