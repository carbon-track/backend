<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\AchievementBadge;
use CarbonTrack\Models\UserBadge;
use CarbonTrack\Models\User;
use Illuminate\Database\ConnectionInterface;
use PDO;
use DateTimeImmutable;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Models\Message;
use Monolog\Logger;

/**
 * Service layer handling creation, assignment, and evaluation of achievement badges.
 */
class BadgeService
{
    private ConnectionInterface $connection;
    private MessageService $messageService;
    private AuditLogService $auditLogService;
    private Logger $logger;

    /** @var array<string,bool> */
    private array $supportedOperators = ['>=' => true, '>' => true, '<=' => true, '<' => true, '==' => true, '!=' => true];

    public function __construct(
        ConnectionInterface $connection,
        MessageService $messageService,
        AuditLogService $auditLogService,
        Logger $logger
    ) {
        $this->connection = $connection;
        $this->messageService = $messageService;
        $this->auditLogService = $auditLogService;
        $this->logger = $logger;
    }

    /**
     * @return array<int,AchievementBadge>
     */
    public function listBadges(bool $includeInactive = false): array
    {
        $query = AchievementBadge::query()->orderBy('sort_order')->orderBy('id');
        if (!$includeInactive) {
            $query->where('is_active', true)->whereNull('deleted_at');
        }
        return $query->get()->all();
    }

    public function findBadge(int $id): ?AchievementBadge
    {
        return AchievementBadge::query()->where('id', $id)->first();
    }

    public function createBadge(array $data, int $adminId): AchievementBadge
    {
        return $this->connection->transaction(function () use ($data, $adminId) {
            $badge = new AchievementBadge($this->sanitizeBadgePayload($data));
            $badge->uuid = $data['uuid'] ?? $this->generateUuid();
            $badge->save();

            $this->auditLogService->log([
                'user_id' => $adminId,
                'action' => 'badge_created',
                'entity_type' => 'achievement_badge',
                'entity_id' => $badge->id,
                'new_value' => json_encode($badge->toArray(), JSON_UNESCAPED_UNICODE),
            ]);

            return $badge;
        });
    }

    public function updateBadge(int $badgeId, array $data, int $adminId): ?AchievementBadge
    {
        return $this->connection->transaction(function () use ($badgeId, $data, $adminId) {
            $badge = AchievementBadge::query()->find($badgeId);
            if (!$badge) {
                return null;
            }
            $original = $badge->toArray();
            $badge->fill($this->sanitizeBadgePayload($data));
            if (array_key_exists('is_active', $data)) {
                $badge->is_active = (bool) $data['is_active'];
            }
            if (array_key_exists('auto_grant_enabled', $data)) {
                $badge->auto_grant_enabled = (bool) $data['auto_grant_enabled'];
            }
            $badge->save();

            $this->auditLogService->logDataChange(
                'badge_management',
                'badge_updated',
                $adminId,
                'admin',
                'achievement_badges',
                $badge->id,
                json_encode($original, JSON_UNESCAPED_UNICODE),
                json_encode($badge->toArray(), JSON_UNESCAPED_UNICODE)
            );

            return $badge;
        });
    }

    public function awardBadge(int $badgeId, int $userId, array $context = []): ?UserBadge
    {
        $source = $context['source'] ?? 'manual';
        $adminId = $context['admin_id'] ?? null;
        $notes = $context['notes'] ?? null;
        $meta = $context['meta'] ?? null;

        return $this->connection->transaction(function () use ($badgeId, $userId, $source, $adminId, $notes, $meta) {
            $badge = AchievementBadge::query()->find($badgeId);
            $user = User::query()->find($userId);
            if (!$badge || !$user) {
                return null;
            }

            $existing = UserBadge::query()->where('user_id', $userId)->where('badge_id', $badgeId)->first();
            if ($existing && $existing->isAwarded()) {
                return $existing;
            }

            if ($existing) {
                $existing->status = 'awarded';
                $existing->awarded_at = date('Y-m-d H:i:s');
                $existing->awarded_by = $adminId;
                $existing->revoked_at = null;
                $existing->revoked_by = null;
                $existing->source = $source;
                if ($notes !== null) {
                    $existing->notes = $notes;
                }
                if ($meta !== null) {
                    $existing->meta = $meta;
                }
                $existing->save();
                $userBadge = $existing;
            } else {
                $userBadge = new UserBadge([
                    'user_id' => $userId,
                    'badge_id' => $badgeId,
                    'status' => 'awarded',
                    'awarded_at' => date('Y-m-d H:i:s'),
                    'awarded_by' => $adminId,
                    'source' => $source,
                    'notes' => $notes,
                    'meta' => $meta,
                ]);
                $userBadge->save();
            }

            $this->sendBadgeMessage($user, $badge, $source);
            $this->auditLogService->log([
                'user_id' => $adminId,
                'action' => 'badge_awarded',
                'entity_type' => 'user_badge',
                'entity_id' => $userBadge->id,
                'new_value' => json_encode([
                    'user_id' => $userId,
                    'badge_id' => $badgeId,
                    'source' => $source,
                ], JSON_UNESCAPED_UNICODE),
                'notes' => $notes,
            ]);

            return $userBadge;
        });
    }

    public function revokeBadge(int $badgeId, int $userId, int $adminId, ?string $notes = null): bool
    {
        return $this->connection->transaction(function () use ($badgeId, $userId, $adminId, $notes) {
            $userBadge = UserBadge::query()
                ->where('user_id', $userId)
                ->where('badge_id', $badgeId)
                ->first();
            if (!$userBadge || !$userBadge->isAwarded()) {
                return false;
            }
            $userBadge->status = 'revoked';
            $userBadge->revoked_at = date('Y-m-d H:i:s');
            $userBadge->revoked_by = $adminId;
            if ($notes !== null) {
                $userBadge->notes = $notes;
            }
            $userBadge->save();

            $this->auditLogService->log([
                'user_id' => $adminId,
                'action' => 'badge_revoked',
                'entity_type' => 'user_badge',
                'entity_id' => $userBadge->id,
                'notes' => $notes,
            ]);

            return true;
        });
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUserBadges(int $userId, bool $includeRevoked = false): array
    {
        $query = UserBadge::query()
            ->with('badge')
            ->where('user_id', $userId)
            ->orderBy('awarded_at', 'desc');
        if (!$includeRevoked) {
            $query->where('status', 'awarded');
        }
        return $query->get()->map(function (UserBadge $userBadge) {
            $badge = $userBadge->badge;
            return [
                'badge' => $badge ? $badge->toArray() : null,
                'user_badge' => $userBadge->toArray(),
            ];
        })->all();
    }

    /**
     * Run automatic badge evaluation across users.
     *
     * @param int|null $badgeId Limit evaluation to single badge
     * @param int|null $userId Evaluate a single user when provided
     * @return array{awarded:int,skipped:int,badges:int,users:int}
     */
    public function runAutoGrant(?int $badgeId = null, ?int $userId = null): array
    {
        $badgesQuery = AchievementBadge::query()
            ->where('auto_grant_enabled', true)
            ->where('is_active', true)
            ->whereNull('deleted_at');
        if ($badgeId !== null) {
            $badgesQuery->where('id', $badgeId);
        }
        $badges = $badgesQuery->get();
        if ($badges->isEmpty()) {
            return ['awarded' => 0, 'skipped' => 0, 'badges' => 0, 'users' => 0];
        }

        $candidates = $userId !== null
            ? User::query()->where('id', $userId)->get()
            : User::query()->where('status', 'active')->whereNull('deleted_at')->get();

        $awarded = 0;
        $skipped = 0;

        foreach ($candidates as $user) {
            $metrics = $this->compileUserMetrics((int) $user->id);
            foreach ($badges as $badge) {
                $criteria = $badge->auto_grant_criteria ?? [];
                if (!$this->passesCriteria($criteria, $metrics)) {
                    $skipped++;
                    continue;
                }

                $result = $this->awardBadge((int) $badge->id, (int) $user->id, [
                    'source' => $userId ? 'trigger' : 'auto',
                    'meta' => ['metrics' => $metrics],
                ]);
                if ($result) {
                    $awarded++;
                }
            }
        }

        return [
            'awarded' => $awarded,
            'skipped' => $skipped,
            'badges' => $badges->count(),
            'users' => $candidates->count(),
        ];
    }

    /**
     * Build metric snapshot for user.
     *
     * @return array<string,float|int>
     */
    public function compileUserMetrics(int $userId): array
    {
        $metrics = [
            'total_carbon_saved' => 0.0,
            'total_points_earned' => 0.0,
            'total_approved_records' => 0,
            'total_records' => 0,
            'total_points_balance' => 0.0,
            'days_since_registration' => 0,
        ];

        try {
            $sql = "SELECT 
                COUNT(*) AS total_records,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN status = 'approved' THEN carbon_saved ELSE 0 END) AS carbon_saved,
                SUM(CASE WHEN status = 'approved' THEN points_earned ELSE 0 END) AS points_earned
            FROM carbon_records
            WHERE user_id = :user_id AND deleted_at IS NULL";
            $stmt = $this->connection->getPdo()->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $metrics['total_records'] = (int) ($row['total_records'] ?? 0);
            $metrics['total_approved_records'] = (int) ($row['approved_records'] ?? 0);
            $metrics['total_carbon_saved'] = (float) ($row['carbon_saved'] ?? 0);
            $metrics['total_points_earned'] = (float) ($row['points_earned'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to compile carbon metrics', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        try {
            $user = User::query()->find($userId);
            if ($user) {
                $metrics['total_points_balance'] = (float) $user->points;
                if ($user->created_at) {
                    $created = new DateTimeImmutable($user->created_at);
                    $metrics['days_since_registration'] = (int) $created->diff(new DateTimeImmutable('now'))->format('%a');
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read user profile for metrics', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        return $metrics;
    }

    private function passesCriteria($criteria, array $metrics): bool
    {
        if (empty($criteria) || !is_array($criteria)) {
            return true;
        }

        $rules = $criteria['rules'] ?? $criteria;
        $allRequired = (bool) ($criteria['all'] ?? $criteria['all_required'] ?? true);

        $passedAny = false;
        foreach ((array) $rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $metric = $rule['metric'] ?? null;
            $operator = $rule['operator'] ?? $rule['op'] ?? '>=';
            $value = $rule['value'] ?? null;
            if ($metric === null || $value === null) {
                continue;
            }
            if (!isset($metrics[$metric])) {
                continue;
            }
            if (!isset($this->supportedOperators[$operator])) {
                $operator = '>=';
            }
            $actual = $metrics[$metric];
            if ($this->compare($actual, $operator, $value)) {
                if (!$allRequired) {
                    return true;
                }
                $passedAny = true;
            } elseif ($allRequired) {
                return false;
            }
        }

        return $allRequired ? $passedAny : false;
    }

    private function compare($actual, string $operator, $expected): bool
    {
        switch ($operator) {
            case '>=': return $actual >= $expected;
            case '>': return $actual > $expected;
            case '<=': return $actual <= $expected;
            case '<': return $actual < $expected;
            case '==': return $actual == $expected;
            case '!=': return $actual != $expected;
            default: return false;
        }
    }

    private function sendBadgeMessage(User $user, AchievementBadge $badge, string $source): void
    {
        try {
            $titleZh = $badge->message_title_zh ?? ('恭喜解锁成就徽章：' . $badge->name_zh);
            $titleEn = $badge->message_title_en ?? ('New achievement badge unlocked: ' . $badge->name_en);
            $bodyZh = $badge->message_body_zh ?? (
                "亲爱的{$user->username}，\n\n" .
                "您已获得成就徽章《{$badge->name_zh}》。继续保持绿色行动！"
            );
            $bodyEn = $badge->message_body_en ?? (
                "Dear {$user->username},\n\n" .
                "You have just unlocked the achievement badge \"{$badge->name_en}\". Keep up the great climate actions!"
            );

            $title = $titleZh . ' / ' . $titleEn;
            $content = $bodyZh . "\n\n---\n" . $bodyEn;

            $this->messageService->sendSystemMessage(
                (int) $user->id,
                $title,
                $content,
                type: Message::TYPE_NOTIFICATION,
                priority: Message::PRIORITY_NORMAL,
                relatedEntityType: 'achievement_badge',
                relatedEntityId: (int) $badge->id
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send badge message', ['user_id' => $user->id, 'badge_id' => $badge->id, 'error' => $e->getMessage()]);
        }
    }

    private function sanitizeBadgePayload(array $data): array
    {
        $allowed = [
            'code', 'name_zh', 'name_en', 'description_zh', 'description_en',
            'icon_path', 'icon_thumbnail_path', 'is_active', 'sort_order',
            'auto_grant_enabled', 'auto_grant_criteria', 'message_title_zh',
            'message_title_en', 'message_body_zh', 'message_body_en'
        ];
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $data)) {
                $clean[$key] = $data[$key];
            }
        }
        if (isset($clean['sort_order'])) {
            $clean['sort_order'] = (int) $clean['sort_order'];
        }
        if (isset($clean['is_active'])) {
            $clean['is_active'] = (bool) $clean['is_active'];
        }
        if (isset($clean['auto_grant_enabled'])) {
            $clean['auto_grant_enabled'] = (bool) $clean['auto_grant_enabled'];
        }
        if (isset($clean['auto_grant_criteria']) && is_string($clean['auto_grant_criteria'])) {
            $decoded = json_decode($clean['auto_grant_criteria'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $clean['auto_grant_criteria'] = $decoded;
            }
        }
        return $clean;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
