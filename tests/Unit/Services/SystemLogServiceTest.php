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
    private mixed $previousDisableSystemWrites = null;
    private mixed $previousDisableSystemWritesServer = null;
    private mixed $previousAppEnv = null;
    private mixed $previousAppEnvServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER ?? [];
        $this->previousDisableSystemWrites = $_ENV['DISABLE_SYSTEM_LOG_WRITES'] ?? null;
        $this->previousDisableSystemWritesServer = $_SERVER['DISABLE_SYSTEM_LOG_WRITES'] ?? null;
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->previousAppEnvServer = $_SERVER['APP_ENV'] ?? null;
        unset($_ENV['DISABLE_SYSTEM_LOG_WRITES']);
        unset($_SERVER['DISABLE_SYSTEM_LOG_WRITES']);
        $_ENV['APP_ENV'] = 'development';
        $_SERVER['APP_ENV'] = 'development';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        if ($this->previousDisableSystemWrites === null) {
            unset($_ENV['DISABLE_SYSTEM_LOG_WRITES']);
        } else {
            $_ENV['DISABLE_SYSTEM_LOG_WRITES'] = $this->previousDisableSystemWrites;
        }
        if ($this->previousDisableSystemWritesServer === null) {
            unset($_SERVER['DISABLE_SYSTEM_LOG_WRITES']);
        } else {
            $_SERVER['DISABLE_SYSTEM_LOG_WRITES'] = $this->previousDisableSystemWritesServer;
        }
        if ($this->previousAppEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        }
        if ($this->previousAppEnvServer === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $this->previousAppEnvServer;
        }
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

    public function testLogReturnsNullWhenWritesDisabled(): void
    {
        $_ENV['DISABLE_SYSTEM_LOG_WRITES'] = 'true';
        $_SERVER['DISABLE_SYSTEM_LOG_WRITES'] = 'true';

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $service = new SystemLogService($pdo, new Logger('test'));

        $result = $service->log([
            'request_id' => 'req-1',
            'method' => 'GET',
            'path' => '/api/test',
        ]);

        $this->assertNull($result);
    }

    public function testProductionEnvironmentIgnoresDisableFlag(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $_ENV['DISABLE_SYSTEM_LOG_WRITES'] = 'true';
        $_SERVER['DISABLE_SYSTEM_LOG_WRITES'] = 'true';

        $pdo = $this->createMock(PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $service = new SystemLogService($pdo, new Logger('test'));

        $result = $service->log([
            'request_id' => 'req-1',
            'method' => 'GET',
            'path' => '/api/test',
        ]);

        $this->assertSame(1, $result);
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
