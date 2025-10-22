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
            'avatar_path' => '/avatars/default/avatar_01.png',
            'lastlgn' => null,
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

    $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
    $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

    $request = makeRequest('PUT', '/users/me/profile', ['avatar_id' => 10, 'school_id' => 5]);
        $response = new \Slim\Psr7\Response();

        try {
            $resp = $controller->updateProfile($request, $response);
            $this->assertEquals(200, $resp->getStatusCode());
            $json = json_decode((string) $resp->getBody(), true);
            $this->assertTrue($json['success']);
            $this->assertEquals(10, $json['data']['avatar_id']);
            $this->assertEquals('/avatars/default/avatar_01.png', $json['data']['avatar_path']);
            $this->assertNull($json['data']['avatar_url']);
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

    $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
    $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

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

    $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
    $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));

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

        $stmtRecords = $this->createMock(\PDOStatement::class);
        $stmtRecords->method('execute')->willReturn(true);
        $stmtRecords->method('fetch')->willReturn([
            'total_activities' => 5,
            'approved_activities' => 4,
            'pending_activities' => 1,
            'rejected_activities' => 0,
            'total_carbon_saved' => 42.3,
            'total_points_earned' => 280
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
        $stmtUserInfo->expects($this->once())->method('fetch')->willReturn(['points' => 200, 'created_at' => '2024-01-01']);

        $stmtRank = $this->createMock(\PDOStatement::class);
        $stmtRank->method('execute')->willReturn(true);
        $stmtRank->method('fetch')->willReturn(['rank' => 7]);

        $stmtShowColumns = $this->createMock(\PDOStatement::class);
        $stmtShowColumns->method('fetch')->willReturn(false);

        $stmtTotalUsers = $this->createMock(\PDOStatement::class);
        $stmtTotalUsers->expects($this->once())->method('fetch')->willReturn(['total' => 200]);

        $stmtLeaderboard = $this->createMock(\PDOStatement::class);
        $stmtLeaderboard->method('fetchAll')->willReturn([
            ['id' => 99, 'username' => 'alice', 'total_points' => 520, 'avatar_id' => null, 'avatar_path' => null],
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('getAttribute')->willReturn('mysql');
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtPoints,
            $stmtMonthly,
            $stmtRecent,
            $stmtUserInfo,
            $stmtRecords,
            $stmtRank
        );
        $pdo->method('query')->willReturnCallback(function ($sql) use ($stmtShowColumns, $stmtTotalUsers, $stmtLeaderboard) {
            if (stripos($sql, 'SHOW COLUMNS FROM points_transactions') !== false) {
                return $stmtShowColumns;
            }
            if (stripos($sql, 'COUNT(*) AS total') !== false && stripos($sql, 'FROM users') !== false) {
                return $stmtTotalUsers;
            }
            if (stripos($sql, 'ORDER BY u.points DESC') !== false) {
                return $stmtLeaderboard;
            }
            return false;
        });

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo, $this->createMock(\CarbonTrack\Services\ErrorLogService::class));
        $request = makeRequest('GET', '/users/me/stats');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserStats($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(200, $json['data']['current_points']);
        $this->assertEquals(42.3, $json['data']['total_carbon_saved']);
        $this->assertEquals(5, $json['data']['total_activities']);
        $this->assertEquals(300, $json['data']['total_earned']);
        $this->assertEquals(7, $json['data']['rank']);
        $this->assertEquals(200, $json['data']['total_users']);
        $this->assertEquals('2024-01-01', $json['data']['member_since']);
        $this->assertCount(1, $json['data']['leaderboard']);
    }


    public function testGetRecentActivitiesReturnsPresignedImages(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $msg = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);

        $auth->method('getCurrentUser')->willReturn(['id' => 7]);

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->onlyMethods(['bindValue', 'execute', 'fetchAll'])
            ->getMock();
        $statement->method('bindValue')->willReturn(true);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->expects($this->once())->method('fetchAll')->willReturn([
            [
                'id' => 42,
                'activity_id' => 5,
                'activity_name_zh' => '节能',
                'activity_name_en' => 'Energy Saving',
                'category' => 'energy',
                'unit' => 'times',
                'data' => 3.0,
                'carbon_saved' => 1.23,
                'points_earned' => 15,
                'status' => 'approved',
                'created_at' => '2025-09-24 12:00:00',
                'images' => json_encode([[
                    'file_path' => 'proofs/a.jpg',
                    'original_name' => 'evidence.jpg',
                ]]),
            ],
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        $r2->method('resolveKeyFromUrl')->willReturnCallback(static function ($value) {
            return trim((string)$value, '/');
        });
        $r2->method('generatePresignedUrl')->willReturn('https://cdn.example.com/proofs/a.jpg?token=abc');
        $r2->method('getPublicUrl')->willReturn('https://cdn.example.com/proofs/a.jpg');

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo, $errorLog, $r2);

        $request = makeRequest('GET', '/users/me/activities');
        $response = new \Slim\Psr7\Response();
        $result = $controller->getRecentActivities($request, $response);

        $this->assertEquals(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);

        $this->assertTrue($payload['success']);
        $this->assertCount(1, $payload['data']);
        $activity = $payload['data'][0];
        $this->assertSame('approved', $activity['status']);
        $this->assertArrayHasKey('images', $activity);
        $this->assertCount(1, $activity['images']);
        $this->assertSame('proofs/a.jpg', $activity['images'][0]['file_path']);
        $this->assertNotEmpty($activity['images'][0]['presigned_url']);
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
            'avatar_path' => '/a.png',
            'lastlgn' => null,
            'updated_at' => '2025-01-01 00:00:00'
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo);
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
            'avatar_path' => '/avatars/default/avatar_01.png',
            'lastlgn' => null,
            'updated_at' => '2025-01-01 00:00:00'
        ]);

        $pdo = $this->createMock(\PDO::class);
        // prepare 顺序: select user -> update -> select joined
        $pdo->method('prepare')->willReturnOnConsecutiveCalls(
            $stmtSelectUser,
            $stmtUpdate,
            $stmtJoined
        );

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $controller = new UserController($auth, $audit, $msg, $avatar, $prefs, null, $logger, $pdo);
    $request = makeRequest('PUT', '/users/me', ['avatar_id' => 10]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateCurrentUser($request, $response);
        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(10, $json['data']['avatar_id']);
    }

    public function testSendNotificationTestEmailActivityUsesLatestRecord(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn([
            'id' => 5,
            'email' => 'user@example.com',
            'username' => 'EcoHero',
        ]);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())
            ->method('logAuthOperation')
            ->with(
                'notification_test_email',
                5,
                true,
                $this->callback(function (array $context): bool {
                    $this->assertSame('activity', $context['category']);
                    $this->assertArrayHasKey('sample', $context);
                    $this->assertFalse($context['sample']['generated']);
                    $this->assertTrue($context['delivered']);
                    $this->assertFalse($context['queued']);
                    return true;
                })
            );

        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $emailService = $this->createMock(\CarbonTrack\Services\EmailService::class);
        $emailService->expects($this->once())
            ->method('sendActivityApprovedNotification')
            ->with(
                'user@example.com',
                'EcoHero',
                'Metro ride to office',
                42.5
            )
            ->willReturn(true);

        $emailService->expects($this->once())
            ->method('dispatchAsyncEmail')
            ->with(
                $this->callback(static fn($callback): bool => is_callable($callback)),
                $this->callback(function (array $context): bool {
                    $this->assertSame('activity', $context['category']);
                    $this->assertArrayHasKey('sample', $context);
                    $this->assertFalse($context['sample']['generated']);
                    return true;
                }),
                false
            )
            ->willReturnCallback(function (callable $callback, array $context, bool $preferAsync): bool {
                $this->assertFalse($preferAsync);
                return (bool) $callback(false);
            });

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $prefs->method('allCategories')->willReturn([
            'activity' => ['label' => 'Activity reviews', 'locked' => false],
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['uid' => 5])->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'points_earned' => 42.5,
            'created_at' => '2025-01-10 10:00:00',
            'name_en' => 'Metro ride to office',
            'name_zh' => '',
            'unit' => 'km',
        ]);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $controller = new UserController(
            $auth,
            $audit,
            $messageService,
            $avatar,
            $prefs,
            $emailService,
            $logger,
            $pdo,
            $errorLog
        );

        $request = makeRequest('POST', '/users/me/notification-preferences/test-email', ['category' => 'activity']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->sendNotificationTestEmail($request, $response);
        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['delivered']);
        $this->assertFalse($json['data']['generated']);
        $this->assertSame('activity', $json['data']['category']);
        $this->assertSame('activity', $json['data']['preview']['category']);
        $this->assertArrayHasKey('sample', $json['data']['preview']);
    }

    public function testSendNotificationTestEmailMarksGeneratedSampleWhenMissingData(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn([
            'id' => 8,
            'email' => 'preview@example.com',
            'username' => 'PreviewUser',
        ]);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())
            ->method('logAuthOperation')
            ->with(
                'notification_test_email',
                8,
                true,
                $this->callback(function (array $context): bool {
                    $this->assertTrue($context['generated']);
                    $this->assertArrayHasKey('sample', $context);
                    $this->assertTrue($context['sample']['generated']);
                    return true;
                })
            );

        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $emailService = $this->createMock(\CarbonTrack\Services\EmailService::class);
        $emailService->expects($this->once())
            ->method('sendActivityApprovedNotification')
            ->with(
                'preview@example.com',
                'PreviewUser',
                $this->stringContains('Test sample'),
                12.5
            )
            ->willReturn(true);

        $emailService->expects($this->once())
            ->method('dispatchAsyncEmail')
            ->with(
                $this->callback(static fn($callback): bool => is_callable($callback)),
                $this->isType('array'),
                false
            )
            ->willReturnCallback(function (callable $callback, array $context, bool $preferAsync): bool {
                $this->assertFalse($preferAsync);
                return (bool) $callback(false);
            });

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $prefs->method('allCategories')->willReturn([
            'activity' => ['label' => 'Activity reviews', 'locked' => false],
        ]);

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())->method('execute')->with(['uid' => 8])->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $controller = new UserController(
            $auth,
            $audit,
            $messageService,
            $avatar,
            $prefs,
            $emailService,
            $logger,
            $pdo,
            $errorLog
        );

        $request = makeRequest('POST', '/users/me/notification-preferences/test-email', ['category' => 'activity']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->sendNotificationTestEmail($request, $response);
        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertTrue($json['data']['delivered']);
        $this->assertTrue($json['data']['generated']);
        $this->assertSame('activity', $json['data']['category']);
        $this->assertStringContainsString('generated preview', $json['message']);
        $this->assertTrue($json['data']['preview']['sample']['generated']);
    }

    public function testSendNotificationTestEmailRejectsInvalidCategory(): void
    {
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn([
            'id' => 3,
            'email' => 'user@example.com',
        ]);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->never())->method('logAuthOperation');

        $messageService = $this->createMock(\CarbonTrack\Services\MessageService::class);
        $avatar = $this->createMock(\CarbonTrack\Models\Avatar::class);
        $logger = $this->createMock(\Monolog\Logger::class);

        $emailService = $this->createMock(\CarbonTrack\Services\EmailService::class);
        $emailService->expects($this->never())->method('dispatchAsyncEmail');

        $prefs = $this->createMock(\CarbonTrack\Services\NotificationPreferenceService::class);
        $prefs->method('allCategories')->willReturn([
            'system' => ['label' => 'System updates', 'locked' => false],
        ]);

        $pdo = $this->createMock(\PDO::class);
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);

        $controller = new UserController(
            $auth,
            $audit,
            $messageService,
            $avatar,
            $prefs,
            $emailService,
            $logger,
            $pdo,
            $errorLog
        );

        $request = makeRequest('POST', '/users/me/notification-preferences/test-email', ['category' => 'unknown']);
        $response = new \Slim\Psr7\Response();

        $resp = $controller->sendNotificationTestEmail($request, $response);
        $this->assertSame(422, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($json['success']);
        $this->assertSame('INVALID_CATEGORY', $json['code']);
    }
}


