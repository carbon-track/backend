<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\DatabaseRows;
use Illuminate\Database\Connection;
use CarbonTrack\Support\ClientIpResolver;
use Monolog\Logger;

/**
 * SystemLogService
 * 负责持久化请求级别系统日志，不抛异常影响主流程。
 */
class SystemLogService
{
    private Connection $db;
    private Logger $logger;
    /** @var array<int, string|null> */
    private array $userUuidCache = [];

    // 截断阈值，防止巨大请求/响应撑爆日志表
    private int $maxBodyLength = 8000; // characters

    public function __construct(Connection $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Persist a log snapshot prepared by the caller.
     *
     * @param array<string, mixed> $data Credentials in bodies and server parameters must be filtered at capture.
     */
    public function log(array $data): ?int
    {
        if ($this->isWriteDisabled()) {
            return null;
        }

        try {
            $requestId = $data['request_id'] ?? null;
            if ($requestId !== null) {
                $requestId = substr((string) $requestId, 0, 64);
            }
            $requestBody = $this->serializeBody($data['request_body'] ?? null);
            $responseBody = $this->serializeBody($data['response_body'] ?? null);
            $serverMeta = $this->buildServerMeta(
                $data['server_params'] ?? [],
                [
                    'method' => $data['method'] ?? null,
                    'path' => $data['path'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                ]
            );
            $userUuid = $this->resolveUserUuid($data);

            // 为了兼容 MySQL 和 SQLite，采用字符串形式写 created_at，使用默认的 CURRENT_TIMESTAMP 进行处理

            $id = DatabaseService::insertGetId($this->db, "INSERT INTO system_logs (
                request_id, method, path, status_code, user_id, user_uuid, ip_address, user_agent, duration_ms, request_body, response_body, server_meta
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", [
                $requestId,
                $data['method'] ?? null,
                $data['path'] ?? null,
                $data['status_code'] ?? null,
                $data['user_id'] ?? null,
                $userUuid,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
                $data['duration_ms'] ?? null,
                $requestBody,
                $responseBody,
                $serverMeta
            ]);
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            // 记录系统日志插入失败的警告，不影响主流程
            try {
                $this->logger->warning('System log insert failed', [
                    'error' => DatabaseService::exceptionMessage($e),
                ]);
            } catch (\Throwable $ignore) {
                // swallow secondary logging failure
            }
        }
        return null;
    }

    private function serializeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }
        if (!is_string($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        if ($body === false) {
            return null;
        }

        if (mb_strlen($body, 'UTF-8') > $this->maxBodyLength) {
            $body = mb_substr($body, 0, $this->maxBodyLength, 'UTF-8') . '...[TRUNCATED]';
        }
        return $body;
    }

    private function buildServerMeta(array $server, array $summaryOverride = []): string
    {
        $server['_summary'] = [
            'method' => $this->resolveSummaryMethod($server, $summaryOverride),
            'uri' => $this->resolveSummaryUri($server, $summaryOverride),
            'ip' => $this->resolveSummaryIp($server, $summaryOverride),
        ];
        $json = json_encode($server, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { return '{}'; }
        if (strlen($json) > 120000) { // 防止爆炸日志撑满磁盘
            $json = substr($json, 0, 120000) . '...[TRUNCATED]';
        }
        return $json;
    }

    private function resolveSummaryMethod(array $server, array $context): ?string
    {
        return $this->firstNonEmptyString([
            $context['method'] ?? null,
            $server['REQUEST_METHOD'] ?? null,
            $_SERVER['REQUEST_METHOD'] ?? null,
        ]);
    }

    private function resolveSummaryUri(array $server, array $context): ?string
    {
        $uri = $this->firstNonEmptyString([
            $server['REQUEST_URI'] ?? null,
            $_SERVER['REQUEST_URI'] ?? null,
        ]);
        if ($uri !== null) {
            return $uri;
        }

        return $this->firstNonEmptyString([
            $context['path'] ?? null,
            $server['PATH_INFO'] ?? null,
            $_SERVER['PATH_INFO'] ?? null,
        ]);
    }

    private function resolveSummaryIp(array $server, array $context): ?string
    {
        return ClientIpResolver::fromServerParams($server)
            ?? $this->firstValidIp([$context['ip_address'] ?? null]);
    }

    private function firstValidIp(array $candidates): ?string
    {
        foreach ($candidates as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $first = trim(explode(',', trim($raw))[0]);
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return null;
    }

    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }
            return $trimmed;
        }
        return null;
    }

    private function resolveUserUuid(array $data): ?string
    {
        $explicit = $data['user_uuid'] ?? $data['uuid'] ?? $data['userUuid'] ?? null;
        if (is_string($explicit)) {
            $trimmed = strtolower(trim($explicit));
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        $userId = $data['user_id'] ?? null;
        if (is_int($userId) || (is_numeric($userId) && (string) (int) $userId === (string) $userId)) {
            return $this->lookupUserUuidById((int) $userId);
        }

        return null;
    }

    private function lookupUserUuidById(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        if (array_key_exists($userId, $this->userUuidCache)) {
            return $this->userUuidCache[$userId];
        }

        try {
            $uuid = DatabaseRows::scalar($this->db->selectOne('SELECT uuid FROM users WHERE id = :id LIMIT 1', ['id' => $userId]));
            $normalized = is_string($uuid) && trim($uuid) !== '' ? strtolower(trim($uuid)) : null;
            $this->userUuidCache[$userId] = $normalized;
            return $normalized;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isWriteDisabled(): bool
    {
        if ($this->isProductionEnvironment()) {
            return false;
        }

        $raw = $_ENV['DISABLE_SYSTEM_LOG_WRITES'] ?? $_SERVER['DISABLE_SYSTEM_LOG_WRITES'] ?? null;
        if (!is_string($raw) && !is_numeric($raw) && !is_bool($raw)) {
            return false;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private function isProductionEnvironment(): bool
    {
        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? '')));
        return $env === 'production';
    }
}

