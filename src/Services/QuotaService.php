<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\User;
use CarbonTrack\Models\UserUsageStats;
use Illuminate\Support\Carbon;
use PDO;

class QuotaService
{
    /**
     * Check if a user can consume a resource and consume it if allowed.
     * Supports two types of limits: 'daily_limit' (quota) and 'rate_limit' (token bucket).
     *
     * @param User $user
     * @param string $resource e.g., 'llm'
     * @param int $cost
     * @return bool
     * @throws \Exception
     */
    public function checkAndConsume(User $user, string $resource, int $cost = 1): bool
    {
        // 1. Get Effective Config
        $config = $this->getEffectiveConfig($user, $resource);
        
        // If no config found, assume allowed (or blocked depending on policy). 
        // Let's assume blocked if empty to be safe, or allow strictly if explicit.
        if (empty($config)) {
            return false; // No quota configured = No access
        }

        // 2. Check Daily Limit (Quota)
        if (isset($config['daily_limit'])) {
            $maxDaily = (int)$config['daily_limit'];
            if (!$this->checkDailyQuota($user->id, $resource, $maxDaily, $cost)) {
                return false;
            }
        }

        // 2b. Check Monthly Limit (Quota)
        if (isset($config['monthly_limit'])) {
            $maxMonthly = (int)$config['monthly_limit'];
            if (!$this->checkMonthlyQuota($user->id, $resource, $maxMonthly, $cost)) {
                return false;
            }
        }

        // 3. Check Rate Limit (Token Bucket)
        // 'rate_limit' represents tokens added per minute, or capacity.
        // Let's assume 'rate_limit' = max burst capacity AND refill rate per minute for simplicity,
        // or we can strictly follow standard token bucket params if config allows.
        // config: { "rate_limit": 60 } -> 60 requests per minute.
        if (isset($config['rate_limit'])) {
            $ratePerMinute = (float)$config['rate_limit'];
            if (!$this->checkTokenBucket($user->id, $resource, $ratePerMinute, $cost)) {
                return false;
            }
        }

        return true;
    }

    public function getEffectiveConfig(User $user, string $resource): array
    {
        // 1. Group Config
        $group = $user->group;
        $groupConfig = $group ? $group->getQuotaConfig($resource) : [];

        // 2. User Override
        $userOverride = $user->quota_override[$resource] ?? [];

        // Merge: User overrides keys in group
        return array_merge($groupConfig, $userOverride);
    }

    private function checkDailyQuota(int $userId, string $resource, int $limit, int $cost): bool
    {
        $key = "{$resource}_daily";
        $stats = UserUsageStats::where('user_id', $userId)
            ->where('resource_key', $key)
            ->first();

        $now = Carbon::now();
        $resetAt = $this->toCarbon($stats?->reset_at);
        $counter = (float)($stats?->counter ?? 0);

        // Reset if needed (new day)
        if (!$resetAt || $now >= $resetAt) {
            $counter = 0;
            // Set next reset to tomorrow 00:00:00
            $resetAt = $now->copy()->addDay()->startOfDay();
        }

        if (($counter + $cost) > $limit) {
            return false;
        }

        $counter += $cost;
        $this->persistUsageStats($userId, $key, $counter, $now, $resetAt);

        return true;
    }

    private function checkDailyQuotaOnConnection(PDO $db, int $userId, string $resource, int $limit, int $cost): bool
    {
        $key = "{$resource}_daily";
        $stats = $this->findUsageStatsOnConnection($db, $userId, $key);

        $now = Carbon::now();
        $resetAt = $this->toCarbon($stats ? ($stats['reset_at'] ?? null) : null);
        $counter = (float) ($stats ? ($stats['counter'] ?? 0) : 0);

        if (!$resetAt || $now >= $resetAt) {
            $counter = 0;
            $resetAt = $now->copy()->addDay()->startOfDay();
        }

        if (($counter + $cost) > $limit) {
            return false;
        }

        $this->persistUsageStatsOnConnection($db, $userId, $key, $counter + $cost, $now, $resetAt);

        return true;
    }

    public function checkAndConsumeOnConnection(PDO $db, User $user, string $resource, int $cost = 1): bool
    {
        $config = $this->getEffectiveConfig($user, $resource);
        if (empty($config)) {
            return false;
        }

        if (isset($config['daily_limit'])) {
            if (!$this->checkDailyQuotaOnConnection($db, $user->id, $resource, (int) $config['daily_limit'], $cost)) {
                return false;
            }
        }

        if (isset($config['monthly_limit'])) {
            if (!$this->checkMonthlyQuotaOnConnection($db, $user->id, $resource, (int) $config['monthly_limit'], $cost)) {
                return false;
            }
        }

        if (isset($config['rate_limit'])) {
            if (!$this->checkTokenBucketOnConnection($db, $user->id, $resource, (float) $config['rate_limit'], $cost)) {
                return false;
            }
        }

        return true;
    }

    private function checkMonthlyQuota(int $userId, string $resource, int $limit, int $cost): bool
    {
        $key = "{$resource}_monthly";
        $stats = UserUsageStats::where('user_id', $userId)
            ->where('resource_key', $key)
            ->first();

        $now = Carbon::now();
        $resetAt = $this->toCarbon($stats?->reset_at);
        $counter = (float)($stats?->counter ?? 0);

        // Reset if needed (new month)
        if (!$resetAt || $now >= $resetAt) {
            $counter = 0;
            $resetAt = $now->copy()->addMonthNoOverflow()->startOfMonth();
        }

        if (($counter + $cost) > $limit) {
            return false;
        }

        $counter += $cost;
        $this->persistUsageStats($userId, $key, $counter, $now, $resetAt);

        return true;
    }

    private function checkMonthlyQuotaOnConnection(PDO $db, int $userId, string $resource, int $limit, int $cost): bool
    {
        $key = "{$resource}_monthly";
        $stats = $this->findUsageStatsOnConnection($db, $userId, $key);

        $now = Carbon::now();
        $resetAt = $this->toCarbon($stats ? ($stats['reset_at'] ?? null) : null);
        $counter = (float) ($stats ? ($stats['counter'] ?? 0) : 0);

        if (!$resetAt || $now >= $resetAt) {
            $counter = 0;
            $resetAt = $now->copy()->addMonthNoOverflow()->startOfMonth();
        }

        if (($counter + $cost) > $limit) {
            return false;
        }

        $this->persistUsageStatsOnConnection($db, $userId, $key, $counter + $cost, $now, $resetAt);

        return true;
    }

    /**
     * Token Bucket implementation backed by SQL
     */
    private function checkTokenBucket(int $userId, string $resource, float $ratePerMinute, int $cost): bool
    {
        $key = "{$resource}_bucket";
        $stats = UserUsageStats::where('user_id', $userId)
            ->where('resource_key', $key)
            ->first();
        
        $capacity = $ratePerMinute; // Bucket size = rate (1 minute burst)
        $now = Carbon::now();

        $lastUpdate = $this->toCarbon($stats?->last_updated_at) ?? $now;
        $secondsPassed = max(0, $now->getTimestamp() - $lastUpdate->getTimestamp());
        $tokensToAdd = ($secondsPassed / 60) * $ratePerMinute;
        
        $currentTokens = $stats ? (float)$stats->counter : $capacity;
        $newTokens = min($capacity, $currentTokens + $tokensToAdd);

        if ($newTokens < $cost) {
            // Need to save the refill even if failed? 
            // Yes, to update timestamp so we don't grant "phantom" tokens next time due to long time gap
            $this->persistUsageStats($userId, $key, $newTokens, $now, $stats?->reset_at);
            return false;
        }

        // Consume
        $this->persistUsageStats($userId, $key, $newTokens - $cost, $now, $stats?->reset_at);

        return true;
    }

    private function checkTokenBucketOnConnection(PDO $db, int $userId, string $resource, float $ratePerMinute, int $cost): bool
    {
        $key = "{$resource}_bucket";
        $stats = $this->findUsageStatsOnConnection($db, $userId, $key);

        $capacity = $ratePerMinute;
        $now = Carbon::now();
        $lastUpdate = $this->toCarbon($stats ? ($stats['last_updated_at'] ?? null) : null) ?? $now;
        $secondsPassed = max(0, $now->getTimestamp() - $lastUpdate->getTimestamp());
        $tokensToAdd = ($secondsPassed / 60) * $ratePerMinute;

        $currentTokens = $stats ? (float) $stats['counter'] : $capacity;
        $newTokens = min($capacity, $currentTokens + $tokensToAdd);

        if ($newTokens < $cost) {
            $this->persistUsageStatsOnConnection($db, $userId, $key, $newTokens, $now, $stats ? ($stats['reset_at'] ?? null) : null);
            return false;
        }

        $this->persistUsageStatsOnConnection($db, $userId, $key, $newTokens - $cost, $now, $stats ? ($stats['reset_at'] ?? null) : null);

        return true;
    }

    /**
     * Normalize database date values (string or Carbon) to Carbon instances.
     */
    private function toCarbon($value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    private function persistUsageStats(int $userId, string $resourceKey, float $counter, Carbon $timestamp, $resetAt): void
    {
        $reset = $this->toCarbon($resetAt);

        UserUsageStats::query()->updateOrInsert(
            ['user_id' => $userId, 'resource_key' => $resourceKey],
            [
                'counter' => $counter,
                'last_updated_at' => $timestamp->toDateTimeString(),
                'reset_at' => $reset ? $reset->toDateTimeString() : null,
            ]
        );
    }

    private function findUsageStatsOnConnection(PDO $db, int $userId, string $resourceKey): ?array
    {
        $sql = "SELECT counter, last_updated_at, reset_at FROM user_usage_stats WHERE user_id = :uid AND resource_key = :rkey LIMIT 1";
        if ($this->getDriverName($db) === 'mysql') {
            $sql .= " FOR UPDATE";
        }

        $stmt = $db->prepare($sql);
        $stmt->execute([
            'uid' => $userId,
            'rkey' => $resourceKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        return $row ?: null;
    }

    private function persistUsageStatsOnConnection(PDO $db, int $userId, string $resourceKey, float $counter, Carbon $timestamp, $resetAt): void
    {
        $reset = $this->toCarbon($resetAt);
        $payload = [
            'uid' => $userId,
            'rkey' => $resourceKey,
            'counter' => $counter,
            'last_updated_at' => $timestamp->toDateTimeString(),
            'reset_at' => $reset ? $reset->toDateTimeString() : null,
        ];

        $driver = $this->getDriverName($db);
        if ($driver === 'mysql') {
            $stmt = $db->prepare(
                "INSERT INTO user_usage_stats (user_id, resource_key, counter, last_updated_at, reset_at)
                 VALUES (:uid, :rkey, :counter, :last_updated_at, :reset_at)
                 ON DUPLICATE KEY UPDATE
                    counter = VALUES(counter),
                    last_updated_at = VALUES(last_updated_at),
                    reset_at = VALUES(reset_at)"
            );
        } elseif ($driver === 'sqlite') {
            $stmt = $db->prepare(
                "INSERT INTO user_usage_stats (user_id, resource_key, counter, last_updated_at, reset_at)
                 VALUES (:uid, :rkey, :counter, :last_updated_at, :reset_at)
                 ON CONFLICT(user_id, resource_key) DO UPDATE SET
                    counter = excluded.counter,
                    last_updated_at = excluded.last_updated_at,
                    reset_at = excluded.reset_at"
            );
        } else {
            $stmt = $db->prepare("UPDATE user_usage_stats SET counter = :counter, last_updated_at = :last_updated_at, reset_at = :reset_at WHERE user_id = :uid AND resource_key = :rkey");
            $stmt->execute($payload);
            if ($stmt->rowCount() > 0) {
                $stmt->closeCursor();
                return;
            }
            $stmt->closeCursor();
            $stmt = $db->prepare("INSERT INTO user_usage_stats (user_id, resource_key, counter, last_updated_at, reset_at) VALUES (:uid, :rkey, :counter, :last_updated_at, :reset_at)");
        }

        $stmt->execute($payload);
        $stmt->closeCursor();
    }

    private function getDriverName(PDO $db): string
    {
        try {
            return (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
