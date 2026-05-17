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

    public function testProductionRefusesPlaceholderSecret(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousAllow = $_ENV['ALLOW_TURNSTILE_BYPASS'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        $_ENV['ALLOW_TURNSTILE_BYPASS'] = 'false';

        try {
            $logger = $this->createMock(\Monolog\Logger::class);
            $service = new TurnstileService('your-turnstile-secret-key', $logger);
            $result = $service->verify('any-token', '127.0.0.1');

            $this->assertFalse($result['success']);
            $this->assertSame('secret_unconfigured', $result['error']);
        } finally {
            $previousAppEnv === null ? null : ($_ENV['APP_ENV'] = $previousAppEnv);
            if ($previousAppEnv === null) {
                unset($_ENV['APP_ENV']);
            }
            $previousAllow === null ? null : ($_ENV['ALLOW_TURNSTILE_BYPASS'] = $previousAllow);
            if ($previousAllow === null) {
                unset($_ENV['ALLOW_TURNSTILE_BYPASS']);
            }
        }
    }

    public function testProductionIgnoresAllowBypassFlag(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousAllow = $_ENV['ALLOW_TURNSTILE_BYPASS'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        $_ENV['ALLOW_TURNSTILE_BYPASS'] = 'true';

        try {
            $logger = $this->createMock(\Monolog\Logger::class);
            $service = new TurnstileService('a-real-looking-secret', $logger);
            $result = $service->verify('', '127.0.0.1');
            // Empty token on production must still go through the full code path
            // (returns missing-input-response) rather than bypassing successfully.
            $this->assertFalse($result['success']);
            $this->assertSame('missing-input-response', $result['error']);
        } finally {
            if ($previousAppEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousAppEnv;
            }
            if ($previousAllow === null) {
                unset($_ENV['ALLOW_TURNSTILE_BYPASS']);
            } else {
                $_ENV['ALLOW_TURNSTILE_BYPASS'] = $previousAllow;
            }
        }
    }

    public function testNonProductionBypassRequiresAllowFlag(): void
    {
        $previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $previousAllow = $_ENV['ALLOW_TURNSTILE_BYPASS'] ?? null;
        $previousLegacy = $_ENV['TURNSTILE_BYPASS'] ?? null;
        $_ENV['APP_ENV'] = 'staging';
        $_ENV['ALLOW_TURNSTILE_BYPASS'] = 'true';
        unset($_ENV['TURNSTILE_BYPASS']);

        try {
            $logger = $this->createMock(\Monolog\Logger::class);
            $service = new TurnstileService('staging-secret', $logger);
            $result = $service->verify('any-token', '127.0.0.1');
            $this->assertTrue($result['success']);
            $this->assertTrue($result['bypassed'] ?? false);
        } finally {
            if ($previousAppEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $previousAppEnv;
            }
            if ($previousAllow === null) {
                unset($_ENV['ALLOW_TURNSTILE_BYPASS']);
            } else {
                $_ENV['ALLOW_TURNSTILE_BYPASS'] = $previousAllow;
            }
            if ($previousLegacy !== null) {
                $_ENV['TURNSTILE_BYPASS'] = $previousLegacy;
            }
        }
    }

    public function testApplyCertificateOptionsAddsConfiguredCaBundleAndNativeStore(): void
    {
        $logger = $this->createMock(\Monolog\Logger::class);
        $service = new TurnstileService(
            'secret',
            $logger,
            null,
            null,
            'C:\\certs\\cacert.pem',
            true
        );

        $method = new \ReflectionMethod(TurnstileService::class, 'applyCertificateOptions');
        $method->setAccessible(true);

        $options = [];
        $method->invokeArgs($service, [&$options]);

        $this->assertSame('C:\\certs\\cacert.pem', $options[CURLOPT_CAINFO]);

        if (\defined('CURLOPT_SSL_OPTIONS') && \defined('CURLSSLOPT_NATIVE_CA')) {
            $this->assertSame(CURLSSLOPT_NATIVE_CA, $options[CURLOPT_SSL_OPTIONS]);
        }
    }
}


