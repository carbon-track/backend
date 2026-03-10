<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\LeaderboardService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\UserProfileViewService;
use PHPUnit\Framework\TestCase;

class LeaderboardServiceTest extends TestCase
{
    public function testRebuildCacheUsesCompatibleSchoolAndRegionFields(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, points REAL, avatar_id INTEGER, region_code TEXT, school_id INTEGER, school TEXT, location TEXT, deleted_at TEXT)');
        $pdo->exec('CREATE TABLE schools (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec('CREATE TABLE avatars (id INTEGER PRIMARY KEY, file_path TEXT)');
        $pdo->exec("INSERT INTO users (id, username, points, avatar_id, region_code, school_id, school, location, deleted_at) VALUES (1, 'alice', 520, NULL, NULL, 7, 'Legacy Academy', 'US-UM-81', NULL)");

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

        $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_leaderboard_cache_' . uniqid('', true);
        mkdir($cacheDir, 0777, true);

        try {
            $service = new LeaderboardService(
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
            @unlink($cacheDir . DIRECTORY_SEPARATOR . 'leaderboards.json');
            @rmdir($cacheDir);
        }
    }
}
