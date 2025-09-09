<?php
declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PDO;

/**
 * LogSearchController
 * 统一搜索 system_logs / audit_logs / error_logs
 * GET /api/v1/admin/logs/search
 * Query params:
 *   q: mixed keyword (LIKE)
 *   date_from, date_to
 *   types: comma list (system,audit,error) default all
 *   limit_per_type: each category limit (default 50, max 200)
 */
class LogSearchController
{
    private PDO $db;
    private AuthService $authService;
    private ?ErrorLogService $errorLogService;
    private const SEP_AND = ' AND ';
    private const KW_WHERE = 'WHERE ';
    private const LIMIT_PARAM = ':limit';

    public function __construct(PDO $db, AuthService $authService, ErrorLogService $errorLogService = null)
    {
        $this->db = $db;
        $this->authService = $authService;
        $this->errorLogService = $errorLogService;
    }

    public function search(Request $request, Response $response): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $q = $request->getQueryParams();
            $keyword = trim((string)($q['q'] ?? ''));
            $types = isset($q['types']) ? array_filter(array_map('trim', explode(',', (string)$q['types']))) : ['system','audit','error'];
            if (!$types) { $types = ['system','audit','error']; }
            $limit = (int)($q['limit_per_type'] ?? 50);
            $limit = max(1, min(200, $limit));
            $dateFrom = $q['date_from'] ?? null;
            $dateTo = $q['date_to'] ?? null;

            $result = [];
            if (in_array('system', $types, true)) {
                $result['system'] = $this->searchSystem($keyword, $limit, $dateFrom, $dateTo);
            }
            if (in_array('audit', $types, true)) {
                $result['audit'] = $this->searchAudit($keyword, $limit, $dateFrom, $dateTo);
            }
            if (in_array('error', $types, true)) {
                $result['error'] = $this->searchError($keyword, $limit, $dateFrom, $dateTo);
            }

            return $this->json($response, ['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { /* swallow secondary */ }
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    private function searchSystem(string $kw, int $limit, ?string $from, ?string $to): array
    {
        $conditions = [];
        $params = [];
        if ($kw !== '') {
            $conditions[] = '(path LIKE :kw OR request_id LIKE :kw OR method LIKE :kw OR user_agent LIKE :kw OR ip_address LIKE :kw OR request_body LIKE :kw OR response_body LIKE :kw OR server_meta LIKE :kw)';
            $params['kw'] = '%' . $kw . '%';
        }
        if ($from) { $conditions[] = 'created_at >= :from'; $params['from'] = $this->normalizeStart($from); }
        if ($to) { $conditions[] = 'created_at <= :to'; $params['to'] = $this->normalizeEnd($to); }
    $where = $conditions ? (self::KW_WHERE . implode(self::SEP_AND, $conditions)) : '';
        $sql = "SELECT id, request_id, method, path, status_code, user_id, duration_ms, created_at FROM system_logs {$where} ORDER BY id DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [ 'items' => $rows, 'count' => count($rows) ];
    }

    private function searchAudit(string $kw, int $limit, ?string $from, ?string $to): array
    {
        $conditions = [];
        $params = [];
        if ($kw !== '') {
            $conditions[] = '(action LIKE :kw OR operation_category LIKE :kw OR operation_subtype LIKE :kw OR endpoint LIKE :kw OR ip_address LIKE :kw OR data LIKE :kw OR old_data LIKE :kw OR new_data LIKE :kw)';
            $params['kw'] = '%' . $kw . '%';
        }
        if ($from) { $conditions[] = 'created_at >= :from'; $params['from'] = $this->normalizeStart($from); }
        if ($to) { $conditions[] = 'created_at <= :to'; $params['to'] = $this->normalizeEnd($to); }
    $where = $conditions ? (self::KW_WHERE . implode(self::SEP_AND, $conditions)) : '';
        $sql = "SELECT id, user_id, actor_type, action, operation_category, status, ip_address, created_at FROM audit_logs {$where} ORDER BY id DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [ 'items' => $rows, 'count' => count($rows) ];
    }

    private function searchError(string $kw, int $limit, ?string $from, ?string $to): array
    {
        $conditions = [];
        $params = [];
        if ($kw !== '') {
            $conditions[] = '(error_type LIKE :kw OR error_message LIKE :kw OR error_file LIKE :kw OR script_name LIKE :kw OR client_get LIKE :kw OR client_post LIKE :kw)';
            $params['kw'] = '%' . $kw . '%';
        }
        if ($from) { $conditions[] = 'error_time >= :from'; $params['from'] = $this->normalizeStart($from); }
        if ($to) { $conditions[] = 'error_time <= :to'; $params['to'] = $this->normalizeEnd($to); }
    $where = $conditions ? (self::KW_WHERE . implode(self::SEP_AND, $conditions)) : '';
        $sql = "SELECT id, error_type, error_message, error_file, error_line, error_time FROM error_logs {$where} ORDER BY id DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [ 'items' => $rows, 'count' => count($rows) ];
    }

    private function normalizeStart(string $d): string
    { return preg_match('/\d{2}:\d{2}:\d{2}/', $d) ? $d : trim($d) . ' 00:00:00'; }
    private function normalizeEnd(string $d): string
    { return preg_match('/\d{2}:\d{2}:\d{2}/', $d) ? $d : trim($d) . ' 23:59:59'; }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
