<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Monolog\Logger;
use JsonException;

/**
 * AuditLogService
 * 负责记录详细的用户/管理员/系统操作审计日志，支持数据变更前后对比。
 * 设计目标：失败不影响主业务，不抛出异常到调用层。
 */
class AuditLogService
{
    private const SQL_AND_CREATED_AT_LTE = ' AND created_at <= ?';
    private const SQL_AND_ACTOR_TYPE = ' AND actor_type = ?';
    private const SQL_AND_OPERATION_CATEGORY = ' AND operation_category = ?';

    private PDO $db;
    private Logger $logger;
    private ?int $lastInsertId = null;
    private int $maxDataLength = 10000; // JSON 字段截断长度
    private array $sensitiveFields = [
        'password','pass','token','authorization','auth','secret',
        'api_key','access_token','refresh_token','session_id','credit_card'
    ];

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * 向后兼容的入口：
     *  1) log(array $payload) 直接写入
     *  2) log(string $action, string $category, array $context = []) 推导并调用 logDataChange
     */
    public function log($arg1, $arg2 = null, $arg3 = null): bool
    {
        $this->lastInsertId = null;
        $result = false;
        try {
            if (is_array($arg1)) {
                $payload = $arg1;
                if (!isset($payload['operation_category']) || $payload['operation_category'] === '') {
                    $actionName = $payload['action'] ?? '';
                    $payload['operation_category'] = str_starts_with($actionName, 'auth_') ? 'authentication' : 'general';
                }
                if (!isset($payload['actor_type'])) {
                    $payload['actor_type'] = ($payload['user_id'] ?? null) ? 'user' : 'system';
                }
                $result = $this->logAudit($payload);
            } else {
                $action = (string)$arg1;
                $userId = null;
                $category = null;
                $context = [];
                // Determine signature form
                if (is_string($arg2) && !is_numeric($arg2)) {
                    // (action, category, context?)
                    $category = $arg2;
                    $context = is_array($arg3) ? $arg3 : [];
                } elseif (is_int($arg2) || (is_numeric($arg2) && (string)(int)$arg2 === (string)$arg2)) {
                    // Legacy (action, userId, context|string)
                    $userId = (int)$arg2;
                    if (is_array($arg3)) { $context = $arg3; }
                    elseif ($arg3 !== null) { $context = ['message' => (string)$arg3]; }
                    $category = $context['operation_category'] ?? 'general';
                } else {
                    // Unsupported combination
                    $category = 'general';
                }
                if (!$category) { $category = 'general'; }
                $userIdRaw   = $context['user_id'] ?? $context['uid'] ?? $userId;
                $recordIdRaw = $context['record_id'] ?? $context['affected_id'] ?? null;
                $finalUserId  = (is_int($userIdRaw) || (is_numeric($userIdRaw) && (string)(int)$userIdRaw === (string)$userIdRaw)) ? (int)$userIdRaw : null;
                $recordId = (is_int($recordIdRaw) || (is_numeric($recordIdRaw) && (string)(int)$recordIdRaw === (string)$recordIdRaw)) ? (int)$recordIdRaw : null;
                $actorType = $context['actor_type'] ?? ($context['is_admin'] ?? false ? 'admin' : 'user');
                $table = $context['table'] ?? $context['affected_table'] ?? null;
                $oldData = $context['old_data'] ?? null;
                $newData = $context['new_data'] ?? null;
                $result = $this->logDataChange(
                    $category,
                    $action,
                    $finalUserId,
                    $actorType,
                    $table,
                    $recordId,
                    is_array($oldData) ? $oldData : null,
                    is_array($newData) ? $newData : null,
                    $context
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('AuditLogService::log failed', [
                'error' => $e->getMessage(),
            ]);
            $result = false;
        }
        return $result;
    }

    /**
     * 核心写入方法
     */
    public function logAudit(array $logData): bool
    {
        try {
            foreach (['action','operation_category'] as $req) {
                if (empty($logData[$req])) {
                    $this->logger->warning('Audit log missing required field', ['field' => $req]);
                    return false;
                }
            }

            $data = $this->sanitizeAuditData($logData);
            foreach (['data','old_data','new_data'] as $opt) {
                if (!array_key_exists($opt, $data)) { $data[$opt] = null; }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (
                    user_id, actor_type, action, data, ip_address, user_agent,
                    request_method, endpoint, old_data, new_data, affected_table,
                    affected_id, status, response_code, session_id, referrer,
                    operation_category, operation_subtype, change_type, request_id
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );

            // request_id 优先来自显式字段，其次全局 $_SERVER（由中间件注入）
            $requestId = $data['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null);

            $ok = $stmt->execute([
                $data['user_id'] ?? null,
                $data['actor_type'] ?? 'user',
                $data['action'],
                $data['data'] ?? null,
                $data['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
                $data['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? null),
                $data['endpoint'] ?? ($_SERVER['REQUEST_URI'] ?? null),
                $data['old_data'] ?? null,
                $data['new_data'] ?? null,
                $data['affected_table'] ?? null,
                $data['affected_id'] ?? null,
                $data['status'] ?? 'success',
                $data['response_code'] ?? (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' ? 200 : null),
                $data['session_id'] ?? (function_exists('session_id') ? session_id() : null),
                $data['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null),
                $data['operation_category'],
                $data['operation_subtype'] ?? null,
                $data['change_type'] ?? 'other',
                $requestId
            ]);

            if (!$ok) {
                $this->lastInsertId = null;
                $this->logger->warning('Audit log insert returned false', [
                    'action' => $data['action'],
                    'category' => $data['operation_category']
                ]);
                return false;
            }
            $insertId = (int) $this->db->lastInsertId();
            $this->lastInsertId = $insertId > 0 ? $insertId : null;
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Audit logging exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 记录数据变更操作
     */
    public function logDataChange(
        string $category,
        string $action,
        ?int $userId,
        string $actorType = 'user',
        ?string $table = null,
        string|int|null $recordId = null,
        ?array $oldData = null,
        ?array $newData = null,
        array $context = []
    ): bool {
        $affectedId = null;
        if ($recordId !== null && (is_int($recordId) || (ctype_digit((string)$recordId) && (string)$recordId === (string)(int)$recordId))) {
            $affectedId = (int)$recordId;
        } elseif ($recordId !== null) {
            $context['non_numeric_record_id'] = (string)$recordId;
        }

        $logData = [
            'action' => $action,
            'operation_category' => $category,
            'user_id' => $userId,
            'actor_type' => $actorType,
            'affected_table' => $table,
            'affected_id' => $affectedId,
            'old_data' => $oldData ? $this->sanitizeData($oldData) : null,
            'new_data' => $newData ? $this->sanitizeData($newData) : null,
            'change_type' => $this->determineChangeType($oldData, $newData),
            'operation_subtype' => $context['subtype'] ?? null,
            'data' => $context['request_data'] ?? $this->getRequestData(),
            'request_id' => $context['request_id'] ?? ($_SERVER['HTTP_X_REQUEST_ID'] ?? null),
            'status' => $context['status'] ?? 'success'
        ];
        return $this->logAudit($logData);
    }

    public function logAuthOperation(string $action, ?int $userId, bool $success, array $context = []): bool
    {
        // Route through legacy log() to satisfy tests that mock log()
        $payload = [
            'action' => $action,
            'operation_category' => 'authentication',
            'user_id' => $userId,
            'actor_type' => 'user',
            'affected_table' => 'users',
            'affected_id' => $userId,
            'status' => $success ? 'success' : 'failed',
            'operation_subtype' => $success ? 'success' : 'failed',
            'request_id' => $context['request_id'] ?? null,
            'data' => $context['request_data'] ?? ($context['data'] ?? null),
            'old_data' => null,
            'new_data' => null
        ];
        return $this->log($payload);
    }

    public function logAdminOperation(string $action, ?int $adminId, string $category, array $context = []): bool
    {
        return $this->logDataChange(
            $category,
            $action,
            $adminId,
            'admin',
            $context['table'] ?? null,
            $context['record_id'] ?? null,
            $context['old_data'] ?? null,
            $context['new_data'] ?? null,
            $context
        );
    }

    // alias kept: primary log() already exists at top for compatibility

    /**
     * Legacy alias used in older tests: logUserAction($userId, $action, $context)
     */
    public function logUserAction(?int $userId, string $action, array $context = [], ?string $ip = null): bool
    {
        if ($ip && !isset($context['ip_address'])) { $context['ip_address'] = $ip; }
        // Reuse high-level logDataChange path
        $ok = $this->logDataChange(
            'user_action',
            $action,
            $userId,
            'user',
            $context['table'] ?? null,
            $context['record_id'] ?? null,
            $context['old_data'] ?? null,
            $context['new_data'] ?? null,
            $context
        );
        if ($ok) {
            $this->logger->info('audit_log_written', [ 'action' => $action, 'category' => 'user_action', 'user_id' => $userId ]);
        }
        return $ok;
    }

    /**
     * Legacy method expected in tests to fetch user logs.
     */
    public function getUserLogs(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM audit_logs WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim');
            $stmt->bindValue(':uid', $userId, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logger->warning('getUserLogs failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function logSystemEvent(string $action, string $category, array $context = []): bool
    {
        return $this->logDataChange(
            $category,
            $action,
            null,
            'system',
            null,
            null,
            null,
            null,
            $context
        );
    }

    public function getAuditStats(array $filters = []): array
    {
        try {
            $sql = "SELECT actor_type, operation_category, COUNT(*) as count, MAX(created_at) as last_activity FROM audit_logs WHERE 1=1";
            $params = [];
            if (isset($filters['date_from'])) { $sql .= " AND created_at >= ?"; $params[] = $filters['date_from']; }
            if (isset($filters['date_to'])) { $sql .= self::SQL_AND_CREATED_AT_LTE; $params[] = $filters['date_to']; }
            if (isset($filters['actor_type'])) { $sql .= self::SQL_AND_ACTOR_TYPE; $params[] = $filters['actor_type']; }
            if (isset($filters['category'])) { $sql .= self::SQL_AND_OPERATION_CATEGORY; $params[] = $filters['category']; }
            $sql .= " GROUP BY actor_type, operation_category ORDER BY count DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit stats', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAuditLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        try {
            $sql = "SELECT * FROM audit_logs WHERE 1=1";
            $params = [];
            if (isset($filters['user_id'])) { $sql .= " AND user_id = ?"; $params[] = $filters['user_id']; }
            if (isset($filters['actor_type'])) { $sql .= self::SQL_AND_ACTOR_TYPE; $params[] = $filters['actor_type']; }
            if (isset($filters['category'])) { $sql .= self::SQL_AND_OPERATION_CATEGORY; $params[] = $filters['category']; }
            if (isset($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; }
            if (isset($filters['date_from'])) { $sql .= " AND created_at >= ?"; $params[] = $filters['date_from']; }
            if (isset($filters['date_to'])) { $sql .= self::SQL_AND_CREATED_AT_LTE; $params[] = $filters['date_to']; }
            $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
            $params[] = $limit; $params[] = $offset;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit logs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getAuditLogsCount(array $filters = []): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM audit_logs WHERE 1=1";
            $params = [];
            if (isset($filters['user_id'])) { $sql .= " AND user_id = ?"; $params[] = $filters['user_id']; }
            if (isset($filters['actor_type'])) { $sql .= self::SQL_AND_ACTOR_TYPE; $params[] = $filters['actor_type']; }
            if (isset($filters['category'])) { $sql .= self::SQL_AND_OPERATION_CATEGORY; $params[] = $filters['category']; }
            if (isset($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; }
            if (isset($filters['date_from'])) { $sql .= " AND created_at >= ?"; $params[] = $filters['date_from']; }
            if (isset($filters['date_to'])) { $sql .= self::SQL_AND_CREATED_AT_LTE; $params[] = $filters['date_to']; }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit logs count', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    public function cleanupOldLogs(int $days = 365): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));
            $stmt = $this->db->prepare("DELETE FROM audit_logs WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to cleanup old audit logs', ['error' => $e->getMessage(), 'days' => $days]);
            return 0;
        }
    }

    private function sanitizeAuditData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                try {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $sanitized[$key] = $this->truncateData($json);
                } catch (JsonException $e) {
                    $sanitized[$key] = '[JSON_ERROR]';
                }
            } else {
                $sanitized[$key] = $this->truncateData((string)$value);
            }
        }
        return $sanitized;
    }

    private function sanitizeData(array $data): ?string
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields, true)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                try {
                    $sanitized[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    $sanitized[$key] = '[JSON_ERROR]';
                }
            } else {
                $sanitized[$key] = $value;
            }
        }
        try {
            $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $this->truncateData($json);
        } catch (JsonException $e) {
            return null;
        }
    }

    private function truncateData(string $data): string
    {
        if (mb_strlen($data, 'UTF-8') > $this->maxDataLength) {
            return mb_substr($data, 0, $this->maxDataLength, 'UTF-8') . '...[TRUNCATED]';
        }
        return $data;
    }

    private function getRequestData(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'query' => $_GET,
            'headers' => function_exists('getallheaders') ? (getallheaders() ?: []) : [],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    private function determineChangeType(?array $oldData, ?array $newData): string
    {
        if ($oldData === null && $newData !== null) return 'create';
        if ($oldData !== null && $newData === null) return 'delete';
        if ($oldData !== null && $newData !== null) return 'update';
        if ($oldData === null && $newData === null) return 'read';
        return 'other';
    }

    public function getLastInsertId(): ?int
    {
        return $this->lastInsertId;
    }
}
