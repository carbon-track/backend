<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Models\CarbonActivity;
use PDO;

class CarbonTrackController
{
    private PDO $db;
    private CarbonCalculatorService $carbonCalculator;
    private MessageService $messageService;
    private AuditLogService $auditLog;
    private AuthService $authService;

    public function __construct(
        PDO $db,
        CarbonCalculatorService $carbonCalculator,
        MessageService $messageService,
        AuditLogService $auditLog,
        AuthService $authService
    ) {
        $this->db = $db;
        $this->carbonCalculator = $carbonCalculator;
        $this->messageService = $messageService;
        $this->auditLog = $auditLog;
        $this->authService = $authService;
    }

    /**
     * 提交碳减排记录
     */
    public function submitRecord(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $data = $request->getParsedBody();
            
            // 验证必需字段
            $requiredFields = ['activity_id', 'amount', 'date'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $this->json($response, [
                        'error' => "Missing required field: {$field}"
                    ], 400);
                }
            }

            // 获取活动信息
            $activity = CarbonActivity::findById($this->db, $data['activity_id']);
            if (!$activity) {
                return $this->json($response, ['error' => 'Activity not found'], 404);
            }

            // 计算碳减排量和积分（支持旧/新API）
            if (method_exists($this->carbonCalculator, 'calculate')) {
                $calculation = $this->carbonCalculator->calculate(
                    $data['activity_id'],
                    floatval($data['amount']),
                    $data['unit'] ?? $activity['unit']
                );
                $carbonSaved = $calculation['carbon_saved'] ?? 0;
                $pointsEarned = $calculation['points_earned'] ?? 0;
            } else {
                $calc = $this->carbonCalculator->calculateCarbonSavings($data['activity_id'], floatval($data['amount']));
                $carbonSaved = $calc['carbon_savings'] ?? 0;
                $pointsEarned = (int)round($carbonSaved * 10);
                $calculation = [
                    'carbon_saved' => $carbonSaved,
                    'points_earned' => $pointsEarned
                ];
            }

            // 创建记录
            $recordId = $this->createCarbonRecord([
                'user_id' => $user['id'],
                'activity_id' => $data['activity_id'],
                'amount' => $data['amount'],
                'unit' => $data['unit'] ?? $activity['unit'],
                'carbon_saved' => $carbonSaved,
                'points_earned' => $pointsEarned,
                'date' => $data['date'],
                'description' => $data['description'] ?? null,
                'images' => $data['images'] ?? null,
                'status' => 'pending'
            ]);

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                'carbon_record_submitted',
                'carbon_records',
                $recordId,
                ['activity_id' => $data['activity_id'], 'amount' => $data['amount']]
            );

            // 发送站内信
            $this->messageService->sendMessage(
                $user['id'],
                'record_submitted',
                '碳减排记录提交成功',
                "您的{$activity['name_zh']}记录已提交，预计获得{$calculation['points_earned']}积分，等待审核。",
                'normal',
                'carbon_records',
                $recordId
            );

            // 通知管理员
            $this->notifyAdminsNewRecord($recordId, $user, $activity);

            return $this->json($response, [
                'success' => true,
                'record_id' => $recordId,
                'calculation' => $calculation,
                'message' => 'Record submitted successfully'
            ]);

        } catch (\Exception $e) {
            error_log("Submit record error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 计算碳减排（仅返回计算结果，不落库）
     */
    public function calculate(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $data = $request->getParsedBody();
            if (!isset($data['activity_id']) || !isset($data['data'])) {
                return $this->json($response, ['error' => 'Missing required fields'], 400);
            }

            $activity = CarbonActivity::findById($this->db, $data['activity_id']);
            if (!$activity) {
                return $this->json($response, ['error' => 'Activity not found'], 404);
            }

            // Support both new and old service APIs
            if (method_exists($this->carbonCalculator, 'calculate')) {
                $calculation = $this->carbonCalculator->calculate(
                    $data['activity_id'],
                    floatval($data['data']),
                    $data['unit'] ?? $activity['unit']
                );
                $carbonSaved = $calculation['carbon_saved'] ?? 0;
                $pointsEarned = $calculation['points_earned'] ?? 0;
            } else {
                $calc = $this->carbonCalculator->calculateCarbonSavings($data['activity_id'], floatval($data['data']));
                $carbonSaved = $calc['carbon_savings'] ?? 0;
                $pointsEarned = (int)round($carbonSaved * 10);
            }

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'carbon_saved' => $carbonSaved,
                    'points_earned' => $pointsEarned,
                ]
            ]);
        } catch (\Exception $e) {
            error_log("Calculate error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取用户记录列表
     */
    public function getUserRecords(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $params = $request->getQueryParams();
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(50, max(10, intval($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $where = ['r.user_id = :user_id', 'r.deleted_at IS NULL'];
            $bindings = ['user_id' => $user['id']];

            if (!empty($params['status'])) {
                $where[] = 'r.status = :status';
                $bindings['status'] = $params['status'];
            }

            if (!empty($params['activity_id'])) {
                $where[] = 'r.activity_id = :activity_id';
                $bindings['activity_id'] = $params['activity_id'];
            }

            if (!empty($params['date_from'])) {
                $where[] = 'r.date >= :date_from';
                $bindings['date_from'] = $params['date_from'];
            }

            if (!empty($params['date_to'])) {
                $where[] = 'r.date <= :date_to';
                $bindings['date_to'] = $params['date_to'];
            }

            $whereClause = implode(' AND ', $where);

            // 获取总数
            $countSql = "
                SELECT COUNT(*) as total
                FROM carbon_records r
                WHERE {$whereClause}
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindings);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取记录列表
            $sql = "
                SELECT 
                    r.*,
                    a.name_zh as activity_name_zh,
                    a.name_en as activity_name_en,
                    a.category,
                    a.icon
                FROM carbon_records r
                LEFT JOIN carbon_activities a ON r.activity_id = a.id
                WHERE {$whereClause}
                ORDER BY r.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理图片字段
            foreach ($records as &$record) {
                $record['images'] = $record['images'] ? json_decode($record['images'], true) : [];
            }

            return $this->json($response, [
                'success' => true,
                'data' => $records,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => intval($total),
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            error_log("Get user records error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取记录详情
     */
    public function getRecordDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $recordId = $args['id'];

            $sql = "
                SELECT 
                    r.*,
                    a.name_zh as activity_name_zh,
                    a.name_en as activity_name_en,
                    a.category,
                    a.icon,
                    a.carbon_factor,
                    a.points_factor,
                    u.username as reviewer_username
                FROM carbon_records r
                LEFT JOIN carbon_activities a ON r.activity_id = a.id
                LEFT JOIN users u ON r.reviewed_by = u.id
                WHERE r.id = :record_id AND r.deleted_at IS NULL
            ";

            // 非管理员只能查看自己的记录
            if (!$this->authService->isAdminUser($user)) {
                $sql .= " AND r.user_id = :user_id";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue('record_id', $recordId);
            if (!$this->authService->isAdminUser($user)) {
                $stmt->bindValue('user_id', $user['id']);
            }
            $stmt->execute();

            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                return $this->json($response, ['error' => 'Record not found'], 404);
            }

            // 处理图片字段
            $record['images'] = $record['images'] ? json_decode($record['images'], true) : [];

            return $this->json($response, [
                'success' => true,
                'data' => $record
            ]);

        } catch (\Exception $e) {
            error_log("Get record detail error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员审核记录
     */
    public function reviewRecord(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $recordId = $args['id'];
            $data = $request->getParsedBody();

            if (!isset($data['action']) || !in_array($data['action'], ['approve', 'reject'])) {
                return $this->json($response, ['error' => 'Invalid action'], 400);
            }

            // 获取记录信息
            $record = $this->getCarbonRecord($recordId);
            if (!$record) {
                return $this->json($response, ['error' => 'Record not found'], 404);
            }

            if ($record['status'] !== 'pending') {
                return $this->json($response, ['error' => 'Record already reviewed'], 400);
            }

            $action = $data['action'];
            $reviewNote = $data['review_note'] ?? null;

            // 更新记录状态
            $sql = "
                UPDATE carbon_records 
                SET status = :status, 
                    reviewed_by = :reviewed_by, 
                    reviewed_at = NOW(),
                    review_note = :review_note
                WHERE id = :record_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => $action === 'approve' ? 'approved' : 'rejected',
                'reviewed_by' => $user['id'],
                'review_note' => $reviewNote,
                'record_id' => $recordId
            ]);

            // 如果审核通过，更新用户积分
            if ($action === 'approve') {
                $this->updateUserPoints($record['user_id'], $record['points_earned']);
            }

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                "carbon_record_{$action}",
                'carbon_records',
                $recordId,
                ['review_note' => $reviewNote]
            );

            // 发送站内信通知用户
            $this->sendReviewNotification($record, $action, $reviewNote);

            return $this->json($response, [
                'success' => true,
                'message' => "Record {$action}d successfully"
            ]);

        } catch (\Exception $e) {
            error_log("Review record error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员获取待审核记录
     */
    public function getPendingRecords(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(50, max(10, intval($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // 获取总数
            $countSql = "
                SELECT COUNT(*) as total
                FROM carbon_records r
                WHERE r.status = 'pending' AND r.deleted_at IS NULL
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute();
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取记录列表
            $sql = "
                SELECT 
                    r.*,
                    a.name_zh as activity_name_zh,
                    a.name_en as activity_name_en,
                    a.category,
                    u.username,
                    u.email,
                    s.name as school_name
                FROM carbon_records r
                LEFT JOIN carbon_activities a ON r.activity_id = a.id
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE r.status = 'pending' AND r.deleted_at IS NULL
                ORDER BY r.created_at ASC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理图片字段
            foreach ($records as &$record) {
                $record['images'] = $record['images'] ? json_decode($record['images'], true) : [];
            }

            return $this->json($response, [
                'success' => true,
                'data' => $records,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => intval($total),
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            error_log("Get pending records error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取用户统计信息
     */
    public function getUserStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $sql = "
                SELECT 
                    COUNT(*) as total_records,
                    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_records,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_records,
                    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_records,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_saved ELSE 0 END), 0) as total_carbon_saved,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN points_earned ELSE 0 END), 0) as total_points_earned
                FROM carbon_records 
                WHERE user_id = :user_id AND deleted_at IS NULL
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $user['id']]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            // 获取月度统计
            $monthlySql = "
                SELECT 
                    DATE_FORMAT(date, '%Y-%m') as month,
                    COUNT(*) as records_count,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN carbon_saved ELSE 0 END), 0) as carbon_saved,
                    COALESCE(SUM(CASE WHEN status = 'approved' THEN points_earned ELSE 0 END), 0) as points_earned
                FROM carbon_records 
                WHERE user_id = :user_id AND deleted_at IS NULL
                    AND date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(date, '%Y-%m')
                ORDER BY month DESC
            ";

            $monthlyStmt = $this->db->prepare($monthlySql);
            $monthlyStmt->execute(['user_id' => $user['id']]);
            $monthlyStats = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'overview' => $stats,
                    'monthly' => $monthlyStats
                ]
            ]);

        } catch (\Exception $e) {
            error_log("Get user stats error: " . $e->getMessage());
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取碳减排因子（占位，向后兼容）
     */
    public function getCarbonFactors(Request $request, Response $response): Response
    {
        return $this->json($response, [
            'success' => true,
            'data' => [
                'version' => '1.0',
                'note' => 'Use /carbon-activities for factors per activity',
            ]
        ]);
    }

    /**
     * 删除记录（软删除）
     */
    public function deleteTransaction(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }
            $recordId = $args['id'];

            // 非管理员只能删自己的记录
            $condition = $this->authService->isAdminUser($user) ? '' : ' AND user_id = :user_id';
            $sql = "UPDATE carbon_records SET deleted_at = NOW() WHERE id = :id{$condition} AND deleted_at IS NULL";
            $stmt = $this->db->prepare($sql);
            $params = ['id' => $recordId];
            if (!$this->authService->isAdminUser($user)) {
                $params['user_id'] = $user['id'];
            }
            $stmt->execute($params);

            return $this->json($response, ['success' => true]);
        } catch (\Exception $e) {
            error_log('Delete transaction error: ' . $e->getMessage());
            return $response->withStatus(500)->withJson(['error' => 'Internal server error']);
        }
    }

    /**
     * 创建碳减排记录
     */
    private function createCarbonRecord(array $data): string
    {
        $sql = "
            INSERT INTO carbon_records (
                id, user_id, activity_id, amount, unit, carbon_saved, 
                points_earned, date, description, images, status, created_at
            ) VALUES (
                :id, :user_id, :activity_id, :amount, :unit, :carbon_saved,
                :points_earned, :date, :description, :images, :status, NOW()
            )
        ";

        $recordId = $this->generateUuid();
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $recordId,
            'user_id' => $data['user_id'],
            'activity_id' => $data['activity_id'],
            'amount' => $data['amount'],
            'unit' => $data['unit'],
            'carbon_saved' => $data['carbon_saved'],
            'points_earned' => $data['points_earned'],
            'date' => $data['date'],
            'description' => $data['description'],
            'images' => $data['images'] ? json_encode($data['images']) : null,
            'status' => $data['status']
        ]);

        return $recordId;
    }

    /**
     * 获取碳减排记录
     */
    private function getCarbonRecord(string $recordId): ?array
    {
        $sql = "SELECT * FROM carbon_records WHERE id = :id AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $recordId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 更新用户积分
     */
    private function updateUserPoints(int $userId, float $points): void
    {
        $sql = "UPDATE users SET points = points + :points WHERE id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['points' => $points, 'user_id' => $userId]);
    }

    /**
     * 通知管理员新记录
     */
    private function notifyAdminsNewRecord(string $recordId, array $user, array $activity): void
    {
        // 获取所有管理员
        $sql = "SELECT id FROM users WHERE role = 'admin' AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $this->messageService->sendMessage(
                $admin['id'],
                'new_record_pending',
                '新的碳减排记录待审核',
                "用户 {$user['username']} 提交了新的{$activity['name_zh']}记录，请及时审核。",
                'high',
                'carbon_records',
                $recordId
            );
        }
    }

    /**
     * 发送审核通知
     */
    private function sendReviewNotification(array $record, string $action, ?string $reviewNote): void
    {
        $title = $action === 'approve' ? '碳减排记录审核通过' : '碳减排记录审核未通过';
        $message = $action === 'approve' 
            ? "恭喜！您的碳减排记录已审核通过，获得 {$record['points_earned']} 积分。"
            : "很抱歉，您的碳减排记录审核未通过。";
        
        if ($reviewNote) {
            $message .= "\n审核备注：{$reviewNote}";
        }

        $this->messageService->sendMessage(
            $record['user_id'],
            $action === 'approve' ? 'record_approved' : 'record_rejected',
            $title,
            $message,
            'normal',
            'carbon_records',
            $record['id']
        );
    }

    /**
     * 生成UUID
     */
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
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}

