<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;

class AdminAiWriteActionService
{
    public function __construct(
        private PDO $db,
        private ?AuditLogService $auditLogService = null,
        private ?MessageService $messageService = null,
        private ?BadgeService $badgeService = null
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    public function execute(string $actionName, array $payload, array $logContext = []): array
    {
        return match ($actionName) {
            'approve_carbon_records' => $this->reviewCarbonRecords('approve', $payload, $logContext),
            'reject_carbon_records' => $this->reviewCarbonRecords('reject', $payload, $logContext),
            'adjust_user_points' => $this->adjustUserPoints($payload, $logContext),
            'create_user' => $this->createUserAccount($payload, $logContext),
            'update_user_status' => $this->updateUserStatus($payload, $logContext),
            'award_badge_to_user' => $this->awardBadgeToUser($payload, $logContext),
            'revoke_badge_from_user' => $this->revokeBadgeFromUser($payload, $logContext),
            'update_exchange_status' => $this->updateExchangeStatus($payload, $logContext),
            'update_product_status' => $this->updateProductStatus($payload, $logContext),
            'adjust_product_inventory' => $this->adjustProductInventory($payload, $logContext),
            default => throw new \RuntimeException('Unsupported write action: ' . $actionName),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function adjustUserPoints(array $payload, array $logContext): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $delta = isset($payload['delta']) && is_numeric((string) $payload['delta']) ? (float) $payload['delta'] : null;
        if ($delta === null || abs($delta) < 0.00001) {
            throw new \RuntimeException('Invalid points delta.');
        }

        $reason = isset($payload['reason']) ? trim((string) $payload['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $oldPoints = isset($user['points']) ? (int) $user['points'] : 0;
        $updatedAt = gmdate('Y-m-d H:i:s');

        $stmt = $this->db->prepare("UPDATE users
            SET points = COALESCE(points, 0) + :delta,
                updated_at = :updated_at
            WHERE id = :user_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':delta' => $delta,
            ':updated_at' => $updatedAt,
            ':user_id' => $userId,
        ]);

        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_points_adjusted', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'old_data' => ['points' => $oldPoints],
            'new_data' => ['points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'delta' => $delta,
                'reason' => $reason,
                'user_uuid' => $freshUser['uuid'] ?? null,
            ],
        ]);

        return [
            'action' => 'adjust_user_points',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0,
            ],
            'delta' => $delta,
            'old_points' => $oldPoints,
            'new_points' => isset($freshUser['points']) ? (int) $freshUser['points'] : 0,
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function createUserAccount(array $payload, array $logContext): array
    {
        $username = trim((string) ($payload['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('username is required.');
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('A valid email is required.');
        }

        $password = isset($payload['password']) ? trim((string) $payload['password']) : '';
        $passwordHash = trim((string) ($payload['password_hash'] ?? ''));
        if ($password === '' && $passwordHash === '') {
            throw new \RuntimeException('password is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
        if ($status === '') {
            $status = 'active';
        }

        $normalizedIsAdmin = $this->normalizeBooleanFilter($payload['is_admin'] ?? false);
        $isAdmin = $normalizedIsAdmin === true ? 1 : 0;

        $schoolId = isset($payload['school_id']) && is_numeric((string) $payload['school_id'])
            ? (int) $payload['school_id']
            : null;
        if ($schoolId !== null && $schoolId > 0 && !$this->schoolExists($schoolId)) {
            throw new \RuntimeException('School not found.');
        }

        $groupId = isset($payload['group_id']) && is_numeric((string) $payload['group_id'])
            ? (int) $payload['group_id']
            : null;
        if ($groupId !== null && $groupId > 0 && !$this->groupExists($groupId)) {
            throw new \RuntimeException('User group not found.');
        }

        $regionCode = trim((string) ($payload['region_code'] ?? ''));
        $regionCode = $regionCode !== '' ? $regionCode : null;

        $adminNotes = trim((string) ($payload['admin_notes'] ?? ''));
        $adminNotes = $adminNotes !== '' ? $adminNotes : null;

        $usernameCheck = $this->db->prepare("SELECT id FROM users WHERE LOWER(username) = LOWER(:username) AND deleted_at IS NULL LIMIT 1");
        $usernameCheck->execute([':username' => $username]);
        if ($usernameCheck->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \RuntimeException('Username already exists.');
        }

        $emailCheck = $this->db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND deleted_at IS NULL LIMIT 1");
        $emailCheck->execute([':email' => $email]);
        if ($emailCheck->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new \RuntimeException('Email already exists.');
        }

        if ($passwordHash === '') {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new \RuntimeException('Unable to hash password.');
            }
        }

        $uuid = $this->generateEntityUuid();
        $timestamp = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("INSERT INTO users
            (username, email, password, uuid, school_id, group_id, region_code, admin_notes, status, is_admin, created_at, updated_at)
            VALUES
            (:username, :email, :password, :uuid, :school_id, :group_id, :region_code, :admin_notes, :status, :is_admin, :created_at, :updated_at)");
        $stmt->execute([
            ':username' => $username,
            ':email' => $email,
            ':password' => $passwordHash,
            ':uuid' => $uuid,
            ':school_id' => $schoolId,
            ':group_id' => $groupId,
            ':region_code' => $regionCode,
            ':admin_notes' => $adminNotes,
            ':status' => $status,
            ':is_admin' => $isAdmin,
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        $userId = (int) $this->db->lastInsertId();
        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after creation.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_created', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'new_data' => [
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'uuid' => $freshUser['uuid'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'is_admin' => isset($freshUser['is_admin']) ? (bool) $freshUser['is_admin'] : false,
                'school_id' => $freshUser['school_id'] ?? null,
                'group_id' => $freshUser['group_id'] ?? null,
                'region_code' => $freshUser['region_code'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'username' => $username,
                'email' => $email,
                'status' => $status,
                'is_admin' => (bool) $isAdmin,
                'school_id' => $schoolId,
                'group_id' => $groupId,
                'region_code' => $regionCode,
                'admin_notes' => $adminNotes,
                'password_provided' => true,
            ],
        ]);

        return [
            'action' => 'create_user',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'is_admin' => isset($freshUser['is_admin']) ? (bool) $freshUser['is_admin'] : false,
                'school_id' => isset($freshUser['school_id']) ? (int) $freshUser['school_id'] : null,
                'school_name' => $freshUser['school_name'] ?? null,
                'group_id' => isset($freshUser['group_id']) ? (int) $freshUser['group_id'] : null,
                'group_name' => $freshUser['group_name'] ?? null,
                'region_code' => $freshUser['region_code'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function updateUserStatus(array $payload, array $logContext): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if ($status === '') {
            throw new \RuntimeException('status is required.');
        }

        $adminNotesProvided = array_key_exists('admin_notes', $payload);
        $adminNotes = $adminNotesProvided ? trim((string) ($payload['admin_notes'] ?? '')) : null;
        if ($adminNotes === '' && $adminNotesProvided) {
            $adminNotes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $sets = ['status = :status', 'updated_at = :updated_at'];
        $params = [
            ':status' => $status,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':user_id' => $userId,
        ];

        if ($adminNotesProvided) {
            $sets[] = 'admin_notes = :admin_notes';
            $params[':admin_notes'] = $adminNotes;
        }

        $stmt = $this->db->prepare("UPDATE users
            SET " . implode(', ', $sets) . "
            WHERE id = :user_id
              AND deleted_at IS NULL");
        $stmt->execute($params);

        $freshUser = $this->resolveUserRowFromPayload(['user_id' => $userId]);
        if ($freshUser === null) {
            throw new \RuntimeException('User not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('user_status_updated', $adminId, 'user_management', [
            'table' => 'users',
            'record_id' => $userId,
            'old_data' => [
                'status' => $user['status'] ?? null,
                'admin_notes' => $user['admin_notes'] ?? null,
            ],
            'new_data' => [
                'status' => $freshUser['status'] ?? null,
                'admin_notes' => $freshUser['admin_notes'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $freshUser['uuid'] ?? null,
                'status' => $status,
                'admin_notes' => $adminNotes,
            ],
        ]);

        return [
            'action' => 'update_user_status',
            'user' => [
                'id' => $userId,
                'uuid' => $freshUser['uuid'] ?? null,
                'username' => $freshUser['username'] ?? null,
                'email' => $freshUser['email'] ?? null,
                'status' => $freshUser['status'] ?? null,
                'admin_notes' => $freshUser['admin_notes'] ?? null,
            ],
            'old_status' => $user['status'] ?? null,
            'new_status' => $freshUser['status'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function awardBadgeToUser(array $payload, array $logContext): array
    {
        if ($this->badgeService === null) {
            throw new \RuntimeException('Badge service unavailable.');
        }

        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $badgeId = isset($payload['badge_id']) && is_numeric((string) $payload['badge_id']) ? (int) $payload['badge_id'] : 0;
        if ($badgeId <= 0) {
            throw new \RuntimeException('badge_id is required.');
        }

        $badge = $this->fetchBadgeById($badgeId);
        if ($badge === null) {
            throw new \RuntimeException('Badge not found.');
        }

        $notes = isset($payload['notes']) ? trim((string) ($payload['notes'] ?? '')) : null;
        if ($notes === '') {
            $notes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->badgeService->awardBadge($badgeId, $userId, [
            'source' => 'manual',
            'admin_id' => $adminId,
            'notes' => $notes,
            'meta' => [
                'source' => 'admin_ai',
                'conversation_id' => $logContext['conversation_id'] ?? null,
                'request_id' => $logContext['request_id'] ?? null,
            ],
        ]);

        $assignment = $this->fetchUserBadgeAssignment($userId, $badgeId);
        if ($assignment === null) {
            throw new \RuntimeException('Badge award did not persist.');
        }

        $this->auditLogService?->logAdminOperation('badge_awarded_via_ai', $adminId, 'badge_management', [
            'table' => 'user_badges',
            'record_id' => $assignment['id'] ?? null,
            'new_data' => [
                'user_id' => $userId,
                'badge_id' => $badgeId,
                'status' => $assignment['status'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $user['uuid'] ?? null,
                'badge_id' => $badgeId,
                'notes' => $notes,
            ],
        ]);

        return [
            'action' => 'award_badge_to_user',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
            ],
            'badge' => [
                'id' => $badgeId,
                'code' => $badge['code'] ?? null,
                'name' => $this->resolveBadgeDisplayName($badge),
            ],
            'assignment' => [
                'id' => $assignment['id'] ?? null,
                'status' => $assignment['status'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function revokeBadgeFromUser(array $payload, array $logContext): array
    {
        if ($this->badgeService === null) {
            throw new \RuntimeException('Badge service unavailable.');
        }

        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $badgeId = isset($payload['badge_id']) && is_numeric((string) $payload['badge_id']) ? (int) $payload['badge_id'] : 0;
        if ($badgeId <= 0) {
            throw new \RuntimeException('badge_id is required.');
        }

        $badge = $this->fetchBadgeById($badgeId);
        if ($badge === null) {
            throw new \RuntimeException('Badge not found.');
        }

        $notes = isset($payload['notes']) ? trim((string) ($payload['notes'] ?? '')) : null;
        if ($notes === '') {
            $notes = null;
        }

        $userId = (int) ($user['id'] ?? 0);
        $before = $this->fetchUserBadgeAssignment($userId, $badgeId);
        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $revoked = $this->badgeService->revokeBadge($badgeId, $userId, $adminId ?? 0, $notes);
        if (!$revoked) {
            throw new \RuntimeException('Badge revoke failed.');
        }

        $assignment = $this->fetchUserBadgeAssignment($userId, $badgeId);
        if ($assignment === null) {
            throw new \RuntimeException('Badge revoke result missing.');
        }

        $this->auditLogService?->logAdminOperation('badge_revoked_via_ai', $adminId, 'badge_management', [
            'table' => 'user_badges',
            'record_id' => $assignment['id'] ?? null,
            'old_data' => $before === null ? null : [
                'status' => $before['status'] ?? null,
            ],
            'new_data' => [
                'status' => $assignment['status'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'user_uuid' => $user['uuid'] ?? null,
                'badge_id' => $badgeId,
                'notes' => $notes,
            ],
        ]);

        return [
            'action' => 'revoke_badge_from_user',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
            ],
            'badge' => [
                'id' => $badgeId,
                'code' => $badge['code'] ?? null,
                'name' => $this->resolveBadgeDisplayName($badge),
            ],
            'assignment' => [
                'id' => $assignment['id'] ?? null,
                'status' => $assignment['status'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function updateExchangeStatus(array $payload, array $logContext): array
    {
        $exchangeId = trim((string) ($payload['exchange_id'] ?? ''));
        if ($exchangeId === '') {
            throw new \RuntimeException('exchange_id is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $allowedStatuses = ['processing', 'shipped', 'completed', 'cancelled', 'rejected'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new \RuntimeException('Invalid exchange status.');
        }

        $notes = isset($payload['notes']) ? trim((string) $payload['notes']) : null;
        if ($notes === '') {
            $notes = null;
        }
        $trackingNumber = isset($payload['tracking_number']) ? trim((string) $payload['tracking_number']) : null;
        if ($trackingNumber === '') {
            $trackingNumber = null;
        }

        $before = $this->fetchExchangeRecordById($exchangeId);
        if ($before === null) {
            throw new \RuntimeException('Exchange order not found.');
        }

        $stmt = $this->db->prepare("UPDATE point_exchanges
            SET status = :status,
                notes = :notes,
                tracking_number = :tracking_number,
                updated_at = :updated_at
            WHERE id = :exchange_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':tracking_number' => $trackingNumber,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':exchange_id' => $exchangeId,
        ]);

        $after = $this->fetchExchangeRecordById($exchangeId);
        if ($after === null) {
            throw new \RuntimeException('Exchange order not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('exchange_status_updated', $adminId, 'exchange_management', [
            'table' => 'point_exchanges',
            'record_id' => $exchangeId,
            'old_data' => [
                'status' => $before['status'] ?? null,
                'notes' => $before['notes'] ?? null,
                'tracking_number' => $before['tracking_number'] ?? null,
            ],
            'new_data' => [
                'status' => $after['status'] ?? null,
                'notes' => $after['notes'] ?? null,
                'tracking_number' => $after['tracking_number'] ?? null,
            ],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'exchange_id' => $exchangeId,
                'status' => $status,
                'notes' => $notes,
                'tracking_number' => $trackingNumber,
            ],
        ]);

        $this->sendExchangeStatusNotification($after, $status, $notes, $trackingNumber);

        return [
            'action' => 'update_exchange_status',
            'exchange' => [
                'id' => $after['id'] ?? null,
                'status' => $after['status'] ?? null,
                'product_name' => $after['product_name'] ?? null,
                'tracking_number' => $after['tracking_number'] ?? null,
                'username' => $after['username'] ?? null,
                'email' => $after['email'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function updateProductStatus(array $payload, array $logContext): array
    {
        $productId = isset($payload['product_id']) && is_numeric((string) $payload['product_id']) ? (int) $payload['product_id'] : 0;
        if ($productId <= 0) {
            throw new \RuntimeException('product_id is required.');
        }

        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new \RuntimeException('Invalid product status.');
        }

        $before = $this->fetchProductById($productId);
        if ($before === null) {
            throw new \RuntimeException('Product not found.');
        }

        $stmt = $this->db->prepare("UPDATE products
            SET status = :status,
                updated_at = :updated_at
            WHERE id = :product_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':status' => $status,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':product_id' => $productId,
        ]);

        $after = $this->fetchProductById($productId);
        if ($after === null) {
            throw new \RuntimeException('Product not found after update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('product_status_updated', $adminId, 'product_management', [
            'table' => 'products',
            'record_id' => $productId,
            'old_data' => ['status' => $before['status'] ?? null],
            'new_data' => ['status' => $after['status'] ?? null],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'product_id' => $productId,
                'status' => $status,
            ],
        ]);

        return [
            'action' => 'update_product_status',
            'product' => [
                'id' => $productId,
                'name' => $after['name'] ?? null,
                'status' => $after['status'] ?? null,
                'stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function adjustProductInventory(array $payload, array $logContext): array
    {
        $productId = isset($payload['product_id']) && is_numeric((string) $payload['product_id']) ? (int) $payload['product_id'] : 0;
        if ($productId <= 0) {
            throw new \RuntimeException('product_id is required.');
        }

        $before = $this->fetchProductById($productId);
        if ($before === null) {
            throw new \RuntimeException('Product not found.');
        }

        $targetStock = array_key_exists('target_stock', $payload) && is_numeric((string) $payload['target_stock'])
            ? (int) $payload['target_stock']
            : null;
        $stockDelta = array_key_exists('stock_delta', $payload) && is_numeric((string) $payload['stock_delta'])
            ? (int) $payload['stock_delta']
            : null;
        if ($targetStock === null && $stockDelta === null) {
            throw new \RuntimeException('Either target_stock or stock_delta is required.');
        }

        $oldStock = isset($before['stock']) ? (int) $before['stock'] : 0;
        $newStock = $targetStock ?? ($oldStock + (int) $stockDelta);
        if ($newStock < 0) {
            throw new \RuntimeException('Inventory cannot be negative.');
        }

        $reason = isset($payload['reason']) ? trim((string) ($payload['reason'] ?? '')) : null;
        if ($reason === '') {
            $reason = null;
        }

        $stmt = $this->db->prepare("UPDATE products
            SET stock = :stock,
                updated_at = :updated_at
            WHERE id = :product_id
              AND deleted_at IS NULL");
        $stmt->execute([
            ':stock' => $newStock,
            ':updated_at' => gmdate('Y-m-d H:i:s'),
            ':product_id' => $productId,
        ]);

        $after = $this->fetchProductById($productId);
        if ($after === null) {
            throw new \RuntimeException('Product not found after inventory update.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $this->auditLogService?->logAdminOperation('product_inventory_adjusted', $adminId, 'product_management', [
            'table' => 'products',
            'record_id' => $productId,
            'old_data' => ['stock' => $oldStock],
            'new_data' => ['stock' => isset($after['stock']) ? (int) $after['stock'] : 0],
            'request_id' => $logContext['request_id'] ?? null,
            'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
            'request_method' => 'POST',
            'conversation_id' => $logContext['conversation_id'] ?? null,
            'request_data' => [
                'product_id' => $productId,
                'stock_delta' => $stockDelta,
                'target_stock' => $targetStock,
                'reason' => $reason,
            ],
        ]);

        return [
            'action' => 'adjust_product_inventory',
            'product' => [
                'id' => $productId,
                'name' => $after['name'] ?? null,
                'status' => $after['status'] ?? null,
                'stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            ],
            'old_stock' => $oldStock,
            'new_stock' => isset($after['stock']) ? (int) $after['stock'] : 0,
            'stock_delta' => $stockDelta,
            'reason' => $reason,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    private function reviewCarbonRecords(string $action, array $payload, array $logContext): array
    {
        $recordIds = array_values(array_unique(array_filter(array_map(
            static fn ($item) => !is_array($item) && !is_object($item) ? trim((string) $item) : '',
            (array) ($payload['record_ids'] ?? [])
        ))));
        if ($recordIds === []) {
            throw new \RuntimeException('No record_ids provided.');
        }

        $reviewNote = isset($payload['review_note']) && is_string($payload['review_note']) ? trim($payload['review_note']) : null;
        if ($reviewNote === '') {
            $reviewNote = null;
        }

        $records = $this->fetchCarbonRecordsByIds($recordIds);
        if ($records === []) {
            throw new \RuntimeException('No records found for provided ids.');
        }

        $adminId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id']) ? (int) $logContext['actor_id'] : null;
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';
        $reviewedAt = gmdate('Y-m-d H:i:s');
        $processed = [];
        $skipped = [];
        $recordsByUser = [];

        $this->db->beginTransaction();
        try {
            $updateStmt = $this->db->prepare("UPDATE carbon_records
                SET status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_note = :review_note
                WHERE id = :record_id");
            $pointsStmt = $this->db->prepare("UPDATE users SET points = COALESCE(points, 0) + :points WHERE id = :user_id");

            foreach ($records as $record) {
                $recordId = (string) ($record['id'] ?? '');
                if ($recordId === '') {
                    continue;
                }
                if (($record['status'] ?? '') !== 'pending') {
                    $skipped[] = ['id' => $recordId, 'status' => $record['status'] ?? null];
                    continue;
                }

                $updateStmt->execute([
                    ':status' => $newStatus,
                    ':reviewed_by' => $adminId,
                    ':reviewed_at' => $reviewedAt,
                    ':review_note' => $reviewNote,
                    ':record_id' => $recordId,
                ]);

                if ($action === 'approve') {
                    $points = (int) ($record['points_earned'] ?? 0);
                    $userId = (int) ($record['user_id'] ?? 0);
                    if ($points !== 0 && $userId > 0) {
                        $pointsStmt->execute([':points' => $points, ':user_id' => $userId]);
                    }
                }

                $processed[] = $recordId;
                $record['status'] = $newStatus;
                $record['review_note'] = $reviewNote;
                $userId = (int) ($record['user_id'] ?? 0);
                if ($userId > 0) {
                    $recordsByUser[$userId][] = $this->buildReviewSummaryRecord($record);
                }

                $this->auditLogService?->logAdminOperation(
                    'carbon_record_' . ($action === 'approve' ? 'approve' : 'reject'),
                    $adminId,
                    'carbon_management',
                    [
                        'table' => 'carbon_records',
                        'record_id' => $recordId,
                        'old_data' => ['status' => 'pending'],
                        'new_data' => ['status' => $newStatus, 'review_note' => $reviewNote],
                        'request_id' => $logContext['request_id'] ?? null,
                        'endpoint' => $logContext['source'] ?? '/admin/ai/chat',
                        'request_method' => 'POST',
                        'conversation_id' => $logContext['conversation_id'] ?? null,
                    ]
                );
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        foreach ($recordsByUser as $userId => $userRecords) {
            if ($this->messageService !== null && $userRecords !== []) {
                $this->messageService->sendCarbonRecordReviewSummary($userId, $action, $userRecords, $reviewNote, [
                    'reviewed_by_id' => $adminId,
                ]);
            }
        }

        return [
            'action' => $action,
            'processed_ids' => $processed,
            'processed_count' => count($processed),
            'skipped' => $skipped,
            'review_note' => $reviewNote,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function resolveUserRowFromPayload(array $payload): ?array
    {
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;
        $userUuid = strtolower(trim((string) ($payload['user_uuid'] ?? '')));

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($userId !== null && $userId > 0) {
            $where[] = 'u.id = :user_id';
            $params[':user_id'] = $userId;
        } elseif ($userUuid !== '') {
            $where[] = 'LOWER(u.uuid) = :user_uuid';
            $params[':user_uuid'] = $userUuid;
        } else {
            return null;
        }

        $stmt = $this->db->prepare("SELECT u.*, s.name AS school_name, g.name AS group_name
            FROM users u
            LEFT JOIN schools s ON s.id = u.school_id
            LEFT JOIN user_groups g ON g.id = u.group_id
            WHERE " . implode(' AND ', $where) . "
            LIMIT 1");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function schoolExists(int $schoolId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM schools WHERE id = :school_id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':school_id' => $schoolId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    private function groupExists(int $groupId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM user_groups WHERE id = :group_id LIMIT 1");
        $stmt->execute([':group_id' => $groupId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchBadgeById(int $badgeId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM achievement_badges
            WHERE id = :badge_id
              AND deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':badge_id' => $badgeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchUserBadgeAssignment(int $userId, int $badgeId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM user_badges
            WHERE user_id = :user_id
              AND badge_id = :badge_id
            ORDER BY id DESC
            LIMIT 1");
        $stmt->execute([
            ':user_id' => $userId,
            ':badge_id' => $badgeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $badge
     */
    private function resolveBadgeDisplayName(array $badge): string
    {
        $name = trim((string) ($badge['name_zh'] ?? $badge['name_en'] ?? $badge['code'] ?? ''));
        return $name !== '' ? $name : '未命名徽章';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchProductById(int $productId): ?array
    {
        $stmt = $this->db->prepare("SELECT *
            FROM products
            WHERE id = :product_id
              AND deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':product_id' => $productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function resolvePointExchangeUserColumn(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $resolved = 'user_id';
        try {
            $driver = (string) ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) ?: 'mysql');
            if ($driver === 'sqlite') {
                $stmt = $this->db->query("PRAGMA table_info(point_exchanges)");
                $columns = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
                $names = array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), $columns);
                if (!in_array('user_id', $names, true) && in_array('uid', $names, true)) {
                    $resolved = 'uid';
                }
            }
        } catch (\Throwable) {
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchExchangeRecordById(string $exchangeId): ?array
    {
        $userColumn = $this->resolvePointExchangeUserColumn();
        $stmt = $this->db->prepare("SELECT e.*, u.username, u.email
            FROM point_exchanges e
            LEFT JOIN users u ON u.id = e.{$userColumn}
            WHERE e.id = :exchange_id
              AND e.deleted_at IS NULL
            LIMIT 1");
        $stmt->execute([':exchange_id' => $exchangeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $exchange
     */
    private function sendExchangeStatusNotification(array $exchange, string $status, ?string $notes, ?string $trackingNumber): void
    {
        if ($this->messageService === null) {
            return;
        }

        $statusMessages = [
            'processing' => '您的兑换订单正在处理中',
            'shipped' => '您的兑换商品已发货',
            'completed' => '您的兑换订单已完成',
            'cancelled' => '您的兑换订单已取消',
            'rejected' => '您的兑换订单已被驳回',
        ];
        $title = $statusMessages[$status] ?? '兑换状态更新';
        $message = sprintf(
            '您的兑换订单（%s x%s）状态已更新为：%s',
            (string) ($exchange['product_name'] ?? '未知商品'),
            (string) ($exchange['quantity'] ?? '1'),
            $title
        );
        if ($trackingNumber !== null && $trackingNumber !== '') {
            $message .= "\n物流单号：" . $trackingNumber;
        }
        if ($notes !== null && $notes !== '') {
            $message .= "\n备注：" . $notes;
        }

        $userColumn = $this->resolvePointExchangeUserColumn();
        $userId = isset($exchange[$userColumn]) ? (int) $exchange[$userColumn] : 0;
        if ($userId <= 0) {
            return;
        }

        $this->messageService->sendMessage(
            $userId,
            'exchange_status_updated',
            $title,
            $message,
            'normal'
        );
        $this->messageService->sendExchangeStatusUpdateEmailToUser(
            $userId,
            (string) ($exchange['product_name'] ?? ''),
            $status,
            $trackingNumber,
            $notes,
            isset($exchange['email']) ? (string) $exchange['email'] : null,
            isset($exchange['username']) ? (string) $exchange['username'] : null
        );
    }

    /**
     * @param array<int,string> $recordIds
     * @return array<int,array<string,mixed>>
     */
    private function fetchCarbonRecordsByIds(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($recordIds) as $index => $recordId) {
            $placeholder = ':record_id_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $recordId;
        }

        $sql = "SELECT r.id, r.user_id, r.activity_id, r.status, r.date, r.carbon_saved, r.points_earned,
                       r.review_note, u.username, u.email, a.name_zh AS activity_name_zh, a.name_en AS activity_name_en
                FROM carbon_records r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE r.id IN (" . implode(',', $placeholders) . ')';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function buildReviewSummaryRecord(array $record): array
    {
        return [
            'id' => $record['id'] ?? null,
            'date' => $record['date'] ?? null,
            'status' => $record['status'] ?? null,
            'carbon_saved' => isset($record['carbon_saved']) ? (float) $record['carbon_saved'] : null,
            'points_earned' => isset($record['points_earned']) ? (int) $record['points_earned'] : null,
            'activity_name' => $record['activity_name_zh'] ?? ($record['activity_name_en'] ?? null),
            'review_note' => $record['review_note'] ?? null,
        ];
    }

    private function normalizeBooleanFilter(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (!is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function generateEntityUuid(): string
    {
        try {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            return sprintf(
                '%s-%s-%s-%s-%s',
                substr($hex, 0, 8),
                substr($hex, 8, 4),
                substr($hex, 12, 4),
                substr($hex, 16, 4),
                substr($hex, 20, 12)
            );
        } catch (\Throwable) {
            return strtolower(sprintf(
                '%08s-%04s-4%03s-%04s-%012s',
                substr(md5(uniqid('user', true)), 0, 8),
                substr(md5(uniqid('user', true)), 8, 4),
                substr(md5(uniqid('user', true)), 12, 3),
                substr(md5(uniqid('user', true)), 15, 4),
                substr(md5(uniqid('user', true)), 19, 12)
            ));
        }
    }
}
