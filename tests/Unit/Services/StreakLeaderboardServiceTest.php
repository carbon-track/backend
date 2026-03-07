<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\StreakLeaderboardService;
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
}