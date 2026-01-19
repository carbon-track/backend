<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\User;
use CarbonTrack\Models\UserUsageStats;
use Illuminate\Support\Carbon;

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
        $counter = (float)($stats->counter ?? 0);

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
}
