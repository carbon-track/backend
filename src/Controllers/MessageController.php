<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Models\Message;
use PDO;

class MessageController
{
    private PDO $db;
    private MessageService $messageService;
    private AuditLogService $auditLog;
    private AuthService $authService;
    private ?EmailService $emailService;
    private ?ErrorLogService $errorLogService;

    public function __construct(
        PDO $db,
        MessageService $messageService,
        AuditLogService $auditLog,
        AuthService $authService,
        ?EmailService $emailService = null,
        ?ErrorLogService $errorLogService = null
    ) {
        $this->db = $db;
        $this->messageService = $messageService;
        $this->auditLog = $auditLog;
        $this->authService = $authService;
        $this->emailService = $emailService;
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

            // 状态筛选：前端使用 `status=unread|read`
            if (isset($params['status']) && $params['status'] !== '') {
                if ($params['status'] === 'unread') {
                    $where[] = 'm.is_read = 0';
                } elseif ($params['status'] === 'read') {
                    $where[] = 'm.is_read = 1';
                }
            }

            // 搜索：在 title 和 content 上模糊匹配
            if (!empty($params['search'])) {
                $where[] = '(m.title LIKE :search OR m.content LIKE :search)';
                $bindings['search'] = '%' . trim((string)$params['search']) . '%';
            }

            $whereClause = implode(' AND ', $where);

            // 计算总数
            $countSql = "SELECT COUNT(*) as total FROM messages m WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindings);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 检查 messages 表是否包含 priority 列（兼容老数据库）
            $hasPriority = false;
            try {
                $colStmt = $this->db->query("SHOW COLUMNS FROM messages LIKE 'priority'");
                if ($colStmt && $colStmt->fetch()) {
                    $hasPriority = true;
                }
            } catch (\Throwable $_) {
                // ignore - absence of column will be handled
                $hasPriority = false;
            }

            // 构建 priority 排序表达式（数值越大优先级越高）
            if ($hasPriority) {
                $priorityExpr = "(CASE COALESCE(m.priority,'normal') WHEN 'urgent' THEN 3 WHEN 'high' THEN 2 WHEN 'normal' THEN 1 WHEN 'low' THEN 0 ELSE 1 END)";
            } else {
                // 不存在 priority 列时，使用 0 常量占位（对排序无影响）
                $priorityExpr = "0";
            }

            // 处理排序参数，确保 priority 排序优先于用户指定排序
            $sort = trim((string)($params['sort'] ?? 'created_at_desc'));
            $userOrder = 'm.created_at DESC';
            $priorityOrderDir = 'DESC';

            switch ($sort) {
                case 'created_at_asc':
                    $userOrder = 'm.created_at ASC';
                    break;
                case 'created_at_desc':
                    $userOrder = 'm.created_at DESC';
                    break;
                case 'priority_asc':
                    // 用户请求优先级从低到高：priority 升序
                    $priorityOrderDir = 'ASC';
                    // 仍然在同一优先级内按时间倒序
                    $userOrder = 'm.created_at DESC';
                    break;
                case 'priority_desc':
                    $priorityOrderDir = 'DESC';
                    $userOrder = 'm.created_at DESC';
                    break;
                default:
                    // fallback
                    $userOrder = 'm.created_at DESC';
            }

            // 最终 ORDER BY：未读优先 -> priority 优先 -> 用户排序 -> 最后按 id 保持稳定
            $orderParts = [];
            $orderParts[] = 'm.is_read ASC';
            if ($hasPriority) {
                $orderParts[] = $priorityExpr . ' ' . $priorityOrderDir;
            }
            $orderParts[] = $userOrder;
            $orderParts[] = 'm.id DESC';
            $orderClause = implode(', ', $orderParts);

            // 获取消息列表
            $sql = "
                SELECT 
                    m.*
                FROM messages m
                WHERE {$whereClause}
                ORDER BY {$orderClause}
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

            // Cast is_read to boolean
            foreach ($messages as &$msg) {
                $msg['is_read'] = (bool)($msg['is_read'] ?? false);
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
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
                    m.*
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
            if (!($message['is_read'] ?? false)) {
                $this->markMessageAsRead($messageId);
                $message['is_read'] = true;
            }

            return $this->json($response, [
                'success' => true,
                'data' => $message
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
                    SET is_read = 1, updated_at = NOW() 
                    WHERE receiver_id = :user_id AND is_read = 0 AND deleted_at IS NULL
                ";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['user_id' => $user['id']]);
                $affectedRows = $stmt->rowCount();
            } else {
                // 标记指定消息为已读
                $placeholders = str_repeat('?,', count($messageIds) - 1) . '?';
                $sql = "
                    UPDATE messages 
                    SET is_read = 1, updated_at = NOW() 
                    WHERE receiver_id = ? AND id IN ({$placeholders}) AND is_read = 0 AND deleted_at IS NULL
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
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
            $this->auditLog->log([
                'user_id' => $user['id'],
                'actor_type' => 'user',
                'action' => 'message_deleted',
                'operation_category' => 'message',
                'affected_table' => 'messages',
                'affected_id' => $messageId,
                'data' => ['message_id' => $messageId],
            ]);

            return $this->json($response, [
                'success' => true,
                'message' => 'Message deleted successfully'
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
            $this->auditLog->log([
                'user_id' => $user['id'],
                'actor_type' => 'user',
                'action' => 'messages_batch_deleted',
                'operation_category' => 'message',
                'affected_table' => 'messages',
                'affected_id' => null,
                'data' => ['message_ids' => $messageIds, 'count' => $affectedRows],
            ]);

            return $this->json($response, [
                'success' => true,
                'affected_rows' => $affectedRows,
                'message' => 'Messages deleted successfully'
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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
                    COUNT(*) as total_unread
                FROM messages 
                WHERE receiver_id = :user_id AND is_read = 0 AND deleted_at IS NULL
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['user_id' => $user['id']]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'total_unread' => intval($counts['total_unread']),
                    'urgent_unread' => 0,
                    'high_unread' => 0,
                    'system_unread' => 0,
                    'notification_unread' => 0
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
            if (!is_array($data)) {
                return $this->json($response, ['error' => 'Invalid payload'], 400);
            }

            $title = trim((string)($data['title'] ?? ''));
            if ($title === '') {
                return $this->json($response, ['error' => 'Missing required field: title'], 400);
            }
            if (mb_strlen($title, 'UTF-8') > 255) {
                return $this->json($response, ['error' => 'Title must be 255 characters or less'], 422);
            }

            $content = trim((string)($data['content'] ?? ''));
            if ($content === '') {
                return $this->json($response, ['error' => 'Missing required field: content'], 400);
            }

            $priorityRaw = $data['priority'] ?? Message::PRIORITY_NORMAL;
            $priority = strtolower(trim((string)$priorityRaw));
            if ($priority === '') {
                $priority = Message::PRIORITY_NORMAL;
            }
            $validPriorities = Message::getValidPriorities();
            if (!in_array($priority, $validPriorities, true)) {
                return $this->json($response, ['error' => 'Invalid priority value'], 422);
            }

            $targetUsersRaw = $data['target_users'] ?? null;
            $filterGroupsRaw = $data['target_filters'] ?? null;

            $targetUserRecords = [];
            $targetUserIds = [];
            $invalidTargetIds = [];
            $errorLogIds = [];
            $loggedErrorMessages = [];

            if ($targetUsersRaw !== null) {
                if (!is_array($targetUsersRaw)) {
                    return $this->json($response, ['error' => 'target_users must be an array of positive integers'], 400);
                }
                $resolved = $this->resolveExplicitRecipients($targetUsersRaw);
                if ($resolved['error']) {
                    return $this->json($response, ['error' => $resolved['error']], $resolved['status']);
                }
                $targetUserIds = $resolved['user_ids'];
                $targetUserRecords = $resolved['records'];
                $invalidTargetIds = $resolved['invalid_ids'];
            }

            if ($filterGroupsRaw !== null) {
                if (!is_array($filterGroupsRaw)) {
                    return $this->json($response, ['error' => 'target_filters must be an array'], 400);
                }
                $filterResult = $this->resolveFilteredRecipients($filterGroupsRaw);
                $targetUserIds = array_values(array_unique(array_merge($targetUserIds, $filterResult['user_ids'])));
                foreach ($filterResult['records'] as $id => $record) {
                    if (!isset($targetUserRecords[$id])) {
                        $targetUserRecords[$id] = $record;
                    }
                }
            }

            $scope = 'all';
            if ($targetUsersRaw !== null || $filterGroupsRaw !== null) {
                $scope = 'custom';
            }

            if ($scope === 'all' && empty($targetUserIds)) {
                $allResult = $this->resolveAllRecipients();
                $targetUserIds = $allResult['user_ids'];
                $targetUserRecords = $allResult['records'];
            }

            if (empty($targetUserIds)) {
                return $this->json($response, ['error' => 'No target users found for broadcast'], 404);
            }

            $sentCount = 0;
            $failedUserIds = [];
            $createdMessageIds = [];
            foreach ($targetUserIds as $targetUserId) {
                try {
                    $message = $this->messageService->sendSystemMessage(
                        (int)$targetUserId,
                        $title,
                        $content,
                        Message::TYPE_SYSTEM,
                        $priority,
                        null,
                        null,
                        false
                    );
                    $sentCount++;
                    if ($message && isset($message->id)) {
                        $createdMessageIds[(int)$targetUserId] = (int)$message->id;
                    }
                } catch (\Throwable $e) {
                    $failedUserIds[] = (int)$targetUserId;
                    if ($this->errorLogService) {
                        try {
                            $this->errorLogService->logException($e, $request);
                        } catch (\Throwable $ignore) {}
                    }
                }
            }

            $missingEmailUserIds = [];
            $emailRecipients = [];
            foreach ($targetUserIds as $recipientId) {
                $record = $targetUserRecords[$recipientId] ?? null;
                if ($record === null) {
                    continue;
                }
                $email = trim((string)($record['email'] ?? ''));
                if ($email === '') {
                    $missingEmailUserIds[] = (int)$recipientId;
                    continue;
                }
                $displayName = $record['username'] ?? null;
                if ($displayName === null || $displayName === '') {
                    $displayName = $email;
                }
                $emailRecipients[] = [
                    'user_id' => (int)$recipientId,
                    'email' => $email,
                    'name' => $displayName,
                ];
            }

            $allMessageIds = array_values(array_filter($createdMessageIds));
            $messageIdCount = count($allMessageIds);
            $messageIdSample = $messageIdCount > 200 ? array_slice($allMessageIds, 0, 200) : $allMessageIds;
            $messageMapSample = $messageIdCount > 200 ? array_slice($createdMessageIds, 0, 200, true) : $createdMessageIds;
            $contentHash = hash('sha256', $title . '||' . $content);

            $emailDelivery = [
                'triggered' => false,
                'attempted_recipients' => count($emailRecipients),
                'successful_chunks' => 0,
                'failed_chunks' => 0,
                'failed_recipient_ids' => [],
                'missing_email_user_ids' => $missingEmailUserIds,
                'status' => count($emailRecipients) > 0 ? 'queued' : 'skipped',
                'errors' => [],
                'completed_at' => null,
            ];
            if ($this->shouldSendPriorityEmail($priority) && !empty($emailRecipients)) {
                $queueResult = $this->messageService->queueBroadcastEmail(
                    $emailRecipients,
                    $title,
                    $content,
                    $priority,
                    [
                        'scope' => $scope,
                        'message_ids' => $messageIdSample,
                    ]
                );
                if (!empty($queueResult['queued'])) {
                    $emailDelivery['triggered'] = true;
                    $emailDelivery['status'] = 'queued';
                } elseif (!empty($queueResult['error'])) {
                    $normalizedError = trim((string) $queueResult['error']);
                    if ($normalizedError !== '') {
                        $emailDelivery['errors'][] = $normalizedError;
                        $loggedErrorMessages[$normalizedError] = true;
                    }
                    $emailDelivery['status'] = 'failed';
                    $errorId = $this->logBroadcastError($request, 'broadcast_email_queue_failed', [
                        'scope' => $scope,
                        'message' => $normalizedError,
                        'priority' => $priority,
                    ]);
                    if ($errorId) {
                        $errorLogIds[] = $errorId;
                    }
                } else {
                    $emailDelivery['status'] = 'skipped';
                }
            }

            if (!empty($emailDelivery['errors'])) {
                foreach ($emailDelivery['errors'] as $deliveryError) {
                    $normalized = trim((string) $deliveryError);
                    if ($normalized === '' || isset($loggedErrorMessages[$normalized])) {
                        continue;
                    }
                    $errorId = $this->logBroadcastError($request, $normalized, [
                        'scope' => $scope,
                        'priority' => $priority,
                    ]);
                    if ($errorId) {
                        $errorLogIds[] = $errorId;
                    }
                    $loggedErrorMessages[$normalized] = true;
                }
            }

            $emailDeliveryForLog = $this->trimEmailDeliveryForLog($emailDelivery);

$auditPayload = [
                'action' => 'system_message_broadcast',
                'operation_category' => 'admin_message',
                'user_id' => $user['id'],
                'actor_type' => 'admin',
                'affected_table' => 'messages',
                'change_type' => 'broadcast',
                'data' => [
                    'title' => $title,
                    'content' => $content,
                    'priority' => $priority,
                    'scope' => $scope,
                    'target_count' => count($targetUserIds),
                    'sent_count' => $sentCount,
                    'invalid_user_ids' => $invalidTargetIds,
                    'failed_user_ids' => $failedUserIds,
                    'message_ids' => $messageIdSample,
                    'message_id_count' => $messageIdCount,
                    'message_id_map' => $messageMapSample,
                    'content_hash' => $contentHash,
                    'email_delivery' => $emailDeliveryForLog,
                ],
            ];

            $this->auditLog->log($auditPayload);
            $auditLogId = method_exists($this->auditLog, 'getLastInsertId') ? $this->auditLog->getLastInsertId() : null;
            $requestId = $request->getAttribute('request_id') ?? $request->getHeaderLine('X-Request-ID') ?? ($request->getServerParams()['HTTP_X_REQUEST_ID'] ?? null);
            if (is_string($requestId)) {
                $requestId = trim($requestId);
                if ($requestId === '') {
                    $requestId = null;
                }
            } else {
                $requestId = null;
            }
            $systemLogId = $this->lookupSystemLogId($requestId);

            $cleanErrorLogIds = [];
            foreach ($errorLogIds as $candidateId) {
                $candidateId = (int) $candidateId;
                if ($candidateId > 0) {
                    $cleanErrorLogIds[$candidateId] = $candidateId;
                }
            }
            $cleanErrorLogIds = array_values($cleanErrorLogIds);

            $filtersSnapshot = ['scope' => $scope];
            if ($filterGroupsRaw !== null) {
                $filtersSnapshot['target_filters'] = $filterGroupsRaw;
            }
            if ($targetUsersRaw !== null) {
                $filtersSnapshot['explicit_targets'] = $targetUsersRaw;
            }
            if (empty($filtersSnapshot['target_filters']) && empty($filtersSnapshot['explicit_targets'])) {
                $filtersSnapshot = ['scope' => $scope];
            }
            if ($filtersSnapshot === ['scope' => $scope]) {
                $filtersSnapshot = null;
            }

            $metaSnapshot = [];

            if (!empty($targetUserRecords)) {
                $metaSnapshot['target_records_sample'] = array_slice(array_values($targetUserRecords), 0, 50);
            }
            if (!empty($loggedErrorMessages)) {
                $metaSnapshot['error_messages_logged'] = array_keys($loggedErrorMessages);
            }

            try {
                $insert = $this->db->prepare('INSERT INTO message_broadcasts (request_id, audit_log_id, system_log_id, error_log_ids, title, content, priority, scope, target_count, sent_count, invalid_user_ids, failed_user_ids, message_ids_snapshot, message_map_snapshot, message_id_count, content_hash, email_delivery_snapshot, filters_snapshot, meta, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                if ($insert) {
                    $insert->execute([
                        $requestId,
                        $auditLogId,
                        $systemLogId,
                        $this->encodeJson($cleanErrorLogIds),
                        $title,
                        $content,
                        $priority,
                        $scope,
                        count($targetUserIds),
                        $sentCount,
                        $this->encodeJson($invalidTargetIds),
                        $this->encodeJson($failedUserIds),
                        $this->encodeJson($messageIdSample),
                        $this->encodeJson($messageMapSample),
                        $messageIdCount,
                        $contentHash,
                        $this->encodeJson($emailDeliveryForLog),
                        $this->encodeJson($filtersSnapshot),
                        $this->encodeJson(!empty($metaSnapshot) ? $metaSnapshot : null),
                        $user['id'] ?? null,
                    ]);
                }
            } catch (\Throwable $persistError) {
                $this->logBroadcastError($request, 'broadcast_record_persist_failed', [
                    'message' => $persistError->getMessage(),
                ]);
            }

                        return $this->json($response, [
                'success' => true,
                'sent_count' => $sentCount,
                'total_targets' => count($targetUserIds),
                'failed_user_ids' => $failedUserIds,
                'invalid_user_ids' => $invalidTargetIds,
                'scope' => $scope,
                'message' => 'System message sent successfully',
                'priority' => $priority,
                'message_ids' => $messageIdSample,
                'message_id_count' => $messageIdCount,
                'email_delivery' => $emailDelivery,
                'error_log_ids' => $cleanErrorLogIds,
                'request_id' => $requestId,
            ]);
        } catch (\Exception $e) {
            try {
                if ($this->errorLogService) {
                    $this->errorLogService->logException($e, $request);
                }
            } catch (\Throwable $ignore) {}
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

            $overview = ['total' => 0, 'unread' => 0, 'read' => 0];
            $byType = [];
            $raw = [];

            // Try rich aggregation first (works with tests' PDO mock); fallback to simple counts
            try {
                $aggSql = "
                    SELECT 
                        COALESCE(type,'unknown') AS type,
                        COALESCE(priority,'normal') AS priority,
                        COUNT(*) AS count,
                        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count
                    FROM messages
                    WHERE receiver_id = :user_id AND deleted_at IS NULL
                    GROUP BY COALESCE(type,'unknown'), COALESCE(priority,'normal')
                ";
                $aggStmt = $this->db->prepare($aggSql);
                $aggStmt->execute(['user_id' => $user['id']]);
                $raw = $aggStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $total = 0; $unread = 0;
                foreach ($raw as $row) {
                    $t = $row['type'];
                    $p = $row['priority'];
                    $c = (int)$row['count'];
                    $u = (int)$row['unread_count'];
                    if (!isset($byType[$t])) {
                        $byType[$t] = ['total' => 0, 'unread' => 0, 'by_priority' => []];
                    }
                    $byType[$t]['total'] += $c;
                    $byType[$t]['unread'] += $u;
                    $byType[$t]['by_priority'][$p] = [
                        'total' => $c,
                        'unread' => $u
                    ];
                    $total += $c; $unread += $u;
                }
                $overview = [
                    'total' => $total,
                    'unread' => $unread,
                    'read' => max(0, $total - $unread)
                ];
            } catch (\Throwable $ignored) {
                // Fallback simple counts
                $simple = $this->db->prepare("SELECT COUNT(*) as total, COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread FROM messages WHERE receiver_id = :user_id AND deleted_at IS NULL");
                $simple->execute(['user_id' => $user['id']]);
                $row = $simple->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'unread' => 0];
                $overview = [
                    'total' => (int)$row['total'],
                    'unread' => (int)$row['unread'],
                    'read' => max(0, (int)$row['total'] - (int)$row['unread'])
                ];
                $byType = [];
                $raw = [];
            }

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'overview' => $overview,
                    'by_type' => $byType,
                    'raw_stats' => $raw
                ]
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 获取广播历史（管理员）
     */
        public function getBroadcastHistory(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = min(50, max(5, (int)($params['limit'] ?? 10)));
            $offset = ($page - 1) * $limit;

            $total = 0;
            try {
                $countStmt = $this->db->query('SELECT COUNT(*) FROM message_broadcasts');
                if ($countStmt !== false) {
                    $total = (int) $countStmt->fetchColumn();
                }
            } catch (\Throwable $countError) {
                $total = 0;
            }

            $rows = [];
            try {
                $listStmt = $this->db->prepare('SELECT * FROM message_broadcasts ORDER BY id DESC LIMIT :limit OFFSET :offset');
                if ($listStmt) {
                    $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                    $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                    $listStmt->execute();
                    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                }
            } catch (\Throwable $listError) {
                $rows = [];
            }

            $actorIds = [];
            foreach ($rows as $row) {
                if (isset($row['created_by']) && $row['created_by'] !== null) {
                    $actorIds[] = (int) $row['created_by'];
                }
            }
            $actorMap = !empty($actorIds) ? $this->loadUsernames($actorIds) : [];

            $items = [];
            foreach ($rows as $row) {
                $broadcastId = (int)($row['id'] ?? 0);
                $createdBy = isset($row['created_by']) ? (int)$row['created_by'] : null;
                $title = (string)($row['title'] ?? '');
                $content = (string)($row['content'] ?? '');
                $priority = (string)($row['priority'] ?? Message::PRIORITY_NORMAL);
                if (!in_array($priority, Message::getValidPriorities(), true)) {
                    $priority = Message::PRIORITY_NORMAL;
                }
                $scope = (string)($row['scope'] ?? 'all');
                $targetCount = (int)($row['target_count'] ?? 0);
                $sentCount = (int)($row['sent_count'] ?? 0);
                $invalidIds = $this->decodeIdList($row['invalid_user_ids'] ?? []);
                $failedIds = $this->decodeIdList($row['failed_user_ids'] ?? []);
                $messageIds = $this->decodeIdList($row['message_ids_snapshot'] ?? []);
                $messageIdCount = isset($row['message_id_count']) ? (int)$row['message_id_count'] : (count($messageIds) ?: null);
                $messageIdMap = $this->decodeJsonObject($row['message_map_snapshot'] ?? null);
                $emailDelivery = $this->normalizeEmailDeliveryMeta($this->decodeJsonValue($row['email_delivery_snapshot'] ?? null));
                $errorIds = $this->decodeIdList($row['error_log_ids'] ?? []);
                $requestId = isset($row['request_id']) ? trim((string)$row['request_id']) : null;
                if ($requestId === '') {
                    $requestId = null;
                }
                $createdAtRaw = $row['created_at'] ?? date('Y-m-d H:i:s');
                $startTime = is_string($createdAtRaw) ? $createdAtRaw : date('Y-m-d H:i:s');
                if ($createdAtRaw instanceof \DateTimeInterface) {
                    $startTime = $createdAtRaw->format('Y-m-d H:i:s');
                }
                $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' +60 minutes'));
                $recipients = $this->loadBroadcastRecipients($title, $startTime, $endTime, $messageIds, $row['content_hash'] ?? null);

                $readUsers = [];
                $unreadUsers = [];
                foreach ($recipients as $recipient) {
                    $entry = [
                        'user_id' => isset($recipient['receiver_id']) ? (int)$recipient['receiver_id'] : null,
                        'username' => $recipient['username'] ?? null,
                        'message_id' => isset($recipient['id']) ? (int)$recipient['id'] : null,
                        'read' => (bool)($recipient['is_read'] ?? false),
                    ];
                    if ($entry['read']) {
                        $readUsers[] = $entry;
                    } else {
                        $unreadUsers[] = $entry;
                    }
                }

                $items[] = [
                    'id' => $broadcastId,
                    'actor_user_id' => $createdBy,
                    'actor_username' => ($createdBy !== null && isset($actorMap[$createdBy])) ? $actorMap[$createdBy] : null,
                    'title' => $title,
                    'content' => $content,
                    'priority' => $priority,
                    'scope' => $scope,
                    'target_count' => $targetCount,
                    'sent_count' => $sentCount,
                    'read_count' => count($readUsers),
                    'unread_count' => count($unreadUsers),
                    'invalid_user_ids' => $invalidIds,
                    'failed_user_ids' => $failedIds,
                    'read_users' => $readUsers,
                    'unread_users' => $unreadUsers,
                    'created_at' => $startTime,
                    'message_id_count' => $messageIdCount ?? count($recipients),
                    'message_ids' => $messageIds,
                    'message_id_map' => $messageIdMap,
                    'email_delivery' => $emailDelivery,
                    'request_id' => $requestId,
                    'audit_log_id' => isset($row['audit_log_id']) ? (int)$row['audit_log_id'] : null,
                    'system_log_id' => isset($row['system_log_id']) ? (int)$row['system_log_id'] : null,
                    'error_log_ids' => $errorIds,
                ];
            }

            return $this->json($response, [
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int) max(1, ceil($total / max(1, $limit))),
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                if ($this->errorLogService) {
                    $this->errorLogService->logException($e, $request);
                }
            } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * 刷新广播邮件队列并尝试发送（仅限管理员）。
     */
    public function flushBroadcastEmailQueue(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $query = $request->getQueryParams();
            $body = $request->getParsedBody();
            $params = [];
            if (is_array($query)) {
                $params = array_merge($params, $query);
            }
            if (is_array($body)) {
                $params = array_merge($params, $body);
            }

            $limit = isset($params['limit']) ? (int)$params['limit'] : 10;
            $limit = max(1, min(50, $limit));

            $forceSend = false;
            if (isset($params['force'])) {
                $rawForce = $params['force'];
                if (is_bool($rawForce)) {
                    $forceSend = $rawForce;
                } elseif (is_numeric($rawForce)) {
                    $forceSend = ((int)$rawForce) !== 0;
                } elseif (is_string($rawForce)) {
                    $normalized = strtolower(trim($rawForce));
                    $forceSend = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
                }
            }

            if ($forceSend && $this->emailService === null) {
                return $this->json($response, [
                    'error' => 'Email service unavailable, cannot force send queued broadcasts',
                ], 503);
            }

            $fetchLimit = max($limit * 3, $limit);
            $stmt = $this->db->prepare('SELECT * FROM message_broadcasts ORDER BY created_at ASC LIMIT :limit');
            if (!$stmt) {
                return $this->json($response, ['error' => 'Failed to inspect broadcast queue'], 500);
            }
            $stmt->bindValue(':limit', $fetchLimit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $processed = [];
            $skipped = [];
            $now = date('Y-m-d H:i:s');

            foreach ($rows as $row) {
                if (count($processed) >= $limit) {
                    break;
                }

                $broadcastId = isset($row['id']) ? (int)$row['id'] : 0;
                if ($broadcastId <= 0) {
                    continue;
                }

                $delivery = $this->normalizeEmailDeliveryMeta($row['email_delivery_snapshot'] ?? null);
                if (!in_array($delivery['status'], ['queued', 'partial'], true)) {
                    $skipped[] = $broadcastId;
                    continue;
                }

                if ($delivery['completed_at'] !== null && !$forceSend) {
                    $skipped[] = $broadcastId;
                    continue;
                }

                $createdAtRaw = $row['created_at'] ?? $now;
                if ($createdAtRaw instanceof \DateTimeInterface) {
                    $startTime = $createdAtRaw->format('Y-m-d H:i:s');
                } else {
                    $startTime = is_string($createdAtRaw) && $createdAtRaw !== '' ? $createdAtRaw : $now;
                }
                $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' +90 minutes'));

                $messageIds = $this->decodeIdList($row['message_ids_snapshot'] ?? []);
                $contentHash = isset($row['content_hash']) && is_string($row['content_hash']) ? $row['content_hash'] : null;
                $recipients = $this->loadBroadcastRecipients(
                    (string)($row['title'] ?? ''),
                    $startTime,
                    $endTime,
                    $messageIds,
                    $contentHash
                );

                $deliverable = [];
                $missingEmailUserIds = [];
                foreach ($recipients as $recipient) {
                    $receiverId = isset($recipient['receiver_id']) ? (int)$recipient['receiver_id'] : 0;
                    if ($receiverId <= 0) {
                        continue;
                    }
                    if (isset($deliverable[$receiverId]) || in_array($receiverId, $missingEmailUserIds, true)) {
                        continue;
                    }
                    $email = trim((string)($recipient['email'] ?? ''));
                    if ($email === '') {
                        $missingEmailUserIds[] = $receiverId;
                        continue;
                    }
                    $name = (string)($recipient['username'] ?? '');
                    if ($name === '') {
                        $name = $email;
                    }
                    $deliverable[$receiverId] = [
                        'email' => $email,
                        'name' => $name,
                    ];
                }

                $deliverableList = array_values($deliverable);
                $attempted = count($deliverableList);

                $status = $delivery['status'];
                $errors = $delivery['errors'];
                $failedChunks = $delivery['failed_chunks'];
                $successfulChunks = $delivery['successful_chunks'];
                $failedRecipientIds = $delivery['failed_recipient_ids'];

                $sendResult = true;
                if ($forceSend && $attempted > 0 && $this->emailService) {
                    $payload = [];
                    foreach ($deliverableList as $entry) {
                        $payload[] = [
                            'email' => $entry['email'],
                            'name' => $entry['name'],
                        ];
                    }

                    $sendResult = $this->emailService->sendAnnouncementBroadcast(
                        $payload,
                        (string)($row['title'] ?? ''),
                        (string)($row['content'] ?? ''),
                        (string)($row['priority'] ?? Message::PRIORITY_NORMAL)
                    );

                    if ($sendResult) {
                        $status = empty($missingEmailUserIds) ? 'sent' : 'partial';
                        $successfulChunks = max(1, $successfulChunks);
                        $failedChunks = 0;
                        $failedRecipientIds = [];
                    } else {
                        $status = 'failed';
                        $failedChunks = max(1, $failedChunks);
                        $successfulChunks = max(0, $successfulChunks);
                        $failedRecipientIds = array_keys($deliverable);
                        $errorMessage = $this->emailService->getLastError() ?? 'Broadcast email dispatch failed';
                        if ($errorMessage !== '' && !in_array($errorMessage, $errors, true)) {
                            $errors[] = $errorMessage;
                        }
                    }
                } else {
                    if ($attempted > 0) {
                        $status = empty($missingEmailUserIds) ? 'sent' : 'partial';
                        $successfulChunks = max(1, $successfulChunks);
                        $failedChunks = 0;
                        $failedRecipientIds = [];
                    } else {
                        $status = 'skipped';
                    }
                }

                $updatedDelivery = [
                    'triggered' => true,
                    'attempted_recipients' => $attempted,
                    'successful_chunks' => $successfulChunks,
                    'failed_chunks' => $failedChunks,
                    'failed_recipient_ids' => array_values(array_unique($failedRecipientIds)),
                    'missing_email_user_ids' => array_values(array_unique(array_merge(
                        $delivery['missing_email_user_ids'] ?? [],
                        $missingEmailUserIds
                    ))),
                    'status' => $status,
                    'errors' => array_values(array_unique($errors)),
                    'completed_at' => $now,
                ];

                $update = $this->db->prepare('UPDATE message_broadcasts SET email_delivery_snapshot = :snapshot, updated_at = NOW() WHERE id = :id');
                if ($update) {
                    $update->execute([
                        ':snapshot' => $this->encodeJson($updatedDelivery),
                        ':id' => $broadcastId,
                    ]);
                }

                $processed[] = [
                    'id' => $broadcastId,
                    'status' => $status,
                    'attempted' => $attempted,
                    'force' => $forceSend,
                    'missing_email_user_ids' => $missingEmailUserIds,
                    'errors' => $updatedDelivery['errors'],
                ];
            }

            if (!empty($processed)) {
                $auditPayload = [
                    'action' => 'broadcast_email_flush',
                    'operation_category' => 'admin_message',
                    'user_id' => $user['id'],
                    'actor_type' => 'admin',
                    'change_type' => 'update',
                    'data' => [
                        'requested_limit' => $limit,
                        'force_send' => $forceSend,
                        'processed_ids' => array_column($processed, 'id'),
                        'skipped_ids' => $skipped,
                    ],
                ];
                $this->auditLog->log($auditPayload);
            }

            return $this->json($response, [
                'success' => true,
                'processed' => $processed,
                'skipped' => $skipped,
                'count' => count($processed),
            ]);
        } catch (\Throwable $e) {
            try {
                if ($this->errorLogService) {
                    $this->errorLogService->logException($e, $request, ['context' => 'flushBroadcastEmailQueue']);
                }
            } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function logBroadcastError(Request $request, string $message, array $context = []): ?int
    {
        if (!$this->errorLogService) {
            return null;
        }
        try {
            $payload = array_merge([
                'controller' => 'MessageController',
                'action' => 'sendSystemMessage',
            ], $context);
            return $this->errorLogService->logError('broadcast_error', $message, $request, $payload);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function lookupSystemLogId(?string $requestId): ?int
    {
        if ($requestId === null || $requestId === '') {
            return null;
        }
        try {
            $stmt = $this->db->prepare('SELECT id FROM system_logs WHERE request_id = :request_id ORDER BY id DESC LIMIT 1');
            if ($stmt && $stmt->execute(['request_id' => $requestId])) {
                $value = $stmt->fetchColumn();
                if ($value !== false) {
                    $id = (int) $value;
                    return $id > 0 ? $id : null;
                }
            }
        } catch (\Throwable $ignored) {
        }
        return null;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? null : $encoded;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decodeJsonValue($value)
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return [];
    }

    private function decodeJsonObject($value): array
    {
        $decoded = $this->decodeJsonValue($value);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeAuditData(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeIdList($value): array
    {
        if (is_array($value)) {
            return array_values(array_map('intval', $value));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_map('intval', $decoded));
            }
            $parts = preg_split('/[\s,]+/', $value);
            if ($parts) {
                $clean = [];
                foreach ($parts as $part) {
                    $num = (int)$part;
                    if ($num > 0) {
                        $clean[] = $num;
                    }
                }
                if ($clean) {
                    return $clean;
                }
            }
        }
        return [];
    }

    private function loadBroadcastRecipients(string $title, string $start, string $end, array $messageIds = [], ?string $contentHash = null): array
    {
        try {
            $ids = array_values(array_filter(array_map('intval', $messageIds), static fn(int $value): bool => $value > 0));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sql = 'SELECT m.id, m.receiver_id, m.is_read, u.username, u.email FROM messages m LEFT JOIN users u ON u.id = m.receiver_id WHERE m.deleted_at IS NULL AND m.id IN (' . $placeholders . ')';
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    return [];
                }
                foreach ($ids as $index => $id) {
                    $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
                }
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $sql = 'SELECT m.id, m.receiver_id, m.is_read, u.username, u.email FROM messages m LEFT JOIN users u ON u.id = m.receiver_id WHERE m.deleted_at IS NULL AND m.title = :title AND m.created_at >= :start AND m.created_at <= :end';
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($contentHash !== null && $contentHash !== '' && !empty($rows)) {
                $filtered = [];
                $contentStmt = $this->db->prepare('SELECT content FROM messages WHERE id = :id');
                foreach ($rows as $row) {
                    $messageId = isset($row['id']) ? (int)$row['id'] : 0;
                    if ($messageId <= 0) {
                        continue;
                    }
                    try {
                        if (!$contentStmt) {
                            $filtered = $rows;
                            break;
                        }
                        $contentStmt->bindValue(':id', $messageId, PDO::PARAM_INT);
                        $contentStmt->execute();
                        $contentRow = $contentStmt->fetch(PDO::FETCH_ASSOC);
                        $contentStmt->closeCursor();
                        $contentValue = is_array($contentRow) ? (string)($contentRow['content'] ?? '') : '';
                        if (hash('sha256', $title . '||' . $contentValue) === $contentHash) {
                            $filtered[] = $row;
                        }
                    } catch (\Throwable $e) {
                        $filtered = $rows;
                        break;
                    }
                }
                if (!empty($filtered)) {
                    return $filtered;
                }
            }

            return $rows;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 搜索广播收件人（管理员）
     */
    public function searchBroadcastRecipients(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => 'Admin access required'], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, (int)($params['page'] ?? 1));
            $limit = min(200, max(1, (int)($params['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;

            $criteria = $params;
            if (isset($params['ids'])) {
                $criteria['include_ids'] = $this->sanitizeIdList(is_array($params['ids']) ? $params['ids'] : explode(',', (string)$params['ids']));
            }
            if (isset($params['exclude_ids'])) {
                $criteria['exclude_ids'] = $this->sanitizeIdList(is_array($params['exclude_ids']) ? $params['exclude_ids'] : explode(',', (string)$params['exclude_ids']));
            }

            $rows = $this->performUserSearch($criteria, $limit + 1, $offset);
            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
            }

            $data = [];
            foreach ($rows as $row) {
                $normalized = $this->normalizeUserRow($row);
                if ($normalized['id'] === null) {
                    continue;
                }
                $data[] = $normalized;
            }

            return $this->json($response, [
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'has_more' => $hasMore,
                ],
            ]);
        } catch (\Throwable $e) {
            try {
                if ($this->errorLogService) {
                    $this->errorLogService->logException($e, $request);
                }
            } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function resolveExplicitRecipients(array $ids): array
    {
        $result = [
            'error' => null,
            'status' => 200,
            'user_ids' => [],
            'records' => [],
            'invalid_ids' => [],
        ];

        $sanitized = [];
        foreach ($ids as $value) {
            if (is_int($value) || (is_numeric($value) && (string)(int)$value === (string)$value)) {
                $intVal = (int)$value;
                if ($intVal > 0) {
                    $sanitized[$intVal] = $intVal;
                }
            }
        }

        if (empty($sanitized)) {
            $result['error'] = 'target_users must contain at least one valid id';
            $result['status'] = 400;
            return $result;
        }

        $placeholders = implode(',', array_fill(0, count($sanitized), '?'));
        $sql = 'SELECT id, username, email, school, school_id, location, is_admin, status FROM users WHERE deleted_at IS NULL AND id IN (' . $placeholders . ')';

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $result['error'] = 'Failed to resolve target users';
                $result['status'] = 500;
                return $result;
            }

            $stmt->execute(array_values($sanitized));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $collectedIds = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $id = isset($row['id']) ? (int)$row['id'] : 0;
                    if ($id <= 0) {
                        continue;
                    }
                    $collectedIds[$id] = $id;
                    $result['records'][$id] = $this->normalizeUserRow($row);
                } elseif (is_scalar($row)) {
                    $id = (int)$row;
                    if ($id <= 0) {
                        continue;
                    }
                    $collectedIds[$id] = $id;
                    if (!isset($result['records'][$id])) {
                        $result['records'][$id] = $this->normalizeUserRow(['id' => $id]);
                    }
                }
            }

            $result['user_ids'] = array_values($collectedIds);
            $result['invalid_ids'] = array_values(array_diff($sanitized, $result['user_ids']));
        } catch (\Throwable $e) {
            $result['error'] = 'Failed to resolve target users';
            $result['status'] = 500;
        }

        return $result;
    }

    private function resolveFilteredRecipients(array $filterGroups): array
    {
        $aggregated = [
            'user_ids' => [],
            'records' => [],
        ];

        foreach ($filterGroups as $filterGroup) {
            if (!is_array($filterGroup)) {
                continue;
            }
            $limit = (int)($filterGroup['limit'] ?? 250);
            $limit = max(10, min(500, $limit));
            $offset = max(0, (int)($filterGroup['offset'] ?? 0));

            $searchResult = $this->performUserSearch($filterGroup, $limit, $offset);
            foreach ($searchResult as $row) {
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $aggregated['user_ids'][$id] = $id;
                $aggregated['records'][$id] = $this->normalizeUserRow($row);
            }
        }

        $aggregated['user_ids'] = array_values($aggregated['user_ids']);

        return $aggregated;
    }

    private function resolveAllRecipients(): array
    {
        $result = [
            'user_ids' => [],
            'records' => [],
        ];

        try {
            $sql = 'SELECT id, username, email, school, school_id, location, is_admin, status FROM users WHERE deleted_at IS NULL';
            $stmt = $this->db->query($sql);
            if (!$stmt) {
                return $result;
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $row) {
                $id = isset($row['id']) ? (int)$row['id'] : 0;
                if ($id <= 0) {
                    continue;
                }
                $result['user_ids'][] = $id;
                $result['records'][$id] = $this->normalizeUserRow($row);
            }
        } catch (\Throwable $e) {
            // Swallow exception and return what we have
        }

        return $result;
    }

    private function performUserSearch(array $criteria, int $limit, int $offset = 0): array
    {
        $where = ['u.deleted_at IS NULL'];
        $params = [];

        $search = trim((string)($criteria['search'] ?? $criteria['q'] ?? ''));
        $fieldsRaw = $criteria['fields'] ?? null;
        $fields = [];
        if (is_string($fieldsRaw)) {
            $fields = array_filter(array_map('trim', explode(',', $fieldsRaw)));
        } elseif (is_array($fieldsRaw)) {
            foreach ($fieldsRaw as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    $fields[] = trim($candidate);
                }
            }
        }
        if (empty($fields)) {
            $fields = ['username', 'email', 'school', 'location', 'school_name'];
        }

        $fieldMap = [
            'username' => 'u.username',
            'email' => 'u.email',
            'school' => 'u.school',
            'location' => 'u.location',
            'school_name' => 's.name',
            'status' => 'u.status',
            'role' => 'u.role',
        ];

        if ($search !== '') {
            $searchParts = [];
            foreach ($fields as $field) {
                if ($field === 'id') {
                    $searchParts[] = 'CAST(u.id AS CHAR) LIKE :search';
                    continue;
                }
                if (!isset($fieldMap[$field])) {
                    continue;
                }
                $searchParts[] = $fieldMap[$field] . ' LIKE :search';
            }
            if (!empty($searchParts)) {
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
                $params['search'] = '%' . $search . '%';
            }
        }

        if (!empty($criteria['school_id'])) {
            $where[] = 'u.school_id = :school_id';
            $params['school_id'] = (int)$criteria['school_id'];
        }

        if (!empty($criteria['school'])) {
            $where[] = 'u.school LIKE :school_exact';
            $params['school_exact'] = '%' . trim((string)$criteria['school']) . '%';
        }

        if (!empty($criteria['email_suffix'])) {
            $suffix = ltrim(trim((string)$criteria['email_suffix']), '@');
            $where[] = 'u.email LIKE :email_suffix';
            $params['email_suffix'] = '%@' . $suffix;
        } elseif (!empty($criteria['email_domain'])) {
            $suffix = ltrim(trim((string)$criteria['email_domain']), '@');
            $where[] = 'u.email LIKE :email_suffix';
            $params['email_suffix'] = '%@' . $suffix;
        }

        if (array_key_exists('status', $criteria) && $criteria['status'] !== null && $criteria['status'] !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = trim((string)$criteria['status']);
        }

        if (array_key_exists('is_admin', $criteria)) {
            $value = $criteria['is_admin'];
            if ($value === '1' || $value === 1 || $value === true || $value === 'true') {
                $where[] = 'u.is_admin = 1';
            } elseif ($value === '0' || $value === 0 || $value === false || $value === 'false') {
                $where[] = 'u.is_admin = 0';
            }
        }

        if (!empty($criteria['include_ids']) && is_array($criteria['include_ids'])) {
            $clean = $this->sanitizeIdList($criteria['include_ids']);
            if (!empty($clean)) {
                $placeholders = implode(',', array_fill(0, count($clean), '?'));
                $where[] = 'u.id IN (' . $placeholders . ')';
                foreach ($clean as $id) {
                    $params[] = $id;
                }
            }
        }

        if (!empty($criteria['exclude_ids']) && is_array($criteria['exclude_ids'])) {
            $clean = $this->sanitizeIdList($criteria['exclude_ids']);
            if (!empty($clean)) {
                $placeholders = implode(',', array_fill(0, count($clean), '?'));
                $where[] = 'u.id NOT IN (' . $placeholders . ')';
                foreach ($clean as $id) {
                    $params[] = $id;
                }
            }
        }

        $conditions = implode(' AND ', $where);

        $sql = 'SELECT u.id, u.username, u.email, u.school, u.school_id, u.location, u.is_admin, u.status, s.name AS school_name '
            . 'FROM users u '
            . 'LEFT JOIN schools s ON s.id = u.school_id '
            . 'WHERE ' . $conditions . ' '
            . 'ORDER BY u.id DESC '
            . 'LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }

            $paramIndex = 1;
            foreach ($params as $key => $value) {
                if (is_int($key)) {
                    $stmt->bindValue($paramIndex, $value, PDO::PARAM_INT);
                    $paramIndex++;
                }
            }

            foreach ($params as $key => $value) {
                if (!is_int($key)) {
                    $type = PDO::PARAM_STR;
                    if (in_array($key, ['school_id'], true)) {
                        $type = PDO::PARAM_INT;
                        $value = (int)$value;
                    }
                    $stmt->bindValue(':' . $key, $value, $type);
                }
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function sanitizeIdList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (is_int($value) || (is_numeric($value) && (string)(int)$value === (string)$value)) {
                $intVal = (int)$value;
                if ($intVal > 0) {
                    $clean[$intVal] = $intVal;
                }
            }
        }
        return array_values($clean);
    }

    private function normalizeUserRow(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int)$row['id'] : null,
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
            'school' => $row['school'] ?? ($row['school_name'] ?? null),
            'school_id' => isset($row['school_id']) ? (int)$row['school_id'] : null,
            'location' => $row['location'] ?? null,
            'is_admin' => isset($row['is_admin']) ? (bool)$row['is_admin'] : null,
            'status' => $row['status'] ?? null,
        ];
    }

    private function normalizeEmailDeliveryMeta($value): array
    {
        $defaults = [
            'triggered' => false,
            'attempted_recipients' => 0,
            'successful_chunks' => 0,
            'failed_chunks' => 0,
            'failed_recipient_ids' => [],
            'missing_email_user_ids' => [],
            'status' => 'skipped',
            'errors' => [],
            'completed_at' => null,
        ];

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return $defaults;
        }

        $result = $defaults;
        $result['triggered'] = (bool)($value['triggered'] ?? false);
        $result['attempted_recipients'] = (int)($value['attempted_recipients'] ?? 0);
        $result['successful_chunks'] = (int)($value['successful_chunks'] ?? 0);
        $result['failed_chunks'] = (int)($value['failed_chunks'] ?? 0);
        $result['failed_recipient_ids'] = $this->decodeIdList($value['failed_recipient_ids'] ?? []);
        $result['missing_email_user_ids'] = $this->decodeIdList($value['missing_email_user_ids'] ?? []);
        $status = (string)($value['status'] ?? '');
        $result['status'] = $status !== '' ? $status : 'skipped';

        $errors = $value['errors'] ?? [];
        if (is_string($errors)) {
            $decodedErrors = json_decode($errors, true);
            if (is_array($decodedErrors)) {
                $errors = $decodedErrors;
            } else {
                $errors = array_filter(array_map('trim', preg_split('/[\r\n]+/', $errors) ?: []));
            }
        }
        if (!is_array($errors)) {
            $errors = [];
        }
        $normalizedErrors = [];
        foreach ($errors as $error) {
            if (!is_scalar($error)) {
                continue;
            }
            $trimmed = trim((string)$error);
            if ($trimmed === '' || in_array($trimmed, $normalizedErrors, true)) {
                continue;
            }
            $normalizedErrors[] = $trimmed;
        }
        $result['errors'] = $normalizedErrors;
        $completedAt = $value['completed_at'] ?? null;
        if ($completedAt instanceof \DateTimeInterface) {
            $completedAt = $completedAt->format('Y-m-d H:i:s');
        } elseif (!is_string($completedAt) || $completedAt === '') {
            $completedAt = null;
        }
        $result['completed_at'] = $completedAt;

        return $result;
    }

    private function trimEmailDeliveryForLog(array $delivery): array
    {
        $result = $delivery;
        $limit = 100;
        foreach (['failed_recipient_ids', 'missing_email_user_ids', 'errors'] as $key) {
            if (!isset($result[$key]) || !is_array($result[$key])) {
                continue;
            }
            if (count($result[$key]) > $limit) {
                $result[$key] = array_slice($result[$key], 0, $limit);
                $result[$key . '_truncated'] = true;
            }
        }
        return $result;
    }

    private function normalizeMessageIdMap($value): array
    {
        $result = [];
        if (is_array($value)) {
            foreach ($value as $userId => $messageId) {
                $intUserId = is_numeric($userId) ? (int)$userId : null;
                $intMessageId = is_numeric($messageId) ? (int)$messageId : null;
                if ($intUserId !== null && $intUserId > 0 && $intMessageId !== null && $intMessageId > 0) {
                    $result[$intUserId] = $intMessageId;
                }
            }
        } elseif (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $this->normalizeMessageIdMap($decoded);
            }
        }
        return $result;
    }

    private function shouldSendPriorityEmail(string $priority): bool
    {
        return in_array($priority, [Message::PRIORITY_HIGH, Message::PRIORITY_URGENT], true);
    }

    private function loadUsernames(array $ids): array
    {
        $cleanIds = [];
        foreach ($ids as $id) {
            $intId = (int)$id;
            if ($intId > 0) {
                $cleanIds[$intId] = $intId;
            }
        }
        if (empty($cleanIds)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $sql = 'SELECT id, username, email FROM users WHERE id IN (' . $placeholders . ')';
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $index = 1;
            foreach ($cleanIds as $userId) {
                $stmt->bindValue($index, $userId, PDO::PARAM_INT);
                $index++;
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $result = [];
            foreach ($rows as $row) {
                $uid = isset($row['id']) ? (int)$row['id'] : null;
                if ($uid === null) {
                    continue;
                }
                $username = null;
                if (!empty($row['username'])) {
                    $username = (string)$row['username'];
                } elseif (!empty($row['email'])) {
                    $username = (string)$row['email'];
                }
                $result[$uid] = $username;
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 标记消息为已读的私有方法
     */
    private function markMessageAsRead(string $messageId): void
    {
        $sql = "UPDATE messages SET is_read = 1, updated_at = NOW() WHERE id = :id AND is_read = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $messageId]);
    }
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}




