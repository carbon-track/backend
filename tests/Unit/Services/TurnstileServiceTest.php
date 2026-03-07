<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\TurnstileService;

class TurnstileServiceTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(TurnstileService::class));
    }

    public function testVerifyWithEmptyToken(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousBypass = $_ENV['TURNSTILE_BYPASS'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        $_ENV['TURNSTILE_BYPASS'] = 'false';

        $logger = $this->createMock(\Monolog\Logger::class);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['action'] ?? null) === 'turnstile_verification_missing_token'
                    && ($payload['operation_category'] ?? null) === 'security';
            }))
            ->willReturn(true);

        try {
            $svc = new TurnstileService('secret', $logger, $audit, $this->createMock(ErrorLogService::class));
            $res = $svc->verify('');
            $this->assertFalse($res['success']);
            $this->assertEquals('missing-input-response', $res['error']);
        } finally {
            if ($previousAppEnv !== null) {
                $_ENV['APP_ENV'] = $previousAppEnv;
            } else {
                unset($_ENV['APP_ENV']);
            }

            if ($previousBypass !== null) {
                $_ENV['TURNSTILE_BYPASS'] = $previousBypass;
            } else {
                unset($_ENV['TURNSTILE_BYPASS']);
            }
        }
    }
}


