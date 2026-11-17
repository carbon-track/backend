<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;

trait LogsClientExceptions
{
    private function logClientException(
        \Throwable $exception,
        Request $request,
        int $status,
        string $operation,
        ?array $actor = null,
        ?string $auditCategory = null
    ): void {
        try {
            $this->errorLogService?->logException($exception, $request, [
                'context_message' => $operation,
                'response_code' => $status,
            ]);
        } catch (\Throwable $ignore) {
            error_log('Could not persist a handled request exception: ' . $ignore->getMessage());
        }

        if ($auditCategory === null) {
            return;
        }

        $actor ??= $request->getAttribute('user');
        $actor = is_array($actor) ? $actor : [];
        try {
            $this->auditLogService?->log([
                'action' => 'handled_request_failed',
                'operation_category' => $auditCategory,
                'actor_type' => !empty($actor['is_admin']) ? 'admin' : 'user',
                'user_id' => isset($actor['id']) ? (int) $actor['id'] : null,
                'status' => 'failed',
                'response_code' => $status,
                'request_id' => $request->getAttribute('request_id'),
                'request_method' => $request->getMethod(),
                'endpoint' => $request->getUri()->getPath(),
                'data' => ['operation' => $operation],
            ]);
        } catch (\Throwable $ignore) {
            error_log('Could not audit a handled request exception: ' . $ignore->getMessage());
        }
    }
}
