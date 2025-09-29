<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\BadgeService;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

class AdminController
{
    public function __construct(
        private PDO $db,
        private AuthService $authService,
        private AuditLogService $auditLog,
        private BadgeService $badgeService,
        private ?ErrorLogService $errorLogService = null,
        private ?CloudflareR2Service $r2Service = null
    ) {}


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
            $isAdminParam = $params['is_admin'] ?? null;
            if ($isAdminParam === null && isset($params['role'])) {
                $role = strtolower(trim((string)$params['role']));
                if ($role === 'admin') {
                    $isAdminParam = '1';
                } elseif ($role === 'user') {
                    $isAdminParam = '0';
                }
            }
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
                $where[] = '(u.username LIKE :search_username OR u.email LIKE :search_email)';
                $queryParams['search_username'] = "%{$search}%";
                $queryParams['search_email'] = "%{$search}%";
            }
            if ($status !== '') {
                $where[] = 'u.status = :status';
                $queryParams['status'] = $status;
            }
            if ($schoolId > 0) {
                $where[] = 'u.school_id = :school_id';
                $queryParams['school_id'] = $schoolId;
            }
            if ($isAdmin !== null) {
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
                    u.id, u.username, u.email, u.school_id,
                    u.points, u.is_admin, u.status, u.avatar_id, u.created_at, u.updated_at,
                    {$lastLoginSelect},
                    s.name as school_name,
                    a.name as avatar_name, a.file_path as avatar_path,
                    COUNT(pt.id) as total_transactions,
                    COALESCE(SUM(CASE WHEN pt.status = 'approved' THEN pt.points ELSE 0 END), 0) as earned_points,
                    COALESCE(cr.total_carbon_saved, 0) as total_carbon_saved,
                    COALESCE(ub.badges_awarded, 0) as badges_awarded,
                    COALESCE(ub.badges_revoked, 0) as badges_revoked,
                    COALESCE(ub.active_badges, 0) as active_badges,
                    ub.last_badge_awarded_at
                FROM users u
                LEFT JOIN schools s ON u.school_id = s.id
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
                $row['is_admin'] = (bool) ($row['is_admin'] ?? false);
                $row['points'] = (float) ($row['points'] ?? 0);
                $row['total_transactions'] = (int) ($row['total_transactions'] ?? 0);
                $row['earned_points'] = (float) ($row['earned_points'] ?? 0);
                $row['total_carbon_saved'] = (float) ($row['total_carbon_saved'] ?? 0);
                $row['badges_awarded'] = (int) ($row['badges_awarded'] ?? 0);
                $row['badges_revoked'] = (int) ($row['badges_revoked'] ?? 0);
                $row['active_badges'] = (int) ($row['active_badges'] ?? 0);
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
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $userId = (int) ($args['id'] ?? 0);
            if ($userId <= 0) {
                return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400);
            }

            $userRow = $this->loadUserRow($userId);
            if (!$userRow) {
                return $this->jsonResponse($response, ['error' => 'User not found'], 404);
            }

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

    public function getUserOverview(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $userId = (int) ($args['id'] ?? 0);
            if ($userId <= 0) {
                return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400);
            }

            $userRow = $this->loadUserRow($userId);
            if (!$userRow) {
                return $this->jsonResponse($response, ['error' => 'User not found'], 404);
            }

            $metrics = $this->badgeService->compileUserMetrics($userId);
            $badgePayload = $this->buildUserBadgePayload($userId, true);
            $payload = [
                'user' => $userRow,
                'metrics' => $metrics,
                'badge_summary' => $badgePayload['summary'],
                'recent_badges' => array_slice($badgePayload['items'], 0, 5),
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

            $timezoneName = $_ENV['APP_TIMEZONE'] ?? date_default_timezone_get();
            if (!$timezoneName) {
                $timezoneName = 'UTC';
            }
            $timezone = new DateTimeZone($timezoneName);
            $now = new DateTimeImmutable('now', $timezone);
            $thirtyDaysAgo = $now->modify('-30 days')->format('Y-m-d H:i:s');
            $sevenDaysAgo = $now->modify('-7 days')->format('Y-m-d H:i:s');

            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dateExpr = $driver === 'sqlite' ? "substr(created_at,1,10)" : "DATE(created_at)";

            $stmtUser = $this->db->prepare("SELECT COUNT(*) AS total_users,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status IN ('inactive','suspended') THEN 1 ELSE 0 END) AS inactive_users,
                SUM(CASE WHEN created_at >= :d30 THEN 1 ELSE 0 END) AS new_users_30d
                FROM users WHERE deleted_at IS NULL");
            $stmtUser->execute([':d30' => $thirtyDaysAgo]);
            $userStatsRaw = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

            $transactionStatsRaw = $this->db->query("SELECT COUNT(*) AS total_transactions,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_transactions,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_transactions,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected_transactions,
                COALESCE(SUM(CASE WHEN status='approved' THEN points ELSE 0 END), 0) AS total_points_awarded
                FROM points_transactions WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            $txWindowStmt = $this->db->prepare("SELECT COUNT(*) AS total_transactions,
                COALESCE(SUM(CASE WHEN status='approved' THEN points ELSE 0 END), 0) AS total_points_awarded
                FROM points_transactions
                WHERE deleted_at IS NULL AND created_at >= :d7");
            $txWindowStmt->execute([':d7' => $sevenDaysAgo]);
            $txWindowRaw = $txWindowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $exchangeStatsRaw = $this->db->query("SELECT COUNT(*) AS total_exchanges,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_exchanges,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed_exchanges,
                SUM(CASE WHEN status NOT IN ('pending','completed') THEN 1 ELSE 0 END) AS other_exchanges,
                COALESCE(SUM(points_used), 0) AS total_points_spent
                FROM point_exchanges WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            $messageStatsRaw = $this->db->query("SELECT COUNT(*) AS total_messages,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_messages,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_messages
                FROM messages WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            $activityStatsRaw = $this->db->query("SELECT COUNT(*) AS total_activities,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_activities
                FROM carbon_activities WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            $carbonStatsRaw = $this->db->query("SELECT COUNT(*) AS total_records,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_records,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_records,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) AS rejected_records,
                COALESCE(SUM(CASE WHEN status='approved' THEN carbon_saved ELSE 0 END), 0) AS total_carbon_saved,
                COALESCE(SUM(CASE WHEN status='approved' THEN points_earned ELSE 0 END), 0) AS total_points_earned
                FROM carbon_records WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            $carbonWindowStmt = $this->db->prepare("SELECT
                    COALESCE(SUM(CASE WHEN status='approved' THEN carbon_saved ELSE 0 END), 0) AS carbon_saved,
                    COALESCE(SUM(CASE WHEN status='approved' THEN points_earned ELSE 0 END), 0) AS points_earned,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_records,
                    SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved_records
                FROM carbon_records
                WHERE deleted_at IS NULL AND created_at >= :d7");
            $carbonWindowStmt->execute([':d7' => $sevenDaysAgo]);
            $carbonWindowRaw = $carbonWindowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $trendTxStmt = $this->db->prepare("SELECT {$dateExpr} AS date,
                    COUNT(*) AS transactions,
                    COALESCE(SUM(CASE WHEN status='approved' THEN points ELSE 0 END), 0) AS points_awarded
                FROM points_transactions
                WHERE created_at >= :d30 AND deleted_at IS NULL
                GROUP BY {$dateExpr}");
            $trendTxStmt->execute([':d30' => $thirtyDaysAgo]);
            $trendTransactions = $trendTxStmt->fetchAll(PDO::FETCH_ASSOC);

            $trendCarbon = [];
            try {
                $trendCarbonStmt = $this->db->prepare("SELECT {$dateExpr} AS date,
                        COALESCE(SUM(carbon_saved), 0) AS carbon_saved,
                        COUNT(*) AS approved_records
                    FROM carbon_records
                    WHERE created_at >= :d30 AND deleted_at IS NULL AND status='approved'
                    GROUP BY {$dateExpr}");
                $trendCarbonStmt->execute([':d30' => $thirtyDaysAgo]);
                $trendCarbon = $trendCarbonStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $ignore) {
            }

            $trendMap = [];
            for ($i = 29; $i >= 0; $i--) {
                $dateKey = $now->modify("-{$i} days")->format('Y-m-d');
                $trendMap[$dateKey] = [
                    'date' => $dateKey,
                    'transactions' => 0,
                    'carbon_saved' => 0.0,
                    'points_awarded' => 0.0,
                    'approved_records' => 0,
                ];
            }

            foreach ($trendTransactions as $row) {
                $d = (string)($row['date'] ?? '');
                if ($d !== '' && isset($trendMap[$d])) {
                    $trendMap[$d]['transactions'] = $this->toInt($row['transactions'] ?? 0);
                    $trendMap[$d]['points_awarded'] = $this->toFloat($row['points_awarded'] ?? 0);
                }
            }

            foreach ($trendCarbon as $row) {
                $d = (string)($row['date'] ?? '');
                if ($d !== '' && isset($trendMap[$d])) {
                    $trendMap[$d]['carbon_saved'] = $this->toFloat($row['carbon_saved'] ?? 0);
                    $trendMap[$d]['approved_records'] = $this->toInt($row['approved_records'] ?? 0);
                }
            }

            $trendData = array_values($trendMap);
            $trendCount = count($trendData);
            $trendTotals = [
                'transactions' => 0,
                'carbon_saved' => 0.0,
                'points_awarded' => 0.0,
                'approved_records' => 0,
            ];
            foreach ($trendData as $entry) {
                $trendTotals['transactions'] += $entry['transactions'];
                $trendTotals['carbon_saved'] += $entry['carbon_saved'];
                $trendTotals['points_awarded'] += $entry['points_awarded'];
                $trendTotals['approved_records'] += $entry['approved_records'];
            }

            $last7 = $trendCount > 7 ? array_slice($trendData, -7) : $trendData;
            $prev7 = [];
            if ($trendCount > 7) {
                $prev7 = array_slice($trendData, max(0, $trendCount - 14), max(0, min(7, $trendCount - 7)));
            }

            $sumColumn = static function (array $rows, string $key): float {
                $total = 0.0;
                foreach ($rows as $row) {
                    $value = $row[$key] ?? 0;
                    $total += is_numeric($value) ? (float) $value : 0.0;
                }
                return $total;
            };

            $carbonLast7 = $sumColumn($last7, 'carbon_saved');
            $carbonPrev7 = $sumColumn($prev7, 'carbon_saved');
            $transactionsLast7 = (int) round($sumColumn($last7, 'transactions'));
            $pointsLast7 = $sumColumn($last7, 'points_awarded');

            $trendSummary = [
                'carbon_last7' => $carbonLast7,
                'carbon_prev7' => $carbonPrev7,
                'carbon_delta' => $carbonLast7 - $carbonPrev7,
                'carbon_delta_rate' => $this->safeDivide($carbonLast7 - $carbonPrev7, max($carbonPrev7, 1)),
                'transactions_last7' => $transactionsLast7,
                'points_last7' => $pointsLast7,
                'average_daily_carbon_30d' => $trendCount > 0 ? $trendTotals['carbon_saved'] / $trendCount : 0.0,
            ];

            $pendingTxStmt = $this->db->prepare("SELECT id, uid AS user_id, username, points, status, created_at
                FROM points_transactions
                WHERE deleted_at IS NULL AND status='pending'
                ORDER BY created_at DESC
                LIMIT 5");
            $pendingTxStmt->execute();
            $pendingTransactionsList = $pendingTxStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $pendingRecordsStmt = $this->db->prepare("SELECT cr.id, cr.user_id, u.username, cr.activity_id,
                    ca.name_zh AS activity_name_zh, ca.name_en AS activity_name_en,
                    cr.carbon_saved, cr.points_earned, cr.created_at
                FROM carbon_records cr
                LEFT JOIN users u ON u.id = cr.user_id
                LEFT JOIN carbon_activities ca ON ca.id = cr.activity_id
                WHERE cr.deleted_at IS NULL AND cr.status='pending'
                ORDER BY cr.created_at DESC
                LIMIT 5");
            $pendingRecordsStmt->execute();
            $pendingRecordsList = $pendingRecordsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $latestUsersStmt = $this->db->prepare("SELECT id, username, email, status, created_at
                FROM users WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5");
            $latestUsersStmt->execute();
            $latestUsers = $latestUsersStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $users = $this->normalizeUsersStats($userStatsRaw);
            $transactions = $this->normalizeTransactionStats($transactionStatsRaw, $txWindowRaw, $carbonStatsRaw);
            $exchanges = $this->normalizeExchangeStats($exchangeStatsRaw);
            $messages = $this->normalizeMessageStats($messageStatsRaw);
            $activities = $this->normalizeActivityStats($activityStatsRaw);
            $carbon = $this->normalizeCarbonStats($carbonStatsRaw, $carbonWindowRaw, $trendTotals, $trendCount);

            $recent = [
                'pending_transactions' => $this->formatPendingTransactions($pendingTransactionsList),
                'pending_carbon_records' => $this->formatPendingCarbonRecords($pendingRecordsList),
                'latest_users' => $this->formatLatestUsers($latestUsers),
            ];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'users' => $users,
                    'transactions' => $transactions,
                    'exchanges' => $exchanges,
                    'messages' => $messages,
                    'activities' => $activities,
                    'carbon' => $carbon,
                    'trends' => $trendData,
                    'trend_summary' => $trendSummary,
                    'recent' => $recent,
                ],
            ]);
        } catch (\Exception $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            $this->logExceptionWithFallback($e, $request);
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }
    private function normalizeUsersStats(array $row): array
    {
        $total = $this->toInt($row['total_users'] ?? 0);
        $active = $this->toInt($row['active_users'] ?? 0);
        $inactive = $this->toInt($row['inactive_users'] ?? 0);
        if ($inactive === 0 && $total >= $active) {
            $inactive = max(0, $total - $active);
        }
        $newThirty = $this->toInt($row['new_users_30d'] ?? 0);

        return [
            'total_users' => $total,
            'active_users' => $active,
            'inactive_users' => $inactive,
            'new_users_30d' => $newThirty,
            'active_ratio' => $this->safeDivide((float) $active, max($total, 1)),
            'new_users_ratio' => $this->safeDivide((float) $newThirty, max($total, 1)),
        ];
    }

    private function normalizeTransactionStats(array $row, array $windowRow, array $carbonRow): array
    {
        $total = $this->toInt($row['total_transactions'] ?? 0);
        $pending = $this->toInt($row['pending_transactions'] ?? 0);
        $approved = $this->toInt($row['approved_transactions'] ?? 0);
        $rejected = $this->toInt($row['rejected_transactions'] ?? 0);
        $points = $this->toFloat($row['total_points_awarded'] ?? 0);
        $windowTransactions = $this->toInt($windowRow['total_transactions'] ?? 0);
        $windowPoints = $this->toFloat($windowRow['total_points_awarded'] ?? 0);
        $totalCarbon = $this->toFloat($carbonRow['total_carbon_saved'] ?? 0);

        $avgPoints = $approved > 0 ? round($points / $approved, 2) : 0.0;

        return [
            'total_transactions' => $total,
            'pending_transactions' => $pending,
            'approved_transactions' => $approved,
            'rejected_transactions' => $rejected,
            'total_points_awarded' => $points,
            'approval_rate' => $this->safeDivide((float) $approved, max($total, 1)),
            'pending_ratio' => $this->safeDivide((float) $pending, max($total, 1)),
            'avg_points_per_transaction' => $avgPoints,
            'last7_transactions' => $windowTransactions,
            'last7_points_awarded' => $windowPoints,
            'total_carbon_saved' => $totalCarbon,
        ];
    }

    private function normalizeExchangeStats(array $row): array
    {
        $total = $this->toInt($row['total_exchanges'] ?? 0);
        $pending = $this->toInt($row['pending_exchanges'] ?? 0);
        $completed = $this->toInt($row['completed_exchanges'] ?? 0);
        $other = $this->toInt($row['other_exchanges'] ?? 0);
        if ($other === 0 && $total >= ($pending + $completed)) {
            $other = max(0, $total - $pending - $completed);
        }
        $pointsSpent = $this->toFloat($row['total_points_spent'] ?? 0);

        return [
            'total_exchanges' => $total,
            'pending_exchanges' => $pending,
            'completed_exchanges' => $completed,
            'other_exchanges' => $other,
            'total_points_spent' => $pointsSpent,
            'completion_rate' => $this->safeDivide((float) $completed, max($total, 1)),
        ];
    }

    private function normalizeMessageStats(array $row): array
    {
        $total = $this->toInt($row['total_messages'] ?? 0);
        $unread = $this->toInt($row['unread_messages'] ?? 0);
        $read = $this->toInt($row['read_messages'] ?? 0);
        if ($read === 0 && $total >= $unread) {
            $read = max(0, $total - $unread);
        }

        return [
            'total_messages' => $total,
            'unread_messages' => $unread,
            'read_messages' => $read,
            'unread_ratio' => $this->safeDivide((float) $unread, max($total, 1)),
        ];
    }

    private function normalizeActivityStats(array $row): array
    {
        $total = $this->toInt($row['total_activities'] ?? 0);
        $active = $this->toInt($row['active_activities'] ?? 0);
        $inactive = max(0, $total - $active);

        return [
            'total_activities' => $total,
            'active_activities' => $active,
            'inactive_activities' => $inactive,
        ];
    }

    private function normalizeCarbonStats(array $row, array $windowRow, array $trendTotals, int $trendCount): array
    {
        $totalRecords = $this->toInt($row['total_records'] ?? 0);
        $pendingRecords = $this->toInt($row['pending_records'] ?? 0);
        $approvedRecords = $this->toInt($row['approved_records'] ?? 0);
        $rejectedRecords = $this->toInt($row['rejected_records'] ?? 0);
        $totalCarbon = $this->toFloat($row['total_carbon_saved'] ?? 0);
        $totalPointsEarned = $this->toFloat($row['total_points_earned'] ?? 0);
        $windowCarbon = $this->toFloat($windowRow['carbon_saved'] ?? 0);
        $windowPoints = $this->toFloat($windowRow['points_earned'] ?? 0);
        $windowPending = $this->toInt($windowRow['pending_records'] ?? 0);
        $windowApproved = $this->toInt($windowRow['approved_records'] ?? 0);
        $averageDaily = $trendCount > 0
            ? $trendTotals['carbon_saved'] / $trendCount
            : ($approvedRecords > 0 ? $totalCarbon / $approvedRecords : 0.0);

        return [
            'total_records' => $totalRecords,
            'pending_records' => $pendingRecords,
            'approved_records' => $approvedRecords,
            'rejected_records' => $rejectedRecords,
            'total_carbon_saved' => $totalCarbon,
            'total_points_earned' => $totalPointsEarned,
            'last7_carbon_saved' => $windowCarbon,
            'last7_points_earned' => $windowPoints,
            'last7_pending_records' => $windowPending,
            'last7_approved_records' => $windowApproved,
            'approval_rate' => $this->safeDivide((float) $approvedRecords, max($totalRecords, 1)),
            'average_carbon_per_record' => $approvedRecords > 0 ? round($totalCarbon / $approvedRecords, 4) : 0.0,
            'average_daily_carbon' => round($averageDaily, 4),
        ];
    }

    private function formatPendingTransactions(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => isset($row['id']) ? (string) $row['id'] : '',
                'user_id' => isset($row['user_id']) ? $this->toInt($row['user_id']) : null,
                'username' => $row['username'] ?? null,
                'points' => $this->toFloat($row['points'] ?? 0),
                'status' => $row['status'] ?? 'pending',
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function formatPendingCarbonRecords(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => isset($row['id']) ? (string) $row['id'] : '',
                'user_id' => isset($row['user_id']) ? $this->toInt($row['user_id']) : null,
                'username' => $row['username'] ?? null,
                'activity_id' => $row['activity_id'] ?? null,
                'activity_name_zh' => $row['activity_name_zh'] ?? null,
                'activity_name_en' => $row['activity_name_en'] ?? null,
                'carbon_saved' => $this->toFloat($row['carbon_saved'] ?? 0),
                'points_earned' => $this->toFloat($row['points_earned'] ?? 0),
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function formatLatestUsers(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'id' => $this->toInt($row['id'] ?? 0),
                'username' => $row['username'] ?? null,
                'email' => $row['email'] ?? null,
                'status' => $row['status'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows));
    }

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $filtered = preg_replace('/[^0-9\\-]/', '', $value);
            return (int) ($filtered ?? 0);
        }
        return (int) $value;
    }

    private function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $filtered = preg_replace('/[^0-9\\-\\.]/', '', $value);
            return (float) ($filtered ?? 0);
        }
        return (float) $value;
    }

    private function safeDivide(float $numerator, float $denominator, int $scale = 4): float
    {
        if ($denominator <= 0.0) {
            return 0.0;
        }
        return round($numerator / $denominator, $scale);
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
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }
            $userId = (int)($args['id'] ?? 0);
            if ($userId <= 0) {
                return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400);
            }

            $payload = $request->getParsedBody() ?? [];
            $isAdmin  = array_key_exists('is_admin', $payload) ? (int)!!$payload['is_admin'] : null;
            $status   = array_key_exists('status', $payload) ? trim((string)$payload['status']) : null;

            if ($isAdmin === null && $status === null) {
                return $this->jsonResponse($response, ['error' => 'No fields to update'], 400);
            }

            $sets = [];
            $params = ['id' => $userId];
            if ($isAdmin !== null) { $sets[] = 'is_admin = :is_admin'; $params['is_admin'] = $isAdmin; }
            if ($status !== null) { $sets[] = 'status = :status'; $params['status'] = $status; }
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
                ['fields' => array_keys($params)]
            );

            return $this->jsonResponse($response, ['success' => true]);
        } catch (\Exception $e) {
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

    private function loadUserRow(int $userId): ?array
    {
        $lastLoginSelect = $this->buildLastLoginSelect('u');
        $stmt = $this->db->prepare('SELECT u.id, u.username, u.email, u.status, u.is_admin, u.points, u.created_at, u.updated_at, u.school_id, s.name as school_name, ' . $lastLoginSelect . " FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE u.id = :id AND u.deleted_at IS NULL LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['is_admin'] = (bool) ($row['is_admin'] ?? false);
        $row['points'] = (float) ($row['points'] ?? 0);
        $row['days_since_registration'] = $this->computeDaysSince($row['created_at'] ?? null);
        return $row;
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

