<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DiagnosticOAuth
{
    private const REQUESTS = 'log_diagnostic_oauth_requests';
    public const BROWSER_COOKIE = '__Host-ctdiag-oauth-browser';
    public const REQUEST_SECONDS = 600;
    private const CODE_SECONDS = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly DiagnosticConfig $config,
        private readonly DiagnosticAccess $access,
        private readonly DiagnosticOAuthClients $clients
    ) {
    }

    public function resourceMetadata(): array
    {
        $this->config->ensureEnabled();
        return [
            'resource' => $this->config->resource(),
            'authorization_servers' => [$this->config->issuer()],
            'scopes_supported' => [DiagnosticConfig::SCOPE],
            'bearer_methods_supported' => ['header'],
        ];
    }

    public function issuerMetadata(): array
    {
        $this->config->ensureEnabled();
        $issuer = $this->config->issuer();
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
            'scopes_supported' => [DiagnosticConfig::SCOPE],
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'],
            'token_endpoint_auth_methods_supported' => $this->clients->methods(),
            'token_endpoint_auth_signing_alg_values_supported' => $this->clients->signingAlgorithms(),
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => $this->clients->supportsCimd(),
        ];
    }

    public function begin(Request $request): array
    {
        $this->config->ensureEnabled();
        $query = $request->getQueryParams();
        $client = $this->clients->registered($query['client_id'] ?? null);
        $redirect = $this->clients->validateRedirect($client, $query['redirect_uri'] ?? null, true);
        $state = $query['state'] ?? null;
        if ($state !== null && (!is_string($state) || strlen($state) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $state) || !mb_check_encoding($state, 'UTF-8'))) {
            throw DiagnosticProblem::invalid('Invalid OAuth state.');
        }
        $error = match (true) {
            ($query['response_type'] ?? null) !== 'code' => 'unsupported_response_type',
            ($query['resource'] ?? null) !== $this->config->resource() => 'invalid_target',
            ($query['scope'] ?? DiagnosticConfig::SCOPE) !== DiagnosticConfig::SCOPE => 'invalid_scope',
            ($query['response_mode'] ?? 'query') !== 'query' => 'invalid_request',
            ($query['code_challenge_method'] ?? null) !== 'S256'
                || !self::matchesSecret($query['code_challenge'] ?? null) => 'invalid_request',
            default => null,
        };
        if ($error !== null) {
            $this->access->record($request, 'oauth_rejected', null, ['reason' => $error], status: 400);
            return ['redirect' => $this->callback($redirect, $state, ['error' => $error])];
        }

        $browser = $request->getCookieParams()[self::BROWSER_COOKIE] ?? null;
        if (!self::matchesSecret($browser)) {
            $browser = DiagnosticConfig::secret();
        }
        $id = DiagnosticConfig::secret();
        $this->db->transaction(function () use ($request, $id, $client, $redirect, $state, $query, $browser): void {
            $this->db->table(self::REQUESTS)->insert([
                'id' => $id,
                'client_id' => $client['id'],
                'redirect_uri' => $redirect,
                'resource' => $this->config->resource(),
                'state' => $state,
                'code_challenge' => $query['code_challenge'],
                'browser_hash' => hash('sha256', $browser),
                'expires_at' => time() + self::REQUEST_SECONDS,
            ]);
            $this->access->record($request, 'oauth_started', null, ['client_id' => $client['id']]);
        });

        return [
            'redirect' => $this->config->frontendOrigin . '/admin/system-logs/diagnostic-consent/' . $id,
            'browser' => $browser,
        ];
    }

    public function describe(Request $request, string $id): array
    {
        $adminId = $this->access->adminId($request);
        $pending = $this->pending($id);
        $client = $this->clients->registered($pending->client_id);
        $this->access->record($request, 'oauth_viewed', null, ['client_id' => $client['id']], $adminId);
        return [
            'client_name' => $client['name'] ?? $client['id'],
            'client_id' => $client['id'],
            'resource' => $pending->resource,
            'expires_at' => DiagnosticConfig::iso((int) $pending->expires_at),
        ];
    }

    public function decide(Request $request, string $id, array $input): array
    {
        $adminId = $this->access->adminId($request);
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== $this->config->frontendOrigin && $origin !== $this->config->apiOrigin) {
            throw DiagnosticProblem::scope();
        }
        $approved = $input['approve'] ?? null;
        $sessionId = $input['session_id'] ?? null;
        if (!is_bool($approved) || ($approved && (!is_int($sessionId) || $sessionId < 1))) {
            throw DiagnosticProblem::invalid('Choose a diagnostic authorization to approve access.');
        }

        $ticket = DiagnosticConfig::secret();
        $sessionId = $approved ? $sessionId : null;
        $this->db->transaction(function () use ($request, $id, $adminId, $approved, $sessionId, $ticket): void {
            $pending = $this->pending($id, true);
            $this->clients->registered($pending->client_id);
            if ($sessionId !== null) {
                $this->access->ownedActiveSession($sessionId, $adminId, true);
            }
            if ($pending->decided_by !== null && (int) $pending->decided_by !== $adminId) {
                throw DiagnosticProblem::scope();
            }
            $this->db->table(self::REQUESTS)->where('id', $id)->update([
                'session_id' => $sessionId,
                'decided_by' => $adminId,
                'approved' => $approved ? 1 : 0,
                'ticket_hash' => hash('sha256', $ticket),
            ]);
            $this->access->record($request, 'oauth_decided', $sessionId, [
                'approved' => $approved, 'client_id' => $pending->client_id,
            ], $adminId);
        });
        return ['continue_url' => $this->config->issuer() . '/consent#ticket=' . $ticket];
    }

    public function confirm(Request $request, mixed $ticket): string
    {
        $this->config->ensureEnabled();
        $browser = $request->getCookieParams()[self::BROWSER_COOKIE] ?? null;
        if (!self::matchesSecret($ticket) || !self::matchesSecret($browser)
            || $request->getHeaderLine('Origin') !== $this->config->apiOrigin) {
            throw DiagnosticProblem::invalid('The OAuth browser confirmation is invalid.');
        }
        return $this->db->transaction(function () use ($request, $ticket, $browser): string {
            $pending = $this->db->table(self::REQUESTS)->where('ticket_hash', hash('sha256', $ticket))->lockForUpdate()->first();
            if (!$this->isPending($pending) || !hash_equals($pending->browser_hash, hash('sha256', $browser))) {
                throw DiagnosticProblem::invalid('The OAuth browser confirmation has expired or is invalid.');
            }
            $client = $this->clients->registered($pending->client_id);
            $redirect = $this->clients->validateRedirect($client, $pending->redirect_uri, false);
            $grant = null;
            if ((int) $pending->approved === 1) {
                try {
                    $grant = $this->access->ownedActiveSession((int) $pending->session_id, (int) $pending->decided_by, true);
                } catch (DiagnosticProblem) {
                    // A grant can be revoked between the administrator's consent and the browser handoff.
                }
            }
            $code = $grant !== null ? DiagnosticConfig::secret() : null;
            $codeExpires = $code === null ? null : min((int) $grant->expires_at, time() + self::CODE_SECONDS);
            $this->db->table(self::REQUESTS)->where('id', $pending->id)->update([
                'ticket_hash' => null,
                'completed_at' => time(),
                'code_hash' => $code === null ? null : hash('sha256', $code),
                'code_expires_at' => $codeExpires,
                'expires_at' => max((int) $pending->expires_at, $codeExpires ?? 0),
            ]);
            $this->access->record($request, 'oauth_confirmed', $pending->session_id === null ? null : (int) $pending->session_id, [
                'approved' => $code !== null, 'client_id' => $client['id'],
            ], (int) $pending->decided_by);

            return $this->callback($redirect, $pending->state, $code === null ? ['error' => 'access_denied'] : ['code' => $code]);
        });
    }

    public function exchange(Request $request, array $input): array
    {
        $this->config->ensureEnabled();
        $code = $input['code'] ?? null;
        $verifier = $input['code_verifier'] ?? null;
        if (($input['grant_type'] ?? null) !== 'authorization_code') {
            throw new DiagnosticProblem(400, 'unsupported_grant_type', 'Only the authorization code grant is supported.');
        }
        if (($input['resource'] ?? null) !== $this->config->resource()) {
            throw new DiagnosticProblem(400, 'invalid_target', 'The requested OAuth resource is invalid.');
        }
        if (!self::matchesSecret($code) || !is_string($verifier)
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            throw self::invalidGrant();
        }
        $codeHash = hash('sha256', $code);
        $candidate = $this->db->table(self::REQUESTS)->useWritePdo()->where('code_hash', $codeHash)->first();
        $this->validateCode($candidate, $input['redirect_uri'] ?? null, $verifier);
        $client = $this->clients->registered($candidate->client_id);
        $this->clients->validateRedirect($client, $candidate->redirect_uri, false);
        $assertion = $this->clients->authenticate($client, $request, $input);

        return $this->db->transaction(function () use ($request, $input, $verifier, $codeHash, $client, $assertion): array {
            $pending = $this->db->table(self::REQUESTS)->where('code_hash', $codeHash)->lockForUpdate()->first();
            $this->validateCode($pending, $input['redirect_uri'] ?? null, $verifier);
            if ($assertion !== null && $assertion['expires_at'] <= time()) {
                throw new DiagnosticProblem(401, 'invalid_client', 'OAuth client validation failed.');
            }
            try {
                $session = $this->access->ownedActiveSession((int) $pending->session_id, (int) $pending->decided_by, true);
            } catch (DiagnosticProblem) {
                throw self::invalidGrant();
            }
            try {
                $this->db->table(self::REQUESTS)->where('id', $pending->id)->update([
                    'consumed_at' => time(),
                    'code_hash' => null,
                    'assertion_hash' => $assertion['hash'] ?? null,
                    'expires_at' => max((int) $pending->expires_at, $assertion['expires_at'] ?? 0),
                ]);
            } catch (QueryException $e) {
                if ($assertion !== null && in_array((int) ($e->errorInfo[1] ?? 0), [19, 1062], true)) {
                    throw new DiagnosticProblem(401, 'invalid_client', 'OAuth client validation failed.');
                }
                throw $e;
            }
            $token = $this->access->issueToken($request, $session, 'mcp', (int) $pending->decided_by, $client['id']);
            return [
                'access_token' => $token['access_token'],
                'token_type' => 'Bearer',
                'expires_in' => $token['expires_in'],
                'scope' => DiagnosticConfig::SCOPE,
            ];
        });
    }

    public function cleanup(): array
    {
        return $this->db->transaction(function (): array {
            $now = time();
            $oauth = $this->deleteExpired(self::REQUESTS, 'id', $now);
            $leases = $this->deleteExpired('log_diagnostic_query_leases', 'request_id', $now);
            return ['deleted_oauth_requests' => $oauth, 'deleted_query_leases' => $leases];
        });
    }

    private function deleteExpired(string $table, string $key, int $now): int
    {
        $ids = $this->db->table($table)->where('expires_at', '<=', $now)
            ->orderBy('expires_at')->limit(250)->pluck($key)->all();
        return $ids === [] ? 0 : $this->db->table($table)->whereIn($key, $ids)->where('expires_at', '<=', $now)->delete();
    }

    private function validateCode(?object $pending, mixed $redirect, string $verifier): void
    {
        if ($pending === null || $pending->consumed_at !== null || (int) $pending->code_expires_at <= time()
            || (int) $pending->approved !== 1 || $pending->resource !== $this->config->resource()
            || $pending->redirect_uri !== $redirect
            || !hash_equals($pending->code_challenge, DiagnosticConfig::base64Url(hash('sha256', $verifier, true)))) {
            throw self::invalidGrant();
        }
    }

    private function pending(string $id, bool $lock = false): object
    {
        if (!self::matchesSecret($id)) {
            throw DiagnosticProblem::invalid('The OAuth request is invalid.');
        }
        $query = $this->db->table(self::REQUESTS)->useWritePdo()->where('id', $id);
        $pending = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$this->isPending($pending)) {
            throw new DiagnosticProblem(404, 'invalid_request', 'The OAuth request is unavailable or expired.');
        }
        return $pending;
    }

    private function isPending(?object $pending): bool
    {
        return $pending !== null && $pending->completed_at === null && (int) $pending->expires_at > time();
    }

    private function callback(string $uri, ?string $state, array $params): string
    {
        if ($state !== null) {
            $params['state'] = $state;
        }
        $params['iss'] = $this->config->issuer();
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function matchesSecret(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value) === 1;
    }

    private static function invalidGrant(): DiagnosticProblem
    {
        return new DiagnosticProblem(400, 'invalid_grant', 'The code, verifier, or diagnostic authorization is invalid.');
    }
}
