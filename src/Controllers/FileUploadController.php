<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\FileMetadataService;
use CarbonTrack\Services\FileOwnershipConflictException;
use CarbonTrack\Services\MultipartUploadService;
use Monolog\Logger;
use CarbonTrack\Models\File;

class FileUploadController
{
    private CloudflareR2Service $r2Service;
    private AuthService $authService;
    private AuditLogService $auditLogService;
    private Logger $logger;
    private ErrorLogService $errorLogService;
    private FileMetadataService $fileMetadataService;
    private MultipartUploadService $multipartUploadService;

    public function __construct(
        CloudflareR2Service $r2Service,
        AuthService $authService,
        AuditLogService $auditLogService,
        Logger $logger,
    ErrorLogService $errorLogService,
    FileMetadataService $fileMetadataService,
    MultipartUploadService $multipartUploadService
    ) {
        $this->r2Service = $r2Service;
        $this->authService = $authService;
        $this->auditLogService = $auditLogService;
        $this->logger = $logger;
        $this->errorLogService = $errorLogService;
    $this->fileMetadataService = $fileMetadataService;
    $this->multipartUploadService = $multipartUploadService;
    }

    /**
     * 获取前端直传预签名（生成对象 key + 预签名 PUT URL）
     * 前端步骤：
     * 1. POST /api/v1/files/presign {original_name, directory, mime_type, entity_type, entity_id}
     * 2. 使用返回的 url, headers 用 PUT 上传文件二进制
     * 3. 可选：调用 confirm 接口通知后端记录（若需要 DB 记录）
     */
    public function getDirectUploadPresign(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $body = $request->getParsedBody() ?: [];
            $originalName = trim($body['original_name'] ?? '');
            $directory = $body['directory'] ?? 'uploads';
            $mimeType = trim($body['mime_type'] ?? '');
            $fileSize = isset($body['file_size']) ? (int)$body['file_size'] : null; // 前端声明的大小
            $sha256 = isset($body['sha256']) ? strtolower(trim($body['sha256'])) : null;
            $entityType = $body['entity_type'] ?? null;
            $entityId = isset($body['entity_id']) ? (int)$body['entity_id'] : null;
            $expiresIn = isset($body['expires_in']) ? (int)$body['expires_in'] : 600;

            if ($originalName === '' || $mimeType === '') {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'original_name and mime_type are required'
                ], 400);
            }

            if (!$this->isValidDirectory($directory)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid directory name'
                ], 400);
            }

            // 校验 MIME & 扩展
            $allowedMime = $this->r2Service->getAllowedMimeTypes();
            if (!in_array($mimeType, $allowedMime, true)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'MIME type not allowed'
                ], 400);
            }
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $this->r2Service->getAllowedExtensions(), true)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File extension not allowed'
                ], 400);
            }

            // 文件大小预校验
            if ($fileSize !== null && $fileSize > $this->r2Service->getMaxFileSize()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File size exceeds limit'
                ], 400);
            }

            // 校验 sha256 格式（64 hex）
            if ($sha256 && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid sha256'
                ], 400);
            }

            // 去重：仅允许当前用户复用自己已持有的 sha256 记录，避免泄露其他用户文件元数据。
            if ($sha256) {
                $existing = $this->fileMetadataService->findBySha256($sha256);
                if ($existing && $this->isOwnedFileRecord($existing, (int) $user['id'])) {
                    return $this->jsonResponse($response, [
                        'success' => true,
                        'data' => [
                            'duplicate' => true,
                            'file_path' => $existing->file_path,
                            'public_url' => $this->r2Service->getPublicUrl($existing->file_path),
                            'sha256' => $sha256,
                            'reference_count' => $existing->reference_count,
                            'stored' => true
                        ]
                    ]);
                }
            }

            // 生成对象 key 与预签名
            $keyInfo = $this->r2Service->generateDirectUploadKey($originalName, $directory);
            $presign = $this->r2Service->generateUploadPresignedUrl(
                $keyInfo['file_path'],
                $mimeType,
                $expiresIn,
                $this->buildDirectUploadMetadata($user, $entityType, $entityId)
            );

            $data = array_merge($keyInfo, $presign, [
                'max_file_size' => $this->r2Service->getMaxFileSize(),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'confirm_required' => true,
                'sha256' => $sha256,
                'declared_file_size' => $fileSize,
                'duplicate' => false
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Generate direct upload presign failed', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to generate presign'], 500);
        }
    }

    /**
     * 前端直传完成后确认（可用于记录审计日志/数据库 metadata）
     * 请求体：{ file_path, original_name, entity_type?, entity_id? }
     */
    public function confirmDirectUpload(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $body = $request->getParsedBody() ?: [];
            $filePath = trim($body['file_path'] ?? '');
            $originalName = trim($body['original_name'] ?? '');
            $entityType = $body['entity_type'] ?? null;
            $entityId = isset($body['entity_id']) ? (int)$body['entity_id'] : null;
            $sha256 = isset($body['sha256']) ? strtolower(trim($body['sha256'])) : null;

            if ($sha256 && !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid sha256'], 400);
            }

            if ($filePath === '' || $originalName === '') {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'file_path and original_name are required'], 400);
            }

            // 初次获取对象信息
            $info = $this->r2Service->getFileInfo($filePath);
            if (!$info) {
                // 可能为 R2 写入后延迟可见，等待再试一次
                usleep(250000); // 250ms
                $info = $this->r2Service->getFileInfo($filePath);
            }
            // 若仍未找到，尝试检测是否因为 endpoint 误包含 bucket 造成 key 实际被写成 bucketName/xxx
            if (!$info) {
                $altPath = $this->r2Service->getBucketName() . '/' . ltrim($filePath, '/');
                $altInfo = $this->r2Service->getFileInfo($altPath);
                if ($altInfo) {
                    $this->logger->warning('File found only under bucketName-prefixed key; endpoint may include bucket path (misconfiguration).', [
                        'expected_file_path' => $filePath,
                        'actual_file_path' => $altPath,
                        'user_id' => $user['id']
                    ]);
                    // 改用实际信息，但返回时仍提示
                    $info = $altInfo;
                }
            }
            if (!$info) {
                $this->logger->warning('Confirm direct upload: file not yet visible in R2', [
                    'file_path' => $filePath,
                    'user_id' => $user['id']
                ]);
                return $this->jsonResponse($response, ['success' => false, 'message' => 'File not found in storage'], 404);
            }

            // 持久化元数据（如果 sha256 提供，则去重引用计数）
            ['file' => $fileRecord, 'duplicate' => $duplicated] = $this->persistDirectUploadOwnership(
                $filePath,
                (int) $user['id'],
                $originalName,
                $info,
                $sha256
            );

            // 记录审计日志
            $this->r2Service->logDirectUploadAudit($user['id'], $entityType, $entityId, $info, $originalName);

            $payload = $info;
            if ($fileRecord) {
                $payload['reference_count'] = $fileRecord->reference_count;
                $payload['sha256'] = $fileRecord->sha256 ?: null;
                $payload['duplicate'] = $duplicated;
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Upload confirmed',
                'data' => $payload
            ]);
        } catch (FileOwnershipConflictException $e) {
            $this->auditLogService->log([
                'user_id' => isset($user['id']) ? (int) $user['id'] : null,
                'action' => 'direct_upload_confirmed',
                'operation_category' => 'file_management',
                'affected_table' => 'files',
                'status' => 'failed',
                'data' => [
                    'file_path' => $body['file_path'] ?? null,
                    'error' => $e->getMessage(),
                    'error_code' => 'FILE_OWNERSHIP_CONFLICT',
                ],
            ]);
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'File ownership conflict detected',
                'code' => 'FILE_OWNERSHIP_CONFLICT'
            ], 409);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            $this->logger->error('Confirm direct upload failed', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to confirm upload'], 500);
        }
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

            $fileInfo = $this->r2Service->getFileInfo($filePath);
            if (!$fileInfo) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            if (!$this->canDeleteFile($user, $filePath, $fileInfo)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'You do not have permission to delete this file',
                    'code' => 'FILE_ACCESS_DENIED'
                ], 403);
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

            if (!$fileInfo) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            if (!$this->canReadFile($user, $filePath, $fileInfo)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'You do not have permission to access this file',
                    'code' => 'FILE_ACCESS_DENIED'
                ], 403);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $fileInfo
            ]);

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

            $fileInfo = $this->r2Service->getFileInfo($filePath);
            if (!$fileInfo) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'File not found'
                ], 404);
            }

            if (!$this->canReadFile($user, $filePath, $fileInfo)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'You do not have permission to access this file',
                    'code' => 'FILE_ACCESS_DENIED'
                ], 403);
            }

            // 获取过期时间（默认10分钟）
            $queryParams = $request->getQueryParams();
            $expiresIn = isset($queryParams['expires_in']) ? (int)$queryParams['expires_in'] : 600;

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
     * 列出文件（管理员） /api/v1/admin/files 已在路由引用 getFilesList
     */
    public function getFilesList(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Admin access required'], 403);
            }
            $query = $request->getQueryParams();
            $prefix = $query['prefix'] ?? null;
            $limit = isset($query['limit']) ? (int)$query['limit'] : 100;
            $list = $this->r2Service->listFiles($prefix, $limit);
            return $this->jsonResponse($response, $list, $list['success'] ? 200 : 500);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to list files'], 500);
        }
    }

    /**
     * 初始化分片上传
     */
    public function initMultipartUpload(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $body = $request->getParsedBody() ?: [];
            $originalName = trim($body['original_name'] ?? '');
            $directory = $body['directory'] ?? 'uploads';
            $mimeType = trim($body['mime_type'] ?? 'application/octet-stream');
            $sha256 = isset($body['sha256']) ? strtolower(trim($body['sha256'])) : null;
            if ($originalName === '' || !$this->isValidDirectory($directory)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid params'], 400);
            }
            if (!$sha256 || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid sha256'], 400);
            }
            if (!in_array($mimeType, $this->r2Service->getAllowedMimeTypes(), true)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'MIME type not allowed'], 400);
            }
            $init = $this->r2Service->initMultipartUpload($originalName, $directory, $mimeType);
            $this->multipartUploadService->registerUpload(
                $init['upload_id'],
                $init['file_path'],
                (int) $user['id'],
                $sha256
            );
            return $this->jsonResponse($response, ['success' => true, 'data' => $init]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to init multipart'], 500);
        }
    }

    /**
     * 获取单个分片的预签名上传 URL
     */
    public function getMultipartPartUrl(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $query = $request->getQueryParams();
            $filePath = $query['file_path'] ?? '';
            $uploadId = $query['upload_id'] ?? '';
            $partNumber = isset($query['part_number']) ? (int)$query['part_number'] : 0;
            if ($filePath === '' || $uploadId === '' || $partNumber < 1) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid params'], 400);
            }
            $multipartError = $this->authorizeMultipartUpload($response, $user, $uploadId, $filePath);
            if ($multipartError instanceof Response) {
                return $multipartError;
            }
            $part = $this->r2Service->generateMultipartPartUrl($filePath, $uploadId, $partNumber);
            return $this->jsonResponse($response, ['success' => true, 'data' => $part]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to get part url'], 500);
        }
    }

    /**
     * 完成分片上传
     */
    public function completeMultipartUpload(Request $request, Response $response): Response
    {
        $user = null;
        $body = [];
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $body = $request->getParsedBody() ?: [];
            $filePath = $body['file_path'] ?? '';
            $uploadId = $body['upload_id'] ?? '';
            $parts = $body['parts'] ?? [];
            $sha256 = isset($body['sha256']) ? strtolower(trim($body['sha256'])) : null;
            if ($filePath === '' || $uploadId === '' || !is_array($parts) || empty($parts)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid params'], 400);
            }
            $multipartError = $this->authorizeMultipartUpload($response, $user, $uploadId, $filePath);
            if ($multipartError instanceof Response) {
                return $multipartError;
            }
            $upload = $this->multipartUploadService->findActiveUpload($uploadId);
            $effectiveSha256 = $upload && !empty($upload->sha256) ? strtolower((string) $upload->sha256) : $sha256;
            if (!$effectiveSha256 || !preg_match('/^[a-f0-9]{64}$/', $effectiveSha256)) {
                if (!$this->fileMetadataService->findByFilePath($filePath)) {
                    return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid sha256'], 400);
                }
                $effectiveSha256 = null;
            }
            $result = $this->r2Service->completeMultipartUpload($filePath, $uploadId, $parts);
            $fileInfo = $this->r2Service->getFileInfo($filePath);
            $ownershipPersisted = false;
            try {
                $ownershipPersisted = $this->persistMultipartOwnership($filePath, (int) $user['id'], $fileInfo, $effectiveSha256);
            } finally {
                $this->multipartUploadService->clearUpload($uploadId);
            }

            $this->auditLogService->log([
                'user_id' => (int) $user['id'],
                'action' => 'multipart_upload_completed',
                'operation_category' => 'file_management',
                'affected_table' => 'files',
                'status' => 'success',
                'data' => [
                    'upload_id' => $uploadId,
                    'file_path' => $filePath,
                    'ownership_persisted' => $ownershipPersisted,
                ],
            ]);

            $result['ownership_persisted'] = $ownershipPersisted;

            return $this->jsonResponse($response, ['success' => true, 'data' => $result]);
        } catch (FileOwnershipConflictException $e) {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            $this->auditLogService->log([
                'user_id' => $userId,
                'action' => 'multipart_upload_completed',
                'operation_category' => 'file_management',
                'affected_table' => 'files',
                'status' => 'failed',
                'data' => [
                    'file_path' => $body['file_path'] ?? null,
                    'upload_id' => $body['upload_id'] ?? null,
                    'error' => $e->getMessage(),
                    'error_code' => 'FILE_OWNERSHIP_CONFLICT',
                ],
            ]);
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'File ownership conflict detected',
                'code' => 'FILE_OWNERSHIP_CONFLICT'
            ], 409);
        } catch (\Exception $e) {
            $userId = isset($user['id']) ? (int) $user['id'] : null;
            $this->auditLogService->log([
                'user_id' => $userId,
                'action' => 'multipart_upload_completed',
                'operation_category' => 'file_management',
                'affected_table' => 'files',
                'status' => 'failed',
                'data' => [
                    'file_path' => $body['file_path'] ?? null,
                    'upload_id' => $body['upload_id'] ?? null,
                    'error' => $e->getMessage(),
                ],
            ]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to complete multipart'], 500);
        }
    }

    /**
     * 取消分片上传
     */
    public function abortMultipartUpload(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $body = $request->getParsedBody() ?: [];
            $filePath = $body['file_path'] ?? '';
            $uploadId = $body['upload_id'] ?? '';
            if ($filePath === '' || $uploadId === '') {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Invalid params'], 400);
            }
            $multipartError = $this->authorizeMultipartUpload($response, $user, $uploadId, $filePath);
            if ($multipartError instanceof Response) {
                return $multipartError;
            }
            $ok = $this->r2Service->abortMultipartUpload($filePath, $uploadId);
            if ($ok) {
                $this->multipartUploadService->clearUpload($uploadId);
            }
            return $this->jsonResponse($response, ['success' => $ok, 'message' => $ok ? 'Aborted' : 'Abort failed']);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { $this->logger->error('ErrorLogService failed: ' . $ignore->getMessage()); }
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Failed to abort multipart'], 500);
        }
    }

    /**
     * 验证目录名是否有效
     */

    private function isValidDirectory(string $directory): bool
    {
        // 允许的根目录名称（可附带子目录段）
        $allowedRoots = [
            'uploads',
            'avatars',
            'activities',
            'products',
            'badges',
            'temp',
            'documents'
        ];

        $normalized = trim($directory);
        if ($normalized === '') {
            return false;
        }

        $normalized = trim($normalized, " /\t\n\r\0\x0B");
        if ($normalized === '') {
            return false;
        }

        $segments = explode('/', $normalized);
        $root = array_shift($segments);

        if (!in_array($root, $allowedRoots, true)) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return false;
            }
        }

        return true;
    }

    private function canReadFile(array $user, string $filePath, ?array $fileInfo = null): bool
    {
        if ($this->isAdminUser($user) || $this->fileMetadataService->isPubliclyReadablePath($filePath)) {
            return true;
        }

        return $this->isFileOwner($user, $filePath, $fileInfo);
    }

    private function canDeleteFile(array $user, string $filePath, ?array $fileInfo = null): bool
    {
        if ($this->isAdminUser($user)) {
            return true;
        }

        return $this->isFileOwner($user, $filePath, $fileInfo);
    }

    private function isFileOwner(array $user, string $filePath, ?array $fileInfo = null): bool
    {
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            return false;
        }

        $fileRecord = $this->fileMetadataService->findByFilePath($filePath);
        if ($fileRecord && (int) ($fileRecord->user_id ?? 0) === $userId) {
            return true;
        }

        $fileInfo ??= $this->r2Service->getFileInfo($filePath);
        $ownerId = (int) ($fileInfo['metadata']['uploaded_by'] ?? 0);

        return $ownerId > 0 && $ownerId === $userId;
    }

    private function isAdminUser(array $user): bool
    {
        return !empty($user['is_admin']);
    }

    private function buildDirectUploadMetadata(array $user, ?string $entityType, ?int $entityId): array
    {
        return [
            'uploaded_by' => (string) ((int) ($user['id'] ?? 0)),
            'entity_type' => $entityType ?: 'unknown',
            'entity_id' => $entityId !== null ? (string) $entityId : '',
            'upload_time' => date('Y-m-d H:i:s'),
        ];
    }

    private function isOwnedFileRecord(File $fileRecord, int $userId): bool
    {
        $existingOwnerId = (int) ($fileRecord->user_id ?? 0);

        return $userId > 0 && $existingOwnerId > 0 && $existingOwnerId === $userId;
    }

    private function persistDirectUploadOwnership(string $filePath, int $userId, string $originalName, array $fileInfo, ?string $sha256 = null): array
    {
        $fileRecord = $this->fileMetadataService->findByFilePath($filePath);
        if ($fileRecord) {
            $updated = $this->syncDirectUploadFileRecord($fileRecord, $filePath, $userId, $originalName, $fileInfo, $sha256);
            return ['file' => $updated, 'duplicate' => false];
        }

        $duplicated = false;
        $persistedSha256 = $sha256;
        $digestClearedForConflict = false;
        if ($sha256) {
            $existing = $this->fileMetadataService->findBySha256($sha256);
            if ($existing && $existing->file_path === $filePath && $this->isOwnedFileRecord($existing, $userId)) {
                return [
                    'file' => $this->fileMetadataService->incrementReference($existing),
                    'duplicate' => true,
                ];
            }

            if ($existing) {
                $persistedSha256 = null;
                $digestClearedForConflict = true;
            }
        }

        $fileRecord = $this->fileMetadataService->createRecord(
            $this->buildDirectUploadFileRecordData(
                $filePath,
                $userId,
                $originalName,
                $fileInfo,
                $persistedSha256,
                !$digestClearedForConflict
            )
        );

        return ['file' => $fileRecord, 'duplicate' => $duplicated];
    }

    private function syncDirectUploadFileRecord(File $fileRecord, string $filePath, int $userId, string $originalName, array $fileInfo, ?string $sha256 = null): File
    {
        $existingOwnerId = (int) ($fileRecord->user_id ?? 0);
        if ($existingOwnerId > 0 && $existingOwnerId !== $userId) {
            throw new FileOwnershipConflictException('File ownership conflict detected for direct upload');
        }

        $recordData = $this->buildDirectUploadFileRecordData($filePath, $userId, $originalName, $fileInfo, $sha256);
        $fileRecord->user_id = $userId;
        $fileRecord->original_name = $fileRecord->original_name ?: $recordData['original_name'];
        $fileRecord->mime_type = $fileRecord->mime_type ?: $recordData['mime_type'];
        $fileRecord->size = (int) ($fileRecord->size ?? 0) > 0 ? $fileRecord->size : $recordData['size'];
        $fileRecord->reference_count = (int) ($fileRecord->reference_count ?? 0) > 0 ? $fileRecord->reference_count : 1;
        if (!empty($recordData['sha256']) && empty($fileRecord->sha256)) {
            $fileRecord->sha256 = $recordData['sha256'];
        }
        $fileRecord->save();

        return $fileRecord;
    }

    private function buildDirectUploadFileRecordData(
        string $filePath,
        int $userId,
        string $originalName,
        array $fileInfo,
        ?string $sha256 = null,
        bool $allowMetadataSha256 = true
    ): array
    {
        $recordData = [
            'sha256' => $this->resolveFileRecordSha256($sha256, $filePath, $fileInfo, $originalName, $allowMetadataSha256),
            'file_path' => $filePath,
            'mime_type' => $fileInfo['mime_type'] ?? null,
            'size' => (int) ($fileInfo['size'] ?? 0),
            'original_name' => $originalName,
            'user_id' => $userId,
            'reference_count' => 1,
        ];

        return $recordData;
    }

    private function persistMultipartOwnership(string $filePath, int $userId, ?array $fileInfo = null, ?string $sha256 = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $fileRecord = $this->fileMetadataService->findByFilePath($filePath);
        if ($fileRecord) {
            return $this->syncExistingMultipartOwnership($fileRecord, $filePath, $userId, $fileInfo, $sha256);
        }

        if (!$sha256) {
            throw new \InvalidArgumentException('Missing sha256 for multipart upload ownership persistence');
        }

        $persistedSha256 = $sha256;
        $digestClearedForConflict = false;
        $existing = $this->fileMetadataService->findBySha256($sha256);
        if ($existing) {
            $persistedSha256 = null;
            $digestClearedForConflict = true;
        }

        $this->fileMetadataService->createRecord(
            $this->buildMultipartFileRecordData(
                $filePath,
                $userId,
                $fileInfo,
                $persistedSha256,
                !$digestClearedForConflict
            )
        );

        return true;
    }

    private function syncExistingMultipartOwnership(File $fileRecord, string $filePath, int $userId, ?array $fileInfo = null, ?string $sha256 = null): bool
    {
        $existingOwnerId = (int) ($fileRecord->user_id ?? 0);
        if ($existingOwnerId > 0 && $existingOwnerId !== $userId) {
            throw new FileOwnershipConflictException('File ownership conflict detected for multipart upload');
        }

        if ($existingOwnerId === $userId) {
            return true;
        }

        $recordData = $this->buildMultipartFileRecordData($filePath, $userId, $fileInfo, $sha256);
        $fileRecord->user_id = $userId;
        $fileRecord->original_name = $fileRecord->original_name ?: $recordData['original_name'];
        $fileRecord->mime_type = $fileRecord->mime_type ?: $recordData['mime_type'];
        $fileRecord->size = (int) ($fileRecord->size ?? 0) > 0 ? $fileRecord->size : $recordData['size'];
        $fileRecord->reference_count = (int) ($fileRecord->reference_count ?? 0) > 0 ? $fileRecord->reference_count : 1;
        if (!empty($recordData['sha256']) && empty($fileRecord->sha256)) {
            $fileRecord->sha256 = $recordData['sha256'];
        }
        $fileRecord->save();

        return true;
    }

    private function buildMultipartFileRecordData(
        string $filePath,
        int $userId,
        ?array $fileInfo = null,
        ?string $sha256 = null,
        bool $allowMetadataSha256 = true
    ): array
    {
        $originalName = (string) ($fileInfo['metadata']['original_name'] ?? basename($filePath));
        $recordData = [
            'sha256' => $this->resolveFileRecordSha256($sha256, $filePath, $fileInfo ?? [], $originalName, $allowMetadataSha256),
            'file_path' => $filePath,
            'mime_type' => $fileInfo['mime_type'] ?? null,
            'size' => isset($fileInfo['size']) ? (int) $fileInfo['size'] : 0,
            'original_name' => $originalName,
            'user_id' => $userId,
            'reference_count' => 1,
        ];

        return $recordData;
    }

    private function resolveFileRecordSha256(
        ?string $sha256,
        string $filePath,
        array $fileInfo,
        string $originalName,
        bool $allowMetadataSha256 = true
    ): string
    {
        $candidate = is_string($sha256) ? strtolower(trim($sha256)) : '';
        if ($candidate !== '' && preg_match('/^[a-f0-9]{64}$/', $candidate)) {
            return $candidate;
        }

        if ($allowMetadataSha256) {
            $metadataSha256 = strtolower(trim((string) ($fileInfo['metadata']['sha256'] ?? '')));
            if ($metadataSha256 !== '' && preg_match('/^[a-f0-9]{64}$/', $metadataSha256)) {
                return $metadataSha256;
            }
        }

        return hash('sha256', json_encode([
            'file_path' => $filePath,
            'etag' => (string) ($fileInfo['etag'] ?? ''),
            'size' => (int) ($fileInfo['size'] ?? 0),
            'mime_type' => (string) ($fileInfo['mime_type'] ?? ''),
            'original_name' => $originalName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function authorizeMultipartUpload(Response $response, array $user, string $uploadId, string $filePath): ?Response
    {
        $upload = $this->multipartUploadService->findActiveUpload($uploadId);
        if (!$upload) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Multipart upload not found',
                'code' => 'MULTIPART_UPLOAD_NOT_FOUND'
            ], 404);
        }

        if ($upload->file_path !== $filePath) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'You do not have permission to access this multipart upload',
                'code' => 'MULTIPART_ACCESS_DENIED'
            ], 403);
        }

        if (!$this->isAdminUser($user) && (int) $upload->user_id !== (int) ($user['id'] ?? 0)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'You do not have permission to access this multipart upload',
                'code' => 'MULTIPART_ACCESS_DENIED'
            ], 403);
        }

        return null;
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

    /**
     * R2 诊断信息
     */
    public function r2Diagnostics(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $data = $this->r2Service->diagnostics();
            return $this->jsonResponse($response, ['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Diagnostics failed: ' . $e->getMessage()], 500);
        }
    }
}

