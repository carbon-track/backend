<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Monolog\Logger;

/**
 * SystemLogService
 * 负责持久化请求级别系统日志，不抛异常影响主流程。
 */
class SystemLogService
{
    private PDO $db;
    private Logger $logger;

    // 截断阈值，防止巨大请求/响应撑爆日志表
    private int $maxBodyLength = 8000; // characters

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    public function log(array $data): ?int
    {
        try {
            $requestId = $data['request_id'] ?? null;
            if ($requestId !== null) {
                $requestId = substr((string) $requestId, 0, 64);
            }
            $requestBody = $this->sanitizeBody($data['request_body'] ?? null);
            $responseBody = $this->sanitizeBody($data['response_body'] ?? null);
            $serverMeta = $this->buildServerMeta(
                $data['server_params'] ?? [],
                [
                    'method' => $data['method'] ?? null,
                    'path' => $data['path'] ?? null,
                    'ip_address' => $data['ip_address'] ?? null,
                ]
            );

            // 为了兼容 MySQL 和 SQLite，采用字符串形式写 created_at，使用默认的 CURRENT_TIMESTAMP 进行处理
            $stmt = $this->db->prepare("INSERT INTO system_logs (
                request_id, method, path, status_code, user_id, ip_address, user_agent, duration_ms, request_body, response_body, server_meta
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

            $stmt->execute([
                $requestId,
                $data['method'] ?? null,
                $data['path'] ?? null,
                $data['status_code'] ?? null,
                $data['user_id'] ?? null,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
                $data['duration_ms'] ?? null,
                $requestBody,
                $responseBody,
                $serverMeta
            ]);
            $id = (int) $this->db->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            // ����¼��Ӧ����־������Ӱ����ҵ��
            try {
                $this->logger->warning('System log insert failed', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignore) {
                // swallow secondary logging failure
            }
        }
        return null;
    }

    private function sanitizeBody($body): ?string
    {
        if ($body === null) {
            return null;
        }
        if (is_array($body)) {
            // 复制数组并脱敏常见敏感字段
            $clone = $body;
            $sensitive = ['password','pass','token','authorization','auth','secret'];
            foreach ($sensitive as $key) {
                if (isset($clone[$key])) { $clone[$key] = '[REDACTED]'; }
            }
            $body = json_encode($clone, JSON_UNESCAPED_UNICODE);
        } elseif (!is_string($body)) {
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
        $clone = $server;
        $sensitiveKeys = ['HTTP_AUTHORIZATION','PHP_AUTH_PW','HTTP_COOKIE'];
        foreach ($sensitiveKeys as $k) {
            if (isset($clone[$k])) { $clone[$k] = '[REDACTED]'; }
        }
        $clone['_summary'] = [
            'method' => $this->resolveSummaryMethod($clone, $summaryOverride),
            'uri' => $this->resolveSummaryUri($clone, $summaryOverride),
            'ip' => $this->resolveSummaryIp($clone, $summaryOverride),
        ];
        $json = json_encode($clone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $candidates = [
            $server['HTTP_CF_CONNNECTING_IP'] ?? null, // common typo with double N
            $_SERVER['HTTP_CF_CONNNECTING_IP'] ?? null,
            $server['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $server['CF_CONNECTING_IP'] ?? null,
            $_SERVER['CF_CONNECTING_IP'] ?? null,
            $context['ip_address'] ?? null,
            $server['REMOTE_ADDR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (!is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }
            $first = trim(explode(',', $trimmed)[0]);
            if ($first === '') {
                continue;
            }
            if (filter_var($first, FILTER_VALIDATE_IP)) {
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
}

