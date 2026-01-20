<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminLlmUsageController
{
    public function __construct(
        private PDO $db,
        private AuthService $authService,
        private ?ErrorLogService $errorLogService = null
    ) {
    }

    /**
     * GET /api/v1/admin/llm-usage
     */
    public function summary(Request $request, Response $response): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $q = $request->getQueryParams();
            $page = max(1, (int) ($q['page'] ?? 1));
            $limit = min(200, max(10, (int) ($q['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $search = isset($q['q']) ? trim((string) $q['q']) : '';
            $sort = isset($q['sort']) ? (string) $q['sort'] : 'llm_used_desc';

            $where = ['u.deleted_at IS NULL'];
            $params = [];
            if ($search !== '') {
                $where[] = '(u.username LIKE :search OR u.email LIKE :search)';
                $params['search'] = '%' . $search . '%';
            }
            $whereClause = implode(' AND ', $where);

            $sortMap = [
                'llm_used_desc' => 'COALESCE(usage_stats.counter, 0) DESC',
                'llm_used_asc' => 'COALESCE(usage_stats.counter, 0) ASC',
                'last_used_desc' => 'usage_stats.last_updated_at DESC',
                'username_asc' => 'u.username ASC',
                'username_desc' => 'u.username DESC',
            ];
            $orderBy = $sortMap[$sort] ?? $sortMap['llm_used_desc'];

            $sql = "SELECT
                        u.id,
                        u.username,
                        u.email,
                        u.is_admin,
                        u.group_id,
                        u.quota_override,
                        g.name AS group_name,
                        g.config AS group_config,
                        usage_stats.counter AS llm_daily_used,
                        usage_stats.reset_at AS llm_reset_at,
                        usage_stats.last_updated_at AS llm_last_used_at
                    FROM users u
                    LEFT JOIN user_groups g ON u.group_id = g.id
                    LEFT JOIN user_usage_stats usage_stats
                        ON usage_stats.user_id = u.id
                        AND usage_stats.resource_key = 'llm_daily'
                    WHERE {$whereClause}
                    ORDER BY {$orderBy}
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $users = [];
            foreach ($rows as $row) {
                $groupConfig = $this->decodeJson($row['group_config'] ?? null);
                $userOverride = $this->decodeJson($row['quota_override'] ?? null);
                $effective = array_merge(
                    $groupConfig['llm'] ?? [],
                    $userOverride['llm'] ?? []
                );

                $dailyLimit = isset($effective['daily_limit']) ? (int) $effective['daily_limit'] : null;
                $rateLimit = isset($effective['rate_limit']) ? (int) $effective['rate_limit'] : null;
                $dailyUsed = isset($row['llm_daily_used']) ? (int) $row['llm_daily_used'] : 0;
                $dailyRemaining = $dailyLimit !== null ? max($dailyLimit - $dailyUsed, 0) : null;

                $users[] = [
                    'id' => (int) $row['id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'is_admin' => (bool) $row['is_admin'],
                    'group_id' => $row['group_id'] !== null ? (int) $row['group_id'] : null,
                    'group_name' => $row['group_name'],
                    'daily_used' => $dailyUsed,
                    'daily_limit' => $dailyLimit,
                    'daily_remaining' => $dailyRemaining,
                    'rate_limit' => $rateLimit,
                    'reset_at' => $row['llm_reset_at'],
                    'last_used_at' => $row['llm_last_used_at'],
                ];
            }

            $countSql = "SELECT COUNT(*) FROM users u WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue(':' . $key, $value);
            }
            $countStmt->execute();
            $total = (int) $countStmt->fetchColumn();

            $summary = $this->fetchSummary();

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'users' => $users,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => $total,
                        'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { /* ignore */ }
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/v1/admin/llm-usage/analytics
     */
    public function analytics(Request $request, Response $response): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $q = $request->getQueryParams();
            $days = max(7, min(90, (int)($q['days'] ?? 30)));
            $recentLimit = max(5, min(30, (int)($q['recent_limit'] ?? 8)));

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $start = $now->modify('-' . max(0, $days - 1) . ' days')->setTime(0, 0, 0);
            $since = $start->format('Y-m-d H:i:s');

            $summary = $this->fetchSummary();
            $trends = $this->fetchDailyTrends($since, $start, $now);
            $distributions = [
                'models' => $this->fetchDistribution('model', 'model', $since, 8),
                'sources' => $this->fetchDistribution('source', 'source', $since, 8),
                'actors' => $this->fetchActorDistribution($since),
                'status' => $this->fetchDistribution('status', 'status', $since, 4),
            ];
            $rangeStats = $this->fetchRangeStats($since);
            $insights = $this->buildInsights($trends, $distributions, $rangeStats);
            $recent = $this->fetchRecentConversations($recentLimit);

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'range_days' => $days,
                    'trends' => $trends,
                    'distributions' => $distributions,
                    'insights' => $insights,
                    'recent_conversations' => $recent,
                ],
            ]);
        } catch (\Throwable $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { /* ignore */ }
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * GET /api/v1/admin/llm-usage/logs/{id}
     */
    public function logDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $admin = $this->authService->getCurrentUser($request);
            if (!$admin || !$this->authService->isAdminUser($admin)) {
                return $this->json($response, ['error' => 'Access denied'], 403);
            }

            $id = isset($args['id']) ? (int) $args['id'] : 0;
            if ($id <= 0) {
                return $this->json($response, ['error' => 'Invalid id'], 400);
            }

            $stmt = $this->db->prepare('SELECT * FROM llm_logs WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) {
                return $this->json($response, ['error' => 'Not found'], 404);
            }

            $log['usage'] = $this->decodeJson($log['usage_json'] ?? null);
            unset($log['usage_json']);
            $log['context'] = $this->decodeJson($log['context_json'] ?? null);
            unset($log['context_json']);
            $log['response_raw'] = $this->decodeMaybeJson($log['response_raw'] ?? null);
            $log['prompt'] = $this->decodeMaybeJson($log['prompt'] ?? null);

            return $this->json($response, [
                'success' => true,
                'data' => $log,
            ]);
        } catch (\Throwable $e) {
            try { $this->errorLogService?->logException($e, $request); } catch (\Throwable $ignore) { /* ignore */ }
            return $this->json($response, ['error' => 'Internal server error'], 500);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function fetchSummary(): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since1d = $now->modify('-1 day')->format('Y-m-d H:i:s');
        $since7d = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $since30d = $now->modify('-30 days')->format('Y-m-d H:i:s');

        $sql = "SELECT
                    COUNT(*) AS total_calls,
                    SUM(CASE WHEN created_at >= :since1d THEN 1 ELSE 0 END) AS calls_24h,
                    SUM(CASE WHEN created_at >= :since7d THEN 1 ELSE 0 END) AS calls_7d,
                    SUM(CASE WHEN created_at >= :since30d_calls THEN 1 ELSE 0 END) AS calls_30d,
                    SUM(CASE WHEN created_at >= :since30d_admin AND actor_type = 'admin' THEN 1 ELSE 0 END) AS admin_calls_30d,
                    SUM(CASE WHEN created_at >= :since30d_user AND actor_type = 'user' THEN 1 ELSE 0 END) AS user_calls_30d,
                    SUM(CASE WHEN created_at >= :since30d_tokens THEN COALESCE(total_tokens, 0) ELSE 0 END) AS tokens_30d,
                    SUM(CASE WHEN created_at >= :since30d_failed AND status = 'failed' THEN 1 ELSE 0 END) AS failed_calls_30d,
                    MAX(created_at) AS last_call_at
                FROM llm_logs";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since1d', $since1d);
        $stmt->bindValue(':since7d', $since7d);
        $stmt->bindValue(':since30d_calls', $since30d);
        $stmt->bindValue(':since30d_admin', $since30d);
        $stmt->bindValue(':since30d_user', $since30d);
        $stmt->bindValue(':since30d_tokens', $since30d);
        $stmt->bindValue(':since30d_failed', $since30d);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total_calls' => (int) ($row['total_calls'] ?? 0),
            'calls_24h' => (int) ($row['calls_24h'] ?? 0),
            'calls_7d' => (int) ($row['calls_7d'] ?? 0),
            'calls_30d' => (int) ($row['calls_30d'] ?? 0),
            'admin_calls_30d' => (int) ($row['admin_calls_30d'] ?? 0),
            'user_calls_30d' => (int) ($row['user_calls_30d'] ?? 0),
            'tokens_30d' => (int) ($row['tokens_30d'] ?? 0),
            'failed_calls_30d' => (int) ($row['failed_calls_30d'] ?? 0),
            'last_call_at' => $row['last_call_at'] ?? null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDailyTrends(string $since, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $sql = "SELECT
                    DATE(created_at) AS log_date,
                    COUNT(*) AS calls,
                    SUM(COALESCE(total_tokens, 0)) AS tokens,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_calls,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_calls,
                    AVG(latency_ms) AS avg_latency_ms
                FROM llm_logs
                WHERE created_at >= :since
                GROUP BY DATE(created_at)
                ORDER BY log_date ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since', $since);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexed = [];
        foreach ($rows as $row) {
            $date = $row['log_date'] ?? null;
            if (!$date) {
                continue;
            }
            $indexed[$date] = [
                'date' => $date,
                'calls' => (int) ($row['calls'] ?? 0),
                'tokens' => (int) ($row['tokens'] ?? 0),
                'success_calls' => (int) ($row['success_calls'] ?? 0),
                'failed_calls' => (int) ($row['failed_calls'] ?? 0),
                'avg_latency_ms' => $row['avg_latency_ms'] !== null ? (float) $row['avg_latency_ms'] : null,
            ];
        }

        $cursor = $start->setTime(0, 0, 0);
        $endDate = $end->setTime(0, 0, 0);
        $points = [];
        while ($cursor <= $endDate) {
            $dateKey = $cursor->format('Y-m-d');
            $points[] = $indexed[$dateKey] ?? [
                'date' => $dateKey,
                'calls' => 0,
                'tokens' => 0,
                'success_calls' => 0,
                'failed_calls' => 0,
                'avg_latency_ms' => null,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $points;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDistribution(string $column, string $alias, string $since, int $limit): array
    {
        $sql = "SELECT {$column} AS label,
                    COUNT(*) AS calls,
                    SUM(COALESCE(total_tokens, 0)) AS tokens
                FROM llm_logs
                WHERE created_at >= :since
                  AND {$column} IS NOT NULL
                  AND {$column} <> ''
                GROUP BY {$column}
                ORDER BY calls DESC
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                $alias => $row['label'],
                'calls' => (int) ($row['calls'] ?? 0),
                'tokens' => (int) ($row['tokens'] ?? 0),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchActorDistribution(string $since): array
    {
        return $this->fetchDistribution('actor_type', 'actor_type', $since, 4);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRangeStats(string $since): array
    {
        $sql = "SELECT
                    COUNT(*) AS total_calls,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_calls,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) AS success_calls,
                    AVG(latency_ms) AS avg_latency_ms,
                    AVG(total_tokens) AS avg_tokens_per_call,
                    SUM(COALESCE(total_tokens, 0)) AS total_tokens
                FROM llm_logs
                WHERE created_at >= :since";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':since', $since);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $p95Latency = $this->computeLatencyPercentile($since, 0.95, 2000);

        return [
            'total_calls' => (int) ($row['total_calls'] ?? 0),
            'failed_calls' => (int) ($row['failed_calls'] ?? 0),
            'success_calls' => (int) ($row['success_calls'] ?? 0),
            'avg_latency_ms' => $row['avg_latency_ms'] !== null ? (float) $row['avg_latency_ms'] : null,
            'p95_latency_ms' => $p95Latency,
            'avg_tokens_per_call' => $row['avg_tokens_per_call'] !== null ? (float) $row['avg_tokens_per_call'] : null,
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
        ];
    }

    private function computeLatencyPercentile(string $since, float $percentile, int $limit): ?float
    {
        $stmt = $this->db->prepare("SELECT latency_ms FROM llm_logs WHERE created_at >= :since AND latency_ms IS NOT NULL ORDER BY latency_ms ASC LIMIT :limit");
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return null;
        }

        $values = array_map(static fn ($row) => (float) $row['latency_ms'], $rows);
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return null;
        }
        $index = (int) floor(($count - 1) * $percentile);
        return isset($values[$index]) ? (float) $values[$index] : null;
    }

    /**
     * @param array<int, array<string, mixed>> $trends
     * @param array<string, mixed> $distributions
     * @param array<string, mixed> $rangeStats
     * @return array<string, mixed>
     */
    private function buildInsights(array $trends, array $distributions, array $rangeStats): array
    {
        [$recentCalls, $prevCalls, $callsDelta, $callsDeltaRate] = $this->computeDelta($trends, 7, 'calls');
        [$recentTokens, $prevTokens, $tokensDelta, $tokensDeltaRate] = $this->computeDelta($trends, 7, 'tokens');

        $totalCalls = (int) ($rangeStats['total_calls'] ?? 0);
        $failedCalls = (int) ($rangeStats['failed_calls'] ?? 0);
        $successRate = $totalCalls > 0 ? ($totalCalls - $failedCalls) / $totalCalls : null;

        $topModel = $distributions['models'][0]['model'] ?? null;
        $topSource = $distributions['sources'][0]['source'] ?? null;

        $actorTotals = array_reduce($distributions['actors'] ?? [], fn ($carry, $item) => $carry + (int) ($item['calls'] ?? 0), 0);
        $adminCalls = 0;
        $userCalls = 0;
        foreach ($distributions['actors'] ?? [] as $item) {
            if (($item['actor_type'] ?? null) === 'admin') {
                $adminCalls += (int) ($item['calls'] ?? 0);
            } elseif (($item['actor_type'] ?? null) === 'user') {
                $userCalls += (int) ($item['calls'] ?? 0);
            }
        }
        $adminShare = $actorTotals > 0 ? $adminCalls / $actorTotals : null;
        $userShare = $actorTotals > 0 ? $userCalls / $actorTotals : null;

        return [
            'success_rate' => $successRate,
            'avg_latency_ms' => $rangeStats['avg_latency_ms'] ?? null,
            'p95_latency_ms' => $rangeStats['p95_latency_ms'] ?? null,
            'avg_tokens_per_call' => $rangeStats['avg_tokens_per_call'] ?? null,
            'total_calls' => $totalCalls,
            'total_tokens' => $rangeStats['total_tokens'] ?? 0,
            'calls_last_7d' => $recentCalls,
            'calls_prev_7d' => $prevCalls,
            'calls_delta' => $callsDelta,
            'calls_delta_rate' => $callsDeltaRate,
            'tokens_last_7d' => $recentTokens,
            'tokens_prev_7d' => $prevTokens,
            'tokens_delta' => $tokensDelta,
            'tokens_delta_rate' => $tokensDeltaRate,
            'top_model' => $topModel,
            'top_source' => $topSource,
            'admin_share' => $adminShare,
            'user_share' => $userShare,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $trends
     * @return array{int,int,int,?float}
     */
    private function computeDelta(array $trends, int $window, string $key): array
    {
        $recentSlice = array_slice($trends, -$window);
        $prevSlice = array_slice($trends, -$window * 2, $window);
        $recent = array_sum(array_map(static fn ($item) => (int) ($item[$key] ?? 0), $recentSlice));
        $previous = array_sum(array_map(static fn ($item) => (int) ($item[$key] ?? 0), $prevSlice));
        $delta = $recent - $previous;
        $deltaRate = $previous > 0 ? $delta / $previous : null;
        return [$recent, $previous, $delta, $deltaRate];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentConversations(int $limit): array
    {
        $sql = "SELECT
                    l.id,
                    l.request_id,
                    l.actor_type,
                    l.actor_id,
                    l.source,
                    l.model,
                    l.status,
                    l.response_id,
                    l.total_tokens,
                    l.latency_ms,
                    l.prompt,
                    l.response_raw,
                    l.context_json,
                    l.created_at,
                    u.username AS actor_name,
                    u.email AS actor_email,
                    (SELECT COUNT(*) FROM system_logs s WHERE s.request_id = l.request_id) AS system_count,
                    (SELECT COUNT(*) FROM audit_logs a WHERE a.request_id = l.request_id) AS audit_count,
                    (SELECT COUNT(*) FROM error_logs e WHERE e.request_id = l.request_id) AS error_count,
                    (SELECT path FROM system_logs s WHERE s.request_id = l.request_id ORDER BY id DESC LIMIT 1) AS system_path,
                    (SELECT status_code FROM system_logs s WHERE s.request_id = l.request_id ORDER BY id DESC LIMIT 1) AS system_status_code
                FROM llm_logs l
                LEFT JOIN users u ON u.id = l.actor_id
                ORDER BY l.id DESC
                LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int) $row['id'],
                'created_at' => $row['created_at'] ?? null,
                'actor_type' => $row['actor_type'],
                'actor_id' => $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
                'actor_name' => $row['actor_name'] ?? null,
                'actor_email' => $row['actor_email'] ?? null,
                'source' => $row['source'] ?? null,
                'model' => $row['model'] ?? null,
                'status' => $row['status'] ?? null,
                'request_id' => $row['request_id'] ?? null,
                'response_id' => $row['response_id'] ?? null,
                'total_tokens' => $row['total_tokens'] !== null ? (int) $row['total_tokens'] : null,
                'latency_ms' => $row['latency_ms'] !== null ? (float) $row['latency_ms'] : null,
                'prompt_preview' => $this->buildPreview($row['prompt'] ?? null, 200),
                'response_preview' => $this->buildPreview($row['response_raw'] ?? null, 240),
                'context' => $this->decodeJson($row['context_json'] ?? null),
                'system_path' => $row['system_path'] ?? null,
                'system_status_code' => $row['system_status_code'] !== null ? (int) $row['system_status_code'] : null,
                'related' => [
                    'system' => (int) ($row['system_count'] ?? 0),
                    'audit' => (int) ($row['audit_count'] ?? 0),
                    'error' => (int) ($row['error_count'] ?? 0),
                ],
            ];
        }

        return $result;
    }

    private function buildPreview($value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            $value = $this->encodeJson($value);
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return mb_substr($value, 0, $maxLength, 'UTF-8') . '...';
        }
        return $value;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    private function decodeJson($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function decodeMaybeJson($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
