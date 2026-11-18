<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\Avatar;
use CarbonTrack\Services\ErrorLogService;
use Psr\Log\LoggerInterface;

class AvatarTest extends TestCase
{
    public function testGetAvailableAvatarsFiltersAndOrders(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id'=>1,'category'=>'c1','is_default'=>0],
            ['id'=>2,'category'=>'c1','is_default'=>1]
        ]);
        $connection->method('responseFor')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $list = $model->getAvailableAvatars('c1');
        $this->assertCount(2, $list);
        $this->assertEquals('c1', $list[0]['category']);
    }

    public function testGetAvailableAvatarsCanIncludeInactive(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->method('bindings')->with(['c1'])->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id' => 1, 'category' => 'c1', 'is_active' => 1],
            ['id' => 2, 'category' => 'c1', 'is_active' => 0],
        ]);
        $connection->expects($this->once())
            ->method('responseFor')
            ->with($this->callback(function (string $sql): bool {
                $this->assertStringContainsString('WHERE deleted_at IS NULL', $sql);
                $this->assertStringNotContainsString('AND is_active = 1', $sql);
                return true;
            }))
            ->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $list = $model->getAvailableAvatars('c1', true);
        $this->assertCount(2, $list);
        $this->assertSame(0, $list[1]['is_active']);
    }

    public function testGetAvatarByIdReturnsNullWhenNotFound(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn(false);
        $connection->method('responseFor')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $res = $model->getAvatarById(999);
        $this->assertNull($res);
    }

    public function testGetAvailableAvatarsLogsViaLoggerWhenErrorLogServiceFails(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->method('bindings')->willThrowException(new \RuntimeException('db down'));
        $connection->method('responseFor')->willReturn($stmt);

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

        $model = new Avatar($connection, $logger, $errorLogService);
        $list = $model->getAvailableAvatars('c1');

        $this->assertSame([], $list);
        $this->assertSame([
            'ErrorLogService logging failed for avatar model',
            'Avatar query failed: db down',
        ], $loggedMessages);
    }

    public function testCreateAvatarNormalizesEmptyStringNumericFields(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['generatedKey', 'responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->expects($this->once())
            ->method('bindings')
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
        $connection->method('responseFor')->willReturn($stmt);
        $connection->method('generatedKey')->willReturn('12');
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $avatarId = $model->createAvatar([
            'name' => 'Demo Avatar',
            'file_path' => '/avatars/demo.png',
            'sort_order' => '',
            'is_active' => '',
            'is_default' => '',
        ]);

        $this->assertSame(12, $avatarId);
    }

    public function testCreateAvatarClearsOtherDefaultsWhenNewAvatarIsDefault(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'generatedKey', 'responseFor', 'rollBack'])->getMock();
        $resetStmt = $this->createMock(DatabaseQueryResponse::class);
        $insertStmt = $this->createMock(DatabaseQueryResponse::class);
        $prepareCalls = [];

        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->once())->method('commit')->willReturn(true);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $resetStmt, $insertStmt) {
                $prepareCalls[] = $sql;
                return count($prepareCalls) === 1 ? $resetStmt : $insertStmt;
            });

        $resetStmt->expects($this->once())
            ->method('bindings')
            ->with([])
            ->willReturn(true);

        $insertStmt->expects($this->once())
            ->method('bindings')
            ->with($this->callback(function (array $params): bool {
                $this->assertSame(1, $params[8]);
                return true;
            }))
            ->willReturn(true);

        $connection->method('generatedKey')->willReturn('18');
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $avatarId = $model->createAvatar([
            'name' => 'Default Avatar',
            'file_path' => '/avatars/default.png',
            'is_default' => true,
        ]);

        $this->assertSame(18, $avatarId);
        $this->assertStringContainsString('SET is_default = 0', $prepareCalls[0]);
        $this->assertStringContainsString('AND is_default = 1', $prepareCalls[0]);
        $this->assertStringContainsString('INSERT INTO avatars', $prepareCalls[1]);
    }

    public function testUpdateAvatarNormalizesEmptyStringNumericFields(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->expects($this->once())
            ->method('bindings')
            ->with([0, 0, 0, 7])
            ->willReturn(true);
        $connection->method('responseFor')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $result = $model->updateAvatar(7, [
            'sort_order' => '',
            'is_active' => '',
            'is_default' => '',
        ]);

        $this->assertTrue($result);
    }

    public function testUpdateAvatarClearsOtherDefaultsWhenAvatarBecomesDefault(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'responseFor', 'rollBack'])->getMock();
        $resetStmt = $this->createMock(DatabaseQueryResponse::class);
        $updateStmt = $this->createMock(DatabaseQueryResponse::class);
        $prepareCalls = [];

        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->once())->method('commit')->willReturn(true);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $resetStmt, $updateStmt) {
                $prepareCalls[] = $sql;
                return count($prepareCalls) === 1 ? $resetStmt : $updateStmt;
            });

        $resetStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);

        $updateStmt->expects($this->once())
            ->method('bindings')
            ->with([1, 7])
            ->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $result = $model->updateAvatar(7, [
            'is_default' => true,
        ]);

        $this->assertTrue($result);
        $this->assertStringContainsString('AND id <> ?', $prepareCalls[0]);
        $this->assertStringContainsString('AND is_default = 1', $prepareCalls[0]);
        $this->assertStringContainsString('SET is_default = ?', $prepareCalls[1]);
    }

    public function testGetUsersAssignedToAvatarReturnsRecipients(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'rows';
        $stmt->expects($this->once())
            ->method('bindings')
            ->with([5])
            ->willReturn(true);
        $stmt->method('rows')->willReturn([
            ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
            ['id' => 202, 'username' => 'bob', 'email' => 'bob@example.com'],
        ]);
        $connection->method('responseFor')->willReturn($stmt);
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $users = $model->getUsersAssignedToAvatar(5);

        $this->assertCount(2, $users);
        $this->assertSame('alice@example.com', $users[0]['email']);
    }

    public function testUpdateAvatarAndReassignUsersWrapsAvatarAndUserUpdatesInTransaction(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'getDriverName', 'responseFor', 'rollBack'])->getMock();
        $userSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $userSelectStmt->shape = 'rows';
        $fallbackSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackSelectStmt->shape = 'row';
        $avatarStmt = $this->createMock(DatabaseQueryResponse::class);
        $userUpdateStmt = $this->createMock(DatabaseQueryResponse::class);
        $prepareCalls = [];

        $connection->method('getDriverName')->willReturn('mysql');
        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->once())->method('commit')->willReturn(true);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->exactly(4))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $userSelectStmt, $fallbackSelectStmt, $avatarStmt, $userUpdateStmt) {
                $prepareCalls[] = $sql;
                return match (count($prepareCalls)) {
                    1 => $userSelectStmt,
                    2 => $fallbackSelectStmt,
                    3 => $avatarStmt,
                    default => $userUpdateStmt,
                };
            });

        $userSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);
        $userSelectStmt->expects($this->once())
            ->method('rows')
            ->with()
            ->willReturn([
                ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
                ['id' => 202, 'username' => 'bob', 'email' => 'bob@example.com'],
            ]);

        $fallbackSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7, 1])
            ->willReturn(true);
        $fallbackSelectStmt->expects($this->once())
            ->method('row')
            ->with()
            ->willReturn([
                'id' => 1,
                'name' => 'Default Seedling',
                'is_default' => 1,
                'is_active' => 1,
            ]);

        $avatarStmt->expects($this->once())
            ->method('bindings')
            ->with([0, 7])
            ->willReturn(true);

        $userUpdateStmt->expects($this->once())
            ->method('bindings')
            ->with([1, 7])
            ->willReturn(true);
        $userUpdateStmt->method('affected')->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $reassigned = $model->updateAvatarAndReassignUsers(7, ['is_active' => false], 1);

        $this->assertSame(2, $reassigned['reassigned_user_count']);
        $this->assertSame([101, 202], array_column($reassigned['users'], 'id'));
        $this->assertSame(1, $reassigned['fallback_avatar']['id']);
        $this->assertStringContainsString('FOR UPDATE', $prepareCalls[0]);
        $this->assertStringContainsString('is_default = 1', $prepareCalls[1]);
        $this->assertStringContainsString('FOR UPDATE', $prepareCalls[1]);
        $this->assertStringContainsString('UPDATE avatars SET is_active = ?', $prepareCalls[2]);
        $this->assertStringContainsString('UPDATE users', $prepareCalls[3]);
    }

    public function testUpdateAvatarAndReassignUsersOmitsRowLocksForSqlite(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'getDriverName', 'responseFor', 'rollBack'])->getMock();
        $userSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $userSelectStmt->shape = 'rows';
        $fallbackSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackSelectStmt->shape = 'row';
        $avatarStmt = $this->createMock(DatabaseQueryResponse::class);
        $userUpdateStmt = $this->createMock(DatabaseQueryResponse::class);
        $prepareCalls = [];

        $connection->method('getDriverName')->willReturn('sqlite');
        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->once())->method('commit')->willReturn(true);
        $connection->expects($this->never())->method('rollBack');
        $connection->expects($this->exactly(4))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $userSelectStmt, $fallbackSelectStmt, $avatarStmt, $userUpdateStmt) {
                $prepareCalls[] = $sql;
                return match (count($prepareCalls)) {
                    1 => $userSelectStmt,
                    2 => $fallbackSelectStmt,
                    3 => $avatarStmt,
                    default => $userUpdateStmt,
                };
            });

        $userSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);
        $userSelectStmt->expects($this->once())
            ->method('rows')
            ->with()
            ->willReturn([
                ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
            ]);

        $fallbackSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7, 1])
            ->willReturn(true);
        $fallbackSelectStmt->expects($this->once())
            ->method('row')
            ->with()
            ->willReturn([
                'id' => 1,
                'name' => 'Default Seedling',
                'is_default' => 1,
                'is_active' => 1,
            ]);

        $avatarStmt->expects($this->once())
            ->method('bindings')
            ->with([0, 7])
            ->willReturn(true);

        $userUpdateStmt->expects($this->once())
            ->method('bindings')
            ->with([1, 7])
            ->willReturn(true);
        $userUpdateStmt->method('affected')->willReturn(1);

        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);
        $reassigned = $model->updateAvatarAndReassignUsers(7, ['is_active' => false], 1);

        $this->assertSame(1, $reassigned['reassigned_user_count']);
        $this->assertStringNotContainsString('FOR UPDATE', $prepareCalls[0]);
        $this->assertStringNotContainsString('FOR UPDATE', $prepareCalls[1]);
    }

    public function testUpdateAvatarAndReassignUsersRequiresFallbackWhenUsersAreAssigned(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'responseFor', 'rollBack'])->getMock();
        $userSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $userSelectStmt->shape = 'rows';
        $fallbackSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackSelectStmt->shape = 'row';

        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->never())->method('commit');
        $connection->expects($this->once())->method('rollBack')->willReturn(true);
        $prepareCalls = [];
        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $userSelectStmt, $fallbackSelectStmt) {
                $prepareCalls[] = $sql;
                return count($prepareCalls) === 1 ? $userSelectStmt : $fallbackSelectStmt;
            });

        $userSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);
        $userSelectStmt->expects($this->once())
            ->method('rows')
            ->with()
            ->willReturn([
                ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
            ]);
        $fallbackSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);
        $fallbackSelectStmt->expects($this->once())
            ->method('row')
            ->with()
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $model = new Avatar($connection, $logger);

        $this->expectException(\CarbonTrack\Models\AvatarFallbackUnavailableException::class);

        $model->updateAvatarAndReassignUsers(7, ['is_active' => false], null);
    }

    public function testUpdateAvatarAndReassignUsersRejectsStaleFallbackAvatarInsideTransaction(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['beginTransaction', 'commit', 'responseFor', 'rollBack'])->getMock();
        $userSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $userSelectStmt->shape = 'rows';
        $fallbackSelectStmt = $this->createMock(DatabaseQueryResponse::class);
        $fallbackSelectStmt->shape = 'row';
        $prepareCalls = [];

        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->never())->method('commit');
        $connection->expects($this->once())->method('rollBack')->willReturn(true);
        $connection->expects($this->exactly(2))
            ->method('responseFor')
            ->willReturnCallback(function (string $sql) use (&$prepareCalls, $userSelectStmt, $fallbackSelectStmt) {
                $prepareCalls[] = $sql;
                return count($prepareCalls) === 1 ? $userSelectStmt : $fallbackSelectStmt;
            });

        $userSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7])
            ->willReturn(true);
        $userSelectStmt->expects($this->once())
            ->method('rows')
            ->with()
            ->willReturn([
                ['id' => 101, 'username' => 'alice', 'email' => 'alice@example.com'],
            ]);

        $fallbackSelectStmt->expects($this->once())
            ->method('bindings')
            ->with([7, 1])
            ->willReturn(true);
        $fallbackSelectStmt->expects($this->once())
            ->method('row')
            ->with()
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $model = new Avatar($connection, $logger);

        $this->expectException(\CarbonTrack\Models\AvatarFallbackUnavailableException::class);

        $model->updateAvatarAndReassignUsers(7, ['is_active' => false], 1);
    }

    public function testCreateAvatarRejectsInvalidNonEmptyNumericStrings(): void
    {
        $connection = new DatabaseConnectionStub();
        $logger = $this->createMock(LoggerInterface::class);

        $model = new Avatar($connection, $logger);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sort_order must be an integer');

        $model->createAvatar([
            'name' => 'Demo Avatar',
            'file_path' => '/avatars/demo.png',
            'sort_order' => 'abc',
        ]);
    }
}


