<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use Monolog\Logger;
use PDO;

class LeaderboardService
{
    private const DEFAULT_TTL = 600; // 10 minutes
    private const GLOBAL_LIMIT = 50;
    private const REGION_LIMIT = 20;
    private const SCHOOL_LIMIT = 20;

    private PDO $db;
    private RegionService $regionService;
    private ?Logger $logger;
    private string $cacheFile;
    private int $ttlSeconds;

    public function __construct(PDO $db, RegionService $regionService, ?Logger $logger = null, ?string $cacheDir = null, ?int $ttlSeconds = null)
    {
        $this->db = $db;
        $this->regionService = $regionService;
        $this->logger = $logger;
        $baseDir = $cacheDir ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0755, true);
        }
        $this->cacheFile = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'leaderboards.json';
        $this->ttlSeconds = $this->validateTtl($ttlSeconds ?? (int) ($_ENV['LEADERBOARD_CACHE_TTL'] ?? self::DEFAULT_TTL));
    }

    public function getSnapshot(bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->readCache();
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->rebuildCache('auto');
    }

    public function rebuildCache(?string $reason = null): array
    {
        try {
            $data = $this->generateSnapshot();
            $this->writeCache($data, $reason);
            return $data;
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to rebuild leaderboard cache', [
                'error' => $e->getMessage(),
                'reason' => $reason,
            ]);
            return $this->readCache() ?? [
                'generated_at' => null,
                'expires_at' => null,
                'global' => [],
                'regions' => [],
                'schools' => [],
            ];
        }
    }

    private function generateSnapshot(): array
    {
        $sql = "SELECT u.id, u.username, COALESCE(u.points, 0) AS total_points,
                    u.avatar_id, u.region_code, u.school_id, s.name AS school_name, a.file_path AS avatar_path
                FROM users u
                LEFT JOIN avatars a ON u.avatar_id = a.id
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE u.deleted_at IS NULL
                ORDER BY u.points DESC, u.id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $global = [];
        $regions = [];
        $schools = [];

        foreach ($rows as $row) {
            $entry = $this->formatEntry($row);

            if (count($global) < self::GLOBAL_LIMIT) {
                $entry['rank'] = count($global) + 1;
                $global[] = $entry;
            }

            $regionCode = $row['region_code'] ?? null;
            if ($regionCode) {
                if (!isset($regions[$regionCode])) {
                    $context = $this->regionService->getRegionContext($regionCode) ?? [
                        'region_code' => $regionCode,
                        'country_code' => null,
                        'state_code' => null,
                        'region_label' => null,
                    ];
                    $regions[$regionCode] = [
                        'region_code' => $context['region_code'] ?? $regionCode,
                        'country_code' => $context['country_code'] ?? null,
                        'state_code' => $context['state_code'] ?? null,
                        'region_label' => $context['region_label'] ?? null,
                        'entries' => [],
                    ];
                }
                if (count($regions[$regionCode]['entries']) < self::REGION_LIMIT) {
                    $entry['rank'] = count($regions[$regionCode]['entries']) + 1;
                    $regions[$regionCode]['entries'][] = $entry;
                }
            }

            $schoolId = isset($row['school_id']) ? (int) $row['school_id'] : 0;
            if ($schoolId > 0) {
                if (!isset($schools[$schoolId])) {
                    $schools[$schoolId] = [
                        'school_id' => $schoolId,
                        'school_name' => $row['school_name'] ?? null,
                        'entries' => [],
                    ];
                }
                if (count($schools[$schoolId]['entries']) < self::SCHOOL_LIMIT) {
                    $entry['rank'] = count($schools[$schoolId]['entries']) + 1;
                    $schools[$schoolId]['entries'][] = $entry;
                }
            }
        }

        $generatedAt = (new \DateTimeImmutable('now'))->format(DATE_ATOM);
        $expiresAt = (new \DateTimeImmutable('now'))->modify(sprintf('+%d seconds', $this->ttlSeconds))->format(DATE_ATOM);

        return [
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt,
            'ttl' => $this->ttlSeconds,
            'global' => $global,
            'regions' => $regions,
            'schools' => $schools,
        ];
    }

    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        $modified = @filemtime($this->cacheFile);
        if ($modified === false || (time() - $modified) > $this->ttlSeconds) {
            return null;
        }

        $contents = @file_get_contents($this->cacheFile);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function writeCache(array $data, ?string $reason = null): void
    {
        if (!is_dir(dirname($this->cacheFile))) {
            @mkdir(dirname($this->cacheFile), 0755, true);
        }
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->cacheFile, $payload, LOCK_EX);
        $this->log('info', 'Leaderboard cache written', [
            'reason' => $reason,
            'entries_global' => count($data['global'] ?? []),
        ]);
    }

    private function formatEntry(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'username' => $row['username'] ?? null,
            'total_points' => isset($row['total_points']) ? (float) $row['total_points'] : 0.0,
            'avatar_id' => isset($row['avatar_id']) ? (int) $row['avatar_id'] : null,
            'avatar_path' => $row['avatar_path'] ?? null,
            'region_code' => $row['region_code'] ?? null,
            'school_id' => isset($row['school_id']) ? (int) $row['school_id'] : null,
            'school_name' => $row['school_name'] ?? null,
        ];
    }

    private function validateTtl(int $value): int
    {
        return max(60, min($value, 3600));
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable $ignore) {
            // swallow logger failures
        }
    }
}
