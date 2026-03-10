<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\StreakLeaderboardService;
use CarbonTrack\Services\UserProfileViewService;
use PHPUnit\Framework\TestCase;

class StreakLeaderboardServiceTest extends TestCase
{
    public function testGetSnapshotLogsAuditWhenCacheHit(): void
    {
        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_streak_cache_' . uniqid('', true);
        mkdir($cacheDir, 0777, true);
        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'streak_leaderboards.json';

        $payload = [
            'generated_at' => '2026-01-01T00:00:00+00:00',
            'expires_at' => '2026-01-01T00:10:00+00:00',
            'global' => [['id' => 1, 'current_streak' => 3]],
            'regions' => [],
            'schools' => [],
            'ranks' => ['global' => [1 => 1], 'regions' => [], 'schools' => []],
        ];
        file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $logPayload) use ($cacheFile): bool {
                return ($logPayload['action'] ?? null) === 'streak_leaderboard_cache_hit'
                    && ($logPayload['data']['cache_file'] ?? null) === $cacheFile;
            }))
            ->willReturn(true);

        $service = new StreakLeaderboardService(
            $this->createMock(\PDO::class),
            $this->createMock(RegionService::class),
            null,
            $cacheDir,
            600,
            $audit,
            null
        );

        $result = $service->getSnapshot(false);

        $this->assertSame($payload['generated_at'], $result['generated_at']);
        @unlink($cacheFile);
        @rmdir($cacheDir);
    }

    public function testRebuildCacheUsesCompatibleSchoolAndRegionFields(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, region_code TEXT, school_id INTEGER, school TEXT, location TEXT, avatar_id INTEGER, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE schools (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE avatars (id INTEGER PRIMARY KEY, file_path TEXT)');
        $pdo->exec('CREATE TABLE user_checkins (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, checkin_date TEXT)');

        $today = new \DateTimeImmutable('now');
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->modify('-1 day')->format('Y-m-d');

        $pdo->exec("INSERT INTO users (id, username, region_code, school_id, school, location, avatar_id, deleted_at) VALUES (1, 'alice', NULL, 7, 'Legacy Academy', 'US-UM-81', NULL, NULL)");
        $pdo->exec("INSERT INTO user_checkins (user_id, checkin_date) VALUES (1, '{$yesterdayStr}')");
        $pdo->exec("INSERT INTO user_checkins (user_id, checkin_date) VALUES (1, '{$todayStr}')");

        $regionService = $this->createMock(RegionService::class);
        $regionService->method('getRegionContext')
            ->willReturnCallback(static function (?string $value): ?array {
                if ($value !== 'US-UM-81') {
                    return null;
                }

                return [
                    'region_code' => 'US-UM-81',
                    'region_label' => null,
                    'country_code' => 'US',
                    'state_code' => 'UM-81',
                    'country_name' => 'United States',
                    'state_name' => null,
                ];
            });

        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_streak_cache_' . uniqid('', true);
        mkdir($cacheDir, 0777, true);

        try {
            $service = new StreakLeaderboardService(
                $pdo,
                $regionService,
                null,
                $cacheDir,
                600,
                null,
                null,
                new UserProfileViewService($regionService)
            );

            $snapshot = $service->rebuildCache('test');

            $this->assertSame('US-UM-81', $snapshot['global'][0]['region_code']);
            $this->assertSame('Legacy Academy', $snapshot['global'][0]['school_name']);
            $this->assertArrayHasKey('US-UM-81', $snapshot['regions']);
            $this->assertSame('Legacy Academy', $snapshot['schools'][7]['school_name']);
        } finally {
            @unlink($cacheDir . DIRECTORY_SEPARATOR . 'streak_leaderboards.json');
            @rmdir($cacheDir);
        }
    }
}
