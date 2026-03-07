<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CheckinService;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

class CheckinServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($this->pdo);
    }

    public function testStreakStatsAndDuplicateCheckins(): void
    {
        $service = new CheckinService($this->pdo, null, 'UTC');
        $userId = (int) $this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();

        $service->recordCheckinFromSubmission($userId, 'rec-1', new DateTimeImmutable('2026-01-01 10:00:00', new DateTimeZone('UTC')));
        $service->recordCheckinFromSubmission($userId, 'rec-2', new DateTimeImmutable('2026-01-02 10:00:00', new DateTimeZone('UTC')));
        $service->recordCheckinFromSubmission($userId, 'rec-3', new DateTimeImmutable('2026-01-02 20:00:00', new DateTimeZone('UTC')));
        $service->recordCheckinFromSubmission($userId, 'rec-4', new DateTimeImmutable('2026-01-04 10:00:00', new DateTimeZone('UTC')));

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM user_checkins WHERE user_id = {$userId}")->fetchColumn();
        $this->assertSame(3, $count);

        $stats = $service->getUserStreakStats($userId, new DateTimeImmutable('2026-01-04', new DateTimeZone('UTC')));
        $this->assertSame(1, $stats['current_streak']);
        $this->assertSame(2, $stats['longest_streak']);
        $this->assertSame('2026-01-04', $stats['last_checkin_date']);
    }

    public function testMakeupCheckin(): void
    {
        $service = new CheckinService($this->pdo, null, 'UTC');
        $userId = (int) $this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();

        $service->createMakeupCheckin($userId, '2026-01-03', 'manual', 'rec-20260103');

        $this->assertTrue($service->hasCheckin($userId, '2026-01-03'));
        $stats = $service->getUserStreakStats($userId, new DateTimeImmutable('2026-01-03', new DateTimeZone('UTC')));
        $this->assertSame(1, $stats['makeup_days']);
    }

    public function testSyncUserCheckinsLogsAuditWhenRowsInserted(): void
    {
        $userId = (int) $this->pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $stmt->execute([
            'rec-sync-1',
            $userId,
            '550e8400-e29b-41d4-a716-446655440001',
            1,
            'times',
            0.019,
            1,
            '2026-01-05',
            'approved',
            '2026-01-05 08:00:00',
        ]);

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload) use ($userId): bool {
                return ($payload['action'] ?? null) === 'checkin_sync_completed'
                    && ($payload['operation_category'] ?? null) === 'checkin'
                    && ($payload['data']['user_id'] ?? null) === $userId
                    && ($payload['data']['synced_count'] ?? null) === 1;
            }))
            ->willReturn(true);

        $service = new CheckinService($this->pdo, null, 'UTC', $audit, null);

        $this->assertSame(1, $service->syncUserCheckinsFromRecords($userId));
    }
}
