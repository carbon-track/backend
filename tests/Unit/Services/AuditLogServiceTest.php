<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\AuditLogService;

class AuditLogServiceTest extends TestCase
{
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
}


