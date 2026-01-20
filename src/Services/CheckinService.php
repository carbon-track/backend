<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Monolog\Logger;
use PDO;

class CheckinService
{
    private PDO $db;
    private ?Logger $logger;
    private DateTimeZone $timezone;
    private string $driver;

    public function __construct(PDO $db, ?Logger $logger = null, ?string $timezone = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $tzName = $timezone ?? ($_ENV['APP_TIMEZONE'] ?? date_default_timezone_get());
        if (!$tzName) {
            $tzName = 'UTC';
        }
        $this->timezone = new DateTimeZone($tzName);
        try {
            $this->driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            $this->driver = 'mysql';
        }
    }

    public function recordCheckinFromSubmission(int $userId, ?string $recordId = null, ?DateTimeInterface $submittedAt = null): bool
    {
        $submittedAt = $submittedAt ?: new DateTimeImmutable('now', $this->timezone);
        $date = $this->formatDate($submittedAt);
        return $this->recordCheckinForDate($userId, $date, 'record', $recordId, $submittedAt);
    }

    public function createMakeupCheckin(
        int $userId,
        string $date,
        ?string $note = null,
        ?string $recordId = null,
        ?DateTimeInterface $createdAt = null
    ): bool
    {
        $createdAt = $createdAt ?: new DateTimeImmutable('now', $this->timezone);
        return $this->recordCheckinForDate($userId, $date, 'makeup', $recordId, $createdAt, $note);
    }

    public function recordCheckinForDate(
        int $userId,
        string $date,
        string $source,
        ?string $recordId = null,
        ?DateTimeInterface $createdAt = null,
        ?string $note = null
    ): bool {
        $createdAt = $createdAt ?: new DateTimeImmutable('now', $this->timezone);
        $date = $this->normalizeDateString($date) ?? $date;
        return $this->insertCheckin($userId, $date, $source, $recordId, $createdAt, $note);
    }

    public function syncUserCheckinsFromRecords(int $userId): int
    {
        $sql = $this->driver === 'sqlite'
            ? "INSERT OR IGNORE INTO user_checkins (user_id, checkin_date, source, record_id, created_at)
                SELECT cr.user_id, DATE(cr.created_at) AS checkin_date, 'record' AS source, MIN(cr.id) AS record_id, MIN(cr.created_at) AS created_at
                FROM carbon_records cr
                LEFT JOIN user_checkins uc ON uc.record_id = cr.id
                WHERE cr.user_id = :uid AND cr.deleted_at IS NULL AND uc.id IS NULL
                GROUP BY cr.user_id, DATE(cr.created_at)"
            : "INSERT IGNORE INTO user_checkins (user_id, checkin_date, source, record_id, created_at)
                SELECT cr.user_id, DATE(cr.created_at) AS checkin_date, 'record' AS source, MIN(cr.id) AS record_id, MIN(cr.created_at) AS created_at
                FROM carbon_records cr
                LEFT JOIN user_checkins uc ON uc.record_id = cr.id
                WHERE cr.user_id = :uid AND cr.deleted_at IS NULL AND uc.id IS NULL
                GROUP BY cr.user_id, DATE(cr.created_at)";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['uid' => $userId]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->log('Checkin sync failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
            return 0;
        }
    }

    public function getConnection(): PDO
    {
        return $this->db;
    }

    public function hasCheckin(int $userId, string $date): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM user_checkins WHERE user_id = :uid AND checkin_date = :cdate LIMIT 1");
        $stmt->execute([
            'uid' => $userId,
            'cdate' => $date,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    public function getCheckinsForRange(int $userId, string $startDate, string $endDate): array
    {
        $stmt = $this->db->prepare("SELECT checkin_date, source, created_at, record_id, notes
            FROM user_checkins
            WHERE user_id = :uid AND checkin_date BETWEEN :start AND :end
            ORDER BY checkin_date ASC");
        $stmt->execute([
            'uid' => $userId,
            'start' => $startDate,
            'end' => $endDate,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'date' => $row['checkin_date'] ?? null,
                'source' => $row['source'] ?? 'record',
                'record_id' => $row['record_id'] ?? null,
                'notes' => $row['notes'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows);
    }

    public function getUserStreakStats(int $userId, ?DateTimeImmutable $today = null): array
    {
        $summary = $this->getUserCheckinSummary($userId);
        $dates = $this->getUserCheckinDates($userId);
        $today = $today ?: new DateTimeImmutable('now', $this->timezone);
        $streaks = $this->computeStreaks($dates, $today);

        return array_merge($summary, $streaks);
    }

    public function normalizeDateString(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $candidate = DateTimeImmutable::createFromFormat('Y-m-d', $raw, $this->timezone);
        if ($candidate instanceof DateTimeImmutable && $candidate->format('Y-m-d') === $raw) {
            return $candidate->format('Y-m-d');
        }

        try {
            $fallback = new DateTimeImmutable($raw, $this->timezone);
            return $fallback->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getUserCheckinDates(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT checkin_date FROM user_checkins WHERE user_id = :uid ORDER BY checkin_date ASC");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dates = [];
        foreach ($rows as $row) {
            $date = $row['checkin_date'] ?? null;
            if ($date !== null && $date !== '') {
                $dates[] = $date;
            }
        }
        return $dates;
    }

    private function getUserCheckinSummary(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT
                COUNT(*) AS total_days,
                SUM(CASE WHEN source = 'makeup' THEN 1 ELSE 0 END) AS makeup_days,
                MAX(checkin_date) AS last_checkin_date
            FROM user_checkins WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_days' => (int) ($row['total_days'] ?? 0),
            'makeup_days' => (int) ($row['makeup_days'] ?? 0),
            'last_checkin_date' => $row['last_checkin_date'] ?? null,
        ];
    }

    private function computeStreaks(array $dates, DateTimeImmutable $today): array
    {
        if (empty($dates)) {
            return [
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_active_date' => null,
                'active_today' => false,
            ];
        }

        $longest = 1;
        $streak = 1;
        $count = count($dates);

        for ($i = 1; $i < $count; $i++) {
            $diff = $this->diffDays($dates[$i - 1], $dates[$i]);
            if ($diff === 1) {
                $streak++;
            } else {
                $streak = 1;
            }
            if ($streak > $longest) {
                $longest = $streak;
            }
        }

        $lastDate = $dates[$count - 1];
        $todayStr = $today->format('Y-m-d');
        $yesterdayStr = $today->modify('-1 day')->format('Y-m-d');
        $activeToday = ($lastDate === $todayStr);

        $current = 0;
        if ($lastDate === $todayStr || $lastDate === $yesterdayStr) {
            $current = 1;
            for ($i = $count - 2; $i >= 0; $i--) {
                $diff = $this->diffDays($dates[$i], $dates[$i + 1]);
                if ($diff === 1) {
                    $current++;
                } else {
                    break;
                }
            }
        }

        return [
            'current_streak' => $current,
            'longest_streak' => $longest,
            'last_active_date' => $lastDate,
            'active_today' => $activeToday,
        ];
    }

    private function diffDays(string $from, string $to): int
    {
        $fromDate = new DateTimeImmutable($from, $this->timezone);
        $toDate = new DateTimeImmutable($to, $this->timezone);
        return (int) $fromDate->diff($toDate)->format('%r%a');
    }

    private function insertCheckin(
        int $userId,
        string $date,
        string $source,
        ?string $recordId,
        DateTimeInterface $createdAt,
        ?string $notes = null
    ): bool {
        $sql = $this->driver === 'sqlite'
            ? "INSERT OR IGNORE INTO user_checkins (user_id, checkin_date, source, record_id, notes, created_at)
                VALUES (:uid, :cdate, :source, :record_id, :notes, :created_at)"
            : "INSERT IGNORE INTO user_checkins (user_id, checkin_date, source, record_id, notes, created_at)
                VALUES (:uid, :cdate, :source, :record_id, :notes, :created_at)";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'uid' => $userId,
                'cdate' => $date,
                'source' => $source,
                'record_id' => $recordId,
                'notes' => $notes,
                'created_at' => $createdAt->format('Y-m-d H:i:s'),
            ]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->log('Checkin insert failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'checkin_date' => $date,
                'source' => $source,
            ]);
            return false;
        }
    }

    private function formatDate(DateTimeInterface $date): string
    {
        $immutable = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);
        return $immutable->setTimezone($this->timezone)->format('Y-m-d');
    }

    private function log(string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable $ignore) {
            // ignore logger failures
        }
    }
}
