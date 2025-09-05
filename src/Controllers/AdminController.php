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
    private ErrorLogService $errorLogService;

    public function __construct(
        PDO $db,
        AuthService $authService,
        AuditLogService $auditLog,
        ErrorLogService $errorLogService
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
            
            $search = $params['search'] ?? '';
            $status = $params['status'] ?? '';
            $role = $params['role'] ?? '';
            $sort = $params['sort'] ?? 'created_at_desc';

            // 构建查询条件
            $whereConditions = ['u.deleted_at IS NULL'];
            $queryParams = [];

            if (!empty($search)) {
                $whereConditions[] = '(u.username LIKE :search OR u.email LIKE :search OR u.real_name LIKE :search)';
                $queryParams['search'] = "%{$search}%";
            }

            if (!empty($status)) {
                $whereConditions[] = 'u.status = :status';
                $queryParams['status'] = $status;
            }

            if (!empty($role)) {
                $whereConditions[] = 'u.role = :role';
                $queryParams['role'] = $role;
            }

            $whereClause = implode(' AND ', $whereConditions);

            // 排序（兼容 PHP 7.4）
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

            // 获取用户列表
            $sql = "
                SELECT 
                    u.id, u.username, u.email, u.real_name, u.phone, u.school_id,
                    u.points, u.role, u.status, u.avatar_id, u.created_at, u.updated_at,
                    s.name as school_name,
                    a.name as avatar_name, a.file_path as avatar_path,
                    COUNT(pt.id) as total_transactions,
                    COALESCE(SUM(CASE WHEN pt.status = 'approved' THEN pt.points ELSE 0 END), 0) as earned_points,
                    COALESCE(SUM(CASE WHEN pt.status = 'approved' THEN pt.carbon_amount ELSE 0 END), 0) as total_carbon_saved
                FROM users u
                LEFT JOIN schools s ON u.school_id = s.id
                LEFT JOIN avatars a ON u.avatar_id = a.id
                LEFT JOIN points_transactions pt ON u.id = pt.user_id AND pt.deleted_at IS NULL
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
            $this->auditLog->log(
                $user['id'],
                'admin_users_list',
                'admin',
                null,
                ['filters' => $params]
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
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
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
                    pt.id, pt.user_id, pt.activity_id, pt.amount, pt.unit,
                    pt.carbon_amount, pt.points, pt.description, pt.images,
                    pt.status, pt.created_at, pt.updated_at,
                    u.username, u.email, u.real_name,
                    ca.name_zh as activity_name_zh, ca.name_en as activity_name_en,
                    ca.category, ca.carbon_factor, ca.unit as activity_unit
                FROM points_transactions pt
                JOIN users u ON pt.user_id = u.id
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
                $transaction['images'] = $transaction['images'] ? json_decode($transaction['images'], true) : [];
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
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
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
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_amount ELSE 0 END), 0) as total_carbon_saved
                FROM points_transactions 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 商品兑换统计
            $exchangeStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_exchanges,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_exchanges,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_exchanges,
                    COALESCE(SUM(points_cost), 0) as total_points_spent
                FROM product_exchanges 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 消息统计
            $messageStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_messages,
                    COUNT(CASE WHEN status = 'unread' THEN 1 END) as unread_messages,
                    COUNT(CASE WHEN type = 'system' THEN 1 END) as system_messages
                FROM messages 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 活动统计
            $activityStats = $this->db->query("
                SELECT 
                    COUNT(*) as total_activities,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_activities
                FROM carbon_activities 
                WHERE deleted_at IS NULL
            ")->fetch(PDO::FETCH_ASSOC);

            // 最近30天的趋势数据
            $trendData = $this->db->query("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as transactions,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_amount ELSE 0 END), 0) as carbon_saved
                FROM points_transactions 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND deleted_at IS NULL
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

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
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log('ErrorLogService failed: ' . $ignore->getMessage()); }
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

