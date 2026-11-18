<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\TurnstileService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

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

    /** @backupGlobals enabled */
    public function testVerifySendsFormDataWithConfiguredTlsTrust(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $history = [];
        $handler = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"success":true,"hostname":"fixture.example.test"}'),
        ]));
        $handler->push(Middleware::history($history));
        $service = new TurnstileService(
            'fixture-secret',
            $this->createMock(\Monolog\Logger::class),
            caBundlePath: 'fixture-ca.pem',
            useNativeCaStore: true,
            httpClient: new Client(['handler' => $handler])
        );

        $result = $service->verify('fixture-token', '198.51.100.27');

        $this->assertTrue($result['success']);
        $this->assertSame('fixture.example.test', $result['hostname']);
        $this->assertCount(1, $history);
        $sent = $history[0]['request'];
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', (string) $sent->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $sent->getHeaderLine('Content-Type'));
        parse_str((string) $sent->getBody(), $form);
        $this->assertSame([
            'secret' => 'fixture-secret',
            'response' => 'fixture-token',
            'remoteip' => '198.51.100.27',
        ], $form);
        $this->assertSame('fixture-ca.pem', $history[0]['options']['verify']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        if (\function_exists('curl_init') && \defined('CURLOPT_SSL_OPTIONS') && \defined('CURLSSLOPT_NATIVE_CA')) {
            $this->assertSame(CURLSSLOPT_NATIVE_CA, $history[0]['options']['curl'][CURLOPT_SSL_OPTIONS]);
        } else {
            $this->assertArrayNotHasKey('curl', $history[0]['options']);
        }
    }

    /** @backupGlobals enabled */
    public function testTransportFailureIsLoggedAndRejectsVerification(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $failure = new ConnectException('Connection timed out', new Request('POST', 'https://fixture.example.test'));
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$failure]))]);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool => $entry['action'] === 'turnstile_verification_network_failed'
                && $entry['status'] === 'failed'
        ))->willReturn(true);
        $errors = $this->createMock(ErrorLogService::class);
        $errors->expects($this->once())->method('logException')->with($this->identicalTo($failure));
        $service = new TurnstileService(
            'fixture-secret',
            $this->createMock(\Monolog\Logger::class),
            $audit,
            $errors,
            httpClient: $client
        );

        $result = $service->verify('fixture-token');

        $this->assertFalse($result['success']);
        $this->assertSame('network-error', $result['error']);
    }
}


