<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Models\User;
use PDO;

class AdminController
{
    private PDO $db;
    private AuthService $authService;
    private AuditLogService $auditLog;
    private ?ErrorLogService $errorLogService;

    public function __construct(
        PDO $db,
        AuthService $authService,
        AuditLogService $auditLog,
        ErrorLogService $errorLogService = null
    ) {
        $this->db = $db;
        $this->authService = $authService;
        $this->auditLog = $auditLog;
        $this->errorLogService = $errorLogService;
    }

    /**
     * 获取用户列表（管理员）
     */
    public function getUsers(Request $request, Response $response): Response
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

            $search = trim((string)($params['search'] ?? ''));
            $status = trim((string)($params['status'] ?? ''));
            $role = trim((string)($params['role'] ?? ''));
            $sort = $params['sort'] ?? 'created_at_desc';

            $whereConditions = ['u.deleted_at IS NULL'];
            $queryParams = [];

            if ($search !== '') {
                $whereConditions[] = '(u.username LIKE :search OR u.email LIKE :search)';
                $queryParams['search'] = "%{$search}%";
            }
            if ($status !== '') {
                $whereConditions[] = 'u.status = :status';
                $queryParams['status'] = $status;
            }
            if ($role !== '') {
                if ($role === 'admin') {
                    $whereConditions[] = 'u.is_admin = 1';
                } elseif ($role === 'user') {
                    $whereConditions[] = 'u.is_admin = 0';
                }
            }

            $whereClause = implode(' AND ', $whereConditions);

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
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($queryParams as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 获取总数
            $countSql = "
                SELECT COUNT(DISTINCT u.id) as total
                FROM users u
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE {$whereClause}
            ";
            $countStmt = $this->db->prepare($countSql);
            foreach ($queryParams as $key => $value) {
                $countStmt->bindValue(":{$key}", $value);
            }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            // 记录审计日志
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
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
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

            $sql = "
                SELECT 
                    pt.id, pt.activity_id,
                    pt.points, pt.notes, pt.img AS img,
                    pt.status, pt.created_at, pt.updated_at,
                    u.username, u.email,
                    ca.name_zh as activity_name_zh, ca.name_en as activity_name_en,
                    ca.category, ca.carbon_factor, ca.unit as activity_unit
                FROM points_transactions pt
                JOIN users u ON pt.uid = u.id
                LEFT JOIN carbon_activities ca ON pt.activity_id = ca.id
                WHERE pt.status = 'pending' AND pt.deleted_at IS NULL
                ORDER BY pt.created_at ASC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理图片数据
            foreach ($transactions as &$transaction) {
                $images = [];
                if (!empty($transaction['img'])) {
                    $maybe = json_decode((string)$transaction['img'], true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($maybe)) {
                        $images = $maybe;
                    } else {
                        $images = [(string)$transaction['img']];
                    }
                }
                $transaction['images'] = $images;
                unset($transaction['img']);
            }

            // 获取总数
            $countSql = "
                SELECT COUNT(*) as total
                FROM points_transactions pt
                WHERE pt.status = 'pending' AND pt.deleted_at IS NULL
            ";
            $countStmt = $this->db->query($countSql);
            $total = (int)$countStmt->fetchColumn();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'transactions' => $transactions,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取管理员统计数据
     */
    public function getStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, ['error' => 'Access denied'], 403);
            }

            // 用户统计
            $userStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_users,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
                    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_users,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_users_30d
                FROM users 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 交易统计
            $transactionStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_transactions,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_transactions,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_transactions,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_transactions,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN points ELSE 0 END), 0) as total_points_awarded,
                        0 as total_carbon_saved
                FROM points_transactions 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

                // 从 carbon_records 统计已审核通过的碳减排总量
                $carbonSavedTotal = $this->db->query("
                    SELECT COALESCE(SUM(carbon_saved), 0)
                    FROM carbon_records
                    WHERE status = 'approved' AND deleted_at IS NULL
                ")->fetchColumn();
                $transactionStats['total_carbon_saved'] = $carbonSavedTotal !== false ? (float)$carbonSavedTotal : 0.0;

            // 商品兑换统计（使用 point_exchanges 表，注意列名为 points_used）
            $exchangeStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_exchanges,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_exchanges,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_exchanges,
                    COALESCE(SUM(points_used), 0) as total_points_spent
                FROM point_exchanges 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 消息统计（messages 表只有 is_read，没有 status/type）
            $messageStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread_messages
                FROM messages 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 活动统计
            $activityStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_activities,
                        COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_activities
                FROM carbon_activities 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 最近30天的趋势数据
                // 最近30天的趋势数据：交易数量来自 points_transactions，碳减排来自 carbon_records
                $trendTransactions = $this->db->query("
                    SELECT DATE(created_at) as date, COUNT(*) as transactions
                    FROM points_transactions
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL
                    GROUP BY DATE(created_at)
                ")->fetchAll(PDO::FETCH_ASSOC);

                $trendCarbon = $this->db->query("
                    SELECT DATE(created_at) as date, COALESCE(SUM(carbon_saved), 0) as carbon_saved
                    FROM carbon_records
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL AND status = 'approved'
                    GROUP BY DATE(created_at)
                ")->fetchAll(PDO::FETCH_ASSOC);

                // 合并趋势数据（补全缺失日期）
                $trendMap = [];
                for ($i = 29; $i >= 0; $i--) {
                    $d = date('Y-m-d', strtotime("-{$i} days"));
                    $trendMap[$d] = ['date' => $d, 'transactions' => 0, 'carbon_saved' => 0.0];
                }
                foreach ($trendTransactions as $row) {
                    $d = $row['date'];
                    if (isset($trendMap[$d])) {
                        $trendMap[$d]['transactions'] = (int)($row['transactions'] ?? 0);
                    }
                }
                foreach ($trendCarbon as $row) {
                    $d = $row['date'];
                    if (isset($trendMap[$d])) {
                        $trendMap[$d]['carbon_saved'] = (float)($row['carbon_saved'] ?? 0);
                    }
                }
                    $trendData = array_values($trendMap); // This line remains unchanged

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
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取审计日志
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
            
            $action = $params['action'] ?? '';
            $entity_type = $params['entity_type'] ?? '';
            $user_id = $params['user_id'] ?? '';
            $start_date = $params['start_date'] ?? '';
            $end_date = $params['end_date'] ?? '';

            // 构建查询条件
            $whereConditions = [];
            $queryParams = [];

            if (!empty($action)) {
                $whereConditions[] = 'al.action LIKE :action';
                $queryParams['action'] = "%{$action}%";
            }

            if (!empty($entity_type)) {
                $whereConditions[] = 'al.entity_type = :entity_type';
                $queryParams['entity_type'] = $entity_type;
            }

            if (!empty($user_id)) {
                $whereConditions[] = 'al.user_id = :user_id';
                $queryParams['user_id'] = $user_id;
            }

            if (!empty($start_date)) {
                $whereConditions[] = 'al.created_at >= :start_date';
                $queryParams['start_date'] = $start_date;
            }

            if (!empty($end_date)) {
                $whereConditions[] = 'al.created_at <= :end_date';
                $queryParams['end_date'] = $end_date . ' 23:59:59';
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            $sql = "
                SELECT 
                    al.id, al.user_id, al.action, al.entity_type, al.entity_id,
                    al.old_values, al.new_values, al.ip_address, al.user_agent,
                    al.created_at,
                    u.username, u.email
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($queryParams as $key => $value) {
                $stmt->bindValue(":{$key}", $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理JSON数据
            foreach ($logs as &$log) {
                $log['old_values'] = $log['old_values'] ? json_decode($log['old_values'], true) : null;
                $log['new_values'] = $log['new_values'] ? json_decode($log['new_values'], true) : null;
            }

            // 获取总数
            $countSql = "SELECT COUNT(*) as total FROM audit_logs al {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            foreach ($queryParams as $key => $value) {
                $countStmt->bindValue(":{$key}", $value);
            }
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
                        'total_pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员更新用户信息（目前支持：is_admin, status）
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

            $data = $request->getParsedBody() ?: [];

            $fields = [];
            $params = [];

            if (array_key_exists('is_admin', $data)) {
                $fields[] = 'is_admin = :is_admin';
                $params['is_admin'] = (int)((bool)$data['is_admin']);
            }

            if (array_key_exists('status', $data)) {
                $allowed = ['active', 'inactive'];
                $status = in_array($data['status'], $allowed, true) ? $data['status'] : 'inactive';
                $fields[] = 'status = :status';
                $params['status'] = $status;
            }

            if (empty($fields)) {
                return $this->jsonResponse($response, [
                    'error' => 'No valid fields to update',
                    'code' => 'NO_UPDATE_FIELDS'
                ], 400);
            }

            $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id AND deleted_at IS NULL';
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue(':' . $k, $v);
            }
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            // 审计日志
            $this->auditLog->logAdminOperation(
                'user_updated',
                $admin['id'],
                'user_management',
                [
                    'table' => 'users',
                    'record_id' => $userId,
                    'updated_fields' => array_keys($data),
                    'old_data' => null,
                    'new_data' => $data
                ]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User updated successfully'
            ]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员删除用户（软删除）
     */
    public function deleteUser(Request $request, Response $response, array $args): Response
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

            // 不允许删除自己
            if ((int)$admin['id'] === $userId) {
                return $this->jsonResponse($response, ['error' => 'Cannot delete yourself'], 400);
            }

            $stmt = $this->db->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
            $stmt->execute();

            $this->auditLog->logAdminOperation(
                'user_deleted',
                $admin['id'],
                'user_management',
                [
                    'table' => 'users',
                    'record_id' => $userId,
                    'old_data' => null,
                    'new_data' => null
                ]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员调整用户积分（可正可负），并记录到积分流水
     */
    public function adjustUserPoints(Request $request, Response $response, array $args): Response
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

            $data = $request->getParsedBody() ?: [];
            $delta = isset($data['delta']) ? (float)$data['delta'] : 0.0;
            $reason = trim((string)($data['reason'] ?? ''));

            if ($delta === 0.0) {
                return $this->jsonResponse($response, [
                    'error' => 'Delta must be non-zero',
                    'code' => 'INVALID_DELTA'
                ], 400);
            }

            // 读取用户并更新积分
            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare('SELECT id, username, email, points FROM users WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$user) {
                    $this->db->rollBack();
                    return $this->jsonResponse($response, ['error' => 'User not found'], 404);
                }

                $newPoints = (float)$user['points'] + $delta;
                if ($newPoints < 0) {
                    // 不允许积分为负数（策略可调整）
                    $this->db->rollBack();
                    return $this->jsonResponse($response, [
                        'error' => 'Insufficient points after adjustment',
                        'code' => 'NEGATIVE_BALANCE'
                    ], 400);
                }

                $upd = $this->db->prepare('UPDATE users SET points = :points, updated_at = NOW() WHERE id = :id');
                $upd->execute(['points' => $newPoints, 'id' => $userId]);

                // 记录到积分流水（兼容当前 points_transactions 表结构）
                $ins = $this->db->prepare('INSERT INTO points_transactions (
                        username, email, time, img, points, auth, raw, act, uid, activity_id,
                        type, notes, activity_date, status, approved_by, approved_at, created_at, updated_at
                    ) VALUES (
                        :username, :email, NOW(), NULL, :points, :auth, :raw, :act, :uid, NULL,
                        :type, :notes, NULL, :status, :approved_by, NOW(), NOW(), NOW()
                    )');
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
            } catch (\Throwable $txe) {
                $this->db->rollBack();
                throw $txe;
            }

            // 审计日志
            $this->auditLog->logAdminOperation(
                'user_points_adjusted',
                $admin['id'],
                'user_management',
                [
                    'table' => 'users',
                    'record_id' => $userId,
                    'delta' => $delta,
                    'reason' => $reason,
                    'old_points' => $user['points'],
                    'new_points' => $newPoints
                ]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User points adjusted successfully',
                'data' => [
                    'user_id' => $userId,
                    'delta' => $delta,
                    'new_balance' => $newPoints
                ]
            ]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 返回JSON响应
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
