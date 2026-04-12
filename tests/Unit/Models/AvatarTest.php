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

    public function testCreateAvatarNormalizesEmptyStringNumericFields(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params): bool {
                $this->assertCount(9, $params);
                $this->assertIsString($params[0]);
                $this->assertSame('Demo Avatar', $params[1]);
                $this->assertSame('/avatars/demo.png', $params[3]);
                $this->assertSame(0, $params[6]);
                $this->assertSame(0, $params[7]);
                $this->assertSame(0, $params[8]);
                return true;
            }))
            ->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);
        $pdo->method('lastInsertId')->willReturn('12');
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($pdo, $logger);
        $avatarId = $model->createAvatar([
            'name' => 'Demo Avatar',
            'file_path' => '/avatars/demo.png',
            'sort_order' => '',
            'is_active' => '',
            'is_default' => '',
        ]);

        $this->assertSame(12, $avatarId);
    }

    public function testUpdateAvatarNormalizesEmptyStringNumericFields(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with([0, 0, 0, 7])
            ->willReturn(true);
        $pdo->method('prepare')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($pdo, $logger);
        $result = $model->updateAvatar(7, [
            'sort_order' => '',
            'is_active' => '',
            'is_default' => '',
        ]);

        $this->assertTrue($result);
    }

    public function testCreateAvatarRejectsInvalidNonEmptyNumericStrings(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($pdo, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sort_order must be an integer');

        $model->createAvatar([
            'name' => 'Demo Avatar',
            'file_path' => '/avatars/demo.png',
            'sort_order' => 'abc',
        ]);
    }
}


