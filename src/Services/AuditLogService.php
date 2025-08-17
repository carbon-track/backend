<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Monolog\Logger;

class AuditLogService
{
    private PDO $db;
    private Logger $logger;

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Generic structured audit log (backward-compatible with controllers/tests)
     * Accepts associative array payload and stores into audit_logs table when possible.
     */
    public function log($payload, ...$legacyArgs): void
    {
        try {
            if (!is_array($payload)) {
                // Legacy signature: (user_id, action, entity_type, entity_id, [values])
                $legacy = [
                    'user_id' => $payload ?? null,
                    'action' => $legacyArgs[0] ?? null,
                    'entity_type' => $legacyArgs[1] ?? null,
                    'entity_id' => $legacyArgs[2] ?? null,
                ];
                $values = $legacyArgs[3] ?? [];
                if (is_array($values)) {
                    if (isset($values['old_values']) || isset($values['old_value'])) {
                        $legacy['old_values'] = $values['old_values'] ?? $values['old_value'];
                    }
                    if (isset($values['new_values']) || isset($values['new_value'])) {
                        $legacy['new_values'] = $values['new_values'] ?? $values['new_value'];
                    }
                }
                $payload = $legacy;
            }
            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (
                    user_id, action, entity_type, entity_id,
                    old_values, new_values, ip_address, user_agent, created_at
                ) VALUES (
                    :user_id, :action, :entity_type, :entity_id,
                    :old_values, :new_values, :ip_address, :user_agent, NOW()
                )"
            );

            $stmt->execute([
                'user_id' => $payload['user_id'] ?? null,
                'action' => $payload['action'] ?? ($payload['event'] ?? 'unknown'),
                'entity_type' => $payload['entity_type'] ?? null,
                'entity_id' => $payload['entity_id'] ?? null,
                'old_values' => $payload['old_value'] ?? ($payload['old_values'] ?? null),
                'new_values' => $payload['new_value'] ?? ($payload['new_values'] ?? null),
                'ip_address' => $payload['ip_address'] ?? null,
                'user_agent' => $payload['user_agent'] ?? null,
            ]);

            $this->logger->info('Audit log recorded', [
                'action' => $payload['action'] ?? null,
                'entity_type' => $payload['entity_type'] ?? null,
                'entity_id' => $payload['entity_id'] ?? null,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to record audit log', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
        }
    }

    /**
     * Log user action
     */
    public function logUserAction(int $userId, string $action, array $data = [], string $ipAddress = null): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (user_id, action, data, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW())"
            );
            
            $stmt->execute([
                $userId,
                $action,
                json_encode($data),
                $ipAddress
            ]);
            
            $this->logger->info('User action logged', [
                'user_id' => $userId,
                'action' => $action,
                'ip_address' => $ipAddress
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log user action', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Log system event
     */
    public function logSystemEvent(string $event, array $data = []): void
    {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO audit_logs (action, data, created_at)
                VALUES (?, ?, NOW())"
            );
            
            $stmt->execute([
                $event,
                json_encode($data)
            ]);
            
            $this->logger->info('System event logged', [
                'event' => $event,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to log system event', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get audit logs for user
     */
    public function getUserLogs(int $userId, int $limit = 100): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM audit_logs 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?"
            );
            
            $stmt->execute([$userId, $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user logs', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get system logs
     */
    public function getSystemLogs(int $limit = 100): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT * FROM audit_logs 
                WHERE user_id IS NULL 
                ORDER BY created_at DESC 
                LIMIT ?"
            );
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get system logs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Clean old logs
     */
    public function cleanOldLogs(int $daysToKeep = 90): int
    {
        try {
            $stmt = $this->db->prepare(
                "DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
            );
            
            $stmt->execute([$daysToKeep]);
            $deletedCount = $stmt->rowCount();
            
            $this->logger->info('Old audit logs cleaned', [
                'days_to_keep' => $daysToKeep,
                'deleted_count' => $deletedCount
            ]);
            
            return $deletedCount;
        } catch (\Exception $e) {
            $this->logger->error('Failed to clean old logs', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}

