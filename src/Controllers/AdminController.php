<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use PDO;

class AdminController
{
    public function __construct(
        private PDO $db,
        private AuthService $authService,
        private AuditLogService $auditLog,
        private ?ErrorLogService $errorLogService = null
    ) {}

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

            $search   = trim((string)($params['q'] ?? ''));
            $status   = trim((string)($params['status'] ?? ''));
            $schoolId = (int)($params['school_id'] ?? 0);
            $isAdmin  = $params['is_admin'] ?? null; // '0' or '1'
            $sort     = (string)($params['sort'] ?? 'created_at_desc');

            $where = ['u.deleted_at IS NULL'];
            $queryParams = [];
            if ($search !== '') {
                $where[] = '(u.username LIKE :search OR u.email LIKE :search)';
                $queryParams['search'] = "%{$search}%";
            }
            if ($status !== '') {
                $where[] = 'u.status = :status';
                $queryParams['status'] = $status;
            }
            if ($schoolId > 0) {
                $where[] = 'u.school_id = :school_id';
                $queryParams['school_id'] = $schoolId;
            }
            if ($isAdmin !== null && ($isAdmin === '0' || $isAdmin === '1')) {
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

            $sql = "
                SELECT
                    u.id, u.username, u.email, u.school_id,
                    u.points, u.is_admin, u.status, u.avatar_id, u.created_at, u.updated_at,
                    s.name as school_name,
                    a.name as avatar_name, a.file_path as avatar_path,
                    COUNT(pt.id) as total_transactions,
                    COALESCE(SUM(CASE WHEN pt.status = 'approved' THEN pt.points ELSE 0 END), 0) as earned_points,
                    COALESCE(cr.total_carbon_saved, 0) as total_carbon_saved
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
                $user['id'],
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
                throw $e; // 直接抛出以便 PHPUnit 显示具体错误
            }
            error_log('getStats exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 获取待审核交易列表 */
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
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 管理员统计数据（跨数据库兼容） */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            $thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
            $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $dateExpr = $driver === 'sqlite' ? "substr(created_at,1,10)" : "DATE(created_at)";

            // 用户统计（参数化）
            $stmtUser = $this->db->prepare("SELECT COUNT(*) as total_users,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) as active_users,
                SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive_users,
                SUM(CASE WHEN created_at >= :d30 THEN 1 ELSE 0 END) as new_users_30d
                FROM users WHERE deleted_at IS NULL");
            $stmtUser->execute([':d30' => $thirtyDaysAgo]);
            $userStats = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

            // 交易统计
            $transactionStats = $this->db->query("SELECT COUNT(*) as total_transactions,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_transactions,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_transactions,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected_transactions,
                COALESCE(SUM(CASE WHEN status='approved' THEN points ELSE 0 END),0) as total_points_awarded,
                0 as total_carbon_saved
                FROM points_transactions WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            // 兼容测试环境可能缺少 carbon_saved 列
            $carbonSaved = 0.0;
            try {
                $carbonSavedTotal = $this->db->query("SELECT COALESCE(SUM(carbon_saved),0) FROM carbon_records WHERE status='approved' AND deleted_at IS NULL")?->fetchColumn();
                if ($carbonSavedTotal !== false) { $carbonSaved = (float)$carbonSavedTotal; }
            } catch (\Throwable $ignore) {}
            $transactionStats['total_carbon_saved'] = $carbonSaved;

            // 兑换统计
            $exchangeStats = $this->db->query("SELECT COUNT(*) as total_exchanges,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_exchanges,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed_exchanges,
                COALESCE(SUM(points_used),0) as total_points_spent
                FROM point_exchanges WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            // 消息统计
            $messageStats = $this->db->query("SELECT COUNT(*) as total_messages,
                SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) as unread_messages
                FROM messages WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            // 活动统计
            $activityStats = $this->db->query("SELECT COUNT(*) as total_activities,
                SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active_activities
                FROM carbon_activities WHERE deleted_at IS NULL")?->fetch(PDO::FETCH_ASSOC) ?: [];

            // 趋势（最近30天）
            $trendTxStmt = $this->db->prepare("SELECT {$dateExpr} as date, COUNT(*) as transactions
                FROM points_transactions WHERE created_at >= :d30 AND deleted_at IS NULL GROUP BY {$dateExpr}");
            $trendTxStmt->execute([':d30' => $thirtyDaysAgo]);
            $trendTransactions = $trendTxStmt->fetchAll(PDO::FETCH_ASSOC);

            $trendCarbon = [];
            try {
                $trendCarbonStmt = $this->db->prepare("SELECT {$dateExpr} as date, COALESCE(SUM(carbon_saved),0) as carbon_saved
                    FROM carbon_records WHERE created_at >= :d30 AND deleted_at IS NULL AND status='approved' GROUP BY {$dateExpr}");
                $trendCarbonStmt->execute([':d30' => $thirtyDaysAgo]);
                $trendCarbon = $trendCarbonStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $ignore) {}

            // 合并趋势填充空缺日期
            $trendMap = [];
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $trendMap[$d] = ['date' => $d, 'transactions' => 0, 'carbon_saved' => 0.0];
            }
            foreach ($trendTransactions as $row) {
                $d = $row['date'];
                if (isset($trendMap[$d])) { $trendMap[$d]['transactions'] = (int)($row['transactions'] ?? 0); }
            }
            foreach ($trendCarbon as $row) {
                $d = $row['date'];
                if (isset($trendMap[$d])) { $trendMap[$d]['carbon_saved'] = (float)($row['carbon_saved'] ?? 0); }
            }
            $trendData = array_values($trendMap);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'users' => $userStats,
                    'transactions' => $transactionStats,
                    'exchanges' => $exchangeStats,
                    'messages' => $messageStats,
                    'activities' => $activityStats,
                    'trends' => $trendData
                ]
            ]);
        } catch (\Exception $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') { throw $e; }
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 审计日志列表 */
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

            $action = trim((string)($params['action'] ?? ''));
            $entityType = trim((string)($params['entity_type'] ?? ''));
            $userId = trim((string)($params['user_id'] ?? ''));
            $start = trim((string)($params['start_date'] ?? ''));
            $end = trim((string)($params['end_date'] ?? ''));

            $where = [];
            $queryParams = [];
            if ($action !== '') { $where[] = 'al.action LIKE :action'; $queryParams['action'] = "%{$action}%"; }
            if ($entityType !== '') { $where[] = 'al.entity_type = :entity_type'; $queryParams['entity_type'] = $entityType; }
            if ($userId !== '') { $where[] = 'al.user_id = :user_id'; $queryParams['user_id'] = $userId; }
            if ($start !== '') { $where[] = 'al.created_at >= :start_date'; $queryParams['start_date'] = $start; }
            if ($end !== '') { $where[] = 'al.created_at <= :end_date'; $queryParams['end_date'] = $end . ' 23:59:59'; }
            $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

            $sql = "SELECT al.id, al.user_id, al.action, al.entity_type, al.entity_id, al.old_values, al.new_values,
                            al.ip_address, al.user_agent, al.created_at, u.username, u.email
                    FROM audit_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    {$whereClause}
                    ORDER BY al.created_at DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($sql);
            foreach ($queryParams as $k => $v) { $stmt->bindValue(":{$k}", $v); }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($logs as &$l) {
                $l['old_values'] = $l['old_values'] ? json_decode($l['old_values'], true) : null;
                $l['new_values'] = $l['new_values'] ? json_decode($l['new_values'], true) : null;
            }
            $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_logs al {$whereClause}");
            foreach ($queryParams as $k => $v) { $countStmt->bindValue(":{$k}", $v); }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

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
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 更新用户 is_admin / status */
    public function updateUser(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }
            $userId = (int)($args['id'] ?? 0);
            if ($userId <= 0) { return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400); }

            $data = $request->getParsedBody() ?: [];
            $fields = [];
            $params = [];
            if (array_key_exists('is_admin', $data)) { $fields[] = 'is_admin = :is_admin'; $params['is_admin'] = (int)((bool)$data['is_admin']); }
            if (array_key_exists('status', $data)) {
                $allowed = ['active','inactive'];
                $status = in_array($data['status'], $allowed, true) ? $data['status'] : 'inactive';
                $fields[] = 'status = :status';
                $params['status'] = $status;
            }
            if (!$fields) {
                return $this->jsonResponse($response, ['error' => 'No valid fields to update','code' => 'NO_UPDATE_FIELDS'], 400);
            }
            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id AND deleted_at IS NULL';
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue(':'.$k, $v); }
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $this->auditLog->logAdminOperation('user_updated', $admin['id'], 'user_management', [
                'table' => 'users', 'record_id' => $userId, 'updated_fields' => array_keys($data),
                'old_data' => null, 'new_data' => $data
            ]);

            return $this->jsonResponse($response, ['success' => true, 'message' => 'User updated successfully']);
        } catch (\Exception $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 软删除用户 */
    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) { return $this->jsonResponse($response, ['error' => 'Access denied'], 403); }
            $userId = (int)($args['id'] ?? 0);
            if ($userId <= 0) { return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400); }
            if ((int)$admin['id'] === $userId) { return $this->jsonResponse($response, ['error' => 'Cannot delete yourself'], 400); }

            $stmt = $this->db->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $this->auditLog->logAdminOperation('user_deleted', $admin['id'], 'user_management', [
                'table' => 'users','record_id' => $userId,'old_data' => null,'new_data' => null
            ]);
            return $this->jsonResponse($response, ['success' => true, 'message' => 'User deleted successfully']);
        } catch (\Exception $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /** 调整用户积分 */
    public function adjustUserPoints(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) { return $this->jsonResponse($response, ['error' => 'Access denied'], 403); }
            $userId = (int)($args['id'] ?? 0);
            if ($userId <= 0) { return $this->jsonResponse($response, ['error' => 'Invalid user id'], 400); }

            $data = $request->getParsedBody() ?: [];
            $delta = isset($data['delta']) ? (float)$data['delta'] : 0.0;
            $reason = trim((string)($data['reason'] ?? ''));
            if ($delta === 0.0) { return $this->jsonResponse($response, ['error' => 'Delta must be non-zero','code'=>'INVALID_DELTA'], 400); }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare('SELECT id, username, email, points FROM users WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) { $this->db->rollBack(); return $this->jsonResponse($response, ['error' => 'User not found'], 404); }

                $newPoints = (float)$user['points'] + $delta;
                if ($newPoints < 0) { $this->db->rollBack(); return $this->jsonResponse($response, ['error' => 'Insufficient points after adjustment','code'=>'NEGATIVE_BALANCE'], 400); }

                $upd = $this->db->prepare('UPDATE users SET points = :points, updated_at = NOW() WHERE id = :id');
                $upd->execute(['points' => $newPoints, 'id' => $userId]);

                $ins = $this->db->prepare('INSERT INTO points_transactions (username,email,time,img,points,auth,raw,act,uid,activity_id,type,notes,activity_date,status,approved_by,approved_at,created_at,updated_at)
                        VALUES (:username,:email,NOW(),NULL,:points,:auth,:raw,:act,:uid,NULL,:type,:notes,NULL,:status,:approved_by,NOW(),NOW(),NOW())');
                $ins->execute([
                    'username' => $user['username'] ?? null,
                    'email' => $user['email'] ?? '',
                    'points' => $delta,
                    'auth' => 'admin',
                    'raw' => $delta,
                    'act' => 'admin_adjust',
                    'uid' => $userId,
                    'type' => 'admin_adjust',
                    'notes' => $reason !== '' ? $reason : 'Admin points adjustment',
                    'status' => 'approved',
                    'approved_by' => (int)$admin['id']
                ]);
                $this->db->commit();
            } catch (\Throwable $t) { $this->db->rollBack(); throw $t; }

            $this->auditLog->logAdminOperation('user_points_adjusted', $admin['id'], 'user_management', [
                'table'=>'users','record_id'=>$userId,'delta'=>$delta,'reason'=>$reason,
                'old_points'=>$user['points'],'new_points'=>$newPoints
            ]);

            return $this->jsonResponse($response, ['success'=>true,'message'=>'User points adjusted successfully','data'=>['user_id'=>$userId,'delta'=>$delta,'new_balance'=>$newPoints]]);
        } catch (\Exception $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error'=>'Internal server error'],500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type','application/json')->withStatus($status);
    }
}
