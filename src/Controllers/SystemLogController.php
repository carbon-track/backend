<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
    private ?ErrorLogService $errorLogService;

    private const SENSITIVE_KEYS = ['password','pass','token','authorization','auth','secret'];

    public function __construct(PDO $db, AuthService $authService, ErrorLogService $errorLogService = null)
    {
        $this->db = $db;
        $this->authService = $authService;
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
                $conditions[] = '(
                    request_id LIKE :q OR
                    path LIKE :q OR
                    method LIKE :q OR
                    user_agent LIKE :q OR
                    ip_address LIKE :q OR
                    CAST(status_code AS CHAR) LIKE :q OR
                    request_body LIKE :q OR
                    response_body LIKE :q OR
                    server_meta LIKE :q
                )';
                $params['q'] = '%' . $q['q'] . '%';
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
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
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

            return $this->json($response, [
                'success' => true,
                'data' => $log
            ]);
        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => 'Internal server error'], 500);
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
