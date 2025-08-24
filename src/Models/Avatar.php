<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use PDO;

class Avatar
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * 获取所有可用头像
     */
    public function getAvailableAvatars(?string $category = null): array
    {
        $sql = "
            SELECT id, uuid, name, description, file_path, thumbnail_path, 
                   category, sort_order, is_default
            FROM avatars 
            WHERE is_active = 1 AND deleted_at IS NULL
        ";
        
        $params = [];
        
        if ($category) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY sort_order ASC, id ASC";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $result;
        } catch (\Exception $e) {
            error_log("Avatar query failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 根据ID获取头像信息
     */
    public function getAvatarById(int $avatarId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, name, description, file_path, thumbnail_path, 
                   category, sort_order, is_default, is_active
            FROM avatars 
            WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$avatarId]);
        
        $avatar = $stmt->fetch(PDO::FETCH_ASSOC);
        return $avatar ?: null;
    }

    /**
     * 根据UUID获取头像信息
     */
    public function getAvatarByUuid(string $uuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, name, description, file_path, thumbnail_path, 
                   category, sort_order, is_default, is_active
            FROM avatars 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        
        $avatar = $stmt->fetch(PDO::FETCH_ASSOC);
        return $avatar ?: null;
    }

    /**
     * 获取默认头像
     */
    public function getDefaultAvatar(): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, uuid, name, description, file_path, thumbnail_path, 
                   category, sort_order, is_default
            FROM avatars 
            WHERE is_default = 1 AND is_active = 1 AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute();
        
        $avatar = $stmt->fetch(PDO::FETCH_ASSOC);
        return $avatar ?: null;
    }

    /**
     * 获取头像分类列表
     */
    public function getAvatarCategories(): array
    {
        $stmt = $this->db->prepare("
            SELECT DISTINCT category, COUNT(*) as count
            FROM avatars 
            WHERE is_active = 1 AND deleted_at IS NULL
            GROUP BY category
            ORDER BY category ASC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 验证头像是否可用
     */
    public function isAvatarAvailable(int $avatarId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM avatars 
            WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
        ");
        $stmt->execute([$avatarId]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    /**
     * 创建新头像（管理员功能）
     */
    public function createAvatar(array $data): int
    {
        $uuid = $this->generateUUID();
        
        $stmt = $this->db->prepare("
            INSERT INTO avatars (
                uuid, name, description, file_path, thumbnail_path, 
                category, sort_order, is_active, is_default, 
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $uuid,
            $data['name'],
            $data['description'] ?? null,
            $data['file_path'],
            $data['thumbnail_path'] ?? null,
            $data['category'] ?? 'default',
            $data['sort_order'] ?? 0,
            $data['is_active'] ?? 1,
            $data['is_default'] ?? 0
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * 更新头像信息（管理员功能）
     */
    public function updateAvatar(int $avatarId, array $data): bool
    {
        $fields = [];
        $params = [];
        
        $allowedFields = [
            'name', 'description', 'file_path', 'thumbnail_path', 
            'category', 'sort_order', 'is_active', 'is_default'
        ];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $avatarId;
        
        $sql = "UPDATE avatars SET " . implode(', ', $fields) . " WHERE id = ? AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * 软删除头像（管理员功能）
     */
    public function deleteAvatar(int $avatarId): bool
    {
        // 检查是否有用户正在使用此头像
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM users 
            WHERE avatar_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$avatarId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            // 如果有用户使用，将他们的头像改为默认头像
            $defaultAvatar = $this->getDefaultAvatar();
            if ($defaultAvatar) {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET avatar_id = ?, updated_at = NOW() 
                    WHERE avatar_id = ? AND deleted_at IS NULL
                ");
                $stmt->execute([$defaultAvatar['id'], $avatarId]);
            }
        }
        
        // 软删除头像
        $stmt = $this->db->prepare("
            UPDATE avatars 
            SET deleted_at = NOW(), updated_at = NOW() 
            WHERE id = ? AND deleted_at IS NULL
        ");
        
        return $stmt->execute([$avatarId]);
    }

    /**
     * 恢复已删除的头像（管理员功能）
     */
    public function restoreAvatar(int $avatarId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE avatars 
            SET deleted_at = NULL, updated_at = NOW() 
            WHERE id = ? AND deleted_at IS NOT NULL
        ");
        
        return $stmt->execute([$avatarId]);
    }

    /**
     * 设置默认头像（管理员功能）
     */
    public function setDefaultAvatar(int $avatarId): bool
    {
        $this->db->beginTransaction();
        
        try {
            // 取消所有头像的默认状态
            $stmt = $this->db->prepare("
                UPDATE avatars 
                SET is_default = 0, updated_at = NOW() 
                WHERE deleted_at IS NULL
            ");
            $stmt->execute();
            
            // 设置新的默认头像
            $stmt = $this->db->prepare("
                UPDATE avatars 
                SET is_default = 1, updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL
            ");
            $stmt->execute([$avatarId]);
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * 批量更新头像排序（管理员功能）
     */
    public function updateSortOrders(array $sortOrders): bool
    {
        $this->db->beginTransaction();
        
        try {
            $stmt = $this->db->prepare("
                UPDATE avatars 
                SET sort_order = ?, updated_at = NOW() 
                WHERE id = ? AND deleted_at IS NULL
            ");
            
            foreach ($sortOrders as $avatarId => $sortOrder) {
                $stmt->execute([$sortOrder, $avatarId]);
            }
            
            $this->db->commit();
            return true;
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * 获取头像使用统计（管理员功能）
     */
    public function getAvatarUsageStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.id,
                a.name,
                a.category,
                COUNT(u.id) as user_count,
                a.is_default,
                a.is_active
            FROM avatars a
            LEFT JOIN users u ON a.id = u.avatar_id AND u.deleted_at IS NULL
            WHERE a.deleted_at IS NULL
            GROUP BY a.id, a.name, a.category, a.is_default, a.is_active
            ORDER BY user_count DESC, a.sort_order ASC
        ");
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 生成UUID
     */
    private function generateUUID(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

