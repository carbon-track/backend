<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Models\Avatar;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\ErrorLogService;
use Monolog\Logger;

class AvatarController
{
    private Avatar $avatarModel;
    private AuthService $authService;
    private ?AuditLogService $auditLogService;
    private ?CloudflareR2Service $r2Service;
    private ?Logger $logger;
    private ?ErrorLogService $errorLogService;

    public function __construct(
        Avatar $avatarModel,
        AuthService $authService,
    AuditLogService $auditLogService = null,
    CloudflareR2Service $r2Service = null,
    Logger $logger = null,
    ErrorLogService $errorLogService = null
    ) {
        $this->avatarModel = $avatarModel;
        $this->authService = $authService;
            $this->auditLogService = $auditLogService;
            $this->r2Service = $r2Service;
            $this->logger = $logger;
            $this->errorLogService = $errorLogService;
    }

    /**
     * 获取所有可用头像（用户和管理员都可访问）
     */
    public function getAvatars(Request $request, Response $response): Response
    {
        try {
            $queryParams = $request->getQueryParams();
            $category = $queryParams['category'] ?? null;
            $includeInactive = isset($queryParams['include_inactive']) && $queryParams['include_inactive'] === 'true';

            // 检查是否为管理员（容错：AuthService 可能在匿名请求时抛出异常）
            $user = null;
            $isAdmin = false;
            try {
                $user = $this->authService->getCurrentUser($request);
                $isAdmin = $user && !empty($user['is_admin']);
            } catch (\Throwable $authEx) {
                // 记日志但不影响公开接口返回
                if (isset($this->logger)) {
                    $this->logger->debug('Anonymous avatar listing (auth not resolved)', [
                        'error' => $authEx->getMessage()
                    ]);
                }
            }

            if ($includeInactive && !$isAdmin) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required to view inactive avatars',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatars = $this->avatarModel->getAvailableAvatars($category);
            $avatars = array_map([$this, 'formatAvatar'], array_values($avatars));

            // The database query already filters for active avatars
            // Additional filtering is only needed if admin is requesting inactive avatars

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $avatars
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
            if ($this->logger) {
                $this->logger->error('Get avatars failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get avatars',
                'debug' => getenv('APP_ENV') === 'testing' ? ($e->getMessage()) : null
            ], 500);
        }
    }

    /**
     * 获取头像分类列表
     */
    public function getAvatarCategories(Request $request, Response $response): Response
    {
        try {
            $categories = $this->avatarModel->getAvatarCategories();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            try { if ($this->errorLogService) { $this->errorLogService->logException($e, $request); } } catch (\Throwable $ignore) {}
            if ($this->logger) {
                $this->logger->error('Get avatar categories failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get avatar categories'
            ], 500);
        }
    }

    /**
     * 获取单个头像详情（管理员）
     */
    public function getAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatarId = (int)$args['id'];
            $avatar = $this->avatarModel->getAvatarById($avatarId);

            if (!$avatar) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Avatar not found',
                    'code' => 'AVATAR_NOT_FOUND'
                ], 404);
            }

            $avatar = $this->formatAvatar($avatar);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $avatar
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Get avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'avatar_id' => $args['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get avatar'
            ], 500);
        }
    }

    /**
     * 创建新头像（管理员）
     */
    public function createAvatar(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $data = $request->getParsedBody();

            // 验证必需字段
            $requiredFields = ['name', 'file_path'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }

            // 验证文件路径是否存在（如果是R2路径）
            if (strpos($data['file_path'], '/avatars/') === 0) {
                $filePath = ltrim($data['file_path'], '/');
                if (!$this->r2Service->fileExists($filePath)) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Avatar file does not exist',
                        'code' => 'FILE_NOT_FOUND'
                    ], 400);
                }
            }

            // 创建头像
            $avatarData = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'file_path' => $data['file_path'],
                'thumbnail_path' => $data['thumbnail_path'] ?? null,
                'category' => $data['category'] ?? 'default',
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'] ?? 1,
                'is_default' => $data['is_default'] ?? 0
            ];

            $avatarId = $this->avatarModel->createAvatar($avatarData);

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_created',
                'entity_type' => 'avatar',
                'entity_id' => $avatarId,
                'new_value' => json_encode($avatarData),
                'notes' => 'Avatar created by admin'
            ]);

            $this->logger->info('Avatar created', [
                'avatar_id' => $avatarId,
                'admin_id' => $user['id'],
                'avatar_name' => $data['name']
            ]);

            // 获取创建的头像信息
            $createdAvatar = $this->avatarModel->getAvatarById($avatarId);

            $createdAvatar = $this->formatAvatar($createdAvatar);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Avatar created successfully',
                'data' => $createdAvatar
            ], 201);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Create avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to create avatar'
            ], 500);
        }
    }

    /**
     * 更新头像（管理员）
     */
    public function updateAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatarId = (int)$args['id'];
            $data = $request->getParsedBody();

            // 检查头像是否存在
            $existingAvatar = $this->avatarModel->getAvatarById($avatarId);
            if (!$existingAvatar) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Avatar not found',
                    'code' => 'AVATAR_NOT_FOUND'
                ], 404);
            }

            // 验证文件路径是否存在（如果提供了新的文件路径）
            if (!empty($data['file_path']) && strpos($data['file_path'], '/avatars/') === 0) {
                $filePath = ltrim($data['file_path'], '/');
                if (!$this->r2Service->fileExists($filePath)) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Avatar file does not exist',
                        'code' => 'FILE_NOT_FOUND'
                    ], 400);
                }
            }

            // 准备更新数据
            $updateData = [];
            $allowedFields = [
                'name', 'description', 'file_path', 'thumbnail_path', 
                'category', 'sort_order', 'is_active', 'is_default'
            ];

            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No valid fields to update',
                    'code' => 'NO_UPDATE_DATA'
                ], 400);
            }

            // 如果设置为默认头像，需要特殊处理
            if (isset($updateData['is_default']) && $updateData['is_default']) {
                $this->avatarModel->setDefaultAvatar($avatarId);
                unset($updateData['is_default']); // 从普通更新中移除，因为已经特殊处理
            }

            // 更新头像
            $success = $this->avatarModel->updateAvatar($avatarId, $updateData);

            if (!$success) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to update avatar'
                ], 500);
            }

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_updated',
                'entity_type' => 'avatar',
                'entity_id' => $avatarId,
                'old_value' => json_encode($existingAvatar),
                'new_value' => json_encode($updateData),
                'notes' => 'Avatar updated by admin'
            ]);

            $this->logger->info('Avatar updated', [
                'avatar_id' => $avatarId,
                'admin_id' => $user['id'],
                'updated_fields' => array_keys($updateData)
            ]);

            // 获取更新后的头像信息
            $updatedAvatar = $this->avatarModel->getAvatarById($avatarId);

            $updatedAvatar = $this->formatAvatar($updatedAvatar);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Avatar updated successfully',
                'data' => $updatedAvatar
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Update avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'avatar_id' => $args['id'] ?? null,
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update avatar'
            ], 500);
        }
    }

    /**
     * 删除头像（管理员）
     */
    public function deleteAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatarId = (int)$args['id'];

            // 检查头像是否存在
            $avatar = $this->avatarModel->getAvatarById($avatarId);
            if (!$avatar) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Avatar not found',
                    'code' => 'AVATAR_NOT_FOUND'
                ], 404);
            }

            // 检查是否为默认头像
            if ($avatar['is_default']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Cannot delete default avatar',
                    'code' => 'CANNOT_DELETE_DEFAULT'
                ], 400);
            }

            // 软删除头像
            $success = $this->avatarModel->deleteAvatar($avatarId);

            if (!$success) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to delete avatar'
                ], 500);
            }

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_deleted',
                'entity_type' => 'avatar',
                'entity_id' => $avatarId,
                'old_value' => json_encode($avatar),
                'notes' => 'Avatar deleted by admin'
            ]);

            $this->logger->info('Avatar deleted', [
                'avatar_id' => $avatarId,
                'admin_id' => $user['id'],
                'avatar_name' => $avatar['name']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Avatar deleted successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Delete avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'avatar_id' => $args['id'] ?? null,
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete avatar'
            ], 500);
        }
    }

    /**
     * 恢复已删除的头像（管理员）
     */
    public function restoreAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatarId = (int)$args['id'];

            // 恢复头像
            $success = $this->avatarModel->restoreAvatar($avatarId);

            if (!$success) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to restore avatar or avatar not found'
                ], 500);
            }

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_restored',
                'entity_type' => 'avatar',
                'entity_id' => $avatarId,
                'notes' => 'Avatar restored by admin'
            ]);

            $this->logger->info('Avatar restored', [
                'avatar_id' => $avatarId,
                'admin_id' => $user['id']
            ]);

            // 获取恢复后的头像信息
            $restoredAvatar = $this->avatarModel->getAvatarById($avatarId);

            $restoredAvatar = $this->formatAvatar($restoredAvatar);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Avatar restored successfully',
                'data' => $restoredAvatar
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Restore avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'avatar_id' => $args['id'] ?? null,
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to restore avatar'
            ], 500);
        }
    }

    /**
     * 设置默认头像（管理员）
     */
    public function setDefaultAvatar(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $avatarId = (int)$args['id'];

            // 检查头像是否存在且可用
            if (!$this->avatarModel->isAvatarAvailable($avatarId)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Avatar not found or not available',
                    'code' => 'AVATAR_NOT_AVAILABLE'
                ], 404);
            }

            // 设置默认头像
            $success = $this->avatarModel->setDefaultAvatar($avatarId);

            if (!$success) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to set default avatar'
                ], 500);
            }

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'default_avatar_changed',
                'entity_type' => 'avatar',
                'entity_id' => $avatarId,
                'notes' => 'Default avatar changed by admin'
            ]);

            $this->logger->info('Default avatar changed', [
                'avatar_id' => $avatarId,
                'admin_id' => $user['id']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Default avatar set successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Set default avatar failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'avatar_id' => $args['id'] ?? null,
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to set default avatar'
            ], 500);
        }
    }

    /**
     * 批量更新头像排序（管理员）
     */
    public function updateSortOrders(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $data = $request->getParsedBody();

            if (empty($data['sort_orders']) || !is_array($data['sort_orders'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid sort orders data',
                    'code' => 'INVALID_DATA'
                ], 400);
            }

            // 验证数据格式
            $sortOrders = [];
            foreach ($data['sort_orders'] as $item) {
                if (!isset($item['id']) || !isset($item['sort_order'])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Invalid sort order item format',
                        'code' => 'INVALID_FORMAT'
                    ], 400);
                }
                $sortOrders[(int)$item['id']] = (int)$item['sort_order'];
            }

            // 更新排序
            $success = $this->avatarModel->updateSortOrders($sortOrders);

            if (!$success) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Failed to update sort orders'
                ], 500);
            }

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_sort_orders_updated',
                'entity_type' => 'avatar',
                'new_value' => json_encode($sortOrders),
                'notes' => 'Avatar sort orders updated by admin'
            ]);

            $this->logger->info('Avatar sort orders updated', [
                'admin_id' => $user['id'],
                'updated_count' => count($sortOrders)
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Sort orders updated successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Update sort orders failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update sort orders'
            ], 500);
        }
    }

    /**
     * 获取头像使用统计（管理员）
     */
    public function getAvatarUsageStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            $stats = $this->avatarModel->getAvatarUsageStats();

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Get avatar usage stats failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get avatar usage stats'
            ], 500);
        }
    }

    /**
     * 上传头像文件（管理员）
     */
    public function uploadAvatarFile(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$user['is_admin']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Admin access required',
                    'code' => 'ADMIN_REQUIRED'
                ], 403);
            }

            // 获取上传的文件
            $uploadedFiles = $request->getUploadedFiles();
            if (empty($uploadedFiles['avatar'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'No avatar file uploaded',
                    'code' => 'NO_FILE'
                ], 400);
            }

            $file = $uploadedFiles['avatar'];
            
            // 获取请求参数
            $body = $request->getParsedBody();
            $category = $body['category'] ?? 'default';

            // 上传文件到R2
            $result = $this->r2Service->uploadFile(
                $file,
                "avatars/{$category}",
                $user['id'],
                'avatar',
                null
            );

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'avatar_file_uploaded',
                'entity_type' => 'file',
                'new_value' => json_encode([
                    'file_path' => $result['file_path'],
                    'public_url' => $result['public_url'],
                    'category' => $category
                ]),
                'notes' => 'Avatar file uploaded by admin'
            ]);

            $this->logger->info('Avatar file uploaded', [
                'admin_id' => $user['id'],
                'file_path' => $result['file_path'],
                'category' => $category
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Avatar file uploaded successfully',
                'data' => [
                    'file_path' => '/' . $result['file_path'],
                    'public_url' => $result['public_url'],
                    'file_size' => $result['file_size'],
                    'mime_type' => $result['mime_type']
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            $this->logger->error('Upload avatar file failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'admin_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to upload avatar file'
            ], 500);
        }
    }

    /**
     * Enrich avatar payload with derived URLs for frontend consumption.
     */
    private function formatAvatar(array $avatar): array
    {
        $filePath = $avatar['file_path'] ?? null;
        if ($filePath) {
            $normalizedPath = ltrim((string)$filePath, '/');
            $avatar['icon_path'] = $normalizedPath;
            if ($this->r2Service) {
                try {
                    $avatar['icon_url'] = $this->r2Service->getPublicUrl($normalizedPath);
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->warning('Failed to build avatar icon public URL', [
                            'error' => $e->getMessage(),
                            'file_path' => $normalizedPath
                        ]);
                    }
                }
                try {
                    $avatar['icon_presigned_url'] = $this->r2Service->generatePresignedUrl($normalizedPath, 600);
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->warning('Failed to build avatar icon presigned URL', [
                            'error' => $e->getMessage(),
                            'file_path' => $normalizedPath
                        ]);
                    }
                }
            }
            if (!isset($avatar['image_url']) || !$avatar['image_url']) {
                $avatar['image_url'] = $avatar['icon_url'] ?? $filePath;
            }
            if (!isset($avatar['url']) || !$avatar['url']) {
                $avatar['url'] = $avatar['icon_url'] ?? ($avatar['image_url'] ?? $filePath);
            }
        }

        $thumbnailPath = $avatar['thumbnail_path'] ?? null;
        if ($thumbnailPath) {
            $normalizedThumb = ltrim((string)$thumbnailPath, '/');
            if ($this->r2Service) {
                try {
                    $avatar['thumbnail_url'] = $this->r2Service->getPublicUrl($normalizedThumb);
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->warning('Failed to build avatar thumbnail URL', [
                            'error' => $e->getMessage(),
                            'file_path' => $normalizedThumb
                        ]);
                    }
                }
            }
        }

        return $avatar;
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

