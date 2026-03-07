<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\MultipartUpload;
use CarbonTrack\Support\SyntheticRequestFactory;
use Monolog\Logger;

class MultipartUploadService
{
    public function __construct(
        private Logger $logger,
        private ?AuditLogService $auditLogService = null,
        private ?ErrorLogService $errorLogService = null
    ) {}

    public function registerUpload(string $uploadId, string $filePath, int $userId, ?string $sha256 = null, int $ttlSeconds = 86400): MultipartUpload
    {
        $upload = MultipartUpload::updateOrCreate(
            ['upload_id' => $uploadId],
            [
                'file_path' => $filePath,
                'sha256' => $sha256,
                'user_id' => $userId,
                'expires_at' => date('Y-m-d H:i:s', time() + max(60, $ttlSeconds)),
            ]
        );

        $this->logAudit('multipart_upload_registered', [
            'upload_id' => $uploadId,
            'file_path' => $filePath,
            'sha256' => $sha256,
            'user_id' => $userId,
            'ttl_seconds' => max(60, $ttlSeconds),
        ]);

        return $upload;
    }

    public function findActiveUpload(string $uploadId): ?MultipartUpload
    {
        $upload = MultipartUpload::where('upload_id', $uploadId)->first();
        if (!$upload) {
            return null;
        }

        if ($this->isExpired($upload)) {
            try {
                $upload->delete();
                $this->logAudit('multipart_upload_expired_cleared', [
                    'upload_id' => $uploadId,
                    'user_id' => $upload->user_id,
                ]);
            } catch (\Throwable $e) {
                $this->logAudit('multipart_upload_expired_clear_failed', [
                    'upload_id' => $uploadId,
                    'user_id' => $upload->user_id,
                ], 'failed');
                $this->logError($e, '/internal/files/multipart/expired-cleanup', 'Failed to delete expired multipart upload tracker', [
                    'upload_id' => $uploadId,
                    'user_id' => $upload->user_id,
                ]);
                $this->logger->warning('Failed to delete expired multipart upload tracker', [
                    'upload_id' => $uploadId,
                    'error' => $e->getMessage(),
                ]);
            }
            return null;
        }

        return $upload;
    }

    public function clearUpload(string $uploadId): void
    {
        $deleted = MultipartUpload::where('upload_id', $uploadId)->delete();
        if ($deleted > 0) {
            $this->logAudit('multipart_upload_cleared', [
                'upload_id' => $uploadId,
                'deleted_count' => $deleted,
            ]);
        }
    }

    private function isExpired(MultipartUpload $upload): bool
    {
        $expiresAt = $upload->expires_at;
        if ($expiresAt === null) {
            return false;
        }

        if ($expiresAt instanceof \DateTimeInterface) {
            return $expiresAt->getTimestamp() < time();
        }

        $timestamp = strtotime((string) $expiresAt);
        return $timestamp !== false && $timestamp < time();
    }

    private function logAudit(string $action, array $context = [], string $status = 'success'): void
    {
        if ($this->auditLogService === null) {
            return;
        }

        try {
            $this->auditLogService->log([
                'action' => $action,
                'operation_category' => 'file_management',
                'actor_type' => 'system',
                'status' => $status,
                'data' => $context,
            ]);
        } catch (\Throwable $ignore) {
            // ignore audit failures in multipart tracking
        }
    }

    private function logError(\Throwable $e, string $path, string $message, array $context = []): void
    {
        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext($path, 'POST', null, [], $context);
            $this->errorLogService->logException($e, $request, ['context_message' => $message] + $context);
        } catch (\Throwable $ignore) {
            // ignore error log failures in multipart tracking
        }
    }
}
