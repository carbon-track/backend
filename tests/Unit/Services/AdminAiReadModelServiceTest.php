<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AdminAiReadModelService;
use CarbonTrack\Services\StatisticsService;
use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use PHPUnit\Framework\TestCase;
use PDO;

class AdminAiReadModelServiceTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($pdo);
        return $pdo;
    }

    public function testExecuteSearchUsersFiltersByRoleAndSearch(): void
    {
        $pdo = $this->makePdo();
        $pdo->exec("INSERT INTO users (id, uuid, username, email, status, is_admin, points) VALUES
            (101, '550e8400-e29b-41d4-a716-446655440111', 'admin_alpha', 'admin@example.com', 'active', 1, 42),
            (102, '550e8400-e29b-41d4-a716-446655440112', 'member_beta', 'member@example.com', 'active', 0, 7)");

        $service = new AdminAiReadModelService($pdo);
        $result = $service->execute('search_users', [
            'search' => 'admin',
            'role' => 'admin',
            'limit' => 10,
        ]);

        $this->assertSame('users', $result['scope']);
        $this->assertGreaterThanOrEqual(1, $result['total']);
        $this->assertContains('admin_alpha', array_column($result['items'], 'username'));
        $this->assertTrue(
            array_reduce(
                $result['items'],
                static fn (bool $carry, array $item): bool => $carry && !empty($item['is_admin']),
                true
            )
        );
    }

    public function testExecuteGenerateAdminReportBuildsNestedReadSummary(): void
    {
        $pdo = $this->makePdo();
        $activityId = '550e8400-e29b-41d4-a716-446655440201';
        $pdo->exec("INSERT INTO users (id, uuid, username, email, status, is_admin, points) VALUES
            (103, '550e8400-e29b-41d4-a716-446655440203', 'review_target', 'review@example.com', 'active', 0, 18)");
        $pdo->exec("INSERT INTO carbon_records (id, user_id, activity_id, status, date, carbon_saved, points_earned, created_at) VALUES
            ('rec-report-1', 103, '{$activityId}', 'pending', '2026-03-22', 2.5, 5, '2026-03-22 08:00:00')");
        $pdo->exec("INSERT INTO llm_logs (request_id, actor_type, actor_id, conversation_id, turn_no, source, model, prompt, response_raw, status, total_tokens, latency_ms, created_at)
            VALUES ('req-report-1', 'admin', 1, 'admin-ai-report-1', 1, '/admin/ai/chat', 'test-model', 'prompt', 'response', 'success', 321, 180, '2026-03-22 09:00:00')");

        $statisticsService = $this->createMock(StatisticsService::class);
        $statisticsService->expects($this->once())
            ->method('getAdminStats')
            ->with(false)
            ->willReturn([
                'pending_records' => 1,
                'active_users' => 12,
            ]);

        $service = new AdminAiReadModelService($pdo, $statisticsService);
        $report = $service->execute('generate_admin_report', ['days' => 30]);

        $this->assertSame('admin_report', $report['scope']);
        $this->assertSame(30, $report['days']);
        $this->assertSame(1, $report['stats']['pending_records']);
        $this->assertSame('llm_usage_analytics', $report['llm']['scope']);
        $this->assertSame(1, $report['llm']['total_calls']);
        $this->assertSame('pending_carbon_records', $report['pending']['scope']);
        $this->assertSame(1, $report['pending']['total']);
        $this->assertSame('rec-report-1', $report['pending']['items'][0]['id']);
    }

    public function testExecuteSearchUsersUsesDistinctBindings(): void
    {
        $pdo = $this->createMock(PDO::class);
        $listBound = [];
        $countBound = [];

        $listStmt = $this->createMock(\PDOStatement::class);
        $listStmt->expects($this->exactly(4))
            ->method('bindValue')
            ->willReturnCallback(function (string $key, $value, ?int $type = null) use (&$listBound) {
                $listBound[$key] = [$value, $type];
                return true;
            });
        $listStmt->expects($this->once())->method('execute')->willReturn(true);
        $listStmt->expects($this->once())->method('fetchAll')->willReturn([]);

        $countStmt = $this->createMock(\PDOStatement::class);
        $countStmt->expects($this->exactly(3))
            ->method('bindValue')
            ->willReturnCallback(function (string $key, $value, ?int $type = null) use (&$countBound) {
                $countBound[$key] = [$value, $type];
                return true;
            });
        $countStmt->expects($this->once())->method('execute')->willReturn(true);
        $countStmt->expects($this->once())->method('fetchColumn')->willReturn(0);

        $pdo->expects($this->exactly(2))
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use ($listStmt, $countStmt) {
                static $prepareCalls = 0;
                $prepareCalls++;
                $this->assertStringContainsString('u.username LIKE :user_search_0', $sql);
                $this->assertStringContainsString('u.email LIKE :user_search_1', $sql);
                $this->assertStringContainsString('u.uuid LIKE :user_search_2', $sql);
                return $prepareCalls === 1 ? $listStmt : $countStmt;
            });

        $service = new AdminAiReadModelService($pdo);
        $result = $service->execute('search_users', [
            'search' => 'admin',
            'limit' => 10,
        ]);

        $this->assertSame('users', $result['scope']);
        $this->assertSame('%admin%', $listBound[':user_search_0'][0] ?? null);
        $this->assertSame('%admin%', $listBound[':user_search_1'][0] ?? null);
        $this->assertSame('%admin%', $listBound[':user_search_2'][0] ?? null);
        $this->assertSame(10, $listBound[':limit'][0] ?? null);
        $this->assertSame('%admin%', $countBound[':user_search_0'][0] ?? null);
    }

    public function testExecuteSearchSystemLogsUsesDistinctBindings(): void
    {
        $pdo = $this->createMock(PDO::class);
        $bound = [];

        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->exactly(3))
            ->method('bindValue')
            ->willReturnCallback(function (string $key, $value, ?int $type = null) use (&$bound) {
                $bound[$key] = [$value, $type];
                return true;
            });
        $stmt->expects($this->once())->method('execute')->willReturn(true);
        $stmt->expects($this->once())->method('fetchAll')->willReturn([]);

        $pdo->expects($this->once())
            ->method('prepare')
            ->with($this->callback(static function (string $sql): bool {
                return str_contains($sql, 'LOWER(action) LIKE :audit_search_0')
                    && str_contains($sql, 'LOWER(COALESCE(data, \'\')) LIKE :audit_search_1')
                    && !str_contains($sql, 'LIKE :search');
            }))
            ->willReturn($stmt);

        $service = new AdminAiReadModelService($pdo);
        $result = $service->execute('search_system_logs', [
            'types' => ['audit'],
            'search' => 'trace',
            'limit' => 5,
        ]);

        $this->assertSame('system_logs', $result['scope']);
        $this->assertSame('%trace%', $bound[':audit_search_0'][0] ?? null);
        $this->assertSame('%trace%', $bound[':audit_search_1'][0] ?? null);
        $this->assertSame(5, $bound[':limit'][0] ?? null);
    }
}
