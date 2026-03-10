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
                        $this->assertStringContainsString('u.is_admin = :is_admin', $sql);
                        $this->assertStringContainsString('(u.username LIKE :search_username OR u.email LIKE :search_email)', $sql);
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
        $this->assertEquals('active', $capturedParams[':status'] ?? null);
        $this->assertSame(0, $capturedParams[':is_admin'] ?? null);
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
                user_id, credential_id, credential_id_hash, credential_type, label, public_key, rp_id, user_handle,
                transports, sign_count, backup_eligible, backup_state, last_used_at, attested_at, created_at, updated_at
            ) VALUES (
                1, 'cred-admin', '" . hash('sha256', 'cred-admin') . "', 'public-key', 'Admin Laptop', '{\"alg\":-7}',
                'app.example.test', 'dGVzdA==', '[\"internal\"]', 5, 1, 1,
                '" . gmdate('Y-m-d H:i:s', strtotime('-1 day')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-5 days')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-5 days')) . "',
                '" . gmdate('Y-m-d H:i:s', strtotime('-1 day')) . "'
            )
        ");
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, actor_type, action, status, data, operation_category, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([1, 'user', 'login', 'success', json_encode(['ip_address' => '2.2.2.2']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-3 hours'))]);
        $stmt->execute([1, 'user', 'passkey_registered', 'success', json_encode(['passkey_id' => 1, 'label' => 'Admin Laptop']), 'authentication', gmdate('Y-m-d H:i:s', strtotime('-2 hours'))]);

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

}

