<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\UserController;

class UserControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(UserController::class));
    }

    public function testUpdateProfileSuccess(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);
        $avatar->method('isAvatarAvailable')->willReturn(true);
        $avatar->method('getAvatarById')->willReturn([
            'id' => 10,
            'name' => 'Test Avatar',
            'file_path' => '/avatars/default/avatar_01.png'
        ]);
        $audit->expects($this->once())->method('log');

        // 1) SELECT current user / 2) optional SELECT schools / 3) UPDATE users / 4) SELECT joined user
        $stmtSelectUser = $this->createMock(\PDOStatement::class);
        $stmtSelectUser->method('execute')->willReturn(true);
        $stmtSelectUser->method('fetch')->willReturn([
            'id' => 1,
            'username' => 'john',
            'avatar_id' => null,
            'school_id' => null
        ]);

        $stmtSelectSchool = $this->createMock(\PDOStatement::class);
        $stmtSelectSchool->method('execute')->willReturn(true);
        $stmtSelectSchool->method('fetch')->willReturn(['id' => 5]);

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $stmtJoined = $this->createMock(\PDOStatement::class);
        $stmtJoined->method('execute')->willReturn(true);
        $stmtJoined->method('fetch')->willReturn([
            'id' => 1,
            'uuid' => 'u-1',
            'username' => 'john',
            'email' => 'john@example.com',
            'school_id' => 5,
            'school_name' => 'Test School',
            'points' => 0,
            'is_admin' => 0,
            'avatar_id' => 10,
            'avatar_url' => '/avatars/default/avatar_01.png',
            'last_login_at' => null,
            'updated_at' => '2025-01-01 00:00:00'
        ]);

        $pdo = $this->createMock(\PDO::class);
        // prepare 顺序: select user -> select school -> update -> select joined
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtSelectUser,
            $stmtSelectSchool,
            $stmtUpdate,
            $stmtJoined
        );

    $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

    $request = makeRequest('PUT', '/users/me/profile', ['avatar_id' => 10, 'school_id' => 5]);
        $response = new \Slim\Psr7\Response();

        try {
            $resp = $controller->updateProfile($request, $response);
            $this->assertEquals(200, $resp->getStatusCode());
            $json = json_decode((string) $resp->getBody(), true);
            $this->assertTrue($json['success']);
            $this->assertEquals(10, $json['data']['avatar_id']);
            $this->assertEquals('/avatars/default/avatar_01.png', $json['data']['avatar_url']);
        } catch (\Exception $e) {
            $this->fail('Exception occurred: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    public function testSelectAvatarInvalidReturns400(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $pdo = $this->createMock(\PDO::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);
        $avatar->method('isAvatarAvailable')->willReturn(false);

    $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

        $request = makeRequest('PUT', '/users/me/avatar', ['avatar_id' => 999]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->selectAvatar($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testGetPointsHistoryReturnsPaged(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        // list
        $stmtList = $this->createMock(\PDOStatement::class);
        $stmtList->method('execute')->willReturn(true);
        $stmtList->method('fetchAll')->willReturn([
            [
                'id' => 't1', 'uuid' => 't1', 'type' => 'earn', 'points' => 100,
                'description' => 'walk', 'status' => 'approved', 'activity_id' => 'a1',
                'activity_name' => '步行', 'created_at' => '2025-01-01'
            ]
        ]);
        // count
        $stmtCount = $this->createMock(\PDOStatement::class);
        $stmtCount->method('execute')->willReturn(true);
        $stmtCount->method('fetch')->willReturn(['total' => 1]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtList, $stmtCount);

    $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

        $request = makeRequest('GET', '/users/me/points-history');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getPointsHistory($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total']);
        $this->assertEquals(100, $json['data']['transactions'][0]['points']);
        $this->assertEquals('approved', $json['data']['transactions'][0]['status']);
    }

    public function testGetUserStatsReturnsAggregates(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        $stmtPoints = $this->createMock(\PDOStatement::class);
        $stmtPoints->method('execute')->willReturn(true);
        $stmtPoints->method('fetch')->willReturn([
            'total_earned' => 300,
            'total_spent' => 100,
            'earn_count' => 3,
            'spend_count' => 1,
            'pending_count' => 0
        ]);

        $stmtMonthly = $this->createMock(\PDOStatement::class);
        $stmtMonthly->method('execute')->willReturn(true);
        $stmtMonthly->method('fetchAll')->willReturn([
            ['month' => '2025-01', 'records_count' => 2, 'carbon_saved' => 12.5, 'points_earned' => 125]
        ]);

        $stmtRecent = $this->createMock(\PDOStatement::class);
        $stmtRecent->method('execute')->willReturn(true);
        $stmtRecent->method('fetchAll')->willReturn([]);

        $stmtUserInfo = $this->createMock(\PDOStatement::class);
        $stmtUserInfo->method('execute')->willReturn(true);
        $stmtUserInfo->method('fetch')->willReturn(['points' => 200, 'created_at' => '2024-01-01']);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($stmtPoints, $stmtMonthly, $stmtRecent, $stmtUserInfo);

    $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));
        $request = makeRequest('GET', '/users/me/stats');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserStats($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(200, $json['data']['current_points']);
        $this->assertEquals(300, $json['data']['total_earned']);
        $this->assertEquals('2024-01-01', $json['data']['member_since']);
    }

    public function testGetCurrentUserSuccess(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 1,
            'uuid' => 'u-1',
            'username' => 'john',
            'email' => 'john@example.com',
            'school_id' => 5,
            'school_name' => 'Test School',
            'points' => 200,
            'is_admin' => 0,
            'avatar_id' => 10,
            'avatar_url' => '/a.png',
            'last_login_at' => null,
            'updated_at' => '2025-01-01 00:00:00'
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo);
        $request = makeRequest('GET', '/users/me');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getCurrentUser($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals('john', $json['data']['username']);
    }

    public function testUpdateCurrentUserDelegatesToUpdateProfile(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 1]);
        $avatar->method('isAvatarAvailable')->willReturn(true);
        $avatar->method('getAvatarById')->willReturn([
            'id' => 10,
            'name' => 'Test Avatar',
            'file_path' => '/avatars/default/avatar_01.png'
        ]);
        $audit->expects($this->once())->method('log');

        // 1) SELECT current user / 2) UPDATE users / 3) SELECT joined user
        $stmtSelectUser = $this->createMock(\PDOStatement::class);
        $stmtSelectUser->method('execute')->willReturn(true);
        $stmtSelectUser->method('fetch')->willReturn([
            'id' => 1,
            'username' => 'john',
            'avatar_id' => null,
            'school_id' => null
        ]);

        $stmtUpdate = $this->createMock(\PDOStatement::class);
        $stmtUpdate->method('execute')->willReturn(true);

        $stmtJoined = $this->createMock(\PDOStatement::class);
        $stmtJoined->method('execute')->willReturn(true);
        $stmtJoined->method('fetch')->willReturn([
            'id' => 1,
            'uuid' => 'u-1',
            'username' => 'john',
            'email' => 'john@example.com',
            'school_id' => null,
            'school_name' => null,
            'points' => 0,
            'is_admin' => 0,
            'avatar_id' => 10,
            'avatar_url' => '/avatars/default/avatar_01.png',
            'last_login_at' => null,
            'updated_at' => '2025-01-01 00:00:00'
        ]);

        $pdo = $this->createMock(\PDO::class);
        // prepare 顺序: select user -> update -> select joined
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtSelectUser,
            $stmtUpdate,
            $stmtJoined
        );

        $controller = new UserController($auth, $audit, $msg, $avatar, $logger, $pdo);
    $request = makeRequest('PUT', '/users/me', ['avatar_id' => 10]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateCurrentUser($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(10, $json['data']['avatar_id']);
    }
}


