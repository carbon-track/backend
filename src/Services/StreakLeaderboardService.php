<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use DateTimeImmutable;
use DateTimeZone;
use Monolog\Logger;
use PDO;

class StreakLeaderboardService
{
    private const DEFAULT_TTL = 600;
    private const GLOBAL_LIMIT = 50;
    private const REGION_LIMIT = 20;
    private const SCHOOL_LIMIT = 20;

    private PDO $db;
    private RegionService $regionService;
    private ?Logger $logger;
    private string $cacheFile;
    private int $ttlSeconds;
    private DateTimeZone $timezone;

    public function __construct(PDO $db, RegionService $regionService, ?Logger $logger = null, ?string $cacheDir = null, ?int $ttlSeconds = null)
    {
        $this->db = $db;
        $this->regionService = $regionService;
        $this->logger = $logger;
        $baseDir = $cacheDir ?? (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache');
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0755, true);
        }
        $this->cacheFile = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'streak_leaderboards.json';
        $this->ttlSeconds = $this->validateTtl($ttlSeconds ?? (int) ($_ENV['STREAK_LEADERBOARD_CACHE_TTL'] ?? self::DEFAULT_TTL));

        $tzName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
        if (!$tzName) {
            $tzName = 'UTC';
        }
        $this->timezone = new DateTimeZone($tzName);
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
            $this->log('error', 'Failed to rebuild streak leaderboard cache', [
                'error' => $e->getMessage(),
                'reason' => $reason,
            ]);
            return $this->readCache() ?? [
                'generated_at' => null,
                'expires_at' => null,
                'global' => [],
                'regions' => [],
                'schools' => [],
                'ranks' => [
                    'global' => [],
                    'regions' => [],
                    'schools' => [],
                ],
            ];
        }
    }

    private function generateSnapshot(): array
    {
        $sql = "SELECT uc.user_id, uc.checkin_date,
                    u.username, u.region_code, u.school_id, u.avatar_id,
                    s.name AS school_name, a.file_path AS avatar_path
                FROM user_checkins uc
                JOIN users u ON u.id = uc.user_id AND u.deleted_at IS NULL
                LEFT JOIN schools s ON u.school_id = s.id
                LEFT JOIN avatars a ON u.avatar_id = a.id
                ORDER BY uc.user_id ASC, uc.checkin_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $today = new DateTimeImmutable('now', $this->timezone);
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->modify('-1 day')->format('Y-m-d');

        $entries = [];
        $currentUserId = null;
        $current = null;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            if ($userId <= 0) {
                continue;
            }
            $checkinDate = $row['checkin_date'] ?? null;
            if (!$checkinDate) {
                continue;
            }

            if ($currentUserId === null || $currentUserId !== $userId) {
                if ($currentUserId !== null && $current) {
                    $entry = $this->buildStreakEntry($current, $todayStr, $yesterdayStr);
                    if ($entry) {
                        $entries[] = $entry;
                    }
                }

                $currentUserId = $userId;
                $current = [
                    'id' => $userId,
                    'username' => $row['username'] ?? null,
                    'region_code' => $row['region_code'] ?? null,
                    'school_id' => isset($row['school_id']) ? (int) $row['school_id'] : null,
                    'school_name' => $row['school_name'] ?? null,
                    'avatar_id' => isset($row['avatar_id']) ? (int) $row['avatar_id'] : null,
                    'avatar_path' => $row['avatar_path'] ?? null,
                    'last_date' => null,
                    'current_run' => 0,
                    'longest' => 0,
                    'total' => 0,
                ];
            }

            if (!$current) {
                continue;
            }

            if ($current['last_date'] === null) {
                $current['current_run'] = 1;
                $current['longest'] = 1;
                $current['last_date'] = $checkinDate;
                $current['total'] = 1;
                continue;
            }

            $diff = $this->diffDays($current['last_date'], $checkinDate);
            if ($diff === 0) {
                continue;
            }

            if ($diff === 1) {
                $current['current_run']++;
            } else {
                $current['current_run'] = 1;
            }

            if ($current['current_run'] > $current['longest']) {
                $current['longest'] = $current['current_run'];
            }

            $current['last_date'] = $checkinDate;
            $current['total']++;
        }

        if ($currentUserId !== null && $current) {
            $entry = $this->buildStreakEntry($current, $todayStr, $yesterdayStr);
            if ($entry) {
                $entries[] = $entry;
            }
        }

        $globalSorted = $this->sortEntries($entries);
        $global = $this->limitEntries($globalSorted, self::GLOBAL_LIMIT);
        $globalRanks = $this->buildRanks($globalSorted);

        $regions = [];
        $regionRanks = [];
        foreach ($entries as $entry) {
            $regionCode = $entry['region_code'] ?? null;
            if (!$regionCode) {
                continue;
            }
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
            $regions[$regionCode]['entries'][] = $entry;
        }

        foreach ($regions as $regionCode => $bucket) {
            $sorted = $this->sortEntries($bucket['entries']);
            $regions[$regionCode]['entries'] = $this->limitEntries($sorted, self::REGION_LIMIT);
            $regionRanks[$regionCode] = $this->buildRanks($sorted);
        }

        $schools = [];
        $schoolRanks = [];
        foreach ($entries as $entry) {
            $schoolId = isset($entry['school_id']) ? (int) $entry['school_id'] : 0;
            if ($schoolId <= 0) {
                continue;
            }
            if (!isset($schools[$schoolId])) {
                $schools[$schoolId] = [
                    'school_id' => $schoolId,
                    'school_name' => $entry['school_name'] ?? null,
                    'entries' => [],
                ];
            }
            $schools[$schoolId]['entries'][] = $entry;
        }

        foreach ($schools as $schoolId => $bucket) {
            $sorted = $this->sortEntries($bucket['entries']);
            $schools[$schoolId]['entries'] = $this->limitEntries($sorted, self::SCHOOL_LIMIT);
            $schoolRanks[$schoolId] = $this->buildRanks($sorted);
        }

        $generatedAt = (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM);
        $expiresAt = (new DateTimeImmutable('now', $this->timezone))
            ->modify(sprintf('+%d seconds', $this->ttlSeconds))
            ->format(DATE_ATOM);

        return [
            'generated_at' => $generatedAt,
            'expires_at' => $expiresAt,
            'ttl' => $this->ttlSeconds,
            'global' => $global,
            'regions' => $regions,
            'schools' => $schools,
            'ranks' => [
                'global' => $globalRanks,
                'regions' => $regionRanks,
                'schools' => $schoolRanks,
            ],
        ];
    }

    private function buildStreakEntry(array $accumulator, string $todayStr, string $yesterdayStr): ?array
    {
        $lastDate = $accumulator['last_date'] ?? null;
        if (!$lastDate) {
            return null;
        }

        $currentStreak = 0;
        if ($lastDate === $todayStr || $lastDate === $yesterdayStr) {
            $currentStreak = (int) ($accumulator['current_run'] ?? 0);
        }

        $longest = (int) ($accumulator['longest'] ?? 0);
        if ($longest <= 0) {
            $longest = (int) ($accumulator['current_run'] ?? 0);
        }

        return [
            'id' => $accumulator['id'] ?? null,
            'username' => $accumulator['username'] ?? null,
            'region_code' => $accumulator['region_code'] ?? null,
            'school_id' => $accumulator['school_id'] ?? null,
            'school_name' => $accumulator['school_name'] ?? null,
            'avatar_id' => $accumulator['avatar_id'] ?? null,
            'avatar_path' => $accumulator['avatar_path'] ?? null,
            'current_streak' => $currentStreak,
            'longest_streak' => $longest,
            'total_checkins' => (int) ($accumulator['total'] ?? 0),
            'last_checkin_date' => $lastDate,
        ];
    }

    private function diffDays(string $from, string $to): int
    {
        $fromDate = new DateTimeImmutable($from, $this->timezone);
        $toDate = new DateTimeImmutable($to, $this->timezone);
        return (int) $fromDate->diff($toDate)->format('%r%a');
    }

    private function sortEntries(array $entries): array
    {
        usort($entries, function (array $a, array $b): int {
            $cmp = ($b['current_streak'] ?? 0) <=> ($a['current_streak'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ($b['longest_streak'] ?? 0) <=> ($a['longest_streak'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = ($b['total_checkins'] ?? 0) <=> ($a['total_checkins'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp((string) ($b['last_checkin_date'] ?? ''), (string) ($a['last_checkin_date'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        return $entries;
    }

    private function limitEntries(array $entries, int $limit): array
    {
        $limited = array_slice($entries, 0, $limit);
        foreach ($limited as $index => &$entry) {
            $entry['rank'] = $index + 1;
        }
        unset($entry);
        return $limited;
    }

    private function buildRanks(array $sortedEntries): array
    {
        $ranks = [];
        foreach ($sortedEntries as $index => $entry) {
            $userId = isset($entry['id']) ? (int) $entry['id'] : null;
            if ($userId !== null && $userId > 0) {
                $ranks[$userId] = $index + 1;
            }
        }
        return $ranks;
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
        $this->log('info', 'Streak leaderboard cache written', [
            'reason' => $reason,
            'entries_global' => count($data['global'] ?? []),
        ]);
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
