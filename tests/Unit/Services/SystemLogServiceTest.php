<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\SystemLogService;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

class SystemLogServiceTest extends TestCase
{
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER ?? [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        parent::tearDown();
    }

    public function testSummaryUsesContextValues(): void
    {
        $service = $this->makeService();
        $_SERVER = [];

        $metaJson = $this->invokeBuildServerMeta($service, ['HTTP_AUTHORIZATION' => 'secret-token'], [
            'method' => 'POST',
            'path' => '/api/system/test',
            'ip_address' => '198.51.100.2',
        ]);

        $meta = json_decode($metaJson, true);
        $this->assertIsArray($meta);
        $this->assertSame('[REDACTED]', $meta['HTTP_AUTHORIZATION']);
        $this->assertSame('POST', $meta['_summary']['method']);
        $this->assertSame('/api/system/test', $meta['_summary']['uri']);
        $this->assertSame('198.51.100.2', $meta['_summary']['ip']);
    }

    public function testSummaryFallsBackToServerGlobalsWithCloudflareIpPreference(): void
    {
        $service = $this->makeService();
        $_SERVER = [
            'HTTP_CF_CONNNECTING_IP' => '203.0.113.9',
            'REQUEST_METHOD' => 'DELETE',
            'REQUEST_URI' => '/from-global',
        ];

        $metaJson = $this->invokeBuildServerMeta($service, [], []);
        $meta = json_decode($metaJson, true);

        $this->assertIsArray($meta);
        $this->assertSame('DELETE', $meta['_summary']['method']);
        $this->assertSame('/from-global', $meta['_summary']['uri']);
        $this->assertSame('203.0.113.9', $meta['_summary']['ip']);
    }

    public function testSummaryUsesRemoteAddrWhenNoCloudflareHeaders(): void
    {
        $service = $this->makeService();
        $_SERVER = [];

        $metaJson = $this->invokeBuildServerMeta($service, ['REMOTE_ADDR' => '192.0.2.44'], []);
        $meta = json_decode($metaJson, true);

        $this->assertIsArray($meta);
        $this->assertSame('192.0.2.44', $meta['_summary']['ip']);
    }

    private function makeService(): SystemLogService
    {
        $pdo = new PDO('sqlite::memory:');
        $logger = new Logger('test');
        return new SystemLogService($pdo, $logger);
    }

    private function invokeBuildServerMeta(SystemLogService $service, array $server, array $context): string
    {
        $ref = new \ReflectionClass(SystemLogService::class);
        $method = $ref->getMethod('buildServerMeta');
        $method->setAccessible(true);

        /** @var string $json */
        $json = $method->invoke($service, $server, $context);
        return $json;
    }
}
