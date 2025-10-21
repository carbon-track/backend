<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\User;
use Monolog\Logger;

class NotificationPreferenceService
{
    public const CATEGORY_VERIFICATION = 'verification';
    public const CATEGORY_SECURITY = 'security';
    public const CATEGORY_SYSTEM = 'system';
    public const CATEGORY_TRANSACTION = 'transaction';
    public const CATEGORY_ACTIVITY = 'activity';
    public const CATEGORY_ANNOUNCEMENT = 'announcement';
    public const CATEGORY_MESSAGE = 'message';

    /**
     * @var array<string, array{label:string, locked:bool}>
     */
    private const CATEGORY_DEFINITIONS = [
        self::CATEGORY_VERIFICATION => ['label' => 'Account verification', 'locked' => true],
        self::CATEGORY_SECURITY => ['label' => 'Security alerts', 'locked' => true],
        self::CATEGORY_SYSTEM => ['label' => 'System updates', 'locked' => false],
        self::CATEGORY_TRANSACTION => ['label' => 'Point exchanges', 'locked' => false],
        self::CATEGORY_ACTIVITY => ['label' => 'Activity reviews', 'locked' => false],
        self::CATEGORY_ANNOUNCEMENT => ['label' => 'Announcements', 'locked' => false],
        self::CATEGORY_MESSAGE => ['label' => 'Direct messages', 'locked' => true],
    ];

    /**
     * Bitmask mapping for optional categories (1 bit = category disabled).
     * Locked categories must not appear here.
     *
     * @var array<string,int>
     */
    private const CATEGORY_BITMASKS = [
        self::CATEGORY_SYSTEM => 1 << 0,
        self::CATEGORY_TRANSACTION => 1 << 1,
        self::CATEGORY_ACTIVITY => 1 << 2,
        self::CATEGORY_ANNOUNCEMENT => 1 << 3,
    ];

    /**
     * @var array<int,int>
     */
    private array $maskCache = [];
    /**
     * @var array<string, int>
     */
    private array $userIdByEmailCache = [];

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return array<string, array{label:string, locked:bool}>
     */
    public function allCategories(): array
    {
        return self::CATEGORY_DEFINITIONS;
    }

    /**
     * @return array<int, array{category:string,label:string,locked:bool,email_enabled:bool}>
     */
    public function getPreferencesForUser(int $userId): array
    {
        $mask = $this->getMaskForUser($userId);

        $result = [];
        foreach (self::CATEGORY_DEFINITIONS as $category => $meta) {
            $emailEnabled = true;
            if (!$meta['locked'] && isset(self::CATEGORY_BITMASKS[$category])) {
                $emailEnabled = ($mask & self::CATEGORY_BITMASKS[$category]) === 0;
            }

            $result[] = [
                'category' => $category,
                'label' => $meta['label'],
                'locked' => $meta['locked'],
                'email_enabled' => $meta['locked'] ? true : $emailEnabled,
            ];
        }

        return $result;
    }

    /**
     * @param array<int, array{category:string,email_enabled:bool}> $preferences
     */
    public function updatePreferences(int $userId, array $preferences): void
    {
        $currentMask = $this->getMaskForUser($userId);
        $updatedMask = $currentMask;

        foreach ($preferences as $entry) {
            $category = (string) ($entry['category'] ?? '');
            if (!$this->isValidCategory($category)) {
                continue;
            }

            if ($this->isLockedCategory($category)) {
                continue;
            }

            $enabled = (bool) ($entry['email_enabled'] ?? true);
            $bit = self::CATEGORY_BITMASKS[$category] ?? null;
            if ($bit === null) {
                continue;
            }

            if ($enabled) {
                $updatedMask &= ~$bit;
            } else {
                $updatedMask |= $bit;
            }
        }

        if ($updatedMask !== $currentMask) {
            try {
                User::query()
                    ->where('id', $userId)
                    ->update([
                        'notification_email_mask' => $updatedMask,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to update notification mask', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $this->maskCache[$userId] = $updatedMask;
        }
    }

    public function shouldSendEmailByEmail(string $email, string $category): bool
    {
        $category = trim($category);
        if ($category === '' || !isset(self::CATEGORY_DEFINITIONS[$category])) {
            return true;
        }

        if ($this->isLockedCategory($category)) {
            return true;
        }

        if (!isset($this->userIdByEmailCache[$email])) {
            $user = User::query()
                ->where('email', $email)
                ->whereNull('deleted_at')
                ->first(['id', 'notification_email_mask']);

            if ($user) {
                $this->userIdByEmailCache[$email] = (int) $user->id;
                $this->maskCache[$user->id] = (int) ($user->notification_email_mask ?? 0);
            } else {
                $this->userIdByEmailCache[$email] = 0;
            }
        }

        $userId = $this->userIdByEmailCache[$email];
        if ($userId === 0) {
            return true;
        }

        return $this->shouldSendEmail($userId, $category);
    }

    public function shouldSendEmail(int $userId, string $category): bool
    {
        if (!isset(self::CATEGORY_DEFINITIONS[$category])) {
            return true;
        }

        if ($this->isLockedCategory($category)) {
            return true;
        }

        $bit = self::CATEGORY_BITMASKS[$category] ?? null;
        if ($bit === null) {
            return true;
        }

        $mask = $this->getMaskForUser($userId);

        return ($mask & $bit) === 0;
    }

    private function getMaskForUser(int $userId): int
    {
        if (!array_key_exists($userId, $this->maskCache)) {
            try {
                $mask = User::query()
                    ->where('id', $userId)
                    ->whereNull('deleted_at')
                    ->value('notification_email_mask');
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to load notification mask; assuming defaults', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                $mask = 0;
            }

            $this->maskCache[$userId] = (int) ($mask ?? 0);
        }

        return $this->maskCache[$userId];
    }

    private function isLockedCategory(string $category): bool
    {
        return self::CATEGORY_DEFINITIONS[$category]['locked'] ?? false;
    }

    private function isValidCategory(string $category): bool
    {
        return isset(self::CATEGORY_DEFINITIONS[$category]);
    }
}
