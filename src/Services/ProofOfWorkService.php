<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\SyntheticRequestFactory;
use Monolog\Logger;
use PDO;

class ProofOfWorkService
{
    public const ALLOWED_SCOPES = [
        'auth.login',
        'auth.register',
        'auth.send_verification_code',
        'auth.verify_email',
        'auth.forgot_password',
        'carbon.record.submit',
        'user.profile.school_change',
        'support.ticket.create',
        'support.ticket.reply',
    ];

    private string $secret;
    private Logger $logger;
    private ?AuditLogService $auditLogService;
    private ?ErrorLogService $errorLogService;
    private int $difficulty;
    private int $ttlSeconds;
    private ?PDO $db;
    private \DateTimeZone $clockTimezone;

    public function __construct(
        string $secret,
        Logger $logger,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null,
        int $difficulty = 16,
        int $ttlSeconds = 120,
        ?PDO $db = null
    ) {
        $secret = trim($secret);
        if ($secret === '') {
            $environment = strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'development')));
            if ($environment === 'production') {
                throw new \RuntimeException('POW_SECRET or JWT_SECRET must be configured in production.');
            }
            $secret = 'carbontrack-pow-development-secret';
        }

        $this->secret = $secret;
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->difficulty = max(8, min(28, $difficulty));
        $this->ttlSeconds = max(30, min(600, $ttlSeconds));
        $this->db = $db;
        $this->clockTimezone = new \DateTimeZone('UTC');
    }

    public function createChallenge(string $scope): array
    {
        $expiresAt = $this->now()->modify('+' . $this->ttlSeconds . ' seconds');
        $payload = [
            'id' => bin2hex(random_bytes(16)),
            'salt' => bin2hex(random_bytes(16)),
            'scope' => $scope,
            'difficulty' => $this->difficulty,
            'exp' => $expiresAt->getTimestamp(),
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new \RuntimeException('Failed to encode proof-of-work challenge');
        }

        $payloadPart = $this->base64UrlEncode($payloadJson);
        $signature = hash_hmac('sha256', $payloadPart, $this->secret, true);
        $challenge = $payloadPart . '.' . $this->base64UrlEncode($signature);
        $this->persistChallenge($payload, $challenge);

        $this->logAudit('pow_challenge_created', [
            'scope' => $scope,
            'difficulty' => $this->difficulty,
            'expires_at' => $this->formatAtom($expiresAt),
        ]);

        return [
            'challenge' => $challenge,
            'difficulty' => $this->difficulty,
            'algorithm' => 'sha256',
            'scope' => $scope,
            'expires_at' => $this->formatAtom($expiresAt),
        ];
    }

    public function verify(?string $challenge, ?string $nonce, string $expectedScope): array
    {
        $challenge = is_string($challenge) ? trim($challenge) : '';
        $nonce = is_string($nonce) ? trim($nonce) : '';

        if ($challenge === '' || $nonce === '') {
            $this->logAudit('pow_verification_missing_fields', ['scope' => $expectedScope], 'failed');
            return ['success' => false, 'error' => 'missing-proof'];
        }

        if (!preg_match('/^[0-9]{1,20}$/', $nonce)) {
            $this->logAudit('pow_verification_invalid_nonce', ['scope' => $expectedScope], 'failed');
            return ['success' => false, 'error' => 'invalid-nonce'];
        }

        try {
            $payload = $this->decodeChallenge($challenge);
        } catch (\Throwable $e) {
            $this->logFailure('pow_verification_invalid_challenge', $e, ['scope' => $expectedScope]);
            return ['success' => false, 'error' => 'invalid-challenge'];
        }

        if (($payload['scope'] ?? null) !== $expectedScope) {
            $this->logAudit('pow_verification_scope_mismatch', [
                'expected_scope' => $expectedScope,
                'actual_scope' => $payload['scope'] ?? null,
            ], 'failed');
            return ['success' => false, 'error' => 'scope-mismatch'];
        }

        if ((int)($payload['exp'] ?? 0) < $this->now()->getTimestamp()) {
            $this->logAudit('pow_verification_expired', ['scope' => $expectedScope], 'failed');
            return ['success' => false, 'error' => 'expired-challenge'];
        }

        $difficulty = (int)($payload['difficulty'] ?? 0);
        if ($difficulty < 8 || $difficulty > 28) {
            $this->logAudit('pow_verification_invalid_difficulty', [
                'scope' => $expectedScope,
                'difficulty' => $difficulty,
            ], 'failed');
            return ['success' => false, 'error' => 'invalid-difficulty'];
        }

        $hash = hash('sha256', $challenge . ':' . $nonce, true);
        if (!$this->hasLeadingZeroBits($hash, $difficulty)) {
            $this->logAudit('pow_verification_failed', ['scope' => $expectedScope], 'failed');
            return ['success' => false, 'error' => 'insufficient-work'];
        }

        if (!$this->consumeChallenge((string)($payload['id'] ?? ''), $challenge, $expectedScope)) {
            $this->logAudit('pow_verification_replayed', ['scope' => $expectedScope], 'failed');
            return ['success' => false, 'error' => 'replayed-challenge'];
        }

        $this->logAudit('pow_verification_succeeded', [
            'scope' => $expectedScope,
            'difficulty' => $difficulty,
        ]);

        return ['success' => true, 'difficulty' => $difficulty];
    }

    private function decodeChallenge(string $challenge): array
    {
        $parts = explode('.', $challenge, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Malformed challenge');
        }

        [$payloadPart, $signaturePart] = $parts;
        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $payloadPart, $this->secret, true));
        if (!hash_equals($expectedSignature, $signaturePart)) {
            throw new \InvalidArgumentException('Invalid challenge signature');
        }

        $payloadJson = $this->base64UrlDecode($payloadPart);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Invalid challenge payload');
        }

        return $payload;
    }

    private function persistChallenge(array $payload, string $challenge): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO proof_of_work_challenges (challenge_id, challenge_hash, scope, difficulty, expires_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ? , ?)'
            );
            $now = $this->formatSql($this->now());
            $stmt->execute([
                (string)$payload['id'],
                hash('sha256', $challenge),
                (string)$payload['scope'],
                (int)$payload['difficulty'],
                $this->formatSql($this->fromEpoch((int)$payload['exp'])),
                $now,
                $now,
            ]);
        } catch (\Throwable $e) {
            $this->logFailure('pow_challenge_persist_failed', $e, ['scope' => (string)($payload['scope'] ?? '')]);
            throw new \RuntimeException('Failed to persist proof-of-work challenge', 0, $e);
        }
    }

    private function consumeChallenge(string $challengeId, string $challenge, string $scope): bool
    {
        if ($this->db === null) {
            return true;
        }

        try {
            $now = $this->formatSql($this->now());
            $stmt = $this->db->prepare(
                'UPDATE proof_of_work_challenges
                 SET used_at = ?, updated_at = ?
                 WHERE challenge_id = ?
                   AND challenge_hash = ?
                   AND scope = ?
                   AND used_at IS NULL
                   AND expires_at >= ?'
            );
            $stmt->execute([
                $now,
                $now,
                $challengeId,
                hash('sha256', $challenge),
                $scope,
                $now,
            ]);

            return $stmt->rowCount() === 1;
        } catch (\Throwable $e) {
            $this->logFailure('pow_challenge_consume_failed', $e, ['scope' => $scope]);
            return false;
        }
    }

    public function cleanupExpiredChallenges(): array
    {
        if ($this->db === null) {
            return ['deleted' => 0];
        }

        try {
            $threshold = $this->formatSql($this->now()->modify('-600 seconds'));
            $countStmt = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM proof_of_work_challenges
                 WHERE expires_at < ?
                    OR (used_at IS NOT NULL AND used_at < ?)'
            );
            $countStmt->execute([$threshold, $threshold]);
            $deleted = (int)$countStmt->fetchColumn();
            $stmt = $this->db->prepare(
                'DELETE FROM proof_of_work_challenges
                 WHERE expires_at < ?
                    OR (used_at IS NOT NULL AND used_at < ?)'
            );
            $stmt->execute([$threshold, $threshold]);
            return ['deleted' => $deleted];
        } catch (\Throwable $e) {
            $this->logFailure('pow_challenge_cleanup_failed', $e);
            throw $e;
        }
    }

    private function hasLeadingZeroBits(string $hash, int $difficulty): bool
    {
        $fullBytes = intdiv($difficulty, 8);
        for ($i = 0; $i < $fullBytes; $i++) {
            if (ord($hash[$i]) !== 0) {
                return false;
            }
        }

        $remainingBits = $difficulty % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($hash[$fullBytes]) & $mask) === 0;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->clockTimezone);
    }

    private function fromEpoch(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->clockTimezone);
    }

    private function formatSql(\DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->clockTimezone)->format('Y-m-d H:i:s');
    }

    private function formatAtom(\DateTimeImmutable $value): string
    {
        return $value->setTimezone($this->clockTimezone)->format(DATE_ATOM);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + 4 - strlen($value) % 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url data');
        }
        return $decoded;
    }

    private function logAudit(string $action, array $context = [], string $status = 'success'): void
    {
        if ($this->auditLogService === null) {
            return;
        }

        try {
            $this->auditLogService->log([
                'action' => $action,
                'operation_category' => 'security',
                'actor_type' => 'system',
                'status' => $status,
                'data' => $context,
            ]);
        } catch (\Throwable $auditError) {
            $this->logger->warning('pow_audit_log_failed', [
                'action' => $action,
                'status' => $status,
                'error' => $auditError->getMessage(),
            ] + $context);
        }
    }

    private function logFailure(string $action, \Throwable $e, array $context): void
    {
        $this->logAudit($action, $context, 'failed');
        $this->logger->warning($action, ['error' => $e->getMessage()] + $context);

        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext('/internal/security/pow', 'POST', null, [], $context);
            $this->errorLogService->logException($e, $request, ['context_message' => $action] + $context);
        } catch (\Throwable $errorLogError) {
            $this->logger->warning('pow_error_log_failed', [
                'action' => $action,
                'error' => $errorLogError->getMessage(),
                'original_error' => $e->getMessage(),
            ] + $context);
        }
    }
}
