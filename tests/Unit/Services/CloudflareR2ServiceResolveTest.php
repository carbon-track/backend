<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\AuditLogService;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class CloudflareR2ServiceResolveTest extends TestCase
{
    private function makeService(string $endpoint, string $bucket, ?string $publicUrl = null): CloudflareR2Service
    {
        $logger = new Logger('test');
        $auditLog = $this->createMock(AuditLogService::class);

        return new CloudflareR2Service(
            'test-access',
            'test-secret',
            $endpoint,
            $bucket,
            $publicUrl,
            $logger,
            $auditLog
        );
    }

    public function testResolveKeyFromDerivedPublicUrl(): void
    {
        $service = $this->makeService('https://example.r2.cloudflarestorage.com', 'media', 'https://pub-example.r2.dev/media');

        $key = $service->resolveKeyFromUrl('https://pub-example.r2.dev/media/badges/2025/icon.png');
        $this->assertSame('badges/2025/icon.png', $key);
    }

    public function testResolveKeyFromCustomEndpoint(): void
    {
        $service = $this->makeService('https://files.example.com', 'media', null);

        $key = $service->resolveKeyFromUrl('https://files.example.com/media/uploads/2025/01/avatar.png');
        $this->assertSame('uploads/2025/01/avatar.png', $key);
    }

    public function testResolveKeyFromRelativePath(): void
    {
        $service = $this->makeService('https://files.example.com', 'media');

        $key = $service->resolveKeyFromUrl('uploads/2025/01/icon.webp');
        $this->assertSame('uploads/2025/01/icon.webp', $key);
    }

    public function testResolveKeyWithQueryString(): void
    {
        $service = $this->makeService('https://example.r2.cloudflarestorage.com', 'media', 'https://pub-example.r2.dev/media');

        $key = $service->resolveKeyFromUrl('https://pub-example.r2.dev/media/badges/icon.png?signature=123');
        $this->assertSame('badges/icon.png', $key);
    }

    public function testProductionIgnoresDisableTlsVerifyFlag(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousDisable = $_ENV['R2_DISABLE_TLS_VERIFY'] ?? null;
        $_ENV['APP_ENV'] = 'production';
        $_ENV['R2_DISABLE_TLS_VERIFY'] = 'true';

        try {
            $service = $this->makeService('https://example.r2.cloudflarestorage.com', 'media');
            $property = new \ReflectionProperty(CloudflareR2Service::class, 'tlsVerify');
            $property->setAccessible(true);

            $this->assertTrue($property->getValue($service));
        } finally {
            $this->restoreEnvValue('APP_ENV', $previousEnv);
            $this->restoreEnvValue('R2_DISABLE_TLS_VERIFY', $previousDisable);
        }
    }

    public function testNonProductionCanDisableTlsVerifyForDiagnostics(): void
    {
        $previousEnv = $_ENV['APP_ENV'] ?? null;
        $previousDisable = $_ENV['R2_DISABLE_TLS_VERIFY'] ?? null;
        $_ENV['APP_ENV'] = 'development';
        $_ENV['R2_DISABLE_TLS_VERIFY'] = 'true';

        try {
            $service = $this->makeService('https://example.r2.cloudflarestorage.com', 'media');
            $property = new \ReflectionProperty(CloudflareR2Service::class, 'tlsVerify');
            $property->setAccessible(true);

            $this->assertFalse($property->getValue($service));
        } finally {
            $this->restoreEnvValue('APP_ENV', $previousEnv);
            $this->restoreEnvValue('R2_DISABLE_TLS_VERIFY', $previousDisable);
        }
    }

    private function restoreEnvValue(string $key, mixed $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            return;
        }

        $_ENV[$key] = $value;
    }
}
