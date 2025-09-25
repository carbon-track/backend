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

            // Optional filter by read status using is_read column (1/0)
            if (isset($params['is_read'])) {
                $where[] = 'm.is_read = :is_read';
                $bindings['is_read'] = ($params['is_read'] === 'true' || $params['is_read'] === '1') ? 1 : 0;
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
                    m.*
                FROM messages m
                WHERE {$whereClause}
                ORDER BY 
                    m.is_read ASC,
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

            foreach ($targetUserIds as $targetUserId) {
                try {
                    $this->messageService->sendSystemMessage(
                        (int)$targetUserId,
                        $title,
                        $content,
                        Message::TYPE_SYSTEM,
                        $priority
                    );
                    $sentCount++;
                } catch (\Throwable $e) {
                    $failedUserIds[] = (int)$targetUserId;
                    if ($this->errorLogService) {
                        try {
                            $this->errorLogService->logException($e, $request);
                        } catch (\Throwable $ignore) {}
                    }
                }
            }

            $emailDelivery = [
                'triggered' => false,
                'attempted_recipients' => 0,
                'successful_chunks' => 0,
                'failed_chunks' => 0,
                'failed_recipient_ids' => [],
                'missing_email_user_ids' => [],
            ];

            if ($this->shouldSendPriorityEmail($priority) && $this->emailService) {
                $emailDelivery['triggered'] = true;
                $emailSubject = $this->buildBroadcastEmailSubject($title, $priority);
                $emailBodyHtml = $this->renderBroadcastEmailHtml($title, $content);
                $emailBodyText = $this->renderBroadcastEmailText($title, $content);

                $recipientRows = [];
                foreach ($targetUserIds as $recipientId) {
                    $record = $targetUserRecords[$recipientId] ?? null;
                    if ($record === null) {
                        continue;
                    }
                    $email = trim((string)($record['email'] ?? ''));
                    if ($email === '') {
                        $emailDelivery['missing_email_user_ids'][] = $recipientId;
                        continue;
                    }
                    $recipientRows[] = [
                        'id' => $recipientId,
                        'email' => $email,
                        'name' => $record['username'] ?? $record['email'],
                    ];
                }

                $emailDelivery['attempted_recipients'] = count($recipientRows);
                if (!empty($recipientRows)) {
                    $chunks = array_chunk($recipientRows, 40);
                    foreach ($chunks as $chunk) {
                        $bccList = [];
                        foreach ($chunk as $entry) {
                            $bccList[] = ['email' => $entry['email'], 'name' => $entry['name']];
                        }

                        $success = $this->emailService->sendBroadcastEmail($bccList, $emailSubject, $emailBodyHtml, $emailBodyText);
                        if ($success) {
                            $emailDelivery['successful_chunks']++;
                        } else {
                            $emailDelivery['failed_chunks']++;
                            $emailDelivery['failed_recipient_ids'] = array_merge(
                                $emailDelivery['failed_recipient_ids'],
                                array_map(static fn(array $entry): int => (int)$entry['id'], $chunk)
                            );
                        }
                    }
                }
            }

            $this->auditLog->log(
                $user['id'],
                'system_message_broadcast',
                'messages',
                null,
                [
                    'title' => $title,
                    'content' => $content,
                    'priority' => $priority,
                    'scope' => $scope,
                    'target_count' => count($targetUserIds),
                    'sent_count' => $sentCount,
                    'invalid_user_ids' => $invalidTargetIds,
                    'failed_user_ids' => $failedUserIds,
                    'email_delivery' => $emailDelivery,
                ]
            );

            return $this->json($response, [
                'success' => true,
                'sent_count' => $sentCount,
                'total_targets' => count($targetUserIds),
                'failed_user_ids' => $failedUserIds,
                'invalid_user_ids' => $invalidTargetIds,
                'scope' => $scope,
                'message' => 'System message sent successfully',
                'priority' => $priority,
                'email_delivery' => $emailDelivery,
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

            $countStmt = $this->db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
            if ($countStmt && $countStmt->execute(['action' => 'system_message_broadcast'])) {
                $total = (int) $countStmt->fetchColumn();
            } else {
                $total = 0;
            }

            $listStmt = $this->db->prepare('SELECT id, user_id, data, created_at FROM audit_logs WHERE action = :action ORDER BY id DESC LIMIT :limit OFFSET :offset');
            if ($listStmt) {
                $listStmt->bindValue(':action', 'system_message_broadcast');
                $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $listStmt->execute();
                $logs = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } else {
                $logs = [];
            }

            $actorMap = [];
            if (!empty($logs)) {
                $actorIds = [];
                foreach ($logs as $logRow) {
                    if (isset($logRow['user_id']) && $logRow['user_id'] !== null) {
                        $actorIds[] = (int)$logRow['user_id'];
                    }
                }
                if (!empty($actorIds)) {
                    $actorMap = $this->loadUsernames($actorIds);
                }
            }

            $items = [];
            foreach ($logs as $logRow) {
                $meta = $this->decodeAuditData($logRow['data'] ?? null);
                $title = (string)($meta['title'] ?? '');
                $content = (string)($meta['content'] ?? '');
                if ($title === '' || $content === '') {
                    continue;
                }
                $actorId = isset($logRow['user_id']) ? (int)$logRow['user_id'] : null;
                $priority = (string)($meta['priority'] ?? Message::PRIORITY_NORMAL);
                if (!in_array($priority, Message::getValidPriorities(), true)) {
                    $priority = Message::PRIORITY_NORMAL;
                }
                $scope = (string)($meta['scope'] ?? 'all');
                $targetCount = (int)($meta['target_count'] ?? 0);
                $sentCount = (int)($meta['sent_count'] ?? 0);
                $invalidIds = $this->decodeIdList($meta['invalid_user_ids'] ?? []);
                $failedIds = $this->decodeIdList($meta['failed_user_ids'] ?? []);
                $emailDelivery = $this->normalizeEmailDeliveryMeta($meta['email_delivery'] ?? []);

                $startTime = (string)($logRow['created_at'] ?? date('Y-m-d H:i:s'));
                $endTime = date('Y-m-d H:i:s', strtotime($startTime . ' +60 minutes'));
                $recipients = $this->loadBroadcastRecipients($title, $content, $startTime, $endTime);

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
                    'id' => (int)($logRow['id'] ?? 0),
                    'actor_user_id' => $actorId,
                    'actor_username' => ($actorId !== null && isset($actorMap[$actorId])) ? $actorMap[$actorId] : null,
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
                    'email_delivery' => $emailDelivery,
                ];
            }

            return $this->json($response, [
                'success' => true,
                'data' => $items,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => (int)max(1, ceil($total / max(1, $limit))),
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

    private function loadBroadcastRecipients(string $title, string $content, string $start, string $end): array
    {
        try {
            $sql = 'SELECT m.id, m.receiver_id, m.is_read, u.username FROM messages m LEFT JOIN users u ON u.id = m.receiver_id WHERE m.deleted_at IS NULL AND m.title = :title AND m.content = :content AND m.created_at >= :start AND m.created_at <= :end';
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bindValue(':title', $title);
            $stmt->bindValue(':content', $content);
            $stmt->bindValue(':start', $start);
            $stmt->bindValue(':end', $end);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

        return $result;
    }

    private function shouldSendPriorityEmail(string $priority): bool
    {
        return in_array($priority, [Message::PRIORITY_HIGH, Message::PRIORITY_URGENT], true);
    }

    private function buildBroadcastEmailSubject(string $title, string $priority): string
    {
        $prefix = '';
        if ($priority === Message::PRIORITY_URGENT) {
            $prefix = '[URGENT] ';
        } elseif ($priority === Message::PRIORITY_HIGH) {
            $prefix = '[HIGH] ';
        }
        return $prefix . $title;
    }

    private function renderBroadcastEmailHtml(string $title, string $content): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeContent = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return '<div style="font-family: Arial, sans-serif; font-size: 14px; color: #333;">'
            . '<h2 style="color:#0d9488;">' . $safeTitle . '</h2>'
            . '<div>' . $safeContent . '</div>'
            . '<p style="margin-top:24px; color:#555;">'
            . 'This is an automated notification from CarbonTrack. Please do not reply directly to this email.'
            . '</p>'
            . '</div>';
    }

    private function renderBroadcastEmailText(string $title, string $content): string
    {
        $normalizedContent = preg_replace("/\r\n|\r|\n/", '\n', $content);

        return $title . "\n\n" . $normalizedContent . "\n\n" . 'This is an automated notification from CarbonTrack. Please do not reply.';
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




