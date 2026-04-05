<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\AdminController;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\CheckinService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\StatisticsService;
use CarbonTrack\Services\QuotaConfigService;
use CarbonTrack\Services\UserProfileViewService;

class AdminControllerTest extends TestCase
{
    private function makeUserProfileViewService(): UserProfileViewService
    {
        return new UserProfileViewService(new RegionService(null, null, null, null));
    }

    private function makeController(
        \PDO $pdo,
        \CarbonTrack\Services\AuthService $auth,
        \CarbonTrack\Services\AuditLogService $audit,
        BadgeService $badgeService,
        StatisticsService $statsService,
        CheckinService $checkinService,
        QuotaConfigService $quotaConfigService
    ): AdminController {
        return new AdminController(
            $pdo,
            $auth,
            $audit,
            $badgeService,
            $statsService,
            $checkinService,
            $quotaConfigService,
            $this->makeUserProfileViewService(),
            null,
            null
        );
    }

    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(AdminController::class));
    }

    public function testGetUsersRequiresAdmin(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 0]);
        $auth->method('isAdminUser')->willReturn(false);

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');
        $request = makeRequest('GET', '/admin/users');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function testGetUsersSuccessWithFilters(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);

        $capturedParams = [];

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->expects($this->atLeastOnce())
            ->method('bindValue')
            ->willReturnCallback(function ($param, $value) use (&$capturedParams) {
                $capturedParams[$param] = $value;
                return true;
            });
        $listStmt->method('execute')->willReturn(true);
        $listStmt->method('fetchAll')->willReturn([
            [
                'id'=>1,
                'username'=>'u1',
                'email'=>'u1@x.com',
                'points'=>100,
                'school_id'=>9,
                'school_name'=>'Canonical Academy',
                'passkey_count' => 2,
                'last_passkey_used_at' => '2026-03-10 09:00:00',
            ]
        ]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->method('bindValue')->willReturn(true);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn(1);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->withConsecutive(
                [
                    $this->callback(function ($sql) {
                        $this->assertStringContainsString('LOWER(COALESCE(u.role, \'user\')) = :role_user', $sql);
                        $this->assertStringContainsString('(u.username LIKE :search_username OR u.email LIKE :search_email OR u.uuid LIKE :search_uuid)', $sql);
                        return true;
                    })
                ],
                [
                    $this->stringContains('COUNT(DISTINCT u.id)')
                ]
            )
            ->willReturnOnConsecutiveCalls($listStmt, $countStmt);

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');
        $request = makeRequest('GET', '/admin/users', null, ['search' => 'u', 'status' => 'active', 'role' => 'user', 'sort' => 'points_desc']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);

        $this->assertEquals(200, $resp->getStatusCode());
        $json = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertEquals(1, $json['data']['pagination']['total_items']);
        $this->assertEquals('u1', $json['data']['users'][0]['username']);
        $this->assertSame('Canonical Academy', $json['data']['users'][0]['school_name']);
        $this->assertSame(2, $json['data']['users'][0]['passkey_count']);
        $this->assertEquals('%u%', $capturedParams[':search_username'] ?? null);
        $this->assertEquals('%u%', $capturedParams[':search_email'] ?? null);
        $this->assertEquals('%u%', $capturedParams[':search_uuid'] ?? null);
        $this->assertEquals('active', $capturedParams[':status'] ?? null);
        $this->assertSame('user', $capturedParams[':role_user'] ?? null);
    }

    public function testLoadUserRowUsesCanonicalSchoolName(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn([
            'id' => 2,
            'username' => 'legacy',
            'email' => 'legacy@example.com',
            'status' => 'active',
            'is_admin' => 0,
            'points' => 12,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-02 00:00:00',
            'school_id' => 7,
            'school_name' => 'Canonical Academy',
            'lastlgn' => null,
        ]);
        $pdo->method('prepare')->willReturn($stmt);

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $method = new \ReflectionMethod($controller, 'loadUserRow');
        $method->setAccessible(true);
        $row = $method->invoke($controller, 2);

        $this->assertSame('Canonical Academy', $row['school_name']);
        $this->assertSame(7, $row['school_id']);
    }

    public function testGetUserOverviewIncludesPasskeySummaryAndRecentSecurityActivity(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("
            INSERT INTO user_passkeys (
                user_uuid, credential_id, credential_id_hash, credential_type, label, public_key, rp_id, user_handle,
                transports, sign_count, backup_eligible, backup_state, last_used_at, attested_at, created_at, updated_at
            ) VALUES (
                '550e8400-e29b-41d4-a716-4466554400aa', 'cred-admin', '" . hash('sha256', 'cred-admin') . "', 'public-key', 'Admin Laptop', '{\"alg\":-7}',
                'app.example.test', 'dGVzdA==', '[\"internal\"]', 5, 1, 1,
                '" . gmdate('Y-m-d H:i:s', strtotime('-1 day')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-5 days')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-5 days')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-1 day')) . "'
            )
        ");
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, user_uuid, actor_type, action, status, data, operation_category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, null, 'user', 'login', 'success', json_encode(['ip_address' => '2.2.2.2']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-3 hours'))]);
        $stmt->execute([null, '550e8400-e29b-41d4-a716-4466554400aa', 'user', 'passkey_registered', 'success', json_encode(['passkey_id' => 1, 'label' => 'Admin Laptop']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-2 hours'))]);

        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $badgeService->method('compileUserMetrics')->willReturn([
            'total_points_earned' => 10,
            'total_points_balance' => 1000,
            'total_carbon_saved' => 3.5,
            'total_records' => 2,
            'total_approved_records' => 1,
        ]);
        $badgeService->method('getUserBadges')->willReturn([]);

        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $checkinService->method('getUserStreakStats')->willReturn([
            'current_streak' => 1,
            'longest_streak' => 3,
            'total_days' => 4,
            'makeup_days' => 0,
        ]);
        $quotaConfigService = new QuotaConfigService();

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $request = makeRequest('GET', '/admin/users/1/overview');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserOverview($request, $response, ['id' => 1]);

        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame(1, $json['data']['passkey_summary']['total']);
        $this->assertSame(1, $json['data']['user']['passkey_count']);
        $this->assertCount(2, $json['data']['recent_security_activity']);
        $this->assertSame('passkey_registered', $json['data']['recent_security_activity'][0]['action']);
    }

    public function testGetUserOverviewByUuidResolvesSameUser(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($pdo);

        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $badgeService->method('compileUserMetrics')->willReturn([]);
        $badgeService->method('getUserBadges')->willReturn([]);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $checkinService->method('getUserStreakStats')->willReturn([]);
        $quotaConfigService = new QuotaConfigService();

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $request = makeRequest('GET', '/admin/users/by-uuid/550e8400-e29b-41d4-a716-4466554400aa/overview');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserOverviewByUuid($request, $response, ['uuid' => '550e8400-e29b-41d4-a716-4466554400aa']);

        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('550e8400-e29b-41d4-a716-4466554400aa', $json['data']['user']['uuid']);
    }

    public function testGetUserSecurityActivityAppliesFiltersAndPagination(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($pdo);

        $insert = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, user_uuid, actor_type, action, status, data, operation_category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $userUuid = '550e8400-e29b-41d4-a716-4466554400aa';
        $insert->execute([1, $userUuid, 'user', 'passkey_registered', 'success', json_encode(['label' => 'Laptop']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
        $insert->execute([1, null, 'user', 'login', 'success', json_encode(['ip_address' => '1.1.1.1']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-1 days'))]);
        $insert->execute([1, null, 'user', 'logout', 'success', json_encode([]), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-120 days'))]);

        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'is_admin' => 1]);
        $auth->method('isAdminUser')->willReturn(true);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())
            ->method('logDataChange')
            ->with(
                'admin',
                'user_security_activity_viewed',
                9,
                'admin',
                'audit_logs',
                1,
                null,
                $this->callback(fn ($data) => $data['type'] === 'passkey_changes' && $data['period'] === '30d' && $data['count'] === 1),
                $this->callback(fn ($context) => $context['change_type'] === 'read')
            );
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $request = makeRequest('GET', '/admin/users/1/security-activity', null, [
            'page' => 1,
            'limit' => 1,
            'type' => 'passkey_changes',
            'period' => '30d',
        ]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUserSecurityActivity($request, $response, ['id' => 1]);

        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertSame('passkey_changes', $json['data']['filters']['type']);
        $this->assertSame('30d', $json['data']['filters']['period']);
        $this->assertSame(1, $json['data']['pagination']['per_page']);
        $this->assertSame(1, $json['data']['pagination']['total_items']);
        $this->assertCount(1, $json['data']['items']);
        $this->assertSame('passkey_registered', $json['data']['items'][0]['action']);
    }

    public function testGetUsersCanFilterSupportRole(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("INSERT INTO users (id, uuid, username, email, status, is_admin, role, created_at, updated_at) VALUES
            (21, '11111111-1111-4111-8111-111111111111', 'supporter', 'support@example.com', 'active', 0, 'support', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (22, '22222222-2222-4222-8222-222222222222', 'regular', 'user@example.com', 'active', 0, 'user', '2026-01-01 00:00:00', '2026-01-01 00:00:00'),
            (23, '33333333-3333-4333-8333-333333333333', 'adminish', 'admin@example.com', 'active', 1, 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00')
        ");

        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1, 'role' => 'admin']);
        $auth->method('isAdminUser')->willReturn(true);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $request = makeRequest('GET', '/admin/users', null, ['role' => 'support', 'page' => 1, 'limit' => 10]);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getUsers($request, $response);

        $this->assertSame(200, $resp->getStatusCode());
        $json = json_decode((string) $resp->getBody(), true);
        $this->assertTrue($json['success']);
        $this->assertCount(1, $json['data']['users']);
        $this->assertSame('supporter', $json['data']['users'][0]['username']);
        $this->assertSame('support', $json['data']['users'][0]['role']);
    }

    public function testUpdateUserCanSwitchExplicitRole(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($pdo);

        $pdo->exec("INSERT INTO users (id, uuid, username, email, status, is_admin, role, created_at, updated_at) VALUES
            (31, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'target-user', 'target@example.com', 'active', 0, 'user', '2026-01-01 00:00:00', '2026-01-01 00:00:00')
        ");

        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => 1, 'role' => 'admin']);
        $auth->method('isAdminUser')->willReturn(true);

        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $audit->expects($this->once())
            ->method('logDataChange')
            ->with(
                'admin',
                'user_update',
                1,
                'admin',
                'users',
                31,
                null,
                null,
                $this->callback(fn (array $meta) => in_array('role', $meta['fields'] ?? [], true) && in_array('is_admin', $meta['fields'] ?? [], true))
            );
        $badgeService = $this->createMock(BadgeService::class);
        $statsService = $this->createMock(StatisticsService::class);
        $checkinService = $this->createMock(CheckinService::class);
        $quotaConfigService = new QuotaConfigService();

        $controller = $this->makeController($pdo, $auth, $audit, $badgeService, $statsService, $checkinService, $quotaConfigService);
        $prop = (new \ReflectionClass($controller))->getProperty('lastLoginColumn');
        $prop->setAccessible(true);
        $prop->setValue($controller, 'lastlgn');

        $request = makeRequest('PUT', '/admin/users/31', ['role' => 'support']);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->updateUser($request, $response, ['id' => 31]);

        $this->assertSame(200, $resp->getStatusCode());
        $row = $pdo->query("SELECT role, is_admin FROM users WHERE id = 31")->fetch();
        $this->assertSame('support', $row['role']);
        $this->assertSame(0, (int) $row['is_admin']);
    }

}

