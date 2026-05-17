<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

class SensitiveDataRedactorTest extends TestCase
{
    public function testRedactWalksNestedArraysAndPreservesNonSensitiveValues(): void
    {
        $payload = [
            'username' => 'alice',
            'password' => 'p@ss',
            'profile' => [
                'email' => 'alice@example.com',
                'reset_token' => 'rt-secret',
                'meta' => [
                    'verification_code' => '123456',
                    'safe' => 'visible',
                ],
            ],
            'tokens' => [
                ['access_token' => 'at1', 'expires_in' => 3600],
                ['refresh_token' => 'rt2'],
            ],
        ];

        $sanitized = SensitiveDataRedactor::redact($payload);

        $this->assertSame('alice', $sanitized['username']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['password']);
        $this->assertSame('alice@example.com', $sanitized['profile']['email']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['profile']['reset_token']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['profile']['meta']['verification_code']);
        $this->assertSame('visible', $sanitized['profile']['meta']['safe']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['tokens'][0]['access_token']);
        $this->assertSame(3600, $sanitized['tokens'][0]['expires_in']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['tokens'][1]['refresh_token']);
    }

    public function testRedactCoversAllNewSensitiveFieldNamesFromAuditFinding(): void
    {
        // Field names that audit B-102 / B-205 explicitly required to be added.
        $shouldBeRedacted = [
            'current_password' => 'old',
            'new_password' => 'new',
            'confirm_password' => 'new',
            'verification_token' => 'vt',
            'cf_turnstile_response' => 'tk',
            'pow_nonce' => '123',
            'pow_challenge' => 'abc',
            'mobile_client_token' => 'mobile-secret',
            'x-mobile-client-token' => 'mobile-secret',
            'otp' => '000000',
            'code' => '654321',
            'x-cron-key' => 'k1',
            'x-sla-sweep-key' => 'k2',
        ];

        $sanitized = SensitiveDataRedactor::redact($shouldBeRedacted);

        foreach (array_keys($shouldBeRedacted) as $key) {
            $this->assertSame(
                SensitiveDataRedactor::REDACTED,
                $sanitized[$key],
                sprintf('Expected key "%s" to be redacted', $key)
            );
        }
    }

    public function testRedactPassesThroughScalarsAndNull(): void
    {
        $this->assertNull(SensitiveDataRedactor::redact(null));
        $this->assertSame(42, SensitiveDataRedactor::redact(42));
        $this->assertSame('plain', SensitiveDataRedactor::redact('plain'));
        $this->assertTrue(SensitiveDataRedactor::redact(true));
    }

    public function testRedactServerStripsAuthorizationTurnstileCronAndDebugHeaders(): void
    {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'HTTP_AUTHORIZATION' => 'Bearer xyz',
            'HTTP_COOKIE' => 'a=b',
            'HTTP_X_TURNSTILE_TOKEN' => 'ttok',
            'HTTP_X_MOBILE_CLIENT_TOKEN' => 'mobile-secret',
            'HTTP_X_CRON_KEY' => 'ckey',
            'HTTP_X_SLA_SWEEP_KEY' => 'skey',
            'HTTP_X_DEBUG_USER' => 'admin',
            'HTTP_X_DEBUG_TOKEN' => 'dbg',
            'PHP_AUTH_PW' => 'pw',
            'HTTP_USER_AGENT' => 'pytest/1.0',
        ];

        $filtered = SensitiveDataRedactor::redactServer($server);

        $this->assertSame('POST', $filtered['REQUEST_METHOD']);
        $this->assertSame('pytest/1.0', $filtered['HTTP_USER_AGENT']);
        foreach ([
            'HTTP_AUTHORIZATION',
            'HTTP_COOKIE',
            'HTTP_X_TURNSTILE_TOKEN',
            'HTTP_X_MOBILE_CLIENT_TOKEN',
            'HTTP_X_CRON_KEY',
            'HTTP_X_SLA_SWEEP_KEY',
            'HTTP_X_DEBUG_USER',
            'HTTP_X_DEBUG_TOKEN',
            'PHP_AUTH_PW',
        ] as $key) {
            $this->assertSame(
                SensitiveDataRedactor::REDACTED,
                $filtered[$key],
                sprintf('Expected server key "%s" to be redacted', $key)
            );
        }
    }

    public function testIsSensitiveKeyIsCaseInsensitiveAndIgnoresWhitespace(): void
    {
        $this->assertTrue(SensitiveDataRedactor::isSensitiveKey('PASSWORD'));
        $this->assertTrue(SensitiveDataRedactor::isSensitiveKey('  Token  '));
        $this->assertFalse(SensitiveDataRedactor::isSensitiveKey('username'));
    }

    public function testRedactHandlesCircularObjectsWithoutRecursingForever(): void
    {
        $first = new \stdClass();
        $second = new \stdClass();
        $first->name = 'root';
        $first->child = $second;
        $second->parent = $first;
        $second->token = 'secret';

        $sanitized = SensitiveDataRedactor::redact($first);

        $this->assertSame('root', $sanitized['name']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['child']['token']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['child']['parent']);
    }

    public function testRedactDetectsSensitivePrivateAndProtectedObjectProperties(): void
    {
        $payload = new class {
            private string $password = 'private-secret';
            protected string $refresh_token = 'protected-secret';
            public string $username = 'visible-user';
        };

        $sanitized = SensitiveDataRedactor::redact($payload);

        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['password']);
        $this->assertSame(SensitiveDataRedactor::REDACTED, $sanitized['refresh_token']);
        $this->assertSame('visible-user', $sanitized['username']);
    }
}
