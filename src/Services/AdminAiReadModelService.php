<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;

class AdminAiReadModelService
{
    public function __construct(
        private PDO $db,
        private ?StatisticsService $statisticsService = null
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function execute(string $actionName, array $payload): array
    {
        return match ($actionName) {
            'get_admin_stats' => [
                'scope' => 'admin_stats',
                'data' => $this->statisticsService?->getAdminStats(false) ?? [],
            ],
            'get_pending_carbon_records' => $this->queryPendingCarbonRecords($payload),
            'get_llm_usage_analytics' => $this->queryLlmUsageAnalytics((int) ($payload['days'] ?? 30)),
            'get_activity_statistics' => $this->queryActivityStatistics($payload),
            'generate_admin_report' => $this->buildAdminReport((int) ($payload['days'] ?? 30)),
            'search_users' => $this->queryUsers($payload),
            'get_user_overview' => $this->queryUserOverview($payload),
            'get_exchange_orders' => $this->queryExchangeOrders($payload),
            'get_exchange_order_detail' => $this->queryExchangeOrderDetail($payload),
            'get_product_catalog' => $this->queryProductCatalog($payload),
            'get_passkey_admin_stats' => $this->queryPasskeyAdminStats(),
            'get_passkey_admin_list' => $this->queryPasskeyAdminList($payload),
            'search_system_logs' => $this->querySystemLogs($payload),
            'get_broadcast_history' => $this->queryBroadcastHistory($payload),
            'search_broadcast_recipients' => $this->queryBroadcastRecipients($payload),
            default => throw new \RuntimeException('Unsupported read action: ' . $actionName),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryPendingCarbonRecords(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 5)));
        $status = trim((string) ($payload['status'] ?? 'pending'));
        $where = ['r.deleted_at IS NULL', 'r.status = :status'];
        $params = [':status' => $status];

        if (!empty($payload['record_ids']) && is_array($payload['record_ids'])) {
            $placeholders = [];
            foreach (array_values($payload['record_ids']) as $index => $id) {
                $placeholder = ':record_id_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = (string) $id;
            }
            if ($placeholders !== []) {
                $where[] = 'r.id IN (' . implode(',', $placeholders) . ')';
            }
        }

        $sql = "SELECT r.id, r.status, r.date, r.carbon_saved, r.points_earned, u.username, u.email,
                       a.name_zh AS activity_name_zh, a.name_en AS activity_name_en
                FROM carbon_records r
                LEFT JOIN users u ON u.id = r.user_id
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.created_at DESC
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => $row['id'],
                'status' => $row['status'],
                'date' => $row['date'],
                'carbon_saved' => $row['carbon_saved'] !== null ? (float) $row['carbon_saved'] : null,
                'points_earned' => $row['points_earned'] !== null ? (int) $row['points_earned'] : null,
                'username' => $row['username'],
                'email' => $row['email'],
                'activity_name' => $row['activity_name_zh'] ?: ($row['activity_name_en'] ?: null),
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM carbon_records r WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'pending_carbon_records',
            'status' => $status,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function queryLlmUsageAnalytics(int $days): array
    {
        $days = max(7, min(90, $days));
        $since = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . max(0, $days - 1) . ' days')
            ->setTime(0, 0, 0)
            ->format('Y-m-d H:i:s');

        $summaryStmt = $this->db->prepare("SELECT COUNT(*) AS total_calls, COALESCE(SUM(total_tokens), 0) AS total_tokens,
                AVG(latency_ms) AS avg_latency_ms, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_calls
            FROM llm_logs WHERE created_at >= :since");
        $summaryStmt->execute([':since' => $since]);
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $topModelStmt = $this->db->prepare("SELECT model, COUNT(*) AS calls FROM llm_logs WHERE created_at >= :since GROUP BY model ORDER BY calls DESC LIMIT 1");
        $topModelStmt->execute([':since' => $since]);
        $topModel = $topModelStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $topSourceStmt = $this->db->prepare("SELECT source, COUNT(*) AS calls FROM llm_logs WHERE created_at >= :since GROUP BY source ORDER BY calls DESC LIMIT 1");
        $topSourceStmt->execute([':since' => $since]);
        $topSource = $topSourceStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'scope' => 'llm_usage_analytics',
            'days' => $days,
            'total_calls' => (int) ($summary['total_calls'] ?? 0),
            'total_tokens' => (int) ($summary['total_tokens'] ?? 0),
            'avg_latency_ms' => isset($summary['avg_latency_ms']) ? round((float) $summary['avg_latency_ms'], 2) : null,
            'success_calls' => (int) ($summary['success_calls'] ?? 0),
            'top_model' => $topModel['model'] ?? null,
            'top_source' => $topSource['source'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryActivityStatistics(array $payload): array
    {
        $activityId = trim((string) ($payload['activity_id'] ?? ''));
        $where = ['r.deleted_at IS NULL'];
        $params = [];
        if ($activityId !== '') {
            $where[] = 'r.activity_id = :activity_id';
            $params[':activity_id'] = $activityId;
        }

        $sql = "SELECT r.activity_id, a.name_zh AS activity_name_zh, a.name_en AS activity_name_en,
                       COUNT(*) AS record_count,
                       SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                       SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                       COALESCE(SUM(CASE WHEN r.status = 'approved' THEN r.carbon_saved ELSE 0 END), 0) AS approved_carbon_saved
                FROM carbon_records r
                LEFT JOIN carbon_activities a ON a.id = r.activity_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY r.activity_id, a.name_zh, a.name_en
                ORDER BY approved_carbon_saved DESC
                LIMIT 10";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'activity_id' => $row['activity_id'],
                'activity_name' => $row['activity_name_zh'] ?: ($row['activity_name_en'] ?: null),
                'record_count' => (int) ($row['record_count'] ?? 0),
                'approved_count' => (int) ($row['approved_count'] ?? 0),
                'pending_count' => (int) ($row['pending_count'] ?? 0),
                'approved_carbon_saved' => (float) ($row['approved_carbon_saved'] ?? 0),
            ];
        }

        return [
            'scope' => 'activity_statistics',
            'activity_id' => $activityId !== '' ? $activityId : null,
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildAdminReport(int $days): array
    {
        return [
            'scope' => 'admin_report',
            'days' => $days,
            'stats' => $this->statisticsService?->getAdminStats(false) ?? [],
            'llm' => $this->queryLlmUsageAnalytics($days),
            'pending' => $this->queryPendingCarbonRecords(['status' => 'pending', 'limit' => 5]),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryUsers(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? $payload['keyword'] ?? $payload['query'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        $userUuid = strtolower(trim((string) ($payload['user_uuid'] ?? '')));
        $schoolId = isset($payload['school_id']) && is_numeric((string) $payload['school_id']) ? (int) $payload['school_id'] : null;
        $role = strtolower(trim((string) ($payload['role'] ?? '')));

        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE :search OR u.email LIKE :search OR u.uuid LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }
        if ($userUuid !== '') {
            $where[] = 'LOWER(u.uuid) = :user_uuid';
            $params[':user_uuid'] = $userUuid;
        }
        if ($schoolId !== null && $schoolId > 0) {
            $where[] = 'u.school_id = :school_id';
            $params[':school_id'] = $schoolId;
        }
        if ($role === 'admin') {
            $where[] = 'u.is_admin = 1';
        } elseif ($role === 'user') {
            $where[] = 'u.is_admin = 0';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'username_asc' => 'u.username ASC, u.id ASC',
            'username_desc' => 'u.username DESC, u.id DESC',
            'points_asc' => 'u.points ASC, u.id ASC',
            'points_desc' => 'u.points DESC, u.id DESC',
            'created_at_asc' => 'u.created_at ASC, u.id ASC',
            default => 'u.created_at DESC, u.id DESC',
        };

        $sql = "SELECT u.id, u.uuid, u.username, u.email, u.status, u.points, u.is_admin, u.created_at,
                       s.name AS school_name, COALESCE(pk.passkey_count, 0) AS passkey_count
                FROM users u
                LEFT JOIN schools s ON s.id = u.school_id
                LEFT JOIN (
                    SELECT user_uuid, COUNT(*) AS passkey_count
                    FROM user_passkeys
                    WHERE disabled_at IS NULL
                    GROUP BY user_uuid
                ) pk ON LOWER(pk.user_uuid) = LOWER(u.uuid)
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'uuid' => $row['uuid'] ?? null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $row['status'] ?? null,
                'points' => isset($row['points']) ? (int) $row['points'] : 0,
                'is_admin' => !empty($row['is_admin']),
                'school_name' => $row['school_name'] ?? null,
                'passkey_count' => isset($row['passkey_count']) ? (int) $row['passkey_count'] : 0,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'users',
            'search' => $search !== '' ? $search : null,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryUserOverview(array $payload): array
    {
        $user = $this->resolveUserRowFromPayload($payload);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        $userId = (int) ($user['id'] ?? 0);
        $userUuid = strtolower((string) ($user['uuid'] ?? ''));

        $carbonStmt = $this->db->prepare("SELECT
                COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_saved ELSE 0 END), 0) AS total_carbon_saved,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_records
            FROM carbon_records
            WHERE user_id = :user_id
              AND deleted_at IS NULL");
        $carbonStmt->execute([':user_id' => $userId]);
        $carbon = $carbonStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $checkinStmt = $this->db->prepare("SELECT COUNT(*) AS checkin_days, MAX(checkin_date) AS last_checkin_date
            FROM user_checkins WHERE user_id = :user_id");
        $checkinStmt->execute([':user_id' => $userId]);
        $checkins = $checkinStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $badgeStmt = $this->db->prepare("SELECT COUNT(*) AS badge_count FROM user_badges WHERE user_id = :user_id");
        $badgeStmt->execute([':user_id' => $userId]);
        $badgeCount = (int) $badgeStmt->fetchColumn();

        $passkeyStmt = $this->db->prepare("SELECT COUNT(*) AS passkey_count, MAX(last_used_at) AS last_used_at
            FROM user_passkeys
            WHERE disabled_at IS NULL
              AND LOWER(user_uuid) = :user_uuid");
        $passkeyStmt->execute([':user_uuid' => $userUuid]);
        $passkeys = $passkeyStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'scope' => 'user_overview',
            'user' => [
                'id' => $userId,
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
                'status' => $user['status'] ?? null,
                'points' => isset($user['points']) ? (int) $user['points'] : 0,
                'is_admin' => !empty($user['is_admin']),
                'school_name' => $user['school_name'] ?? null,
                'group_name' => $user['group_name'] ?? null,
                'created_at' => $user['created_at'] ?? null,
                'last_login_at' => $user['lastlgn'] ?? null,
            ],
            'metrics' => [
                'total_carbon_saved' => isset($carbon['total_carbon_saved']) ? (float) $carbon['total_carbon_saved'] : 0.0,
                'approved_records' => (int) ($carbon['approved_records'] ?? 0),
                'pending_records' => (int) ($carbon['pending_records'] ?? 0),
                'checkin_days' => (int) ($checkins['checkin_days'] ?? 0),
                'last_checkin_date' => $checkins['last_checkin_date'] ?? null,
                'badge_count' => $badgeCount,
                'passkey_count' => (int) ($passkeys['passkey_count'] ?? 0),
                'last_passkey_used_at' => $passkeys['last_used_at'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryExchangeOrders(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;
        $userColumn = $this->resolvePointExchangeUserColumn();

        $where = ['e.deleted_at IS NULL'];
        $params = [];
        if ($status !== '') {
            $where[] = 'LOWER(e.status) = :status';
            $params[':status'] = $status;
        }
        if ($userId !== null && $userId > 0) {
            $where[] = "e.{$userColumn} = :user_id";
            $params[':user_id'] = $userId;
        }
        if ($search !== '') {
            $where[] = '(LOWER(e.id) LIKE :search OR LOWER(COALESCE(e.product_name, \'\')) LIKE :search OR LOWER(COALESCE(e.tracking_number, \'\')) LIKE :search OR LOWER(COALESCE(u.username, \'\')) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'created_at_asc' => 'e.created_at ASC, e.id ASC',
            'status_asc' => 'e.status ASC, e.created_at DESC',
            'points_desc' => 'e.points_used DESC, e.created_at DESC',
            default => 'e.created_at DESC, e.id DESC',
        };

        $sql = "SELECT e.id, e.status, e.product_name, e.quantity, e.points_used, e.tracking_number, e.created_at,
                       e.updated_at, e.notes, e.{$userColumn} AS exchange_user_id, u.username, u.email
                FROM point_exchanges e
                LEFT JOIN users u ON u.id = e.{$userColumn}
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => $row['id'] ?? null,
                'status' => $row['status'] ?? null,
                'product_name' => $row['product_name'] ?? null,
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
                'points_used' => isset($row['points_used']) ? (int) $row['points_used'] : null,
                'tracking_number' => $row['tracking_number'] ?? null,
                'user_id' => isset($row['exchange_user_id']) ? (int) $row['exchange_user_id'] : null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*)
            FROM point_exchanges e
            LEFT JOIN users u ON u.id = e.{$userColumn}
            WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'exchange_orders',
            'status' => $status !== '' ? $status : null,
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryExchangeOrderDetail(array $payload): array
    {
        $exchangeId = trim((string) ($payload['exchange_id'] ?? ''));
        if ($exchangeId === '') {
            throw new \RuntimeException('exchange_id is required.');
        }

        $exchange = $this->fetchExchangeRecordById($exchangeId);
        if ($exchange === null) {
            throw new \RuntimeException('Exchange order not found.');
        }

        $userColumn = $this->resolvePointExchangeUserColumn();

        return [
            'scope' => 'exchange_order_detail',
            'exchange' => [
                'id' => $exchange['id'] ?? null,
                'status' => $exchange['status'] ?? null,
                'product_id' => isset($exchange['product_id']) ? (int) $exchange['product_id'] : null,
                'product_name' => $exchange['product_name'] ?? null,
                'quantity' => isset($exchange['quantity']) ? (int) $exchange['quantity'] : null,
                'points_used' => isset($exchange['points_used']) ? (int) $exchange['points_used'] : null,
                'tracking_number' => $exchange['tracking_number'] ?? null,
                'delivery_address' => $exchange['delivery_address'] ?? null,
                'contact_phone' => $exchange['contact_phone'] ?? null,
                'notes' => $exchange['notes'] ?? null,
                'user_id' => isset($exchange[$userColumn]) ? (int) $exchange[$userColumn] : null,
                'username' => $exchange['username'] ?? null,
                'email' => $exchange['email'] ?? null,
                'created_at' => $exchange['created_at'] ?? null,
                'updated_at' => $exchange['updated_at'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryProductCatalog(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $category = trim((string) ($payload['category'] ?? ''));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));

        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if ($status !== '') {
            $where[] = 'LOWER(p.status) = :status';
            $params[':status'] = $status;
        }
        if ($category !== '') {
            $where[] = '(p.category = :category OR p.category_slug = :category_slug)';
            $params[':category'] = $category;
            $params[':category_slug'] = strtolower($category);
        }
        if ($search !== '') {
            $where[] = '(LOWER(p.name) LIKE :search OR LOWER(COALESCE(p.description, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'created_at_desc')));
        $orderBy = match ($sort) {
            'points_asc' => 'p.points_required ASC, p.id ASC',
            'points_desc' => 'p.points_required DESC, p.id DESC',
            'stock_desc' => 'p.stock DESC, p.id DESC',
            'created_at_asc' => 'p.created_at ASC, p.id ASC',
            default => 'p.created_at DESC, p.id DESC',
        };

        $sql = "SELECT p.id, p.name, p.category, p.category_slug, p.points_required, p.stock, p.status, p.created_at,
                       COALESCE(e.total_exchanged, 0) AS total_exchanged
                FROM products p
                LEFT JOIN (
                    SELECT product_id, COUNT(*) AS total_exchanged
                    FROM point_exchanges
                    WHERE deleted_at IS NULL
                    GROUP BY product_id
                ) e ON e.product_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'name' => $row['name'] ?? null,
                'category' => $row['category'] ?? null,
                'category_slug' => $row['category_slug'] ?? null,
                'points_required' => isset($row['points_required']) ? (int) $row['points_required'] : 0,
                'stock' => isset($row['stock']) ? (int) $row['stock'] : 0,
                'status' => $row['status'] ?? null,
                'total_exchanged' => isset($row['total_exchanged']) ? (int) $row['total_exchanged'] : 0,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM products p WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'product_catalog',
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function queryPasskeyAdminStats(): array
    {
        $statsStmt = $this->db->query("SELECT
                COUNT(*) AS total_passkeys,
                COUNT(DISTINCT user_uuid) AS users_with_passkeys,
                SUM(CASE WHEN backup_eligible = 1 THEN 1 ELSE 0 END) AS backup_eligible_count,
                SUM(CASE WHEN backup_state = 1 THEN 1 ELSE 0 END) AS backup_state_count,
                SUM(CASE WHEN last_used_at IS NULL THEN 1 ELSE 0 END) AS never_used_count,
                MAX(last_used_at) AS last_used_at
            FROM user_passkeys
            WHERE disabled_at IS NULL");
        $stats = $statsStmt instanceof \PDOStatement ? ($statsStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $recentStmt = $this->db->query("SELECT COUNT(*) FROM user_passkeys
            WHERE disabled_at IS NULL
              AND last_used_at IS NOT NULL
              AND last_used_at >= datetime('now', '-30 day')");

        return [
            'scope' => 'passkey_admin_stats',
            'total_passkeys' => (int) ($stats['total_passkeys'] ?? 0),
            'users_with_passkeys' => (int) ($stats['users_with_passkeys'] ?? 0),
            'backup_eligible_count' => (int) ($stats['backup_eligible_count'] ?? 0),
            'backup_state_count' => (int) ($stats['backup_state_count'] ?? 0),
            'never_used_count' => (int) ($stats['never_used_count'] ?? 0),
            'used_recently_30d' => (int) (($recentStmt instanceof \PDOStatement ? $recentStmt->fetchColumn() : 0) ?: 0),
            'last_used_at' => $stats['last_used_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryPasskeyAdminList(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['search'] ?? $payload['q'] ?? ''));
        $userId = isset($payload['user_id']) && is_numeric((string) $payload['user_id']) ? (int) $payload['user_id'] : null;

        $where = ['pk.disabled_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(LOWER(COALESCE(pk.label, \'\')) LIKE :search OR LOWER(COALESCE(u.username, \'\')) LIKE :search OR LOWER(COALESCE(u.email, \'\')) LIKE :search OR LOWER(COALESCE(pk.user_uuid, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower($search) . '%';
        }
        if ($userId !== null && $userId > 0) {
            $where[] = 'u.id = :user_id';
            $params[':user_id'] = $userId;
        }

        $sort = strtolower(trim((string) ($payload['sort'] ?? 'last_used_at_desc')));
        $orderBy = match ($sort) {
            'created_at_desc' => 'pk.created_at DESC, pk.id DESC',
            'sign_count_desc' => 'pk.sign_count DESC, pk.id DESC',
            default => 'pk.last_used_at DESC, pk.id DESC',
        };

        $sql = "SELECT pk.id, pk.user_uuid, pk.label, pk.sign_count, pk.backup_eligible, pk.backup_state,
                       pk.last_used_at, pk.created_at, u.id AS user_id, u.username, u.email
                FROM user_passkeys pk
                LEFT JOIN users u ON LOWER(u.uuid) = LOWER(pk.user_uuid)
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy}
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'user_uuid' => $row['user_uuid'] ?? null,
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'label' => $row['label'] ?? null,
                'sign_count' => isset($row['sign_count']) ? (int) $row['sign_count'] : 0,
                'backup_eligible' => !empty($row['backup_eligible']),
                'backup_state' => !empty($row['backup_state']),
                'last_used_at' => $row['last_used_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*)
            FROM user_passkeys pk
            LEFT JOIN users u ON LOWER(u.uuid) = LOWER(pk.user_uuid)
            WHERE " . implode(' AND ', $where));
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();

        return [
            'scope' => 'passkey_admin_list',
            'total' => (int) $countStmt->fetchColumn(),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function querySystemLogs(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $search = trim((string) ($payload['q'] ?? $payload['search'] ?? ''));
        $requestId = trim((string) ($payload['request_id'] ?? ''));
        $conversationId = $this->normalizeConversationId(isset($payload['conversation_id']) ? (string) $payload['conversation_id'] : null);
        $requestedTypes = is_array($payload['types'] ?? null) ? $payload['types'] : ['audit', 'llm', 'error'];
        $allowedTypes = ['audit', 'llm', 'error', 'system'];
        $types = array_values(array_intersect($allowedTypes, array_map(static fn ($item) => strtolower(trim((string) $item)), $requestedTypes)));
        if ($types === []) {
            $types = ['audit', 'llm', 'error'];
        }

        $items = [];
        $searchLike = $search !== '' ? '%' . strtolower($search) . '%' : null;

        if (in_array('audit', $types, true)) {
            $sql = "SELECT id, action, request_id, conversation_id, data, created_at
                FROM audit_logs
                WHERE operation_category = 'admin_ai'";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($conversationId !== null) {
                $sql .= " AND conversation_id = :conversation_id";
                $params[':conversation_id'] = $conversationId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(action) LIKE :search OR LOWER(COALESCE(data, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $data = $this->decodeJson($row['data'] ?? null);
                $items[] = [
                    'type' => 'audit',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'conversation_id' => $row['conversation_id'] ?? null,
                    'summary' => $data['visible_text'] ?? ($row['action'] ?? null),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('llm', $types, true)) {
            $sql = "SELECT id, request_id, conversation_id, turn_no, model, total_tokens, created_at
                FROM llm_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($conversationId !== null) {
                $sql .= " AND conversation_id = :conversation_id";
                $params[':conversation_id'] = $conversationId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(model, '')) LIKE :search OR LOWER(COALESCE(prompt, '')) LIKE :search OR LOWER(COALESCE(response_raw, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'llm',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'conversation_id' => $row['conversation_id'] ?? null,
                    'turn_no' => isset($row['turn_no']) ? (int) $row['turn_no'] : null,
                    'summary' => sprintf('%s / %s tokens', (string) ($row['model'] ?? 'unknown-model'), (string) ($row['total_tokens'] ?? 0)),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('error', $types, true)) {
            $sql = "SELECT id, request_id, error_type, error_message, created_at
                FROM error_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(error_type, '')) LIKE :search OR LOWER(COALESCE(error_message, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'error',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'summary' => trim((string) (($row['error_type'] ?? 'error') . ': ' . ($row['error_message'] ?? ''))),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        if (in_array('system', $types, true)) {
            $sql = "SELECT id, request_id, method, path, status_code, created_at
                FROM system_logs
                WHERE 1 = 1";
            $params = [];
            if ($requestId !== '') {
                $sql .= " AND request_id = :request_id";
                $params[':request_id'] = $requestId;
            }
            if ($searchLike !== null) {
                $sql .= " AND (LOWER(COALESCE(method, '')) LIKE :search OR LOWER(COALESCE(path, '')) LIKE :search)";
                $params[':search'] = $searchLike;
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $items[] = [
                    'type' => 'system',
                    'id' => (int) ($row['id'] ?? 0),
                    'request_id' => $row['request_id'] ?? null,
                    'summary' => trim((string) (($row['method'] ?? 'GET') . ' ' . ($row['path'] ?? '/') . ' [' . ($row['status_code'] ?? '?') . ']')),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });
        $items = array_slice($items, 0, $limit);

        return [
            'scope' => 'system_logs',
            'returned_count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryBroadcastHistory(array $payload): array
    {
        $limit = max(1, min(20, (int) ($payload['limit'] ?? 10)));
        $sql = "SELECT id, title, priority, scope, target_count, sent_count, created_by, created_at
            FROM message_broadcasts
            ORDER BY id DESC
            LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'title' => $row['title'] ?? null,
                'priority' => $row['priority'] ?? null,
                'scope' => $row['scope'] ?? null,
                'target_count' => isset($row['target_count']) ? (int) $row['target_count'] : 0,
                'sent_count' => isset($row['sent_count']) ? (int) $row['sent_count'] : 0,
                'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }

        $countStmt = $this->db->query("SELECT COUNT(*) FROM message_broadcasts");

        return [
            'scope' => 'broadcast_history',
            'total' => (int) (($countStmt instanceof \PDOStatement ? $countStmt->fetchColumn() : 0) ?: 0),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function queryBroadcastRecipients(array $payload): array
    {
        $users = $this->queryUsers([
            'search' => $payload['search'] ?? $payload['q'] ?? '',
            'status' => $payload['status'] ?? null,
            'limit' => $payload['limit'] ?? 20,
        ]);

        return [
            'scope' => 'broadcast_recipients',
            'total' => $users['total'] ?? 0,
            'items' => $users['items'] ?? [],
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

    private function normalizeConversationId(?string $conversationId): ?string
    {
        if (!is_string($conversationId)) {
            return null;
        }

        $normalized = trim($conversationId);
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $normalized) === 1 ? $normalized : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
