<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class AuthService
{
    private string $jwtSecret;
    private string $jwtAlgorithm;
    private int $jwtExpiration;
    private PDO $db;

    public function __construct(string $jwtSecret, string $jwtAlgorithm = 'HS256', int $jwtExpiration = 86400)
    {
        $this->jwtSecret = $jwtSecret;
        $this->jwtAlgorithm = $jwtAlgorithm;
        $this->jwtExpiration = $jwtExpiration;
    }

    public function setDatabase(PDO $db): void
    {
        $this->db = $db;
    }

    /**
     * 生成JWT令牌
     */
    public function generateToken(array $user): string
    {
        $now = time();
        $payload = [
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => $now,
            'exp' => $now + $this->jwtExpiration,
            'sub' => $user['id'],
            'user' => [
                'id' => $user['id'],
                // uuid 在某些旧库/测试数据库中可能不存在，使用 null 回退
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'is_admin' => (bool)($user['is_admin'] ?? 0)
            ]
        ];

        return JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);
    }

    /**
     * 验证JWT令牌
     */
    public function verifyToken(string $token): ?array
    {
        try {
            // 允许少量时钟偏移，默认 60 秒，可通过环境变量 JWT_LEEWAY 配置
            if (class_exists(\Firebase\JWT\JWT::class)) {
                $leeway = isset($_ENV['JWT_LEEWAY']) ? (int)$_ENV['JWT_LEEWAY'] : 60;
                if ($leeway > 0) {
                    \Firebase\JWT\JWT::$leeway = $leeway;
                }
            }
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
            return (array)$decoded;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Backward-compatible validateToken used by middleware/tests
     * Returns a normalized payload array or throws an exception on failure
     */
    public function validateToken(string $token): array
    {
        $decoded = $this->verifyToken($token);
        if (!$decoded || !isset($decoded['user'])) {
            throw new \RuntimeException('Invalid token');
        }
        $user = (array)$decoded['user'];
        return [
            'user_id' => $user['id'] ?? null,
            'email' => $user['email'] ?? null,
            'role' => ($user['is_admin'] ?? false) ? 'admin' : 'user',
            'user' => $user,
        ];
    }

    /**
     * 从请求中获取当前用户
     */
    public function getCurrentUser(Request $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader)) {
            return null;
        }

        // 检查Bearer token格式
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return null;
        }

        $token = $matches[1];
        $decoded = $this->verifyToken($token);

        if (!$decoded || !isset($decoded['user'])) {
            return null;
        }

        return (array)$decoded['user'];
    }

    /**
     * 检查用户是否为管理员
     */
    public function isAdmin(Request $request): bool
    {
        $user = $this->getCurrentUser($request);
        return $user && $user['is_admin'];
    }

    /**
     * 验证密码强度
     */
    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * 生成安全的随机令牌
     */
    public function generateSecureToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * 验证邮箱格式
     */
    public function validateEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * 检查用户名是否可用
     */
    public function isUsernameAvailable(string $username, ?int $excludeUserId = null): bool
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not set');
        }

        $sql = "SELECT COUNT(*) FROM users WHERE username = ? AND deleted_at IS NULL";
        $params = [$username];

        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() == 0;
    }

    /**
     * 检查邮箱是否可用
     */
    public function isEmailAvailable(string $email, ?int $excludeUserId = null): bool
    {
        if (!$this->db) {
            throw new \RuntimeException('Database not set');
        }

        $sql = "SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NULL";
        $params = [$email];

        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() == 0;
    }

    /**
     * 生成UUID
     */
    public function generateUUID(): string
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

    /**
     * 哈希密码
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * 验证密码
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * 检查令牌是否过期
     */
    public function isTokenExpired(array $decoded): bool
    {
        return isset($decoded['exp']) && $decoded['exp'] < time();
    }

    /**
     * 刷新令牌
     */
    public function refreshToken(string $token): ?string
    {
        $decoded = $this->verifyToken($token);
        
        if (!$decoded || $this->isTokenExpired($decoded)) {
            return null;
        }

        // 如果令牌在30分钟内过期，则刷新
        if ($decoded['exp'] - time() < 1800) {
            $user = (array)$decoded['user'];
            return $this->generateToken($user);
        }

        return $token;
    }

    /**
     * 获取令牌剩余时间
     */
    public function getTokenRemainingTime(string $token): ?int
    {
        $decoded = $this->verifyToken($token);
        
        if (!$decoded || !isset($decoded['exp'])) {
            return null;
        }

        $remaining = $decoded['exp'] - time();
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * 验证用户权限
     */
    public function hasPermission(Request $request, string $permission): bool
    {
        $user = $this->getCurrentUser($request);
        
        if (!$user) {
            return false;
        }

        // 管理员拥有所有权限
        if ($user['is_admin']) {
            return true;
        }

        // 这里可以扩展更复杂的权限系统
        switch ($permission) {
            case 'view_own_data':
                return true;
            case 'edit_own_profile':
                return true;
            case 'submit_carbon_record':
                return true;
            case 'exchange_products':
                return true;
            default:
                return false;
        }
    }

    /**
     * 记录登录尝试
     */
    public function recordLoginAttempt(string $username, string $ip, bool $success): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, success, attempted_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$username, $ip, $success ? 1 : 0]);
        } catch (\Exception $e) {
            // 记录失败不应该影响主要流程
        }
    }

    /**
     * 检查是否被锁定（防暴力破解）
     */
    public function isAccountLocked(string $username, string $ip): bool
    {
        if (!$this->db) {
            return false;
        }

        try {
            // 检查最近15分钟内的失败尝试次数
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM login_attempts 
                WHERE (username = ? OR ip_address = ?) 
                AND success = 0 
                AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ");
            $stmt->execute([$username, $ip]);
            
            $failedAttempts = $stmt->fetchColumn();
            
            // 超过5次失败尝试则锁定
            return $failedAttempts >= 5;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 清理过期的登录尝试记录
     */
    public function cleanupLoginAttempts(): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts 
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
        } catch (\Exception $e) {
            // 清理失败不应该影响主要流程
        }
    }

    /**
     * 生成JWT令牌 (别名方法，用于测试)
     */
    public function generateJwtToken(array $user): string
    {
        return $this->generateToken($user);
    }

    /**
     * 验证JWT令牌 (别名方法，用于测试)
     */
    public function validateJwtToken(string $token): ?array
    {
        $decoded = $this->verifyToken($token);
        if (!$decoded) {
            return null;
        }
        // 统一过期校验：若 exp < 当前时间则视为无效
        if (isset($decoded['exp']) && $decoded['exp'] < time()) {
            return null;
        }
        return $decoded;
    }

    /**
     * 检查用户是否为管理员 (重载方法，支持数组参数用于测试)
     */
    public function isAdminUser($user): bool
    {
        if (is_array($user)) {
            return $user['is_admin'] ?? false;
        }
        
        if ($user instanceof Request) {
            return $this->isAdmin($user);
        }
        
        return false;
    }
}

