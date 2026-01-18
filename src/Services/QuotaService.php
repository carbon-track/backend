<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\User;
use CarbonTrack\Models\UserUsageStats;
use DateTime;

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
        $stats = UserUsageStats::firstOrNew(['user_id' => $userId, 'resource_key' => $key]);

        $now = new DateTime();
        $resetAt = $stats->reset_at ? new DateTime($stats->reset_at) : null;

        // Reset if needed (new day)
        if (!$resetAt || $now >= $resetAt) {
            $stats->counter = 0;
            // Set next reset to tomorrow 00:00:00
            $stats->reset_at = (new DateTime('tomorrow'))->format('Y-m-d H:i:s');
        }

        if (($stats->counter + $cost) > $limit) {
            return false;
        }

        $stats->counter += $cost;
        $stats->last_updated_at = $now->format('Y-m-d H:i:s');
        $stats->save();

        return true;
    }

    /**
     * Token Bucket implementation backed by SQL
     */
    private function checkTokenBucket(int $userId, string $resource, float $ratePerMinute, int $cost): bool
    {
        $key = "{$resource}_bucket";
        $stats = UserUsageStats::firstOrNew(['user_id' => $userId, 'resource_key' => $key]);
        
        $capacity = $ratePerMinute; // Bucket size = rate (1 minute burst)
        $now = new DateTime();
        
        // Initialize if new
        if (!$stats->exists) {
            $stats->counter = $capacity;
            $stats->last_updated_at = $now->format('Y-m-d H:i:s');
            $stats->save();
        }

        // Refill
        $lastUpdate = new DateTime($stats->last_updated_at);
        $secondsPassed = $now->getTimestamp() - $lastUpdate->getTimestamp();
        $tokensToAdd = ($secondsPassed / 60) * $ratePerMinute;
        
        $currentTokens = (float)$stats->counter;
        $newTokens = min($capacity, $currentTokens + $tokensToAdd);

        if ($newTokens < $cost) {
            // Need to save the refill even if failed? 
            // Yes, to update timestamp so we don't grant "phantom" tokens next time due to long time gap
             $stats->counter = $newTokens;
             $stats->last_updated_at = $now->format('Y-m-d H:i:s');
             $stats->save();
             return false;
        }

        // Consume
        $stats->counter = $newTokens - $cost;
        $stats->last_updated_at = $now->format('Y-m-d H:i:s');
        $stats->save();

        return true;
    }
}
