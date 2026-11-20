<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Support\ClientIpResolver;
use CarbonTrack\Support\LogDiagnosticRequest;
use CarbonTrack\Support\Uuid;
use Illuminate\Database\Connection;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DiagnosticAccess
{
    private const SESSIONS = 'log_diagnostic_sessions';
    private const CREDENTIALS = 'log_diagnostic_credentials';
    private const LEASES = 'log_diagnostic_query_leases';
    private const PAGE_LIMIT = 30;

    public function __construct(
        private readonly Connection $db,
        private readonly DiagnosticConfig $config,
        private readonly AuditLogService $audit,
        private readonly ErrorLogService $errors
    ) {
    }

    public function adminId(Request $request): int
    {
        $this->config->ensureEnabled();
        $id = (int) $request->getAttribute('user_id');
        if ($id <= 0 || !$this->activeAdmin($id)) {
            throw new DiagnosticProblem(403, 'admin_required', 'An active administrator account is required.');
        }
        return $id;
    }

    public function activeAdmin(int $id): bool
    {
        return $this->db->table('users')->useWritePdo()
            ->where('id', $id)->where('is_admin', 1)->where('status', 'active')->whereNull('deleted_at')->exists();
    }

    public static function types(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw DiagnosticProblem::invalid('Choose one or more log types.');
        }
        foreach ($value as $type) {
            if (!is_string($type) || !in_array($type, DiagnosticConfig::TYPES, true)) {
                throw DiagnosticProblem::invalid('Unknown log type.');
            }
        }
        return array_values(array_unique($value));
    }

    public function create(Request $request, array $input): array
    {
        $adminId = $this->adminId($request);
        if (array_diff(array_keys($input), ['name', 'from', 'to', 'types', 'expires_in_minutes', 'issue_rest_token']) !== []) {
            throw DiagnosticProblem::invalid('The request contains unrecognized fields.');
        }
        $now = time();
        $from = array_key_exists('from', $input) ? DiagnosticConfig::timestamp($input['from'], 'from') : $now - 7200;
        $to = array_key_exists('to', $input) ? DiagnosticConfig::timestamp($input['to'], 'to') : $now;
        if ($from >= $to || $to > $now) {
            throw DiagnosticProblem::invalid('Choose an increasing log window that ends by the current time.');
        }
        $types = self::types($input['types'] ?? ['system', 'error', 'audit']);
        $minutes = $input['expires_in_minutes'] ?? min(30, $this->config->maxSessionMinutes);
        if (!is_int($minutes) || $minutes < 1 || $minutes > $this->config->maxSessionMinutes) {
            throw DiagnosticProblem::invalid('The requested authorization duration is outside the allowed range.');
        }
        $rawName = $input['name'] ?? '';
        if (!is_string($rawName) || !mb_check_encoding($rawName, 'UTF-8') || mb_strlen($rawName) > 160
            || preg_match('/[\x00-\x1f\x7f]/', $rawName)) {
            throw DiagnosticProblem::invalid('The authorization name is invalid or longer than 160 characters.');
        }
        $name = trim($rawName) ?: 'Log diagnostics ' . DiagnosticConfig::iso($now);
        $issueRest = $input['issue_rest_token'] ?? false;
        if (!is_bool($issueRest)) {
            throw DiagnosticProblem::invalid('issue_rest_token must be a boolean.');
        }

        return $this->db->transaction(function () use ($request, $adminId, $now, $from, $to, $types, $minutes, $name, $issueRest): array {
            $sessionId = (int) $this->db->table(self::SESSIONS)->insertGetId([
                'created_by' => $adminId,
                'name' => $name,
                'log_types' => implode(',', $types),
                'log_from' => $from,
                'log_to' => $to,
                'created_at' => $now,
                'expires_at' => $now + $minutes * 60,
            ]);
            $session = $this->session($sessionId);
            $this->record($request, 'created', $sessionId, ['types' => $types, 'from' => $from, 'to' => $to], $adminId);
            $result = ['session' => $this->formatSession($session, true)];
            if ($issueRest) {
                $result['issued'] = $this->issueToken($request, $session, 'rest', $adminId);
            }
            return $result;
        });
    }

    public function list(Request $request, ?int $before, bool $eligibleOnly): array
    {
        $adminId = $this->adminId($request);
        $query = $this->db->table(self::SESSIONS . ' as sessions')->useWritePdo()
            ->leftJoin('users as creator', 'creator.id', '=', 'sessions.created_by')
            ->select('sessions.*', 'creator.is_admin as creator_admin', 'creator.status as creator_status', 'creator.deleted_at as creator_deleted');
        if ($eligibleOnly) {
            $query->where('sessions.created_by', $adminId)->whereNull('sessions.revoked_at')->where('sessions.expires_at', '>', time());
        }
        if ($before !== null) {
            $query->where('sessions.id', '<', $before);
        }
        $rows = $query->orderByDesc('sessions.id')->limit(self::PAGE_LIMIT + 1)->get()->all();
        $result = $this->paginate($rows, fn (object $row): array => $this->formatSession(
            $row,
            (int) $row->creator_admin === 1 && $row->creator_status === 'active' && $row->creator_deleted === null
        ));
        $this->record($request, 'listed', null, ['eligible_only' => $eligibleOnly], $adminId);
        return $result;
    }

    public function detail(Request $request, int $id): array
    {
        $adminId = $this->adminId($request);
        $session = $this->session($id);
        $active = $this->activeAdmin((int) $session->created_by);
        $data = $this->formatSession($session, $active);
        $data['can_issue_rest'] = $data['status'] === 'active' && (int) $session->created_by === $adminId;
        $data['credentials'] = $this->db->table(self::CREDENTIALS)->useWritePdo()->where('session_id', $id)
            ->orderByDesc('id')->get(['id', 'channel', 'client_id', 'created_at', 'expires_at', 'revoked_at', 'revoked_by'])
            ->map(fn (object $row): array => $this->formatCredential($row, $data['status']))->all();
        $this->record($request, 'viewed', $id, [], $adminId);
        return $data;
    }

    public function history(Request $request, int $id, ?int $before): array
    {
        $adminId = $this->adminId($request);
        $this->session($id);
        $query = $this->db->table('audit_logs')->useWritePdo()
            ->where('operation_category', DiagnosticConfig::AUDIT_CATEGORY)
            ->where('affected_id', $id)->whereNotIn('action', ['log_diagnostic_viewed', 'log_diagnostic_history_viewed']);
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $rows = $query->orderByDesc('id')->limit(self::PAGE_LIMIT + 1)
            ->get(['id', 'action', 'user_id', 'status', 'response_code', 'request_id', 'created_at', 'data'])->all();
        $result = $this->paginate($rows, static fn (object $row): array => [
            'id' => (int) $row->id,
            'action' => $row->action,
            'admin_id' => $row->user_id === null ? null : (int) $row->user_id,
            'status' => $row->status,
            'response_code' => (int) $row->response_code,
            'request_id' => $row->request_id,
            'created_at' => $row->created_at,
            'details' => json_decode((string) $row->data, true) ?? [],
        ]);
        $this->record($request, 'history_viewed', $id, [], $adminId);
        return $result;
    }

    public function issueRest(Request $request, int $id): array
    {
        $adminId = $this->adminId($request);
        return $this->db->transaction(function () use ($request, $id, $adminId): array {
            $session = $this->ownedActiveSession($id, $adminId, true);
            return $this->issueToken($request, $session, 'rest', $adminId);
        });
    }

    public function ownedActiveSession(int $id, int $adminId, bool $lock = false): object
    {
        $session = $this->session($id, $lock);
        if ((int) $session->created_by !== $adminId || !$this->sessionActive($session)) {
            throw DiagnosticProblem::scope();
        }
        return $session;
    }

    public function issueToken(Request $request, object $session, string $channel, int $adminId, ?string $clientId = null): array
    {
        $secret = 'ctdiag_' . $channel . '_' . DiagnosticConfig::secret();
        $audience = $channel === 'mcp' ? $this->config->resource() : $this->config->apiOrigin . DiagnosticConfig::API;
        $credentialId = (int) $this->db->table(self::CREDENTIALS)->insertGetId([
            'session_id' => $session->id,
            'token_hash' => hash('sha256', $secret),
            'channel' => $channel,
            'audience' => $audience,
            'client_id' => $clientId,
            'created_at' => time(),
            'expires_at' => $session->expires_at,
        ]);
        $this->record($request, 'credential_issued', (int) $session->id, [
            'credential_id' => $credentialId, 'channel' => $channel, 'client_id' => $clientId,
        ], $adminId);
        return [
            'credential_id' => $credentialId,
            'access_token' => $secret,
            'expires_at' => DiagnosticConfig::iso((int) $session->expires_at),
            'expires_in' => max(0, (int) $session->expires_at - time()),
        ];
    }

    public function revoke(Request $request, int $id, ?int $credentialId = null): array
    {
        $adminId = $this->adminId($request);
        return $this->db->transaction(function () use ($request, $id, $credentialId, $adminId): array {
            $session = $this->session($id, true);
            $now = time();
            if ($credentialId === null) {
                $changed = $this->db->table(self::SESSIONS)->where('id', $id)->whereNull('revoked_at')
                    ->update(['revoked_at' => $now, 'revoked_by' => $adminId]);
            } else {
                $credential = $this->db->table(self::CREDENTIALS)->where('session_id', $id)
                    ->where('id', $credentialId)->lockForUpdate()->first();
                if ($credential === null) {
                    throw new DiagnosticProblem(404, 'credential_not_found', 'The credential was not found.');
                }
                $changed = $this->db->table(self::CREDENTIALS)->where('id', $credentialId)->whereNull('revoked_at')
                    ->update(['revoked_at' => $now, 'revoked_by' => $adminId]);
            }
            $this->record($request, 'revoked', (int) $session->id, [
                'credential_id' => $credentialId, 'changed' => $changed > 0,
            ], $adminId);
            return ['status' => 'revoked'];
        });
    }

    public function search(Request $request, string $channel, string $tool, array $input, callable $query): array
    {
        $this->config->ensureEnabled();
        $started = hrtime(true);
        $deadline = $started + $this->config->timeoutMs * 1000000;
        $requestId = LogDiagnosticRequest::requestId($request) ?? Uuid::generateV4();
        $request = $request->withAttribute('request_id', $requestId);
        $identity = [
            'channel' => $channel,
            'tool' => $tool,
            'filter_keys' => self::safeFilterNames($input),
            'timeout_ms' => $this->config->timeoutMs,
        ];
        $sessionId = null;
        $adminId = null;
        $stage = 'credential_lookup';
        $reserved = false;
        try {
            $credential = $this->findCredential($request, $channel);
            $sessionId = (int) $credential->session_id;
            $credentialId = (int) $credential->id;
            $adminId = isset($credential->owner_id) ? (int) $credential->owner_id : null;
            $identity['credential_id'] = $credentialId;
            if ($credential->client_id !== null) {
                $identity['client_id'] = $credential->client_id;
            }
            $stage = 'reserve';
            $session = $this->reserve($sessionId, $credentialId, $requestId);
            $reserved = true;
            $adminId = (int) $session->created_by;
            $identity += [
                'authorized_log_types' => explode(',', $session->log_types),
                'authorized_log_from' => DiagnosticConfig::iso((int) $session->log_from),
                'authorized_log_to' => DiagnosticConfig::iso((int) $session->log_to),
            ];
            $stage = 'read';
            $result = $query($session, $deadline);
            $stage = 'encode';
            $size = strlen(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $stage = 'delivery';
            $this->db->transaction(function () use ($sessionId, $credentialId, $requestId, $identity, $request, $result, $size, $started, $deadline, $adminId): void {
                $this->lockIdentity($sessionId, $credentialId);
                if (hrtime(true) >= $deadline) {
                    throw DiagnosticProblem::queryTimeout();
                }
                $this->record($request, 'query', $sessionId, $identity + [
                    'log_count' => $result['log_count'],
                    'response_bytes' => $size,
                    'duration_ms' => self::elapsedMs($started),
                ], $adminId);
                $this->db->table(self::SESSIONS)->where('id', $sessionId)->incrementEach([
                    'query_count' => 1, 'result_bytes' => $size,
                ]);
                $this->db->table(self::LEASES)->where('request_id', $requestId)->delete();
            });
            $reserved = false;
            return $result;
        } catch (\Throwable $e) {
            $problem = $e instanceof DiagnosticProblem ? $e : DiagnosticProblem::unavailable(previous: $e);
            $failure = $problem->logDetails() + [
                'failure_stage' => $stage,
                'duration_ms' => self::elapsedMs($started),
            ];
            if ($reserved) {
                try {
                    $this->db->table(self::LEASES)->where('request_id', $requestId)->delete();
                } catch (\Throwable) {
                    // Expired leases are reclaimed by the next request or scheduled cleanup.
                }
            }
            try {
                $this->record($request, 'query', $sessionId, $identity + $failure, $adminId, $problem->status);
            } catch (\Throwable) {
                // The diagnostic result remains blocked when the audit store is unavailable.
            }
            $this->errors->logError('LogDiagnosticFailure', $problem->reason, $request, [
                'diagnostic' => $failure + [
                    'channel' => $channel,
                    'tool' => $tool,
                    'authorization_id' => $sessionId,
                    'credential_id' => $identity['credential_id'] ?? null,
                ],
            ]);
            throw $problem;
        }
    }

    public function record(
        Request $request,
        string $action,
        ?int $sessionId,
        array $details,
        ?int $adminId = null,
        int $status = 200
    ): void {
        $context = LogDiagnosticRequest::context($request);
        if ($context !== null) {
            $context->adminId = $adminId ?? $context->adminId;
            if ($action === 'query') {
                $context->query = [
                    'authorization_id' => $sessionId,
                    'response_code' => $status,
                ] + $details;
            }
        }
        $saved = $this->audit->logAudit([
            'action' => 'log_diagnostic_' . $action,
            'operation_category' => DiagnosticConfig::AUDIT_CATEGORY,
            'actor_type' => $adminId !== null ? 'admin' : 'system',
            'user_id' => $adminId,
            'affected_table' => self::SESSIONS,
            'affected_id' => $sessionId,
            'status' => $status < 400 ? 'success' : 'failed',
            'response_code' => $status,
            'request_id' => LogDiagnosticRequest::requestId($request) ?? Uuid::generateV4(),
            'request_method' => $request->getMethod(),
            'endpoint' => LogDiagnosticRequest::path($request->getUri()->getPath()) ?? DiagnosticConfig::API,
            'referrer' => '',
            'session_id' => '',
            'user_agent' => '',
            'ip_address' => ClientIpResolver::fromRequest($request, null) ?? '',
            'data' => $details,
        ]);
        if (!$saved || $this->audit->getLastInsertId() === null) {
            throw DiagnosticProblem::unavailable();
        }
    }

    private function findCredential(Request $request, string $channel): object
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer +(ctdiag_(?:rest|mcp)_[A-Za-z0-9_-]{43})$/iD', $header, $matches) !== 1) {
            throw DiagnosticProblem::unauthorized();
        }
        $expected = $channel === 'mcp' ? $this->config->resource() : $this->config->apiOrigin . DiagnosticConfig::API;
        $credential = $this->db->table(self::CREDENTIALS . ' as credentials')->useWritePdo()
            ->leftJoin(self::SESSIONS . ' as grants', 'grants.id', '=', 'credentials.session_id')
            ->where('credentials.token_hash', hash('sha256', $matches[1]))
            ->first(['credentials.*', 'grants.created_by as owner_id']);
        if ($credential === null || $credential->channel !== $channel || $credential->audience !== $expected) {
            throw DiagnosticProblem::unauthorized();
        }
        return $credential;
    }

    private function reserve(int $sessionId, int $credentialId, string $requestId): object
    {
        return $this->db->transaction(function () use ($sessionId, $credentialId, $requestId): object {
            $session = $this->lockIdentity($sessionId, $credentialId);
            $now = time();
            $minute = intdiv($now, 60);
            $used = (int) $session->rate_minute === $minute ? (int) $session->rate_count : 0;
            if ($used >= $this->config->requestsPerMinute) {
                throw new DiagnosticProblem(429, 'rate_limited', 'Diagnostic request rate reached.', 60 - $now % 60);
            }
            $leases = $this->db->table(self::LEASES)->where('session_id', $sessionId)->where('expires_at', '>', $now);
            if ($leases->count() >= $this->config->maxConcurrent) {
                throw new DiagnosticProblem(429, 'concurrency_limited', 'Diagnostic query concurrency reached.', 1);
            }
            $this->db->table(self::LEASES)->insert([
                'request_id' => $requestId,
                'session_id' => $sessionId,
                'expires_at' => $now + (int) ceil($this->config->timeoutMs / 1000) + 5,
            ]);
            $this->db->table(self::SESSIONS)->where('id', $sessionId)->update([
                'rate_minute' => $minute, 'rate_count' => $used + 1,
            ]);
            return $session;
        });
    }

    private function lockIdentity(int $sessionId, int $credentialId): object
    {
        $session = $this->session($sessionId, true);
        $credential = $this->db->table(self::CREDENTIALS)->where('id', $credentialId)->where('session_id', $sessionId)
            ->lockForUpdate()->first();
        if ($credential === null || $credential->revoked_at !== null || (int) $credential->expires_at <= time()
            || !$this->sessionActive($session)) {
            throw DiagnosticProblem::unauthorized();
        }
        return $session;
    }

    private function session(int $id, bool $lock = false): object
    {
        $query = $this->db->table(self::SESSIONS)->useWritePdo()->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($row === null) {
            throw new DiagnosticProblem(404, 'session_not_found', 'The diagnostic authorization was not found.');
        }
        return $row;
    }

    private function sessionActive(object $session): bool
    {
        return $session->revoked_at === null && (int) $session->expires_at > time()
            && $this->activeAdmin((int) $session->created_by);
    }

    private function formatSession(object $row, bool $ownerActive): array
    {
        return [
            'id' => (int) $row->id,
            'created_by' => (int) $row->created_by,
            'name' => $row->name,
            'types' => explode(',', $row->log_types),
            'from' => DiagnosticConfig::iso((int) $row->log_from),
            'to' => DiagnosticConfig::iso((int) $row->log_to),
            'created_at' => DiagnosticConfig::iso((int) $row->created_at),
            'expires_at' => DiagnosticConfig::iso((int) $row->expires_at),
            'revoked_at' => $row->revoked_at === null ? null : DiagnosticConfig::iso((int) $row->revoked_at),
            'status' => $this->status($row, $ownerActive ? 'active' : 'inactive'),
            'query_count' => (int) $row->query_count,
            'result_bytes' => (int) $row->result_bytes,
        ];
    }

    private function formatCredential(object $row, string $sessionStatus): array
    {
        return [
            'id' => (int) $row->id,
            'channel' => $row->channel,
            'client_id' => $row->client_id,
            'created_at' => DiagnosticConfig::iso((int) $row->created_at),
            'expires_at' => DiagnosticConfig::iso((int) $row->expires_at),
            'revoked_at' => $row->revoked_at === null ? null : DiagnosticConfig::iso((int) $row->revoked_at),
            'status' => $this->status($row, $sessionStatus),
        ];
    }

    private function status(object $row, string $fallback): string
    {
        if ($row->revoked_at !== null) {
            return 'revoked';
        }
        return (int) $row->expires_at <= time() ? 'expired' : $fallback;
    }

    private function paginate(array $rows, callable $format): array
    {
        $page = array_slice($rows, 0, self::PAGE_LIMIT);
        return [
            'items' => array_map($format, $page),
            'next_before_id' => count($rows) > self::PAGE_LIMIT ? (int) end($page)->id : null,
        ];
    }

    private static function elapsedMs(int $started): int
    {
        return (int) floor((hrtime(true) - $started) / 1000000);
    }

    private static function safeFilterNames(array $input): array
    {
        return array_values(array_intersect([
            'types', 'type', 'from', 'to', 'request_id', 'status', 'status_code',
            'method', 'action', 'error_type', 'source', 'model', 'q', 'cursor', 'limit', 'id',
        ], array_keys($input)));
    }
}
