<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\Avatar;
use CarbonTrack\Services\ErrorLogService;
use Psr\Log\LoggerInterface;

class AvatarTest extends TestCase
{
    public function testGetAvailableAvatarsFiltersAndOrders(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([
            ['id'=>1,'category'=>'c1','is_default'=>0],
            ['id'=>2,'category'=>'c1','is_default'=>1]
        ]);
        $pdo->method('prepare')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($pdo, $logger);
        $list = $model->getAvailableAvatars('c1');
        $this->assertCount(2, $list);
        $this->assertEquals('c1', $list[0]['category']);
    }

    public function testGetAvatarByIdReturnsNullWhenNotFound(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($pdo, $logger);
        $res = $model->getAvatarById(999);
        $this->assertNull($res);
    }

    public function testGetAvailableAvatarsLogsViaLoggerWhenErrorLogServiceFails(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willThrowException(new \PDOException('db down'));
        $pdo->method('prepare')->willReturn($stmt);

        $errorLogService = $this->createMock(ErrorLogService::class);
        $errorLogService->method('logException')->willThrowException(new \RuntimeException('logger down'));

        $loggedMessages = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))
            ->method('error')
            ->willReturnCallback(function (string $message, array $context) use (&$loggedMessages): void {
                $this->assertIsArray($context);
                $loggedMessages[] = $message;
            });

        $model = new Avatar($pdo, $logger, $errorLogService);
        $list = $model->getAvailableAvatars('c1');

        $this->assertSame([], $list);
        $this->assertSame([
            'ErrorLogService logging failed for avatar model',
            'Avatar query failed: db down',
        ], $loggedMessages);
    }
}


