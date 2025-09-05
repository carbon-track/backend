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
    public function generatePresignedUrl(string $filePath, int $expiresIn = 3600): string
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
}

