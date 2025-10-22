<?php

declare(strict_types=1);

use CarbonTrack\Jobs\EmailJobRunner;
use CarbonTrack\Services\EmailService;
use Dotenv\Dotenv;
use DI\Container;
use Monolog\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

$jobFile = $argv[1] ?? null;
if ($jobFile === null || !is_file($jobFile)) {
    fwrite(STDERR, "Missing email job payload file.\n");
    exit(1);
}

$rawPayload = file_get_contents($jobFile);
@unlink($jobFile);
if ($rawPayload === false) {
    fwrite(STDERR, "Unable to read email job payload.\n");
    exit(1);
}

$jobData = json_decode($rawPayload, true);
if (!is_array($jobData)) {
    fwrite(STDERR, "Invalid email job payload.\n");
    exit(1);
}

try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    if (method_exists($dotenv, 'safeLoad')) {
        $dotenv->safeLoad();
    } else {
        $dotenv->load();
    }
} catch (Throwable $e) {
    // Ignore failures to load environment; defaults will be used.
}

$container = new Container();
$dependencies = require __DIR__ . '/../src/dependencies.php';
$dependencies($container);

/** @var EmailService $emailService */
$emailService = $container->get(EmailService::class);
/** @var Logger $logger */
$logger = $container->get(Logger::class);

$jobType = (string) ($jobData['job_type'] ?? '');
$payload = is_array($jobData['payload'] ?? null) ? $jobData['payload'] : [];

EmailJobRunner::run($emailService, $logger, $jobType, $payload);

exit(0);

