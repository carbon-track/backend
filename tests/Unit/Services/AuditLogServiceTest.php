<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Support\DatabaseRows;
use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use CarbonTrack\Tests\Support\DatabaseFixture;
use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\AuditLogService;

class AuditLogServiceTest extends TestCase
{
    private mixed $previousDisableAuditWrites = null;
    private mixed $previousDisableAuditWritesServer = null;
    private mixed $previousAppEnv = null;
    private mixed $previousAppEnvServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDisableAuditWrites = $_ENV['DISABLE_AUDIT_LOG_WRITES'] ?? null;
        $this->previousDisableAuditWritesServer = $_SERVER['DISABLE_AUDIT_LOG_WRITES'] ?? null;
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->previousAppEnvServer = $_SERVER['APP_ENV'] ?? null;
        unset($_ENV['DISABLE_AUDIT_LOG_WRITES']);
        unset($_SERVER['DISABLE_AUDIT_LOG_WRITES']);
        $_ENV['APP_ENV'] = 'development';
        $_SERVER['APP_ENV'] = 'development';
    }

    protected function tearDown(): void
    {
        if ($this->previousDisableAuditWrites === null) {
            unset($_ENV['DISABLE_AUDIT_LOG_WRITES']);
        } else {
            $_ENV['DISABLE_AUDIT_LOG_WRITES'] = $this->previousDisableAuditWrites;
        }
        if ($this->previousDisableAuditWritesServer === null) {
            unset($_SERVER['DISABLE_AUDIT_LOG_WRITES']);
        } else {
            $_SERVER['DISABLE_AUDIT_LOG_WRITES'] = $this->previousDisableAuditWritesServer;
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

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(AuditLogService::class));
    }

    public function testLogUserActionInsertsAndLogs(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->method('bindings')->willReturn(true);
        $connection->method('responseFor')->willReturn($stmt);
        $logger->expects($this->once())->method('info');

        $svc = new AuditLogService($connection, $logger);
        $svc->logUserAction(1, 'login', ['ip'=>'127.0.0.1'], '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testGetUserLogsReturnsArray(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $logger = $this->createMock(\Monolog\Logger::class);
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id'=>1,'user_id'=>1,'action'=>'login']
        ]);
        $connection->method('responseFor')->willReturn($stmt);

        $svc = new AuditLogService($connection, $logger);
        $logs = $svc->getUserLogs(1, 10);
        $this->assertCount(1, $logs);
        $this->assertEquals('login', $logs[0]['action']);
    }

    public function testLogSystemEventPersistsNullUserIdForSystemActor(): void
    {
        $connection = DatabaseFixture::sqlite();
        $connection->unprepared(
            "CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NULL,
                user_uuid TEXT NULL,
                conversation_id TEXT NULL,
                actor_type TEXT NOT NULL,
                action TEXT NOT NULL,
                data TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                request_method TEXT NULL,
                endpoint TEXT NULL,
                old_data TEXT NULL,
                new_data TEXT NULL,
                affected_table TEXT NULL,
                affected_id INTEGER NULL,
                status TEXT NULL,
                response_code INTEGER NULL,
                session_id TEXT NULL,
                referrer TEXT NULL,
                operation_category TEXT NOT NULL,
                operation_subtype TEXT NULL,
                change_type TEXT NULL,
                request_id TEXT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $service = new AuditLogService($connection, new Logger('test'));

        $this->assertTrue($service->logSystemEvent('statistics_public_computed', 'statistics_service', [
            'status' => 'success',
            'request_method' => 'SYSTEM',
            'endpoint' => '/internal/statistics',
            'request_data' => ['force_refresh' => false],
        ]));

        $row = DatabaseRows::one($connection->selectOne('SELECT user_id, actor_type, action, operation_category FROM audit_logs LIMIT 1'));

        $this->assertIsArray($row);
        $this->assertNull($row['user_id']);
        $this->assertSame('system', $row['actor_type']);
        $this->assertSame('statistics_public_computed', $row['action']);
        $this->assertSame('statistics_service', $row['operation_category']);
    }

    public function testLogAdminReadStoresPathAndScopeWithRequestId(): void
    {
        $connection = DatabaseFixture::sqlite();
        TestSchemaBuilder::init($connection);
        $service = new AuditLogService($connection, new Logger('test'));

        $request = makeRequest('GET', '/api/v1/admin/users/10/overview')
            ->withAttribute('request_id', 'admin-read-fixture');
        $request = $request->withUri($request->getUri()->withQuery('search=owner@example.test'));

        $this->assertTrue($service->logAdminRead($request, 'admin_user_overview_viewed', 1, 'users', [], 10));
        $row = (array) $connection->table('audit_logs')->where('request_id', 'admin-read-fixture')->first();
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('admin', $row['actor_type']);
        $this->assertSame('admin_user_overview_viewed', $row['action']);
        $this->assertSame('success', $row['status']);
        $this->assertSame('read', $row['change_type']);
        $this->assertSame('users', $row['affected_table']);
        $this->assertSame(10, (int) $row['affected_id']);
        $this->assertSame('GET', $row['request_method']);
        $this->assertSame('/api/v1/admin/users/10/overview', $row['endpoint']);
        $this->assertSame([], json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLogAuditSkipsInsertWhenWritesDisabled(): void
    {
        $_ENV['DISABLE_AUDIT_LOG_WRITES'] = 'true';
        $_SERVER['DISABLE_AUDIT_LOG_WRITES'] = 'true';

        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $connection->expects($this->never())->method('responseFor');

        $service = new AuditLogService($connection, $this->createMock(\Monolog\Logger::class));

        $this->assertFalse($service->log([
            'action' => 'register',
            'operation_category' => 'authentication',
        ]));
    }

    public function testProductionEnvironmentIgnoresDisableFlag(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $_ENV['DISABLE_AUDIT_LOG_WRITES'] = 'true';
        $_SERVER['DISABLE_AUDIT_LOG_WRITES'] = 'true';

        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['generatedKey', 'responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->method('bindings')->willReturn(true);
        $connection->expects($this->once())->method('responseFor')->willReturn($stmt);
        $connection->method('generatedKey')->willReturn('1');

        $service = new AuditLogService($connection, $this->createMock(\Monolog\Logger::class));

        $this->assertTrue($service->log([
            'action' => 'register',
            'operation_category' => 'authentication',
        ]));
    }
}


