<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class ErrorLogService
{
    private const DATE_FMT = 'Y-m-d H:i:s';
    private PDO $db;
    private LoggerInterface $logger;

    public function __construct(PDO $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Persist an exception and request context into error_logs table.
     */
    public function logException(\Throwable $e, Request $request, array $extra = []): ?int
    {
        return $this->insertLog([
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'error_time' => date(self::DATE_FMT),
            'script_name' => $this->getScriptName($request),
            'client_get' => $this->safeJson($request->getQueryParams()),
            'client_post' => $this->safeJson($this->normalizeBody($request->getParsedBody())),
            'client_files' => $this->safeJson($this->normalizeFiles($request)),
            'client_cookie' => $this->safeJson($request->getCookieParams()),
            'client_session' => $this->safeJson($_SESSION ?? []),
            'client_server' => $this->safeJson($this->filterServer($request->getServerParams(), $extra)),
            'request_id' => $request->getHeaderLine('X-Request-ID') ?: ($request->getServerParams()['HTTP_X_REQUEST_ID'] ?? null),
        ]);
    }

    /**
     * Persist a non-exception error with a custom type/message and request context.
     */
    public function logError(string $type, string $message, Request $request, array $context = []): ?int
    {
        return $this->insertLog([
            'error_type' => $type,
            'error_message' => $message,
            'error_file' => $context['file'] ?? null,
            'error_line' => isset($context['line']) ? (int)$context['line'] : null,
            'error_time' => date(self::DATE_FMT),
            'script_name' => $this->getScriptName($request),
            'client_get' => $this->safeJson($request->getQueryParams()),
            'client_post' => $this->safeJson($this->normalizeBody($request->getParsedBody())),
            'client_files' => $this->safeJson($this->normalizeFiles($request)),
            'client_cookie' => $this->safeJson($request->getCookieParams()),
            'client_session' => $this->safeJson($_SESSION ?? []),
            'client_server' => $this->safeJson($this->filterServer($request->getServerParams(), $context)),
            'request_id' => $request->getHeaderLine('X-Request-ID') ?: ($request->getServerParams()['HTTP_X_REQUEST_ID'] ?? null),
        ]);
    }

    private function insertLog(array $data): ?int
    {
        try {
            $sql = 'INSERT INTO error_logs (error_type, error_message, error_file, error_line, error_time, script_name, client_get, client_post, client_files, client_cookie, client_session, client_server, request_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $data['error_type'] ?? null,
                $data['error_message'] ?? null,
                $data['error_file'] ?? null,
                $data['error_line'] ?? null,
                $data['error_time'] ?? date(self::DATE_FMT),
                $data['script_name'] ?? null,
                $data['client_get'] ?? null,
                $data['client_post'] ?? null,
                $data['client_files'] ?? null,
                $data['client_cookie'] ?? null,
                $data['client_session'] ?? null,
                $data['client_server'] ?? null,
                $data['request_id'] ?? null,
            ]);
            $id = (int) $this->db->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $ex) {
            // Fallback to application logger to avoid losing the error entirely
            try {
                $this->logger->error('Failed to persist error log', [
                    'message' => $ex->getMessage(),
                ]);
            } catch (\Throwable $ignored) {
                // swallow
            }
            return null;
        }
    }

    private function getScriptName(Request $request): string
    {
        $server = $request->getServerParams();
        return $server['SCRIPT_NAME'] ?? $server['PHP_SELF'] ?? (string)$request->getUri()->getPath();
    }

    private function safeJson($data): string
    {
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = '{}';
            }
            // ensure TEXT column size safety (approx)
            if (strlen($json) > 60000) {
                $json = substr($json, 0, 60000);
            }
            return $json;
        } catch (\Throwable $e) {
            return '{}';
        }
    }

    private function normalizeBody($body): array
    {
        if (is_array($body)) {
            return $body;
        }
        if (is_object($body)) {
            return (array) $body;
        }
        return $body ? ['_raw' => $body] : [];
    }

    private function normalizeFiles(Request $request): array
    {
        $files = $request->getUploadedFiles();
        $out = [];
        foreach ($files as $key => $file) {
            if (is_array($file)) {
                $out[$key] = array_map([$this, 'fileInfo'], $file);
            } else {
                $out[$key] = $this->fileInfo($file);
            }
        }
        return $out;
    }

    private function fileInfo($uploadedFile): array
    {
        if (!$uploadedFile) {
            return [];
        }
        // UploadedFileInterface methods
        try {
            return [
                'clientFilename' => method_exists($uploadedFile, 'getClientFilename') ? $uploadedFile->getClientFilename() : null,
                'size' => method_exists($uploadedFile, 'getSize') ? $uploadedFile->getSize() : null,
                'error' => method_exists($uploadedFile, 'getError') ? $uploadedFile->getError() : null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function filterServer(array $server, array $extra = []): array
    {
        // Avoid logging sensitive data
        $hidden = ['PHP_AUTH_PW'];
        foreach ($hidden as $key) {
            if (isset($server[$key])) {
                $server[$key] = '***';
            }
        }
        // Add a few request-line highlights
        $server['_summary'] = [
            'method' => $server['REQUEST_METHOD'] ?? null,
            'uri' => $server['REQUEST_URI'] ?? null,
        ] + $extra;
        return $server;
    }
}
