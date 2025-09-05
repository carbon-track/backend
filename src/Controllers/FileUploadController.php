<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use Monolog\Logger;

class FileUploadController
{
    private CloudflareR2Service $r2Service;
    private AuthService $authService;
    private AuditLogService $auditLogService;
    private Logger $logger;
    private ErrorLogService $errorLogService;

    public function __construct(
        CloudflareR2Service $r2Service,
        AuthService $authService,
        AuditLogService $auditLogService,
        Logger $logger,
        ErrorLogService $errorLogService
    ) {
        $this->r2Service = $r2Service;
        $this->authService = $authService;
        $this->auditLogService = $auditLogService;
        $this->logger = $logger;
        $this->errorLogService = $errorLogService;
    }

    /**
     * 上传单个文件
     */
    public function uploadFile(Request $request, Response $response): Response
    {
        try {
            // 验证用户身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // 获取上传的文件
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['file'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No file uploaded'
                ], 400);
            }

            $file = $uploadedFiles['file'];
            
            // 获取请求参数
            $body = $request->getParsedBody();
            $directory = $body['directory'] ?? 'uploads';
            $entityType = $body['entity_type'] ?? null;
            $entityId = isset($body['entity_id']) ? (int)$body['entity_id'] : null;

            // 验证目录名
            if (!$this->isValidDirectory($directory)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid directory name'
                ], 400);
            }

            // 上传文件
            $result = $this->r2Service->uploadFile(
                $file,
                $directory,
                $user['id'],
                $entityType,
                $entityId
            );

            $this->logger->info('File uploaded successfully', [
                'user_id' => $user['id'],
                'file_path' => $result['file_path'],
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $result
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('File upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'File upload failed'
            ], 500);
        }
    }

    /**
     * 上传多个文件
     */
    public function uploadMultipleFiles(Request $request, Response $response): Response
    {
        try {
            // 验证用户身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            // 获取上传的文件
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['files']) || !is_array($uploadedFiles['files'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No files uploaded'
                ], 400);
            }

            $files = $uploadedFiles['files'];
            
            // 获取请求参数
            $body = $request->getParsedBody();
            $directory = $body['directory'] ?? 'uploads';
            $entityType = $body['entity_type'] ?? null;
            $entityId = isset($body['entity_id']) ? (int)$body['entity_id'] : null;

            // 验证目录名
            if (!$this->isValidDirectory($directory)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid directory name'
                ], 400);
            }

            // 限制文件数量
            if (count($files) > 10) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Too many files. Maximum 10 files allowed'
                ], 400);
            }

            // 批量上传文件
            $result = $this->r2Service->uploadMultipleFiles(
                $files,
                $directory,
                $user['id'],
                $entityType,
                $entityId
            );

            $this->logger->info('Multiple files uploaded', [
                'user_id' => $user['id'],
                'success_count' => $result['success'],
                'failed_count' => $result['failed'],
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => "Uploaded {$result['success']} files successfully" . 
                           ($result['failed'] > 0 ? ", {$result['failed']} failed" : ""),
                'data' => $result
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Multiple file upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'File upload failed'
            ], 500);
        }
    }

    /**
     * 删除文件
     */
    public function deleteFile(Request $request, Response $response, array $args): Response
    {
        try {
            // 验证用户身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $filePath = $args['path'] ?? '';
            if (empty($filePath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File path is required'
                ], 400);
            }

            // URL解码文件路径
            $filePath = urldecode($filePath);

            // 检查文件是否存在
            if (!$this->r2Service->fileExists($filePath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            // 删除文件
            $success = $this->r2Service->deleteFile($filePath, $user['id']);

            if ($success) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'File deleted successfully'
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to delete file'
                ], 500);
            }

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('File deletion failed', [
                'error' => $e->getMessage(),
                'file_path' => $args['path'] ?? '',
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'File deletion failed'
            ], 500);
        }
    }

    /**
     * 获取文件信息
     */
    public function getFileInfo(Request $request, Response $response, array $args): Response
    {
        try {
            // 验证用户身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $filePath = $args['path'] ?? '';
            if (empty($filePath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File path is required'
                ], 400);
            }

            // URL解码文件路径
            $filePath = urldecode($filePath);

            // 获取文件信息
            $fileInfo = $this->r2Service->getFileInfo($filePath);

            if ($fileInfo) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'data' => $fileInfo
                ]);
            } else {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Get file info failed', [
                'error' => $e->getMessage(),
                'file_path' => $args['path'] ?? '',
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get file info'
            ], 500);
        }
    }

    /**
     * 生成预签名URL
     */
    public function generatePresignedUrl(Request $request, Response $response, array $args): Response
    {
        try {
            // 验证用户身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $filePath = $args['path'] ?? '';
            if (empty($filePath)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File path is required'
                ], 400);
            }

            // URL解码文件路径
            $filePath = urldecode($filePath);

            // 获取过期时间（默认1小时）
            $queryParams = $request->getQueryParams();
            $expiresIn = isset($queryParams['expires_in']) ? (int)$queryParams['expires_in'] : 3600;

            // 限制过期时间（最大24小时）
            $expiresIn = min($expiresIn, 86400);

            // 生成预签名URL
            $presignedUrl = $this->r2Service->generatePresignedUrl($filePath, $expiresIn);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'presigned_url' => $presignedUrl,
                    'expires_in' => $expiresIn,
                    'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn)
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Generate presigned URL failed', [
                'error' => $e->getMessage(),
                'file_path' => $args['path'] ?? '',
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to generate presigned URL'
            ], 500);
        }
    }

    /**
     * 获取存储统计信息（管理员）
     */
    public function getStorageStats(Request $request, Response $response): Response
    {
        try {
            // 验证管理员身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            // 获取存储统计信息
            $stats = $this->r2Service->getStorageStats();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Get storage stats failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get storage stats'
            ], 500);
        }
    }

    /**
     * 清理过期文件（管理员）
     */
    public function cleanupExpiredFiles(Request $request, Response $response): Response
    {
        try {
            // 验证管理员身份
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required'
                ], 403);
            }

            $body = $request->getParsedBody();
            $directory = $body['directory'] ?? 'temp';
            $daysOld = isset($body['days_old']) ? (int)$body['days_old'] : 7;

            // 限制天数范围
            $daysOld = max(1, min($daysOld, 365));

            // 清理过期文件
            $deletedCount = $this->r2Service->cleanupExpiredFiles($directory, $daysOld);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => "Cleaned up {$deletedCount} expired files",
                'data' => [
                    'deleted_count' => $deletedCount,
                    'directory' => $directory,
                    'days_old' => $daysOld
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Cleanup expired files failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to cleanup expired files'
            ], 500);
        }
    }

    /**
     * 验证目录名是否有效
     */
    private function isValidDirectory(string $directory): bool
    {
        // 允许的目录名
        $allowedDirectories = [
            'uploads',
            'avatars',
            'activities',
            'products',
            'temp',
            'documents'
        ];

        return in_array($directory, $allowedDirectories);
    }

    /**
     * 返回JSON响应
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}

