<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Controllers\AdminController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\BadgeService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class AdminStatsIntegrationTest extends TestCase
{
    private function makeControllerWithData(): AdminController
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);

        $adminId = (int) $pdo->query("SELECT id FROM users WHERE is_admin = 1 LIMIT 1")->fetchColumn();
        if ($adminId === 0) {
            $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, created_at) VALUES (1, 'admin', 'admin@example.com', 'active', 1, 1000, '2025-09-01 00:00:00')");
            $adminId = 1;
        }

        $pdo->exec("INSERT INTO users (id, username, email, status, is_admin, points, created_at) VALUES
            (2, 'active_user', 'active@example.com', 'active', 0, 120, datetime('now','-1 day')),
            (3, 'inactive_user', 'inactive@example.com', 'inactive', 0, 10, datetime('now','-40 day')),
            (4, 'suspended_user', 'suspended@example.com', 'suspended', 0, 5, datetime('now','-5 day'))");

        $insertPoint = $pdo->prepare("INSERT INTO points_transactions (id, user_id, status, points, created_at) VALUES (:id, :user_id, :status, :points, :created_at)");
        $insertPoint->execute([':id' => 'pt_1', ':user_id' => 2, ':status' => 'approved', ':points' => 10, ':created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $insertPoint->execute([':id' => 'pt_2', ':user_id' => 2, ':status' => 'approved', ':points' => 20, ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $insertPoint->execute([':id' => 'pt_3', ':user_id' => 3, ':status' => 'pending', ':points' => 5, ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $insertPoint->execute([':id' => 'pt_4', ':user_id' => 4, ':status' => 'rejected', ':points' => 8, ':created_at' => date('Y-m-d H:i:s', strtotime('-3 day'))]);

        $insertCarbon = $pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, carbon_saved, points_earned, status, created_at) VALUES (:id, :user_id, :activity_id, :carbon_saved, :points_earned, :status, :created_at)");
        $insertCarbon->execute([':id' => 'cr_1', ':user_id' => 2, ':activity_id' => 'act_a', ':carbon_saved' => 5.5, ':points_earned' => 2, ':status' => 'approved', ':created_at' => date('Y-m-d H:i:s', strtotime('-2 day'))]);
        $insertCarbon->execute([':id' => 'cr_2', ':user_id' => 2, ':activity_id' => 'act_b', ':carbon_saved' => 3.2, ':points_earned' => 1, ':status' => 'approved', ':created_at' => date('Y-m-d H:i:s', strtotime('-6 day'))]);
        $insertCarbon->execute([':id' => 'cr_3', ':user_id' => 3, ':activity_id' => 'act_c', ':carbon_saved' => 1.0, ':points_earned' => 0, ':status' => 'pending', ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $pdo->exec("INSERT INTO carbon_activities (id, name_zh, name_en, is_active, created_at) VALUES
            ('act_a', '活动A', 'Activity A', 1, datetime('now')),
            ('act_b', '活动B', 'Activity B', 1, datetime('now')),
            ('act_c', '活动C', 'Activity C', 0, datetime('now'))");

        $insertExchange = $pdo->prepare("INSERT INTO point_exchanges (id, user_id, status, points_used, created_at) VALUES (:id, :user_id, :status, :points_used, :created_at)");
        $insertExchange->execute([':id' => 'ex_1', ':user_id' => 2, ':status' => 'completed', ':points_used' => 15, ':created_at' => date('Y-m-d H:i:s', strtotime('-4 day'))]);
        $insertExchange->execute([':id' => 'ex_2', ':user_id' => 3, ':status' => 'pending', ':points_used' => 7, ':created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);

        $pdo->exec("INSERT INTO messages (sender_id, receiver_id, title, content, is_read, created_at) VALUES
            ($adminId, 2, 'Notice', 'Please review', 0, datetime('now','-2 day')),
            ($adminId, 3, 'Reminder', 'Update profile', 1, datetime('now','-3 day')),
            ($adminId, 4, 'Alert', 'Pending action', 0, datetime('now','-1 day'))");

        $authService = new class('test-secret', 'HS256', 3600) extends AuthService {
            private array $admin;
            public function __construct($secret, $alg, $exp)
            {
                parent::__construct($secret, $alg, $exp);
                $this->admin = [
                    'id' => 1,
                    'is_admin' => true,
                ];
            }
            public function getCurrentUser(\Psr\Http\Message\ServerRequestInterface $request): ?array
            {
                return $this->admin;
            }
        };

        $auditLog = $this->createMock(AuditLogService::class);
        $badgeService = $this->createMock(BadgeService::class);

        return new AdminController($pdo, $authService, $auditLog, $badgeService);
    }

    public function testGetStatsReturnsTypedAggregates(): void
    {
        $controller = $this->makeControllerWithData();
        $request = makeRequest('GET', '/admin/stats');
        $response = new Response();

        $result = $controller->getStats($request, $response);
        $this->assertSame(200, $result->getStatusCode());

        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $data = $payload['data'];

        $this->assertSame(4, $data['users']['total_users']);
        $this->assertSame(2, $data['users']['active_users']);
        $this->assertSame(2, $data['users']['inactive_users']);
        $this->assertSame(3, $data['users']['new_users_30d']);
        $this->assertEquals(0.5, $data['users']['active_ratio']);
        $this->assertEquals(0.75, $data['users']['new_users_ratio']);

        $this->assertSame(4, $data['transactions']['total_transactions']);
        $this->assertSame(1, $data['transactions']['pending_transactions']);
        $this->assertSame(2, $data['transactions']['approved_transactions']);
        $this->assertSame(1, $data['transactions']['rejected_transactions']);
        $this->assertEquals(30.0, $data['transactions']['total_points_awarded']);
        $this->assertEquals(0.5, $data['transactions']['approval_rate']);
        $this->assertEquals(15.0, $data['transactions']['avg_points_per_transaction']);
        $this->assertSame(4, $data['transactions']['last7_transactions']);

        $this->assertSame(2, $data['exchanges']['total_exchanges']);
        $this->assertSame(1, $data['exchanges']['completed_exchanges']);
        $this->assertEquals(22.0, $data['exchanges']['total_points_spent']);
        $this->assertGreaterThan(0, $data['exchanges']['completion_rate']);

        $this->assertSame(3, $data['messages']['total_messages']);
        $this->assertSame(2, $data['messages']['unread_messages']);
        $this->assertSame(1, $data['messages']['read_messages']);
        $this->assertGreaterThan(0, $data['messages']['unread_ratio']);

        $this->assertSame(3, $data['carbon']['total_records']);
        $this->assertSame(1, $data['carbon']['pending_records']);
        $this->assertSame(2, $data['carbon']['approved_records']);
        $this->assertEquals(8.7, $data['carbon']['total_carbon_saved']);
        $this->assertEquals(3.0, $data['carbon']['total_points_earned']);
        $this->assertGreaterThan(0, $data['carbon']['average_daily_carbon']);

        $this->assertArrayHasKey('trend_summary', $data);
        $this->assertEquals(8.7, $data['trend_summary']['carbon_last7']);
        $this->assertArrayHasKey('recent', $data);
        $this->assertNotEmpty($data['recent']['pending_transactions']);
        $this->assertNotEmpty($data['recent']['pending_carbon_records']);
        $this->assertNotEmpty($data['recent']['latest_users']);

        $this->assertIsInt($data['transactions']['total_transactions']);
        $this->assertIsNumeric($data['transactions']['total_points_awarded']);
        $this->assertIsFloat($data['carbon']['total_carbon_saved']);
        $this->assertIsFloat($data['users']['active_ratio']);
    }
}
