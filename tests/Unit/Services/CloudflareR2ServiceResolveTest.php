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
}
