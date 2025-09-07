<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Monolog\Logger;
use JsonException;

/**
 * AuditLogService
 * 负责记录详细的用户和管理员操作审计日志，支持数据变更前后对比。
 * 设计为不抛异常，确保审计失败不影响主业务流程。
 */
class AuditLogService
{
    private PDO $db;
    private Logger $logger;
    private int $maxDataLength = 10000; // characters for data fields
    private array $sensitiveFields = [
        'password', 'pass', 'token', 'authorization', 'auth', 'secret', 
        'api_key', 'access_token', 'refresh_token', 'session_id', 'credit_card'
    ];

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * 记录审计日志
     * @param array $logData 日志数据数组
     * @return bool 记录是否成功
     */
    public function logAudit(array $logData): bool
    {
        try {
            // 必需字段验证
            $required = ['action', 'operation_category'];
            foreach ($required as $field) {
                if (!isset($logData[$field]) || empty($logData[$field])) {
                    $this->logger->warning('Audit log missing required field', [
                        'field' => $field,
                        'data' => $logData
                    ]);
                    return false;
                }
            }

            // 准备数据
            $data = $this->sanitizeAuditData($logData);
            
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (
                    user_id, actor_type, action, data, ip_address, user_agent,
                    request_method, endpoint, old_data, new_data, affected_table,
                    affected_id, status, response_code, session_id, referrer,
                    operation_category, operation_subtype, change_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $success = $stmt->execute([
                $data['user_id'] ?? null,
                $data['actor_type'] ?? 'user',
                $data['action'],
                $data['data'],
                $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
                $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null,
                $data['request_method'] ?? $_SERVER['REQUEST_METHOD'] ?? null,
                $data['endpoint'] ?? $_SERVER['REQUEST_URI'] ?? null,
                $data['old_data'],
                $data['new_data'],
                $data['affected_table'] ?? null,
                $data['affected_id'] ?? null,
                $data['status'] ?? 'success',
                $data['response_code'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? 200 : null),
                $data['session_id'] ?? session_id() ?? null,
                $data['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? null,
                $data['operation_category'],
                $data['operation_subtype'] ?? null,
                $data['change_type'] ?? 'other'
            ]);

            if (!$success) {
                $this->logger->warning('Audit log insert failed', [
                    'error' => $stmt->errorInfo(),
                    'data' => array_keys($data)
                ]);
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Audit logging exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => array_keys($logData ?? [])
            ]);
            return false;
        }
    }

    /**
     * 记录数据变更操作（支持前后对比）
     * @param string $category 操作类别
     * @param string $action 具体操作
     * @param int|null $userId 执行者ID
     * @param string $actorType 执行者类型
     * @param string $table 受影响的表
     * @param int|null $recordId 记录ID
     * @param array|null $oldData 修改前数据
     * @param array|null $newData 修改后数据
     * @param array $context 额外上下文
     * @return bool
     */
    public function logDataChange(
        string $category,
        string $action,
        ?int $userId,
        string $actorType = 'user',
        ?string $table = null,
        ?int $recordId = null,
        ?array $oldData = null,
        ?array $newData = null,
        array $context = []
    ): bool {
        
        $logData = [
            'action' => $action,
            'operation_category' => $category,
            'user_id' => $userId,
            'actor_type' => $actorType,
            'affected_table' => $table,
            'affected_id' => $recordId,
            'old_data' => $oldData ? $this->sanitizeData($oldData) : null,
            'new_data' => $newData ? $this->sanitizeData($newData) : null,
            'change_type' => $this->determineChangeType($oldData, $newData),
            'operation_subtype' => $context['subtype'] ?? null,
            'data' => $context['request_data'] ?? $this->getRequestData()
        ];

        return $this->logAudit($logData);
    }

    /**
     * 记录用户认证相关操作
     * @param string $action 动作（login, logout, register, password_change等）
     * @param int|null $userId 用户ID
     * @param bool $success 是否成功
     * @param array $context 上下文
     * @return bool
     */
    public function logAuthOperation(string $action, ?int $userId, bool $success, array $context = []): bool
    {
        return $this->logDataChange(
            'authentication',
            $action,
            $userId,
            'user',
            'users',
            $userId,
            null,
            null,
            array_merge($context, [
                'subtype' => $success ? 'success' : 'failed',
                'status' => $success ? 'success' : 'failed'
            ])
        );
    }

    /**
     * 记录管理员操作
     * @param string $action 操作名称
     * @param int $adminId 管理员ID
     * @param string $category 类别
     * @param array $context 上下文
     * @return bool
     */
    public function logAdminOperation(string $action, int $adminId, string $category, array $context = []): bool
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

    /**
     * 记录系统事件
     * @param string $action 事件名称
     * @param string $category 类别
     * @param array $context 上下文
     * @return bool
     */
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

    /**
     * 获取统计信息
     * @param array $filters 过滤条件
     * @return array
     */
    public function getAuditStats(array $filters = []): array
    {
        try {
            $sql = "SELECT 
                actor_type,
                operation_category,
                COUNT(*) as count,
                AVG(DATEDIFF(NOW(), created_at)) as avg_days_ago,
                MAX(created_at) as last_activity
            FROM audit_logs 
            WHERE 1=1";
            
            $params = [];
            if (isset($filters['date_from'])) {
                $sql .= " AND created_at >= ?";
                $params[] = $filters['date_from'];
            }
            if (isset($filters['date_to'])) {
                $sql .= " AND created_at <= ?";
                $params[] = $filters['date_to'];
            }
            if (isset($filters['actor_type'])) {
                $sql .= " AND actor_type = ?";
                $params[] = $filters['actor_type'];
            }
            if (isset($filters['category'])) {
                $sql .= " AND operation_category = ?";
                $params[] = $filters['category'];
            }

            $sql .= " GROUP BY actor_type, operation_category ORDER BY count DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit stats', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 获取详细审计日志
     * @param array $filters 过滤条件
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAuditLogs(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        try {
            $sql = "SELECT * FROM audit_logs WHERE 1=1";
            $params = [];
            
            if (isset($filters['user_id'])) {
                $sql .= " AND user_id = ?";
                $params[] = $filters['user_id'];
            }
            if (isset($filters['actor_type'])) {
                $sql .= " AND actor_type = ?";
                $params[] = $filters['actor_type'];
            }
            if (isset($filters['category'])) {
                $sql .= " AND operation_category = ?";
                $params[] = $filters['category'];
            }
            if (isset($filters['status'])) {
                $sql .= " AND status = ?";
                $params[] = $filters['status'];
            }
            if (isset($filters['date_from'])) {
                $sql .= " AND created_at >= ?";
                $params[] = $filters['date_from'];
            }
            if (isset($filters['date_to'])) {
                $sql .= " AND created_at <= ?";
                $params[] = $filters['date_to'];
            }

            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to get audit logs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * 清理旧日志
     * @param int $days 保留天数
     * @return int 删除的记录数
     */
    public function cleanupOldLogs(int $days = 365): int
    {
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));
            $stmt = $this->db->prepare("DELETE FROM audit_logs WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to cleanup old audit logs', [
                'error' => $e->getMessage(),
                'days' => $days
            ]);
            return 0;
        }
    }

    private function sanitizeAuditData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), $this->sensitiveFields)) {
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
            if (in_array(strtolower($key), $this->sensitiveFields)) {
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
            'headers' => getallheaders() ?? [],
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
}
