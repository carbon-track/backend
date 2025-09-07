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

    public function log(array $data): void
    {
        try {
            $requestBody = $this->sanitizeBody($data['request_body'] ?? null);
            $responseBody = $this->sanitizeBody($data['response_body'] ?? null);

            $stmt = $this->db->prepare("INSERT INTO system_logs (
                request_id, method, path, status_code, user_id, ip_address, user_agent, duration_ms, request_body, response_body, created_at
            ) VALUES (?,?,?,?,?,?,?,?,?,?, strftime('%Y-%m-%d %H:%M:%S','now'))");

            $stmt->execute([
                $data['request_id'] ?? null,
                $data['method'] ?? null,
                $data['path'] ?? null,
                $data['status_code'] ?? null,
                $data['user_id'] ?? null,
                $data['ip_address'] ?? null,
                $data['user_agent'] ?? null,
                $data['duration_ms'] ?? null,
                $requestBody,
                $responseBody
            ]);
        } catch (\Throwable $e) {
            // 仅记录到应用日志，避免影响主业务
            try {
                $this->logger->warning('System log insert failed', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignore) {}
        }
    }

    private function sanitizeBody($body): ?string
    {
        if ($body === null) return null;
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

        if ($body === false) return null;

        if (mb_strlen($body, 'UTF-8') > $this->maxBodyLength) {
            $body = mb_substr($body, 0, $this->maxBodyLength, 'UTF-8') . '...[TRUNCATED]';
        }
        return $body;
    }
}
