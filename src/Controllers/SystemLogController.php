<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PDO;

/**
 * SystemLogController
 * 管理员查询系统请求级日志。
 * 列表接口不返回 request_body / response_body 详情；详情接口才返回且做脱敏。
 */
class SystemLogController
{
    private PDO $db;
    private AuthService $authService;
    private AuditLogService $auditLogService;
    private ?ErrorLogService $errorLogService;

    private const SENSITIVE_KEYS = ['password','pass','token','authorization','auth','secret'];

    public function __construct(PDO $db, AuthService $authService, AuditLogService $auditLogService, ?ErrorLogService $errorLogService = null)
    {
        $this->db = $db;
        $this->authService = $authService;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
    }

    /**
     * GET /api/v1/admin/system-logs
     * 支持过滤: method, status_code, user_id, path(模糊), request_id, date_from, date_to
     * 分页: page, limit
     */
    public function list(Request $request, Response $response): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $q = $request->getQueryParams();
            $page = max(1, (int)($q['page'] ?? 1));
            $limit = min(100, max(10, (int)($q['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $conditions = [];
            $params = [];

            if (!empty($q['method'])) { $conditions[] = 'method = :method'; $params['method'] = strtoupper($q['method']); }
            if (!empty($q['status_code'])) { $conditions[] = 'status_code = :status_code'; $params['status_code'] = (int)$q['status_code']; }
            if (!empty($q['user_id'])) { $conditions[] = 'user_id = :user_id'; $params['user_id'] = (int)$q['user_id']; }
            if (!empty($q['request_id'])) { $conditions[] = 'request_id = :request_id'; $params['request_id'] = $q['request_id']; }
            if (!empty($q['path'])) { $conditions[] = 'path LIKE :path'; $params['path'] = '%' . $q['path'] . '%'; }
            if (!empty($q['date_from'])) { $conditions[] = 'created_at >= :date_from'; $params['date_from'] = $this->normalizeDateStart($q['date_from']); }
            if (!empty($q['date_to'])) { $conditions[] = 'created_at <= :date_to'; $params['date_to'] = $this->normalizeDateEnd($q['date_to']); }
            // super search q: 任意字段模糊匹配（大字段使用 LIKE 可能慢，可后续加全文索引）
            if (!empty($q['q'])) {
                $searchPattern = '%' . $q['q'] . '%';
                $conditions[] = '(
                    request_id LIKE :q_request_id OR
                    path LIKE :q_path OR
                    method LIKE :q_method OR
                    user_agent LIKE :q_user_agent OR
                    ip_address LIKE :q_ip_address OR
                    CAST(status_code AS CHAR) LIKE :q_status_code OR
                    request_body LIKE :q_request_body OR
                    response_body LIKE :q_response_body OR
                    server_meta LIKE :q_server_meta
                )';
                $params['q_request_id'] = $searchPattern;
                $params['q_path'] = $searchPattern;
                $params['q_method'] = $searchPattern;
                $params['q_user_agent'] = $searchPattern;
                $params['q_ip_address'] = $searchPattern;
                $params['q_status_code'] = $searchPattern;
                $params['q_request_body'] = $searchPattern;
                $params['q_response_body'] = $searchPattern;
                $params['q_server_meta'] = $searchPattern;
            }

            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

            $countSql = "SELECT COUNT(*) FROM system_logs {$where}";
            $countStmt = $this->db->prepare($countSql);
            foreach ($params as $k => $v) { $countStmt->bindValue(':' . $k, $v); }
            $countStmt->execute();
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT id, request_id, method, path, status_code, user_id, ip_address, user_agent, duration_ms, created_at
                    FROM system_logs {$where}
                    ORDER BY id DESC
                    LIMIT :limit OFFSET :offset";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k => $v) { $stmt->bindValue(':' . $k, $v); }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $this->logAudit('admin_system_logs_list_viewed', $admin, $request, [
                'data' => [
                    'page' => $page,
                    'limit' => $limit,
                    'result_count' => count($logs),
                ],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'logs' => $logs,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => (int)ceil($total / $limit)
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) { /* swallow secondary logging failure */ }
            $this->logAudit('admin_system_logs_list_failed', $admin ?? null, $request, [
                'data' => ['error' => $e->getMessage()],
            ], 'failed');
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/v1/admin/system-logs/{id}
     * 返回单条日志详情，包含脱敏后的 request_body / response_body。
     */
    public function detail(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->json($response, ['error' => 'Invalid id'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM system_logs WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$log) {
                return $this->json($response, ['error' => 'Not found'], 404);
            }

            $log['request_body'] = $this->decodeMaybeJson($log['request_body']);
            $log['response_body'] = $this->decodeMaybeJson($log['response_body']);
            if (array_key_exists('server_meta', $log)) {
                $log['server_meta'] = $this->decodeMaybeJson($log['server_meta']);
            }
            $log['request_body'] = $this->redact($log['request_body']);
            $log['response_body'] = $this->redact($log['response_body']);

            $this->logAudit('admin_system_log_detail_viewed', $admin, $request, [
                'record_id' => $id,
                'data' => ['request_id' => $log['request_id'] ?? null],
            ]);

            return $this->json($response, [
                'success' => true,
                'data' => $log
            ]);
        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) { /* swallow secondary logging failure */ }
            $this->logAudit('admin_system_log_detail_failed', $admin ?? null, $request, [
                'data' => ['error' => $e->getMessage()],
            ], 'failed');
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function logAudit(string $action, ?array $admin, Request $request, array $context = [], string $status = 'success'): void
    {
        try {
            $adminId = isset($admin['id']) && is_numeric((string)$admin['id']) ? (int)$admin['id'] : null;
            $this->auditLogService->logAdminOperation($action, $adminId, 'system_logs', array_merge([
                'record_id' => $context['record_id'] ?? null,
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => (string)$request->getUri()->getPath(),
                'status' => $status,
                'request_data' => $context['data'] ?? null,
            ], $context));
        } catch (\Throwable $ignore) {
            // 审计日志失败不阻断主流程
        }
    }

    private function normalizeDateStart(string $d): string
    {
        // 如果已经包含时间，直接返回
        if (preg_match('/\d{2}:\d{2}:\d{2}/', $d)) return $d;
        return trim($d) . ' 00:00:00';
    }

    private function normalizeDateEnd(string $d): string
    {
        if (preg_match('/\d{2}:\d{2}:\d{2}/', $d)) return $d;
        return trim($d) . ' 23:59:59';
    }

    private function decodeMaybeJson($raw)
    {
        if ($raw === null) return null;
        if (!is_string($raw)) return $raw; // 已经是数组
        $trim = trim($raw);
        if ($trim === '') return null;
        if (($trim[0] === '{' && substr($trim, -1) === '}') || ($trim[0] === '[' && substr($trim, -1) === ']')) {
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) return $decoded;
        }
        return $raw; // 保留原始字符串
    }

    private function redact($data)
    {
        if ($data === null) return null;
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k) && $this->isSensitive($k)) {
                    $data[$k] = '[REDACTED]';
                } elseif (is_array($v)) {
                    $data[$k] = $this->redact($v);
                }
            }
            return $data;
        }
        if (is_string($data)) {
            // 简单字符串内替换（仅键样式出现时）
            foreach (self::SENSITIVE_KEYS as $key) {
                $pattern = '/("' . preg_quote($key, '/') . '"\s*:\s*")[^"]*(")/i';
                $data = preg_replace($pattern, '$1[REDACTED]$2', $data);
            }
            return $data;
        }
        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $lk = strtolower($key);
        return in_array($lk, self::SENSITIVE_KEYS, true);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
