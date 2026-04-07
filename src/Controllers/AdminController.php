<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\CheckinService;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\StatisticsService;
use CarbonTrack\Services\QuotaConfigService;
use CarbonTrack\Services\UserProfileViewService;
use CarbonTrack\Support\Uuid;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

class AdminController
{
    private const SECURITY_ACTIVITY_ACTIONS = [
        'login',
        'passkey_login',
        'logout',
        'password_change',
        'passkey_registered',
        'passkey_deleted',
        'passkey_label_updated',
    ];
    private const SECURITY_ACTIVITY_TYPE_FILTERS = [
        'all' => self::SECURITY_ACTIVITY_ACTIONS,
        'sign_ins' => ['login', 'passkey_login'],
        'passkey_changes' => ['passkey_registered', 'passkey_deleted', 'passkey_label_updated'],
        'password_changes' => ['password_change'],
        'logouts' => ['logout'],
    ];
    private const SECURITY_ACTIVITY_PERIOD_FILTERS = [
        'all' => null,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    private UserProfileViewService $userProfileViewService;

    public function __construct(
        private PDO $db,
        private AuthService $authService,
        private AuditLogService $auditLog,
        private BadgeService $badgeService,
        private StatisticsService $statisticsService,
        private CheckinService $checkinService,
        private QuotaConfigService $quotaConfigService,
        UserProfileViewService $userProfileViewService,
        private ?ErrorLogService $errorLogService = null,
        private ?CloudflareR2Service $r2Service = null
    ) {
        $this->userProfileViewService = $userProfileViewService;
    }


    private ?string $lastLoginColumn = null;
    /**
     * 用户列表（带简单过滤与分页）
     */
    public function getUsers(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $params = $request->getQueryParams();
            $page   = max(1, (int)($params['page'] ?? 1));
            $limit  = min(100, max(10, (int)($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $rawSearch = $params['q'] ?? $params['search'] ?? $params['keyword'] ?? $params['query'] ?? null;
            $search   = $rawSearch !== null ? trim((string)$rawSearch) : '';
            $status   = trim((string)($params['status'] ?? ''));
            $schoolId = (int)($params['school_id'] ?? 0);
            $userUuid = '';
            if (isset($params['user_uuid']) && is_string($params['user_uuid'])) {
                $userUuid = trim((string) $params['user_uuid']);
            } elseif (isset($params['userUuid']) && is_string($params['userUuid'])) {
                $userUuid = trim((string) $params['userUuid']);
            }
            $roleFilter = isset($params['role']) ? strtolower(trim((string) $params['role'])) : '';
            if (!in_array($roleFilter, ['user', 'support', 'admin'], true)) {
                $roleFilter = '';
            }
            $isAdminParam = $params['is_admin'] ?? null;
            $isAdmin  = $isAdminParam;
            if ($isAdmin !== null) {
                $normalizedIsAdmin = (string)$isAdmin;
                if (in_array($normalizedIsAdmin, ['0', '1'], true)) {
                    $isAdmin = (int)$normalizedIsAdmin;
                } else {
                    $isAdmin = null;
                }
            }
            $sort     = (string)($params['sort'] ?? 'created_at_desc');

            $where = ['u.deleted_at IS NULL'];
            $queryParams = [];
            if ($search !== '') {
                $where[] = '(u.username LIKE :search_username OR u.email LIKE :search_email OR u.uuid LIKE :search_uuid)';
                $queryParams['search_username'] = "%{$search}%";
                $queryParams['search_email'] = "%{$search}%";
                $queryParams['search_uuid'] = "%{$search}%";
            }
            if ($status !== '') {
                $where[] = 'u.status = :status';
                $queryParams['status'] = $status;
            }
            if ($userUuid !== '' && Uuid::isValid($userUuid)) {
                $where[] = 'u.uuid = :user_uuid';
                $queryParams['user_uuid'] = strtolower($userUuid);
            }
            if ($schoolId > 0) {
                $where[] = 'u.school_id = :school_id';
                $queryParams['school_id'] = $schoolId;
            }
            if ($roleFilter === 'admin') {
                $where[] = '(u.is_admin = 1 OR LOWER(COALESCE(u.role, \'user\')) = :role_admin)';
                $queryParams['role_admin'] = 'admin';
            } elseif ($roleFilter === 'support') {
                $where[] = 'u.is_admin = 0 AND LOWER(COALESCE(u.role, \'user\')) = :role_support';
                $queryParams['role_support'] = 'support';
            } elseif ($roleFilter === 'user') {
                $where[] = 'u.is_admin = 0 AND LOWER(COALESCE(u.role, \'user\')) = :role_user';
                $queryParams['role_user'] = 'user';
            } elseif ($isAdmin !== null) {
                $where[] = 'u.is_admin = :is_admin';
                $queryParams['is_admin'] = (int)$isAdmin;
            }
            $whereClause = implode(' AND ', $where);

            $sortMap = [
                'username_asc' => 'u.username ASC',
                'username_desc' => 'u.username DESC',
                'email_asc' => 'u.email ASC',
                'email_desc' => 'u.email DESC',
                'points_asc' => 'u.points ASC',
                'points_desc' => 'u.points DESC',
                'created_at_asc' => 'u.created_at ASC',
                'created_at_desc' => 'u.created_at DESC',
            ];
            $orderBy = $sortMap[$sort] ?? 'u.created_at DESC';

                        $lastLoginSelect = $this->buildLastLoginSelect('u');

$sql = "
                SELECT
                    u.id, u.uuid, u.username, u.email, u.school_id,
                    u.points, u.is_admin, u.role, u.status, u.avatar_id, u.created_at, u.updated_at,
                    u.group_id, u.quota_override, u.admin_notes,
                    {$lastLoginSelect},
                    s.name as school_name,
                    g.name as group_name,
                    a.name as avatar_name, a.file_path as avatar_path,
                    COUNT(pt.id) as total_transactions,
                    COALESCE(SUM(CASE WHEN pt.status = 'approved' THEN pt.points ELSE 0 END), 0) as earned_points,
                    COALESCE(cr.total_carbon_saved, 0) as total_carbon_saved,
                    COALESCE(uc.checkin_days, 0) as checkin_days,
                    COALESCE(uc.makeup_checkins, 0) as makeup_checkins,
                    uc.last_checkin_date,
                    COALESCE(pk.passkey_count, 0) as passkey_count,
                    pk.last_passkey_used_at,
                    COALESCE(ub.badges_awarded, 0) as badges_awarded,
                    COALESCE(ub.badges_revoked, 0) as badges_revoked,
                    COALESCE(ub.active_badges, 0) as active_badges,
                    ub.last_badge_awarded_at
                FROM users u
                LEFT JOIN schools s ON u.school_id = s.id
                LEFT JOIN user_groups g ON u.group_id = g.id
                LEFT JOIN avatars a ON u.avatar_id = a.id
                LEFT JOIN points_transactions pt ON u.id = pt.uid AND pt.deleted_at IS NULL
                LEFT JOIN (
                    SELECT user_id, COALESCE(SUM(carbon_saved), 0) AS total_carbon_saved
                    FROM carbon_records
                    WHERE status = 'approved' AND deleted_at IS NULL
                    GROUP BY user_id
                ) cr ON u.id = cr.user_id
                LEFT JOIN (
                    SELECT user_id,
                        COUNT(*) AS checkin_days,
                        SUM(CASE WHEN source = 'makeup' THEN 1 ELSE 0 END) AS makeup_checkins,
                        MAX(checkin_date) AS last_checkin_date
                    FROM user_checkins
                    GROUP BY user_id
                ) uc ON u.id = uc.user_id
                LEFT JOIN (
                    SELECT
                        user_uuid,
                        COUNT(*) AS passkey_count,
                        MAX(last_used_at) AS last_passkey_used_at
                    FROM user_passkeys
                    WHERE disabled_at IS NULL
                    GROUP BY user_uuid
                ) pk ON u.uuid = pk.user_uuid
                LEFT JOIN (
                    SELECT user_id,
                        COUNT(*) AS badge_records,
                        SUM(CASE WHEN status = 'awarded' THEN 1 ELSE 0 END) AS badges_awarded,
                        SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS badges_revoked,
                        COUNT(DISTINCT CASE WHEN status = 'awarded' THEN badge_id ELSE NULL END) AS active_badges,
                        MAX(awarded_at) AS last_badge_awarded_at
                    FROM user_badges
                    GROUP BY user_id
                ) ub ON u.id = ub.user_id
                WHERE {$whereClause}
                GROUP BY u.id
                ORDER BY {$orderBy}
                LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);
            foreach ($queryParams as $k => $v) {
                $stmt->bindValue(":{$k}", $v);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $timezoneName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
            if (!$timezoneName) {
                $timezoneName = 'UTC';
            }
            $timezone = new DateTimeZone($timezoneName);
            foreach ($users as &$row) {
                $profileFields = $this->userProfileViewService->buildProfileFields($row);
                $row['school_id'] = $profileFields['school_id'];
                $row['school_name'] = $profileFields['school_name'];
                $row['is_admin'] = (bool) ($row['is_admin'] ?? false);
                $row['points'] = (float) ($row['points'] ?? 0);
                $row['total_transactions'] = (int) ($row['total_transactions'] ?? 0);
                $row['earned_points'] = (float) ($row['earned_points'] ?? 0);
                $row['total_carbon_saved'] = (float) ($row['total_carbon_saved'] ?? 0);
                $row['checkin_days'] = (int) ($row['checkin_days'] ?? 0);
                $row['makeup_checkins'] = (int) ($row['makeup_checkins'] ?? 0);
                $row['passkey_count'] = (int) ($row['passkey_count'] ?? 0);
                $row['badges_awarded'] = (int) ($row['badges_awarded'] ?? 0);
                $row['badges_revoked'] = (int) ($row['badges_revoked'] ?? 0);
                $row['active_badges'] = (int) ($row['active_badges'] ?? 0);
                $override = $this->quotaConfigService->decodeJsonToArray($row['quota_override'] ?? null);
                $row['quota_override'] = $override === null ? null : $this->quotaConfigService->normalizeQuotaConfig($override);
                $quotaOverrideConfig = is_array($row['quota_override']) ? $row['quota_override'] : [];
                $row['support_routing_override'] = $this->extractSupportRoutingOverride($quotaOverrideConfig);
                unset($quotaOverrideConfig['support_routing']);
                $row['quota_flat'] = $this->quotaConfigService->flattenQuotas($quotaOverrideConfig);
                $row['days_since_registration'] = 0;
                if (!empty($row['created_at'])) {
                    try {
                        $created = new DateTimeImmutable((string) $row['created_at'], $timezone);
                        $now = new DateTimeImmutable('now', $timezone);
                        $row['days_since_registration'] = max(0, (int) $created->diff($now)->format('%a'));
                    } catch (\Throwable $ignored) {
                        $row['days_since_registration'] = 0;
                    }
                }
            }
            unset($row);

            $countSql = "SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            foreach ($queryParams as $k => $v) {
                $countStmt->bindValue(":{$k}", $v);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            $this->auditLog->logDataChange(
                'admin',
                'users_list',
                (int)($user['id'] ?? 0),
                'admin',
                'users',
                null,
                null,
                null,
                ['filters' => $params, 'page' => $page, 'limit' => $limit]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request, 'getUsers exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    public function getUserBadges(Request $request, Response $response, array $args): Response
    {
        return $this->getUserBadgesForTarget($request, $response, $args);
    }

    public function getUserBadgesByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->getUserBadgesForTarget($request, $response, $args);
    }

    public function getUserOverview(Request $request, Response $response, array $args): Response
    {
        return $this->getUserOverviewForTarget($request, $response, $args);
    }

    public function getUserOverviewByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->getUserOverviewForTarget($request, $response, $args);
    }

    public function getUserSecurityActivity(Request $request, Response $response, array $args): Response
    {
        return $this->getUserSecurityActivityForTarget($request, $response, $args);
    }

    public function getUserSecurityActivityByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->getUserSecurityActivityForTarget($request, $response, $args);
    }

    private function getUserBadgesForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }
            $userId = $target['user']['id'];
            $userRow = $target['user'];

            $query = $request->getQueryParams();
            $includeRevoked = !empty($query['include_revoked']) && filter_var($query['include_revoked'], FILTER_VALIDATE_BOOLEAN);

            $badgePayload = $this->buildUserBadgePayload($userId, $includeRevoked);
            $badgePayload['metrics'] = $this->badgeService->compileUserMetrics($userId);
            $badgePayload['user'] = $userRow;

            return $this->jsonResponse($response, ['success' => true, 'data' => $badgePayload]);
        } catch (\Throwable $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function getUserOverviewForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }
            $userId = $target['user']['id'];
            $userRow = $target['user'];

            $metrics = $this->badgeService->compileUserMetrics($userId);
            $badgePayload = $this->buildUserBadgePayload($userId, true);
            $checkinStats = $this->checkinService->getUserStreakStats($userId);
              $payload = [
                  'user' => $userRow,
                  'metrics' => $metrics,
                  'badge_summary' => $badgePayload['summary'],
                  'recent_badges' => array_slice($badgePayload['items'], 0, 5),
                  'checkin_stats' => $checkinStats,
                  'passkey_summary' => $this->getUserPasskeySummary((string) ($userRow['uuid'] ?? '')),
                  'recent_security_activity' => $this->getRecentSecurityActivity(
                      $userId,
                      isset($userRow['uuid']) ? (string) $userRow['uuid'] : null,
                      10
                  ),
              ];

            return $this->jsonResponse($response, ['success' => true, 'data' => $payload]);
        } catch (\Throwable $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取待审核交易列表
     */
    public function getPendingTransactions(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = min(100, max(10, (int)($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $sql = "SELECT pt.id, pt.activity_id, pt.points, pt.notes, pt.img AS img, pt.status, pt.created_at, pt.updated_at,
                           u.username, u.email,
                           ca.name_zh as activity_name_zh, ca.name_en as activity_name_en,
                           ca.category, ca.carbon_factor, ca.unit as activity_unit
                    FROM points_transactions pt
                    JOIN users u ON pt.uid = u.id
                    LEFT JOIN carbon_activities ca ON pt.activity_id = ca.id
                    WHERE pt.status = 'pending' AND pt.deleted_at IS NULL
                    ORDER BY pt.created_at ASC
                    LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($transactions as &$t) {
                $imgs = [];
                if (!empty($t['img'])) {
                    $decoded = json_decode((string)$t['img'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $imgs = $decoded;
                    } else {
                        $imgs = [(string)$t['img']];
                    }
                }
                // 兼容字符串/对象混合，补充预签名直链
                foreach ($imgs as &$img) {
                    if (is_string($img)) {
                        $img = [ 'url' => $this->r2Service?->generatePresignedUrl($img, 600) ?? $img, 'file_path' => $img ];
                    } elseif (is_array($img) && !empty($img['file_path']) && empty($img['url'])) {
                        $img['url'] = $this->r2Service?->generatePresignedUrl($img['file_path'], 600) ?? $img['file_path'];
                    }
                }
                unset($img);
                $t['images'] = $imgs;
                unset($t['img']);
            }

            $total = (int)$this->db->query("SELECT COUNT(*) FROM points_transactions pt WHERE pt.status='pending' AND pt.deleted_at IS NULL")->fetchColumn();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'transactions' => $transactions,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员统计数据（跨数据库兼容）
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $params = $request->getQueryParams();
            $forceParam = $params['force'] ?? $params['refresh'] ?? null;
            if ($forceParam === null) {
                $forceRefresh = true;
            } else {
                $parsed = filter_var($forceParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $forceRefresh = $parsed ?? true;
            }

            $stats = $this->statisticsService->getAdminStats($forceRefresh);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 审计日志列表
     */
    public function getLogs(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = min(100, max(10, (int)($params['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $filters = [];
            if (!empty($params['action'])) {
                $filters['action'] = '%' . trim($params['action']) . '%'; // Partial match for action
            }
            if (!empty($params['actor_type'])) {
                $filters['actor_type'] = trim($params['actor_type']);
            }
            if (!empty($params['user_id'])) {
                $filters['user_id'] = (int)$params['user_id'];
            }
            if (!empty($params['user_uuid']) && is_string($params['user_uuid']) && Uuid::isValid(trim((string) $params['user_uuid']))) {
                $filters['user_uuid'] = strtolower(trim((string) $params['user_uuid']));
            } elseif (!empty($params['userUuid']) && is_string($params['userUuid']) && Uuid::isValid(trim((string) $params['userUuid']))) {
                $filters['user_uuid'] = strtolower(trim((string) $params['userUuid']));
            }
            if (!empty($params['operation_category'])) {
                $filters['category'] = trim($params['operation_category']);
            }
            if (!empty($params['status'])) {
                $filters['status'] = trim($params['status']);
            }
            if (!empty($params['date_from'])) {
                $filters['date_from'] = trim($params['date_from']) . ' 00:00:00';
            }
            if (!empty($params['date_to'])) {
                $filters['date_to'] = trim($params['date_to']) . ' 23:59:59';
            }

            $logs = $this->auditLog->getAuditLogs($filters, $limit, $offset);

            // Get total count for pagination
            $countFilters = $filters;
            unset($countFilters['limit'], $countFilters['offset']); // Not needed for count
            $total = $this->auditLog->getAuditLogsCount($countFilters);

            $this->auditLog->logAdminOperation('audit_logs_viewed', $user['id'], 'admin', [
                'filters' => $filters,
                'page' => $page,
                'limit' => $limit
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => $total > 0 ? (int)ceil($total / $limit) : 0
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 更新用户 is_admin / status
     */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        return $this->updateUserForTarget($request, $response, $args);
    }

    public function updateUserByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->updateUserForTarget($request, $response, $args);
    }

    private function updateUserForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }
            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }
            $userId = $target['user']['id'];
            $userRow = $target['user'];

            $payload = $request->getParsedBody() ?? [];
            $sets = [];
            $params = ['id' => $userId];

            if (array_key_exists('role', $payload)) {
                $role = strtolower(trim((string) $payload['role']));
                if (!in_array($role, ['user', 'support', 'admin'], true)) {
                    return $this->jsonResponse($response, ['error' => 'Invalid role'], 422);
                }
                $sets[] = 'role = :role';
                $params['role'] = $role;
                $sets[] = 'is_admin = :is_admin';
                $params['is_admin'] = $role === 'admin' ? 1 : 0;
            } elseif (array_key_exists('is_admin', $payload)) {
                $sets[] = 'is_admin = :is_admin';
                $params['is_admin'] = (int)!!$payload['is_admin'];
                $sets[] = 'role = :role';
                $params['role'] = !empty($payload['is_admin'])
                    ? 'admin'
                    : (strtolower((string) ($userRow['role'] ?? 'user')) === 'support' ? 'support' : 'user');
            }
            if (array_key_exists('status', $payload)) {
                $sets[] = 'status = :status';
                $params['status'] = trim((string)$payload['status']);
            }
            if (array_key_exists('group_id', $payload)) {
                $sets[] = 'group_id = :group_id';
                $val = $payload['group_id'];
                $params['group_id'] = ($val === '' || $val === null) ? null : (int)$val;
            }
            if (array_key_exists('quota_override', $payload)) {
                $sets[] = 'quota_override = :quota_override';
                $val = $payload['quota_override'];
                if (is_array($val)) {
                    $val = $this->quotaConfigService->normalizeQuotaConfig($val);
                }
                $params['quota_override'] = is_array($val) ? json_encode($val) : $val; // null stays null
            }
            if (array_key_exists('quota_flat', $payload) && is_array($payload['quota_flat'])) {
                // Fetch current quota override if not provided in payload's quota_override
                if (!array_key_exists('quota_override', $params)) {
                     // We need to fetch the current value to merge safely
                     $currStmt = $this->db->prepare("SELECT quota_override FROM users WHERE id = :id");
                     $currStmt->execute(['id' => $userId]);
                     $currRaw = $currStmt->fetchColumn();
                     $currentJson = $this->quotaConfigService->decodeJsonToArray($currRaw) ?? [];
                } else {
                    // If we are also updating quota_override directly, use that as base (unlikely but safe)
                    $currentJson = $this->quotaConfigService->decodeJsonToArray($params['quota_override']) ?? [];
                }

                $newJson = $this->quotaConfigService->unflattenQuotas($payload['quota_flat'], $currentJson);
                
                // If quota_override was already in sets, update it; otherwise add it
                $jsonStr = json_encode($newJson);
                if (in_array('quota_override = :quota_override', $sets)) {
                    $params['quota_override'] = $jsonStr;
                } else {
                    $sets[] = 'quota_override = :quota_override';
                    $params['quota_override'] = $jsonStr;
                }
            }
            if (array_key_exists('support_routing', $payload) && is_array($payload['support_routing'])) {
                if (!array_key_exists('quota_override', $params)) {
                    $currStmt = $this->db->prepare("SELECT quota_override FROM users WHERE id = :id");
                    $currStmt->execute(['id' => $userId]);
                    $currentJson = $this->quotaConfigService->decodeJsonToArray($currStmt->fetchColumn()) ?? [];
                } else {
                    $currentJson = $this->quotaConfigService->decodeJsonToArray($params['quota_override']) ?? [];
                }

                $supportRoutingOverride = $this->sanitizeSupportRoutingOverride($payload['support_routing']);
                if ($supportRoutingOverride === []) {
                    unset($currentJson['support_routing']);
                } else {
                    $currentJson['support_routing'] = $supportRoutingOverride;
                }

                $jsonStr = $currentJson === [] ? null : json_encode($currentJson);
                if (in_array('quota_override = :quota_override', $sets)) {
                    $params['quota_override'] = $jsonStr;
                } else {
                    $sets[] = 'quota_override = :quota_override';
                    $params['quota_override'] = $jsonStr;
                }
            }
            if (array_key_exists('admin_notes', $payload)) {
                $sets[] = 'admin_notes = :admin_notes';
                $params['admin_notes'] = $payload['admin_notes'];
            }

            if (empty($sets)) {
                 return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $sets[] = 'updated_at = :updated_at';
            $params['updated_at'] = date('Y-m-d H:i:s');

            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $this->auditLog->logDataChange(
                'admin',
                'user_update',
                $admin['id'] ?? null,
                'admin',
                'users',
                $userId,
                null,
                null,
                [
                    'fields' => array_keys($params),
                    'user_uuid' => $userRow['uuid'] ?? null,
                ]
            );

            return $this->jsonResponse($response, ['success' => true]);
        } catch (\Exception $e) {
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function getUserSecurityActivityForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }

            $query = $request->getQueryParams();
            $page = max(1, (int) ($query['page'] ?? 1));
            $limit = min(100, max(1, (int) ($query['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $filters = $this->resolveSecurityActivityFilters($query);
            $userRow = $target['user'];
            $result = $this->fetchSecurityActivityTimeline(
                (int) $userRow['id'],
                isset($userRow['uuid']) ? (string) $userRow['uuid'] : null,
                $filters,
                $limit,
                $offset
            );

            $this->auditLog->logDataChange(
                'admin',
                'user_security_activity_viewed',
                (int) ($admin['id'] ?? 0),
                'admin',
                'audit_logs',
                (int) $userRow['id'],
                null,
                [
                    'page' => $page,
                    'limit' => $limit,
                    'type' => $filters['type'],
                    'period' => $filters['period'],
                    'count' => count($result['items']),
                ],
                ['change_type' => 'read']
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'items' => $result['items'],
                    'filters' => [
                        'type' => $filters['type'],
                        'period' => $filters['period'],
                    ],
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $result['total'],
                        'total_pages' => $result['total'] > 0 ? (int) ceil($result['total'] / $limit) : 0,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        return $this->deleteUserForTarget($request, $response, $args);
    }

    public function deleteUserByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->deleteUserForTarget($request, $response, $args);
    }

    private function deleteUserForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }
            $userRow = $target['user'];
            $userId = $userRow['id'];

            if ((int) ($admin['id'] ?? 0) === $userId) {
                return $this->jsonResponse($response, ['error' => 'Cannot delete current admin user'], 400);
            }

            $stmt = $this->db->prepare(
                'UPDATE users SET deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL'
            );
            $timestamp = date('Y-m-d H:i:s');
            $stmt->execute([
                'deleted_at' => $timestamp,
                'updated_at' => $timestamp,
                'id' => $userId,
            ]);

            if ($stmt->rowCount() < 1) {
                return $this->jsonResponse($response, ['error' => 'User not found'], 404);
            }

            $this->auditLog->logDataChange(
                'admin',
                'user_delete',
                $admin['id'] ?? null,
                'admin',
                'users',
                $userId,
                $userRow,
                ['deleted_at' => $timestamp],
                ['user_uuid' => $userRow['uuid'] ?? null]
            );

            return $this->jsonResponse($response, ['success' => true]);
        } catch (\Throwable $e) {
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    public function adjustUserPoints(Request $request, Response $response, array $args): Response
    {
        return $this->adjustUserPointsForTarget($request, $response, $args);
    }

    public function adjustUserPointsByUuid(Request $request, Response $response, array $args): Response
    {
        return $this->adjustUserPointsForTarget($request, $response, $args);
    }

    private function adjustUserPointsForTarget(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $target = $this->resolveUserTarget($args);
            if ($target['error'] !== null) {
                return $this->jsonResponse($response, ['error' => $target['error']], $target['status']);
            }
            $userRow = $target['user'];
            $userId = $userRow['id'];

            $payload = $request->getParsedBody();
            $data = is_array($payload) ? $payload : [];
            $delta = isset($data['delta']) && is_numeric($data['delta']) ? (float) $data['delta'] : null;
            $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
            if ($delta === null || $delta == 0.0) {
                return $this->jsonResponse($response, ['error' => 'Invalid points delta'], 400);
            }

            $updatedAt = date('Y-m-d H:i:s');
            $stmt = $this->db->prepare(
                'UPDATE users SET points = COALESCE(points, 0) + :delta, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL'
            );
            $stmt->execute([
                'delta' => $delta,
                'updated_at' => $updatedAt,
                'id' => $userId,
            ]);

            if ($stmt->rowCount() < 1) {
                return $this->jsonResponse($response, ['error' => 'User not found'], 404);
            }

            $freshUser = $this->loadUserRow($userId);
            if ($freshUser === null) {
                return $this->jsonResponse($response, ['error' => 'User not found'], 404);
            }

            $this->auditLog->logDataChange(
                'admin',
                'user_points_adjusted',
                $admin['id'] ?? null,
                'admin',
                'users',
                $userId,
                ['points' => $userRow['points'] ?? null],
                ['points' => $freshUser['points'] ?? null],
                [
                    'reason' => $reason,
                    'delta' => $delta,
                    'user_uuid' => $userRow['uuid'] ?? null,
                ]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'user' => $freshUser,
                    'delta' => $delta,
                    'reason' => $reason,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function buildUserBadgePayload(int $userId, bool $includeRevoked = false): array
    {
        $records = $this->badgeService->getUserBadges($userId, $includeRevoked);
        $items = [];
        $awarded = 0;
        $revoked = 0;
        foreach ($records as $entry) {
            $badge = $entry['badge'] ?? null;
            if (is_array($badge)) {
                $badge = $this->formatBadgeForAdmin($badge);
            }
            $userBadge = $entry['user_badge'] ?? [];
            $status = $userBadge['status'] ?? null;
            if ($status === 'awarded') {
                $awarded++;
            } elseif ($status === 'revoked') {
                $revoked++;
            }
            $items[] = [
                'badge' => $badge,
                'user_badge' => $userBadge,
            ];
        }

        return [
            'items' => $items,
            'badges' => $items,
            'summary' => [
                'awarded' => $awarded,
                'revoked' => $revoked,
                'total' => $awarded + $revoked,
            ],
        ];
    }

    private function formatBadgeForAdmin(array $badge): array
    {
        if ($this->r2Service && !empty($badge['icon_path'])) {
            try {
                $badge['icon_url'] = $this->r2Service->getPublicUrl($badge['icon_path']);
                $badge['icon_presigned_url'] = $this->r2Service->generatePresignedUrl($badge['icon_path'], 600);
            } catch (\Throwable $e) {
                // ignore formatting failures for optional assets
            }
        }
        if ($this->r2Service && !empty($badge['icon_thumbnail_path'])) {
            try {
                $badge['icon_thumbnail_url'] = $this->r2Service->getPublicUrl($badge['icon_thumbnail_path']);
            } catch (\Throwable $ignore) {}
        }
        return $badge;
    }

    /**
     * @param array<string, mixed> $args
     * @return array{user: array<string, mixed>|null, error: string|null, status: int}
     */
    private function resolveUserTarget(array $args): array
    {
        if (isset($args['uuid'])) {
            $userUuid = trim((string) $args['uuid']);
            if ($userUuid === '' || !Uuid::isValid($userUuid)) {
                return ['user' => null, 'error' => 'Invalid user uuid', 'status' => 400];
            }

            $user = $this->loadUserRowByUuid($userUuid);
            if ($user === null) {
                return ['user' => null, 'error' => 'User not found', 'status' => 404];
            }

            return ['user' => $user, 'error' => null, 'status' => 200];
        }

        $userId = isset($args['id']) ? (int) $args['id'] : 0;
        if ($userId <= 0) {
            return ['user' => null, 'error' => 'Invalid user id', 'status' => 400];
        }

        $user = $this->loadUserRow($userId);
        if ($user === null) {
            return ['user' => null, 'error' => 'User not found', 'status' => 404];
        }

        return ['user' => $user, 'error' => null, 'status' => 200];
    }

    private function loadUserRow(int $userId): ?array
    {
        return $this->loadUserRowByColumn('u.id = :value', ['value' => $userId]);
    }

    private function loadUserRowByUuid(string $userUuid): ?array
    {
        return $this->loadUserRowByColumn('u.uuid = :value', ['value' => strtolower($userUuid)]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function loadUserRowByColumn(string $whereClause, array $params): ?array
    {
        $lastLoginSelect = $this->buildLastLoginSelect('u');
        $stmt = $this->db->prepare(
              'SELECT
                u.id,
                u.uuid,
                u.username,
                u.email,
                u.status,
                u.is_admin,
                u.points,
                u.created_at,
                u.updated_at,
                u.school_id,
                s.name as school_name,
                COALESCE(pk.passkey_count, 0) as passkey_count,
                pk.last_passkey_used_at,
                ' . $lastLoginSelect . '
             FROM users u
             LEFT JOIN schools s ON u.school_id = s.id
             LEFT JOIN (
                SELECT user_uuid, COUNT(*) AS passkey_count, MAX(last_used_at) AS last_passkey_used_at
                FROM user_passkeys
                WHERE disabled_at IS NULL
                GROUP BY user_uuid
             ) pk ON pk.user_uuid = u.uuid
             WHERE ' . $whereClause . ' AND u.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $profileFields = $this->userProfileViewService->buildProfileFields($row);
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['uuid'] = isset($row['uuid']) ? strtolower((string) $row['uuid']) : null;
        $row['school_id'] = $profileFields['school_id'];
        $row['school_name'] = $profileFields['school_name'];
        $row['is_admin'] = (bool) ($row['is_admin'] ?? false);
        $row['points'] = (float) ($row['points'] ?? 0);
        $row['passkey_count'] = (int) ($row['passkey_count'] ?? 0);
        $row['days_since_registration'] = $this->computeDaysSince($row['created_at'] ?? null);
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserPasskeySummary(string $userUuid): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN backup_state = 1 THEN 1 ELSE 0 END) AS backup_enabled,
                SUM(CASE WHEN backup_eligible = 1 THEN 1 ELSE 0 END) AS backup_eligible,
                MAX(last_used_at) AS last_used_at,
                MAX(created_at) AS last_registered_at
             FROM user_passkeys
             WHERE user_uuid = :user_uuid AND disabled_at IS NULL'
        );
        $stmt->execute(['user_uuid' => strtolower($userUuid)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'backup_enabled' => (int) ($row['backup_enabled'] ?? 0),
            'backup_eligible' => (int) ($row['backup_eligible'] ?? 0),
            'last_used_at' => $row['last_used_at'] ?? null,
            'last_registered_at' => $row['last_registered_at'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentSecurityActivity(int $userId, ?string $userUuid, int $limit): array
    {
        $result = $this->fetchSecurityActivityTimeline(
            $userId,
            $userUuid,
            [
                'type' => 'all',
                'period' => 'all',
                'actions' => self::SECURITY_ACTIVITY_ACTIONS,
                'days' => null,
            ],
            $limit,
            0
        );

        return $result['items'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{type: string, period: string, actions: array<int, string>, days: int|null}
     */
    private function resolveSecurityActivityFilters(array $query): array
    {
        $type = (string) ($query['type'] ?? 'all');
        if (!isset(self::SECURITY_ACTIVITY_TYPE_FILTERS[$type])) {
            $type = 'all';
        }

        $period = (string) ($query['period'] ?? 'all');
        if (!array_key_exists($period, self::SECURITY_ACTIVITY_PERIOD_FILTERS)) {
            $period = 'all';
        }

        $days = self::SECURITY_ACTIVITY_PERIOD_FILTERS[$period];

        return [
            'type' => $type,
            'period' => $period,
            'actions' => self::SECURITY_ACTIVITY_TYPE_FILTERS[$type],
            'days' => is_int($days) ? $days : null,
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function fetchSecurityActivityTimeline(int $userId, ?string $userUuid, array $filters, int $limit, int $offset): array
    {
        $actions = $filters['actions'] ?? self::SECURITY_ACTIVITY_ACTIONS;
        $placeholders = implode(', ', array_fill(0, count($actions), '?'));
        $normalizedUuid = is_string($userUuid) && trim($userUuid) !== '' ? strtolower(trim($userUuid)) : null;
        if ($normalizedUuid !== null) {
            $where = [
                '(user_uuid = ? OR (user_uuid IS NULL AND user_id = ?))',
                "action IN ({$placeholders})",
            ];
            $baseParams = array_merge([$normalizedUuid, $userId], $actions);
        } else {
            $where = [
                'user_id = ?',
                "action IN ({$placeholders})",
            ];
            $baseParams = array_merge([$userId], $actions);
        }
        $days = isset($filters['days']) && is_int($filters['days']) ? $filters['days'] : null;
        if ($days !== null) {
            $where[] = $this->buildSecurityActivityPeriodClause($days);
        }
        $whereSql = implode(' AND ', $where);

        $listStmt = $this->db->prepare(
            "SELECT id, action, status, actor_type, ip_address, user_agent, request_id, data, created_at
             FROM audit_logs
             WHERE {$whereSql}
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?"
        );
        $listStmt->execute(array_merge($baseParams, [$limit, $offset]));
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM audit_logs
             WHERE {$whereSql}"
        );
        $countStmt->execute($baseParams);

        return [
            'items' => array_map([$this, 'normalizeSecurityActivityRow'], $rows),
            'total' => (int) $countStmt->fetchColumn(),
        ];
    }

    private function buildSecurityActivityPeriodClause(int $days): string
    {
        $safeDays = max(1, $days);
        $driver = strtolower((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME));

        if ($driver === 'sqlite') {
            return sprintf("created_at >= datetime('now', '-%d days')", $safeDays);
        }

        return sprintf('created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $safeDays);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSecurityActivityRow(array $row): array
    {
        $metadata = $this->decodeAuditPayload($row['data'] ?? null);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'action' => (string) ($row['action'] ?? ''),
            'status' => (string) ($row['status'] ?? 'success'),
            'actor_type' => (string) ($row['actor_type'] ?? 'user'),
            'occurred_at' => $row['created_at'] ?? null,
            'ip_address' => $metadata['ip_address'] ?? ($row['ip_address'] ?? null),
            'user_agent' => $metadata['user_agent'] ?? ($row['user_agent'] ?? null),
            'request_id' => $row['request_id'] ?? null,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAuditPayload(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function computeDaysSince(?string $timestamp): int
    {
        if (!$timestamp) {
            return 0;
        }
        try {
            $timezoneName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
            if (!$timezoneName) {
                $timezoneName = 'UTC';
            }
            $timezone = new DateTimeZone($timezoneName);
            $created = new DateTimeImmutable((string) $timestamp, $timezone);
            $now = new DateTimeImmutable('now', $timezone);
            return max(0, (int) $created->diff($now)->format('%a'));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function buildLastLoginSelect(string $alias = 'u'): string
    {
        $column = $this->resolveLastLoginColumn();
        if ($column === null) {
            return 'NULL AS lastlgn';
        }
        return $alias . '.' . $column . ' AS lastlgn';
    }

    private function resolveLastLoginColumn(): ?string
    {
        if ($this->lastLoginColumn !== null) {
            return $this->lastLoginColumn !== '' ? $this->lastLoginColumn : null;
        }

        foreach (['lastlgn', 'last_login_at'] as $candidate) {
            if ($this->columnExists('users', $candidate)) {
                $this->lastLoginColumn = $candidate;
                return $candidate;
            }
        }

        $this->lastLoginColumn = '';
        return null;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            $driver = null;
        }

        try {
            if ($driver === 'sqlite') {
                $stmt = $this->db->query('PRAGMA table_info(' . $table . ')');
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (isset($row['name']) && strcasecmp((string) $row['name'], $column) === 0) {
                            return true;
                        }
                    }
                }
                return false;
            }

            $stmt = $this->db->prepare(sprintf('SHOW COLUMNS FROM `%s` LIKE ?', $table));
            if ($stmt && $stmt->execute([$column])) {
                return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
            }
        } catch (\Throwable $e) {
            // ignore detection errors
        }

        return false;
    }

    private function extractSupportRoutingOverride(array $quotaOverride): array
    {
        $supportRouting = $quotaOverride['support_routing'] ?? null;
        return is_array($supportRouting) ? $this->sanitizeSupportRoutingOverride($supportRouting) : [];
    }

    private function sanitizeSupportRoutingOverride(array $supportRouting): array
    {
        $normalized = [];

        foreach ([
            'first_response_minutes' => ['type' => 'int', 'min' => 1],
            'resolution_minutes' => ['type' => 'int', 'min' => 1],
            'routing_weight' => ['type' => 'float', 'min' => 0.1],
            'min_agent_level' => ['type' => 'int', 'min' => 1, 'max' => 5],
            'overdue_boost' => ['type' => 'float', 'min' => 0.0],
            'tier_label' => ['type' => 'string'],
        ] as $key => $rule) {
            if (!array_key_exists($key, $supportRouting)) {
                continue;
            }

            $value = $supportRouting[$key];
            if ($value === '' || $value === null) {
                continue;
            }

            if ($rule['type'] === 'string') {
                $text = trim((string) $value);
                if ($text !== '') {
                    $normalized[$key] = $text;
                }
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $number = $rule['type'] === 'int' ? (int) $value : (float) $value;
            $number = max($rule['min'], $number);
            if (isset($rule['max'])) {
                $number = min($rule['max'], $number);
            }
            $normalized[$key] = $number;
        }

        return $normalized;
    }


    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json === false ? '{}' : $json);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }


    private function logExceptionWithFallback(\Throwable $exception, Request $request, string $contextMessage = ''): void
    {
        if ($this->errorLogService) {
            try {
                $extra = $contextMessage !== '' ? ['context_message' => $contextMessage] : [];
                $this->errorLogService->logException($exception, $request, $extra);
                return;
            } catch (\Throwable $loggingError) {
                error_log('ErrorLogService failed: ' . $loggingError->getMessage());
            }
        }
        if ($contextMessage !== '') {
            error_log($contextMessage);
        } else {
            error_log($exception->getMessage());
        }
    }

}


