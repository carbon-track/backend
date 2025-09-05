<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PDO;

class MessageController
{
    private PDO $db;
    private MessageService $messageService;
    private AuditLogService $auditLog;
    private AuthService $authService;
    private ErrorLogService $errorLogService;

    public function __construct(
        PDO $db,
        MessageService $messageService,
        AuditLogService $auditLog,
        AuthService $authService,
        ErrorLogService $errorLogService
    ) {
        $this->db = $db;
        $this->messageService = $messageService;
        $this->auditLog = $auditLog;
        $this->authService = $authService;
        $this->errorLogService = $errorLogService;
    }

    /**
     * 获取用户消息列表
     */
    public function getUserMessages(Request $request, Response $response): Response
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
            $where = ['m.receiver_id = :user_id', 'm.deleted_at IS NULL'];
            $bindings = ['user_id' => $user['id']];

            if (!empty($params['type'])) {
                $where[] = 'm.type = :type';
                $bindings['type'] = $params['type'];
            }

            if (!empty($params['priority'])) {
                $where[] = 'm.priority = :priority';
                $bindings['priority'] = $params['priority'];
            }

            if (isset($params['is_read'])) {
                if ($params['is_read'] === 'true' || $params['is_read'] === '1') {
                    $where[] = 'm.read_at IS NOT NULL';
                } else {
                    $where[] = 'm.read_at IS NULL';
                }
            }

            $whereClause = implode(' AND ', $where);

            // 获取总数
            $countSql = "SELECT COUNT(*) as total FROM messages m WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindings);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取消息列表
            $sql = "
                SELECT 
                    m.*,
                    CASE 
                        WHEN m.read_at IS NULL THEN false
                        ELSE true
                    END as is_read
                FROM messages m
                WHERE {$whereClause}
                ORDER BY 
                    CASE WHEN m.read_at IS NULL THEN 0 ELSE 1 END,
                    CASE m.priority 
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'normal' THEN 3
                        WHEN 'low' THEN 4
                        ELSE 5
                    END,
                    m.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Ensure is_read field exists when mocking without SQL alias
            foreach ($messages as &$msg) {
                if (!array_key_exists('is_read', $msg)) {
                    $msg['is_read'] = isset($msg['read_at']) && $msg['read_at'] !== null;
                }
            }

            return $this->json($response, [
                'success' => true,
                'data' => $messages,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => intval($total),
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取消息详情
     */
    public function getMessageDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $messageId = $args['id'];

            $sql = "
                SELECT 
                    m.*,
                    CASE 
                        WHEN m.read_at IS NULL THEN false
                        ELSE true
                    END as is_read
                FROM messages m
                WHERE m.id = :message_id AND m.receiver_id = :user_id AND m.deleted_at IS NULL
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'message_id' => $messageId,
                'user_id' => $user['id']
            ]);

            $message = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$message) {
                return $this->json($response, ['error' => 'Message not found'], 404);
            }

            // 如果消息未读，标记为已读
            if (!$message['is_read']) {
                $this->markMessageAsRead($messageId);
                $message['is_read'] = true;
                $message['read_at'] = date('Y-m-d H:i:s');
            }

            return $this->json($response, [
                'success' => true,
                'data' => $message
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 标记消息为已读
     */
    public function markAsRead(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $messageId = $args['id'];

            // 验证消息属于当前用户
            $sql = "SELECT id FROM messages WHERE id = :id AND receiver_id = :user_id AND deleted_at IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $messageId, 'user_id' => $user['id']]);
            
            if (!$stmt->fetch()) {
                return $this->json($response, ['error' => 'Message not found'], 404);
            }

            // 标记为已读
            $this->markMessageAsRead($messageId);

            return $this->json($response, [
                'success' => true,
                'message' => 'Message marked as read'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 批量标记消息为已读
     */
    public function markAllAsRead(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $data = $request->getParsedBody();
            $messageIds = $data['message_ids'] ?? [];

            if (empty($messageIds)) {
                // 标记所有未读消息为已读
                $sql = "
                    UPDATE messages 
                    SET read_at = NOW() 
                    WHERE receiver_id = :user_id AND read_at IS NULL AND deleted_at IS NULL
                ";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['user_id' => $user['id']]);
                $affectedRows = $stmt->rowCount();
            } else {
                // 标记指定消息为已读
                $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
                $sql = "
                    UPDATE messages 
                    SET read_at = NOW() 
                    WHERE receiver_id = ? AND id IN ({$placeholders}) AND read_at IS NULL AND deleted_at IS NULL
                ";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(array_merge([$user['id']], $messageIds));
                $affectedRows = $stmt->rowCount();
            }

            return $this->json($response, [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => 'Messages marked as read'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 删除消息
     */
    public function deleteMessage(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $messageId = $args['id'];

            // 验证消息属于当前用户
            $sql = "SELECT id FROM messages WHERE id = :id AND receiver_id = :user_id AND deleted_at IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $messageId, 'user_id' => $user['id']]);
            
            if (!$stmt->fetch()) {
                return $this->json($response, ['error' => 'Message not found'], 404);
            }

            // 软删除消息
            $sql = "UPDATE messages SET deleted_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $messageId]);

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                'message_deleted',
                'messages',
                $messageId,
                []
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Message deleted successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 批量删除消息
     */
    public function deleteMessages(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $data = $request->getParsedBody();
            $messageIds = $data['message_ids'] ?? [];

            if (empty($messageIds)) {
                return $this->json($response, ['error' => 'No message IDs provided'], 400);
            }

            // 验证所有消息都属于当前用户
            $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
            $sql = "
                SELECT COUNT(*) as count 
                FROM messages 
                WHERE receiver_id = ? AND id IN ({$placeholders}) AND deleted_at IS NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$user['id']], $messageIds));
            $validCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($validCount != count($messageIds)) {
                return $this->json($response, ['error' => 'Some messages not found or not owned by user'], 400);
            }

            // 批量软删除
            $sql = "
                UPDATE messages 
                SET deleted_at = NOW() 
                WHERE receiver_id = ? AND id IN ({$placeholders}) AND deleted_at IS NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$user['id']], $messageIds));
            $affectedRows = $stmt->rowCount();

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                'messages_batch_deleted',
                'messages',
                null,
                ['message_ids' => $messageIds, 'count' => $affectedRows]
            );

            return $this->json($response, [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => 'Messages deleted successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取未读消息数量
     */
    public function getUnreadCount(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $sql = "
                SELECT 
                    COUNT(*) as total_unread,
                    COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent_unread,
                    COUNT(CASE WHEN priority = 'high' THEN 1 END) as high_unread,
                    COUNT(CASE WHEN type = 'system' THEN 1 END) as system_unread,
                    COUNT(CASE WHEN type = 'notification' THEN 1 END) as notification_unread
                FROM messages 
                WHERE receiver_id = :user_id AND read_at IS NULL AND deleted_at IS NULL
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $user['id']]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'total_unread' => intval($counts['total_unread']),
                    'urgent_unread' => intval($counts['urgent_unread']),
                    'high_unread' => intval($counts['high_unread']),
                    'system_unread' => intval($counts['system_unread']),
                    'notification_unread' => intval($counts['notification_unread'])
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 管理员发送系统消息
     */
    public function sendSystemMessage(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $data = $request->getParsedBody();
            
            // 验证必需字段
            $requiredFields = ['title', 'content'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $this->json($response, [
                        'error' => "Missing required field: {$field}"
                    ], 400);
                }
            }

            $title = $data['title'];
            $content = $data['content'];
            $priority = $data['priority'] ?? 'normal';
            $targetUsers = $data['target_users'] ?? []; // 空数组表示发送给所有用户

            // 获取目标用户列表
            if (empty($targetUsers)) {
                // 发送给所有活跃用户
                $sql = "SELECT id FROM users WHERE deleted_at IS NULL";
                $stmt = $this->db->prepare($sql);
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $users = $targetUsers;
            }

            $sentCount = 0;
            foreach ($users as $userId) {
                try {
                    $this->messageService->sendMessage(
                        $userId,
                        'system_announcement',
                        $title,
                        $content,
                        $priority
                    );
                    $sentCount++;
                } catch (\Exception $e) {
                    error_log("Failed to send message to user {$userId}: " . $e->getMessage());
                }
            }

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                'system_message_sent',
                'messages',
                null,
                [
                    'title' => $title,
                    'target_count' => count($users),
                    'sent_count' => $sentCount,
                    'priority' => $priority
                ]
            );

            return $this->json($response, [
                'success' => true,
                'sent_count' => $sentCount,
                'total_targets' => count($users),
                'message' => 'System message sent successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取消息类型统计
     */
    public function getMessageStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $sql = "
                SELECT 
                    type,
                    priority,
                    COUNT(*) as count,
                    COUNT(CASE WHEN read_at IS NULL THEN 1 END) as unread_count
                FROM messages 
                WHERE receiver_id = :user_id AND deleted_at IS NULL
                GROUP BY type, priority
                ORDER BY type, priority
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $user['id']]);
            $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 按类型分组统计
            $typeStats = [];
            foreach ($stats as $stat) {
                $type = $stat['type'];
                if (!isset($typeStats[$type])) {
                    $typeStats[$type] = [
                        'total' => 0,
                        'unread' => 0,
                        'priorities' => []
                    ];
                }
                $typeStats[$type]['total'] += intval($stat['count']);
                $typeStats[$type]['unread'] += intval($stat['unread_count']);
                $typeStats[$type]['priorities'][$stat['priority']] = [
                    'count' => intval($stat['count']),
                    'unread' => intval($stat['unread_count'])
                ];
            }

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'by_type' => $typeStats,
                    'raw_stats' => $stats
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 标记消息为已读的私有方法
     */
    private function markMessageAsRead(string $messageId): void
    {
        $sql = "UPDATE messages SET read_at = NOW() WHERE id = :id AND read_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $messageId]);
    }
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}

