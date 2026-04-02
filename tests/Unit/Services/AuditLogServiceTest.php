<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

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
        $pdo = $this->createMock(\PDO::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);
        $logger->expects($this->once())->method('info');

        $svc = new AuditLogService($pdo, $logger);
        $svc->logUserAction(1, 'login', ['ip'=>'127.0.0.1'], '127.0.0.1');
        $this->assertTrue(true);
    }

    public function testGetUserLogsReturnsArray(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['id'=>1,'user_id'=>1,'action'=>'login']
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $svc = new AuditLogService($pdo, $logger);
        $logs = $svc->getUserLogs(1, 10);
        $this->assertCount(1, $logs);
        $this->assertEquals('login', $logs[0]['action']);
    }

    public function testLogSystemEventPersistsNullUserIdForSystemActor(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
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

        $service = new AuditLogService($pdo, new Logger('test'));

        $this->assertTrue($service->logSystemEvent('statistics_public_computed', 'statistics_service', [
            'status' => 'success',
            'request_method' => 'SYSTEM',
            'endpoint' => '/internal/statistics',
            'request_data' => ['force_refresh' => false],
        ]));

        $row = $pdo->query('SELECT user_id, actor_type, action, operation_category FROM audit_logs LIMIT 1')
            ?->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertNull($row['user_id']);
        $this->assertSame('system', $row['actor_type']);
        $this->assertSame('statistics_public_computed', $row['action']);
        $this->assertSame('statistics_service', $row['operation_category']);
    }

    public function testLogAuditSkipsInsertWhenWritesDisabled(): void
    {
        $_ENV['DISABLE_AUDIT_LOG_WRITES'] = 'true';
        $_SERVER['DISABLE_AUDIT_LOG_WRITES'] = 'true';

        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->never())->method('prepare');

        $service = new AuditLogService($pdo, $this->createMock(\Monolog\Logger::class));

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

        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $pdo->expects($this->once())->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('1');

        $service = new AuditLogService($pdo, $this->createMock(\Monolog\Logger::class));

        $this->assertTrue($service->log([
            'action' => 'register',
            'operation_category' => 'authentication',
        ]));
    }
}


