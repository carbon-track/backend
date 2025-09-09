<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Monolog\Logger;
use Psr\Http\Message\UploadedFileInterface;

class CloudflareR2Service
{
    private S3Client $s3Client;
    private Logger $logger;
    private string $bucketName;
    private string $publicUrl;
    private AuditLogService $auditLogService;

    // 允许的图片类型
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    // 允许的文件扩展名
    private const ALLOWED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp'
    ];

    // 最大文件大小 (5MB)
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;

    public function __construct(
        string $accessKeyId,
        string $secretAccessKey,
        string $endpoint,
        string $bucketName,
        ?string $publicUrl,
        Logger $logger,
        AuditLogService $auditLogService
    ) {
        $this->bucketName = $bucketName;
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;

    // 是否禁用 TLS 校验（仅用于开发/诊断）
    $disableVerify = !empty($_ENV['R2_DISABLE_TLS_VERIFY']);

        // 初始化S3客户端（兼容Cloudflare R2）
        $this->s3Client = new S3Client([
            'version' => 'latest',
            'region' => 'auto', // R2使用auto region
            'endpoint' => $endpoint,
            'credentials' => [
                'key' => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
            'use_path_style_endpoint' => true,
            'http' => [
                'timeout' => 30,
                'connect_timeout' => 10,
            ]
        ]);

        // 直接在底层 guzzle 客户端上设置 verify (S3Client 支持透传 'verify' 配置)
        if ($disableVerify) {
            try {
                $this->s3Client = new S3Client([
                    'version' => 'latest',
                    'region' => 'auto',
                    'endpoint' => $endpoint,
                    'credentials' => [
                        'key' => $accessKeyId,
                        'secret' => $secretAccessKey,
                    ],
                    'use_path_style_endpoint' => true,
                    'http' => [
                        'timeout' => 30,
                        'connect_timeout' => 10,
                    ],
                    'verify' => false
                ]);
                $this->logger->warning('R2 TLS certificate verification DISABLED (R2_DISABLE_TLS_VERIFY=1). Do not use in production.');
            } catch (\Throwable $e) {
                $this->logger->error('Failed to recreate S3Client with verify=false', ['error' => $e->getMessage()]);
            }
        }

        // 计算公共访问基地址
        $derivedBase = $this->derivePublicBase($endpoint, $bucketName);
        $finalPublicUrl = $publicUrl ? rtrim($publicUrl, '/') : $derivedBase;
        $this->publicUrl = $finalPublicUrl;

        if (!$publicUrl) {
            // 记录一次警告，提示使用了推导的公共URL
            try {
                $this->logger->warning('R2 public base URL is not configured. Using derived fallback.', [
                    'derived_public_base' => $derivedBase,
                    'endpoint' => $endpoint,
                    'bucket' => $bucketName
                ]);
            } catch (\Throwable $ignore) {}
        }
    }

    /**
     * 暴露允许的 MIME 类型（只读）
     */
    public function getAllowedMimeTypes(): array
    {
        return self::ALLOWED_MIME_TYPES;
    }

    /**
     * 暴露允许的扩展名（只读）
     */
    public function getAllowedExtensions(): array
    {
        return self::ALLOWED_EXTENSIONS;
    }

    /**
     * 获取最大文件大小（字节）
     */
    public function getMaxFileSize(): int
    {
        return self::MAX_FILE_SIZE;
    }

    public function getBucketName(): string
    {
        return $this->bucketName;
    }

    /**
     * 生成用于前端直接上传的对象 key （不立即上传）
     * @param string $originalName 原始文件名
     * @param string $directory 目标目录
     * @return array{file_name:string,file_path:string,public_url:string}
     */
    public function generateDirectUploadKey(string $originalName, string $directory = 'uploads'): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        // 复用内部的文件名生成逻辑（复制一份以避免修改私有方法签名）
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $fileName = $uuid . '.' . $extension;
        $date = date('Y/m/d');
        $filePath = trim($directory, '/') . '/' . $date . '/' . $fileName;
        return [
            'file_name' => $fileName,
            'file_path' => $filePath,
            'public_url' => $this->getPublicUrl($filePath)
        ];
    }

    /**
     * 为 PUT 上传生成预签名 URL（前端直传）
     * @param string $filePath 对象 key
     * @param string $contentType 内容类型
     * @param int $expiresIn 过期秒数（默认 600，最大 3600）
     * @return array{url:string,method:string,headers:array,expires_in:int,expires_at:string}
     */
    public function generateUploadPresignedUrl(string $filePath, string $contentType, int $expiresIn = 600): array
    {
        $expiresIn = max(60, min($expiresIn, 3600));
        try {
            $command = $this->s3Client->getCommand('PutObject', [
                'Bucket' => $this->bucketName,
                'Key' => $filePath,
                'ContentType' => $contentType
            ]);
            $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");
            return [
                'url' => (string)$request->getUri(),
                'method' => 'PUT',
                'headers' => [
                    // 预签名请求必须保持与签名时一致的 Content-Type
                    'Content-Type' => $contentType
                ],
                'expires_in' => $expiresIn,
                'expires_at' => date('Y-m-d H:i:s', time() + $expiresIn)
            ];
        } catch (AwsException $e) {
            $this->logger->error('Failed to generate upload presigned URL', [
                'error' => $e->getMessage(),
                'file_path' => $filePath
            ]);
            throw new \RuntimeException('Failed to generate upload presigned URL: ' . $e->getMessage());
        }
    }

    /**
     * 记录前端直传完成后的审计日志（在确认接口中调用）
     * @param int $userId
     * @param string|null $entityType
     * @param int|null $entityId
     * @param array $fileInfo 从 getFileInfo 获得
     * @param string $originalName 原始文件名
     */
    public function logDirectUploadAudit(int $userId, ?string $entityType, ?int $entityId, array $fileInfo, string $originalName): void
    {
        try {
            $this->auditLogService->log([
                'user_id' => $userId,
                'action' => 'file_uploaded',
                'entity_type' => $entityType ?: 'file',
                'entity_id' => $entityId,
                'new_value' => json_encode([
                    'file_path' => $fileInfo['file_path'] ?? '',
                    'file_size' => $fileInfo['size'] ?? 0,
                    'mime_type' => $fileInfo['mime_type'] ?? '',
                    'original_name' => $originalName,
                    'direct_upload' => true
                ]),
                'notes' => 'Direct file upload to Cloudflare R2 (presigned PUT)'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to log direct upload audit', [
                'error' => $e->getMessage(),
                'file_path' => $fileInfo['file_path'] ?? ''
            ]);
        }
    }

    /**
     * 上传文件到R2
     */
    public function uploadFile(
        UploadedFileInterface $file,
        string $directory = 'uploads',
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        try {
            // 验证文件
            $this->validateFile($file);

            // 生成文件名和路径
            $fileName = $this->generateFileName($file);
            $filePath = $this->generateFilePath($directory, $fileName);

            // 获取文件内容
            $fileContent = $file->getStream()->getContents();

            // 上传到R2
            $result = $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key' => $filePath,
                'Body' => $fileContent,
                'ContentType' => $file->getClientMediaType(),
                'ContentLength' => $file->getSize(),
                'Metadata' => [
                    'original_name' => $file->getClientFilename(),
                    'uploaded_by' => $userId ? (string)$userId : 'anonymous',
                    'entity_type' => $entityType ?: 'unknown',
                    'entity_id' => $entityId ? (string)$entityId : '',
                    'upload_time' => date('Y-m-d H:i:s'),
                ]
            ]);

            $publicUrl = $this->getPublicUrl($filePath);

            $this->logger->info('File uploaded to R2', [
                'file_path' => $filePath,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMediaType(),
                'user_id' => $userId,
                'public_url' => $publicUrl
            ]);

            // 记录审计日志
            if ($userId) {
                $this->auditLogService->log([
                    'user_id' => $userId,
                    'action' => 'file_uploaded',
                    'entity_type' => $entityType ?: 'file',
                    'entity_id' => $entityId,
                    'new_value' => json_encode([
                        'file_path' => $filePath,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getClientMediaType(),
                        'original_name' => $file->getClientFilename()
                    ]),
                    'notes' => 'File uploaded to Cloudflare R2'
                ]);
            }

            return [
                'success' => true,
                'file_path' => $filePath,
                'public_url' => $publicUrl,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getClientMediaType(),
                'original_name' => $file->getClientFilename(),
                'etag' => $result['ETag'] ?? null
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to upload file to R2', [
                'error' => $e->getMessage(),
                'file_name' => $file->getClientFilename(),
                'file_size' => $file->getSize(),
                'user_id' => $userId
            ]);

            throw new \RuntimeException('File upload failed: ' . $e->getMessage());
        }
    }

    /**
     * 删除文件
     */
    public function deleteFile(string $filePath, ?int $userId = null): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key' => $filePath
            ]);

            $this->logger->info('File deleted from R2', [
                'file_path' => $filePath,
                'user_id' => $userId
            ]);

            // 记录审计日志
            if ($userId) {
                $this->auditLogService->log([
                    'user_id' => $userId,
                    'action' => 'file_deleted',
                    'entity_type' => 'file',
                    'old_value' => json_encode(['file_path' => $filePath]),
                    'notes' => 'File deleted from Cloudflare R2'
                ]);
            }

            return true;

        } catch (AwsException $e) {
            $this->logger->error('Failed to delete file from R2', [
                'error' => $e->getMessage(),
                'file_path' => $filePath,
                'user_id' => $userId
            ]);

            return false;
        }
    }

    /**
     * 检查文件是否存在
     */
    public function fileExists(string $filePath): bool
    {
        try {
            $this->s3Client->headObject([
                'Bucket' => $this->bucketName,
                'Key' => $filePath
            ]);

            return true;

        } catch (AwsException $e) {
            return false;
        }
    }

    /**
     * 获取文件信息
     */
    public function getFileInfo(string $filePath): ?array
    {
        try {
            $result = $this->s3Client->headObject([
                'Bucket' => $this->bucketName,
                'Key' => $filePath
            ]);

            return [
                'file_path' => $filePath,
                'public_url' => $this->getPublicUrl($filePath),
                'size' => $result['ContentLength'] ?? 0,
                'mime_type' => $result['ContentType'] ?? 'application/octet-stream',
                'last_modified' => $result['LastModified'] ?? null,
                'etag' => $result['ETag'] ?? null,
                'metadata' => $result['Metadata'] ?? []
            ];

        } catch (AwsException $e) {
            $this->logger->error('Failed to get file info from R2', [
                'error' => $e->getMessage(),
                'file_path' => $filePath
            ]);

            return null;
        }
    }

    /**
     * 生成预签名URL（用于临时访问私有文件）
     */
    public function generatePresignedUrl(string $filePath, int $expiresIn = 600): string
    {
        try {
            $command = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucketName,
                'Key' => $filePath
            ]);

            $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");

            return (string) $request->getUri();

        } catch (AwsException $e) {
            $this->logger->error('Failed to generate presigned URL', [
                'error' => $e->getMessage(),
                'file_path' => $filePath
            ]);

            throw new \RuntimeException('Failed to generate presigned URL: ' . $e->getMessage());
        }
    }

    /**
     * 批量上传文件
     */
    public function uploadMultipleFiles(
        array $files,
        string $directory = 'uploads',
        ?int $userId = null,
        ?string $entityType = null,
        ?int $entityId = null
    ): array {
        $results = [];
        $errors = [];

        foreach ($files as $index => $file) {
            try {
                $result = $this->uploadFile($file, $directory, $userId, $entityType, $entityId);
                $results[] = $result;
            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'file_name' => $file->getClientFilename(),
                    'error' => $e->getMessage()
                ];
            }
        }

        return [
            'success' => count($results),
            'failed' => count($errors),
            'results' => $results,
            'errors' => $errors
        ];
    }

    /**
     * 获取公共URL
     */
    public function getPublicUrl(string $filePath): string
    {
        return $this->publicUrl . '/' . ltrim($filePath, '/');
    }

    /**
     * 根据 endpoint 与 bucket 推导一个公共访问基地址
     * 优先使用 Cloudflare R2 公共域名（pub-<account>.r2.dev/<bucket>），否则回退到 endpoint/<bucket>
     */
    private function derivePublicBase(string $endpoint, string $bucketName): string
    {
        $base = '';

        // 尝试从 endpoint 中解析出 accountId
        $host = '';
        $scheme = 'https';
        $parts = @parse_url($endpoint);
        if (is_array($parts)) {
            $host = $parts['host'] ?? '';
            $scheme = $parts['scheme'] ?? 'https';
        }

        // 匹配 <account>.r2.cloudflarestorage.com
        if ($host && preg_match('/^([a-z0-9]+)\.r2\.cloudflarestorage\.com$/i', $host, $m)) {
            $accountId = $m[1];
            $base = sprintf('https://pub-%s.r2.dev/%s', $accountId, $bucketName);
        } elseif ($host) {
            // 其他自定义或兼容 S3 的 endpoint，尽力拼接
            $endpointTrimmed = rtrim($endpoint, '/');
            $base = $endpointTrimmed . '/' . $bucketName;
        }

        // 确保非空，最差退回根路径，避免返回 null/空导致拼接异常
        if ($base === '') {
            $base = '/' . ltrim($bucketName, '/');
        }

        return rtrim($base, '/');
    }

    /**
     * 验证上传的文件
     */
    private function validateFile(UploadedFileInterface $file): void
    {
        // 检查上传错误
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('File upload error: ' . $this->getUploadErrorMessage($file->getError()));
        }

        // 检查文件大小
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('File size exceeds maximum allowed size of ' . (self::MAX_FILE_SIZE / 1024 / 1024) . 'MB');
        }

        // 检查MIME类型
        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw new \InvalidArgumentException('File type not allowed. Allowed types: ' . implode(', ', self::ALLOWED_MIME_TYPES));
        }

        // 检查文件扩展名
        $fileName = $file->getClientFilename();
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            throw new \InvalidArgumentException('File extension not allowed. Allowed extensions: ' . implode(', ', self::ALLOWED_EXTENSIONS));
        }

        // 检查文件内容（简单的魔数检查）
        $fileContent = $file->getStream()->getContents();
        $file->getStream()->rewind(); // 重置流位置

        if (!$this->isValidImageContent($fileContent, $mimeType)) {
            throw new \InvalidArgumentException('File content does not match the declared MIME type');
        }
    }

    /**
     * 检查文件内容是否为有效图片
     */
    private function isValidImageContent(string $content, string $mimeType): bool
    {
        // 检查文件魔数
        $magicNumbers = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'image/gif' => ["GIF87a", "GIF89a"],
            'image/webp' => ["RIFF"]
        ];

        if (!isset($magicNumbers[$mimeType])) {
            return false;
        }

        foreach ($magicNumbers[$mimeType] as $magic) {
            if (strpos($content, $magic) === 0) {
                return true;
            }
        }

        // 对于WebP，需要额外检查
        if ($mimeType === 'image/webp') {
            return strpos($content, 'RIFF') === 0 && strpos($content, 'WEBP') === 8;
        }

        return false;
    }

    /**
     * 生成唯一文件名
     */
    private function generateFileName(UploadedFileInterface $file): string
    {
        $originalName = $file->getClientFilename();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // 生成UUID作为文件名
        $uuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        return $uuid . '.' . $extension;
    }

    /**
     * 生成文件路径
     */
    private function generateFilePath(string $directory, string $fileName): string
    {
        $date = date('Y/m/d');
        return trim($directory, '/') . '/' . $date . '/' . $fileName;
    }

    /**
     * 获取上传错误信息
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'File exceeds upload_max_filesize directive';
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds MAX_FILE_SIZE directive';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'File upload stopped by extension';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * 清理过期的临时文件
     */
    public function cleanupExpiredFiles(string $directory = 'temp', int $daysOld = 7): int
    {
        try {
            $deletedCount = 0;
            $cutoffDate = new \DateTime("-{$daysOld} days");

            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName,
                'Prefix' => trim($directory, '/') . '/'
            ]);

            if (isset($objects['Contents'])) {
                foreach ($objects['Contents'] as $object) {
                    $lastModified = new \DateTime($object['LastModified']);
                    
                    if ($lastModified < $cutoffDate) {
                        $this->s3Client->deleteObject([
                            'Bucket' => $this->bucketName,
                            'Key' => $object['Key']
                        ]);
                        $deletedCount++;
                    }
                }
            }

            $this->logger->info('Cleaned up expired files', [
                'directory' => $directory,
                'days_old' => $daysOld,
                'deleted_count' => $deletedCount
            ]);

            return $deletedCount;

        } catch (AwsException $e) {
            $this->logger->error('Failed to cleanup expired files', [
                'error' => $e->getMessage(),
                'directory' => $directory
            ]);

            return 0;
        }
    }

    /**
     * 获取存储统计信息
     */
    public function getStorageStats(): array
    {
        try {
            $objects = $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName
            ]);

            $totalSize = 0;
            $fileCount = 0;
            $fileTypes = [];

            if (isset($objects['Contents'])) {
                foreach ($objects['Contents'] as $object) {
                    $totalSize += $object['Size'];
                    $fileCount++;

                    $extension = strtolower(pathinfo($object['Key'], PATHINFO_EXTENSION));
                    $fileTypes[$extension] = ($fileTypes[$extension] ?? 0) + 1;
                }
            }

            return [
                'total_files' => $fileCount,
                'total_size' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'file_types' => $fileTypes,
                'bucket_name' => $this->bucketName
            ];

        } catch (AwsException $e) {
            $this->logger->error('Failed to get storage stats', [
                'error' => $e->getMessage()
            ]);

            return [
                'total_files' => 0,
                'total_size' => 0,
                'total_size_mb' => 0,
                'file_types' => [],
                'bucket_name' => $this->bucketName,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 列出文件（简单分页，最多 1000）
     * @param string|null $prefix 目录前缀
     * @param int $limit
     * @return array
     */
    public function listFiles(?string $prefix = null, int $limit = 100): array
    {
        $limit = max(1, min($limit, 1000));
        try {
            $params = [
                'Bucket' => $this->bucketName,
                'MaxKeys' => $limit
            ];
            if ($prefix) {
                $params['Prefix'] = rtrim($prefix, '/') . '/';
            }
            $result = $this->s3Client->listObjectsV2($params);
            $files = [];
            if (!empty($result['Contents'])) {
                foreach ($result['Contents'] as $obj) {
                    if (isset($obj['Key']) && substr($obj['Key'], -1) !== '/') {
                        $files[] = [
                            'file_path' => $obj['Key'],
                            'size' => $obj['Size'] ?? 0,
                            'last_modified' => $obj['LastModified'] ?? null,
                            'public_url' => $this->getPublicUrl($obj['Key'])
                        ];
                    }
                }
            }
            return [
                'success' => true,
                'files' => $files,
                'count' => count($files)
            ];
        } catch (AwsException $e) {
            $this->logger->error('Failed to list files', ['error' => $e->getMessage(), 'prefix' => $prefix]);
            return [
                'success' => false,
                'files' => [],
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 初始化分片上传
     * @return array{upload_id:string,file_path:string}
     */
    public function initMultipartUpload(string $originalName, string $directory, string $contentType): array
    {
        $keyInfo = $this->generateDirectUploadKey($originalName, $directory);
        try {
            $result = $this->s3Client->createMultipartUpload([
                'Bucket' => $this->bucketName,
                'Key' => $keyInfo['file_path'],
                'ContentType' => $contentType
            ]);
            return [
                'upload_id' => $result['UploadId'],
                'file_path' => $keyInfo['file_path'],
                'public_url' => $keyInfo['public_url']
            ];
        } catch (AwsException $e) {
            $this->logger->error('Failed to init multipart upload', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to init multipart upload: ' . $e->getMessage());
        }
    }

    /**
     * 为指定 part 生成预签名 URL
     * @return array{url:string,part_number:int,headers:array}
     */
    public function generateMultipartPartUrl(string $filePath, string $uploadId, int $partNumber, int $expiresIn = 600): array
    {
        $partNumber = max(1, min($partNumber, 10000));
        $expiresIn = max(60, min($expiresIn, 3600));
        try {
            $command = $this->s3Client->getCommand('UploadPart', [
                'Bucket' => $this->bucketName,
                'Key' => $filePath,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber
            ]);
            $request = $this->s3Client->createPresignedRequest($command, "+{$expiresIn} seconds");
            return [
                'url' => (string)$request->getUri(),
                'part_number' => $partNumber,
                'headers' => []
            ];
        } catch (AwsException $e) {
            $this->logger->error('Failed to generate multipart part URL', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to generate multipart part URL: ' . $e->getMessage());
        }
    }

    /**
     * 完成分片上传
     * @param array<int,array{part_number:int,etag:string}> $parts
     */
    public function completeMultipartUpload(string $filePath, string $uploadId, array $parts): array
    {
        // 组装为 S3 需要的结构
        $normalized = [];
        foreach ($parts as $p) {
            if (!isset($p['part_number'], $p['etag'])) continue;
            $normalized[] = [
                'PartNumber' => (int)$p['part_number'],
                'ETag' => $p['etag']
            ];
        }
        try {
            $result = $this->s3Client->completeMultipartUpload([
                'Bucket' => $this->bucketName,
                'Key' => $filePath,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $normalized
                ]
            ]);
            return [
                'success' => true,
                'file_path' => $filePath,
                'public_url' => $this->getPublicUrl($filePath),
                'etag' => $result['ETag'] ?? null
            ];
        } catch (AwsException $e) {
            $this->logger->error('Failed to complete multipart upload', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to complete multipart upload: ' . $e->getMessage());
        }
    }

    /**
     * 终止分片上传（可用于取消）
     */
    public function abortMultipartUpload(string $filePath, string $uploadId): bool
    {
        try {
            $this->s3Client->abortMultipartUpload([
                'Bucket' => $this->bucketName,
                'Key' => $filePath,
                'UploadId' => $uploadId
            ]);
            return true;
        } catch (AwsException $e) {
            $this->logger->error('Failed to abort multipart upload', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 诊断服务可用性
     */
    public function diagnostics(): array
    {
        $errors = [];
        $checks = [];
        // Bucket list 权限
        try {
            $this->s3Client->listObjectsV2([
                'Bucket' => $this->bucketName,
                'MaxKeys' => 1
            ]);
            $checks['list_objects'] = true;
        } catch (\Throwable $e) {
            $checks['list_objects'] = false;
            $errors[] = 'ListObjects failed: ' . $e->getMessage();
        }
        // 预签名 PUT
        try {
            $tmpKey = 'diagnostics/_probe_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)),0,12) . '.txt';
            $put = $this->generateUploadPresignedUrl($tmpKey, 'text/plain', 120);
            $checks['presign_put'] = true;
            $checks['presign_sample'] = [
                'file_path' => $tmpKey,
                'url_length' => strlen($put['url'])
            ];
        } catch (\Throwable $e) {
            $checks['presign_put'] = false;
            $errors[] = 'Presign failed: ' . $e->getMessage();
        }
        // 计算 endpoint (用于调试展示)
        $endpoint = method_exists($this->s3Client, 'getEndpoint') ? (string)$this->s3Client->getEndpoint() : 'n/a';
        // 解析 endpoint 是否错误地包含 bucketName（导致双重 /bucket/bucket/）
        $parsed = parse_url($endpoint);
        $path = $parsed['path'] ?? '';
        $endpointHasBucketInPath = false;
        $recommendedEndpoint = $endpoint;
        if ($path && trim($path, '/') === $this->bucketName) {
            $endpointHasBucketInPath = true;
            // 去掉多余 path 的推荐写法
            $recommendedEndpoint = rtrim(str_replace('/' . trim($path, '/'), '', $endpoint), '/');
        }
        return [
            'bucket' => $this->bucketName,
            'endpoint' => $endpoint,
            'public_base' => $this->publicUrl,
            'endpoint_has_bucket_path' => $endpointHasBucketInPath,
            'recommended_endpoint' => $recommendedEndpoint,
            'tls_verify' => empty($_ENV['R2_DISABLE_TLS_VERIFY']),
            'checks' => $checks,
            'errors' => $errors,
            'timestamp' => date('c')
        ];
    }
}

