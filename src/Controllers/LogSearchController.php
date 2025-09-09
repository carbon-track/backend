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
 *   limit_per_type: each category page size (default 50, max 200)
 *   system_page / audit_page / error_page: 页码(>=1) 分别控制三类分页
 */
class LogSearchController
{
    private PDO $db;
    private AuthService $authService;
    private ?ErrorLogService $errorLogService;
    private const SEP_AND = ' AND ';
    private const KW_WHERE = 'WHERE ';
    private const LIMIT_PARAM = ':limit';
    private const OFFSET_PARAM = ':offset';
    private const FOUND_ROWS_SQL = 'SELECT FOUND_ROWS()';

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
            $limit = (int)($q['limit_per_type'] ?? 50); $limit = max(1, min(200, $limit));
            $systemPage = max(1, (int)($q['system_page'] ?? 1));
            $auditPage = max(1, (int)($q['audit_page'] ?? 1));
            $errorPage = max(1, (int)($q['error_page'] ?? 1));
            $dateFrom = $q['date_from'] ?? null;
            $dateTo = $q['date_to'] ?? null;

            $result = [];
            if (in_array('system', $types, true)) {
                $result['system'] = $this->searchSystem($keyword, $limit, $dateFrom, $dateTo, $systemPage);
            }
            if (in_array('audit', $types, true)) {
                $result['audit'] = $this->searchAudit($keyword, $limit, $dateFrom, $dateTo, $auditPage);
            }
            if (in_array('error', $types, true)) {
                $result['error'] = $this->searchError($keyword, $limit, $dateFrom, $dateTo, $errorPage);
            }

            return $this->json($response, ['success' => true, 'data' => $result]);
        } catch (\Exception $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { /* swallow secondary */ }
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

        /**
         * 导出日志 (CSV / NDJSON)
         */
        public function export(Request $request, Response $response): Response
        {
            $q = $request->getQueryParams();
            $format = strtolower($q['format'] ?? 'csv');
            if (!in_array($format, ['csv','ndjson'], true)) {
                return $this->json($response, ['success'=>false,'message'=>'format must be csv or ndjson'], 400);
            }
            $keyword = trim((string)($q['q'] ?? ''));
            $dateFrom = $q['date_from'] ?? null;
            $dateTo = $q['date_to'] ?? null;
            $types = isset($q['types']) && $q['types'] !== '' ? array_values(array_filter(array_map('trim', explode(',', $q['types'])))) : ['system','audit','error'];
            $allowed = ['system','audit','error'];
            $types = array_values(array_intersect($types, $allowed));
            if (!$types) { $types = ['system','audit','error']; }
            $max = (int)($q['max'] ?? 1000); $max = max(1, min(10000, $max));

            // 收集每类记录（最多 max / count(types) 各自抓取 或 统一累积直到总数达到）
            $perTypeCap = (int)ceil($max / max(1,count($types)));

            $datasets = [];
            foreach ($types as $t) {
                $datasets[$t] = $this->exportFetch($t, $keyword, $dateFrom, $dateTo, $perTypeCap);
            }

            if ($format === 'csv') {
                $filename = 'logs_export_' . date('Ymd_His') . '.csv';
                $response = $response->withHeader('Content-Type', 'text/csv; charset=UTF-8')
                                     ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
                $fh = fopen('php://temp','w+');
                // 统一列: type,id,request_id,method,path,status_code,user_id,duration_ms,created_at,action,operation_category,actor_type,audit_status,error_type,error_message,error_file,error_line,error_time
                $header = ['type','id','request_id','method','path','status_code','user_id','duration_ms','created_at','action','operation_category','actor_type','audit_status','error_type','error_message','error_file','error_line','error_time'];
                fputcsv($fh, $header);
                foreach ($datasets as $type => $rows) {
                    foreach ($rows as $r) {
                        fputcsv($fh, [
                            $type,
                            $r['id'] ?? null,
                            $r['request_id'] ?? null,
                            $r['method'] ?? null,
                            $r['path'] ?? null,
                            $r['status_code'] ?? null,
                            $r['user_id'] ?? null,
                            $r['duration_ms'] ?? null,
                            $r['created_at'] ?? null,
                            $r['action'] ?? null,
                            $r['operation_category'] ?? null,
                            $r['actor_type'] ?? null,
                            $r['status'] ?? null,
                            $r['error_type'] ?? null,
                            $r['error_message'] ?? null,
                            $r['error_file'] ?? null,
                            $r['error_line'] ?? null,
                            $r['error_time'] ?? null,
                        ]);
                    }
                }
                rewind($fh);
                $csv = stream_get_contents($fh) ?: '';
                fclose($fh);
                $response->getBody()->write($csv);
                return $response;
            }

            // NDJSON
            $response = $response->withHeader('Content-Type', 'application/x-ndjson')
                                 ->withHeader('Content-Disposition', 'attachment; filename="logs_export_' . date('Ymd_His') . '.ndjson"');
            $body = $response->getBody();
            foreach ($datasets as $type => $rows) {
                foreach ($rows as $r) {
                    $r['type'] = $type;
                    $body->write(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                }
            }
            return $response;
        }

        /**
         * 获取关联日志 (audit + error by request_id)
         */
        public function related(Request $request, Response $response): Response
        {
            $q = $request->getQueryParams();
            $rid = trim((string)($q['request_id'] ?? ''));
            if ($rid === '') {
                return $this->json($response, ['success'=>false,'message'=>'request_id required'], 400);
            }
            $audit = $this->fetchByRequestId('audit_logs', $rid, ['id','action','operation_category','actor_type','status','user_id','ip_address','created_at']);
            $error = $this->fetchByRequestId('error_logs', $rid, ['id','error_type','error_message','error_file','error_line','error_time']);
            return $this->json($response, ['success'=>true,'data'=>[
                'request_id' => $rid,
                'audit' => $audit,
                'error' => $error
            ]]);
        }

        private function fetchByRequestId(string $table, string $rid, array $columns): array
        {
            $cols = implode(',', $columns);
            $sql = "SELECT $cols FROM {$table} WHERE request_id = :rid ORDER BY id DESC LIMIT 200"; // 安全上限
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':rid', $rid);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        private function exportFetch(string $type, string $kw, ?string $from, ?string $to, int $limit): array
        {
            switch ($type) {
                case 'system':
                    return $this->rawFetch('system_logs', ['id','request_id','method','path','status_code','user_id','duration_ms','created_at'], ['method','path','request_body','response_body','error_message','server_meta'], $kw, $limit, ['from'=>$from,'to'=>$to,'date'=>'created_at']);
                case 'audit':
                    return $this->rawFetch('audit_logs', ['id','action','operation_category','actor_type','status','user_id','ip_address','created_at','request_id'], ['action','operation_category','details_raw','summary','old_data','new_data'], $kw, $limit, ['from'=>$from,'to'=>$to,'date'=>'created_at']);
                case 'error':
                    return $this->rawFetch('error_logs', ['id','error_type','error_message','error_file','error_line','error_time','request_id'], ['error_type','error_message','error_file','stack_trace'], $kw, $limit, ['from'=>$from,'to'=>$to,'date'=>'error_time']);
                default:
                    return [];
            }
        }

        private function rawFetch(string $table, array $selectCols, array $likeCols, string $kw, int $limit, array $dateFilter): array
        {
            $conditions = [];
            $params = [];
            $from = $dateFilter['from'] ?? null;
            $to = $dateFilter['to'] ?? null;
            $dateColumn = $dateFilter['date'] ?? 'created_at';
            if ($kw !== '') {
                $likeParts = [];
                foreach ($likeCols as $i => $col) {
                    $p = 'k' . $i;
                    $likeParts[] = "$col LIKE :$p";
                    $params[$p] = '%' . $kw . '%';
                }
                $conditions[] = '(' . implode(' OR ', $likeParts) . ')';
            }
            if ($from) { $conditions[] = "$dateColumn >= :dfrom"; $params['dfrom'] = $from . ' 00:00:00'; }
            if ($to) { $conditions[] = "$dateColumn <= :dto"; $params['dto'] = $to . ' 23:59:59'; }
            $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
            $cols = implode(',', $selectCols);
            $sql = "SELECT $cols FROM {$table} $where ORDER BY id DESC LIMIT :limit";
            $stmt = $this->db->prepare($sql);
            foreach ($params as $k=>$v) { $stmt->bindValue(':'.$k, $v); }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

    private function searchSystem(string $kw, int $limit, ?string $from, ?string $to, int $page): array
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
        $offset = ($page - 1) * $limit;
        $sql = "SELECT SQL_CALC_FOUND_ROWS id, request_id, method, path, status_code, user_id, duration_ms, created_at FROM system_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
    $stmt->bindValue(self::OFFSET_PARAM, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = $this->db->query(self::FOUND_ROWS_SQL)->fetchColumn() ?: count($rows);
        return [ 'items' => $rows, 'count' => (int)$total, 'page' => $page, 'pages' => (int)ceil($total / $limit), 'limit' => $limit ];
    }

    private function searchAudit(string $kw, int $limit, ?string $from, ?string $to, int $page): array
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
        $offset = ($page - 1) * $limit;
        $sql = "SELECT SQL_CALC_FOUND_ROWS id, user_id, actor_type, action, operation_category, status, ip_address, created_at FROM audit_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
        $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
    $stmt->bindValue(self::OFFSET_PARAM, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = $this->db->query(self::FOUND_ROWS_SQL)->fetchColumn() ?: count($rows);
        return [ 'items' => $rows, 'count' => (int)$total, 'page' => $page, 'pages' => (int)ceil($total / $limit), 'limit' => $limit ];
    }

    private function searchError(string $kw, int $limit, ?string $from, ?string $to, int $page): array
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
    $offset = ($page - 1) * $limit;
    $sql = "SELECT SQL_CALC_FOUND_ROWS id, error_type, error_message, error_file, error_line, error_time FROM error_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k=>$v) { $stmt->bindValue(':' . $k, $v); }
    $stmt->bindValue(self::LIMIT_PARAM, $limit, PDO::PARAM_INT);
    $stmt->bindValue(self::OFFSET_PARAM, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $total = $this->db->query(self::FOUND_ROWS_SQL)->fetchColumn() ?: count($rows);
    return [ 'items' => $rows, 'count' => (int)$total, 'page' => $page, 'pages' => (int)ceil($total / $limit), 'limit' => $limit ];
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
