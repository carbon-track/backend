<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\Uuid;
use CarbonTrack\Support\SyntheticRequestFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Http\Message\ServerRequestInterface as Request;
use PDO;

class AuthService
{
    private string $jwtSecret;
    private string $jwtAlgorithm;
    private int $jwtExpiration;
    private ?PDO $db = null;
    private ?AuditLogService $auditLogService;
    private ?ErrorLogService $errorLogService;

    public function __construct(
        string $jwtSecret,
        string $jwtAlgorithm = 'HS256',
        int $jwtExpiration = 86400,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null
    )
    {
        $this->jwtSecret = $jwtSecret;
        $this->jwtAlgorithm = $jwtAlgorithm;
        $this->jwtExpiration = $jwtExpiration;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
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
        $normalizedUser = $this->normalizeAuthenticatedUser($user);
        $subject = $normalizedUser['uuid'] ?? null;
        if ($subject === null && isset($normalizedUser['id'])) {
            $subject = (string) $normalizedUser['id'];
        }
        if ($subject === null || $subject === '') {
            throw new \RuntimeException('Unable to generate token without a stable subject');
        }

        $now = time();
        $payload = [
            'iss' => 'carbontrack',
            'aud' => 'carbontrack-users',
            'iat' => $now,
            'exp' => $now + $this->jwtExpiration,
            'sub' => $subject,
            'user' => [
                'id' => $normalizedUser['id'] ?? null,
                'uuid' => $normalizedUser['uuid'] ?? null,
                'username' => $normalizedUser['username'] ?? null,
                'email' => $normalizedUser['email'] ?? null,
                'points' => (int)($normalizedUser['points'] ?? 0),
                'role' => $normalizedUser['role'] ?? 'user',
                'is_admin' => (bool)($normalizedUser['is_admin'] ?? 0),
                'is_support' => (bool)($normalizedUser['is_support'] ?? false),
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
        if (!$decoded) {
            throw new \RuntimeException('Invalid token');
        }

        $subject = isset($decoded['sub']) ? trim((string) $decoded['sub']) : null;
        $user = [];
        if (isset($decoded['user'])) {
            $user = (array) $decoded['user'];
        } elseif ($subject !== null && $subject !== '') {
            if (Uuid::isValid($subject)) {
                $user['uuid'] = strtolower($subject);
            } elseif (ctype_digit($subject)) {
                $user['id'] = (int) $subject;
            }
        }

        if ($user === []) {
            throw new \RuntimeException('Invalid token');
        }

        $normalizedUser = $this->normalizeAuthenticatedUser($user, $subject);
        return [
            'user_id' => $normalizedUser['id'] ?? null,
            'uuid' => $normalizedUser['uuid'] ?? null,
            'email' => $normalizedUser['email'] ?? null,
            'role' => $normalizedUser['role'] ?? 'user',
            'user' => $normalizedUser,
        ];
    }

    /**
     * 从请求中获取当前用户
     */
    public function getCurrentUser(Request $request): ?array
    {
        $authenticatedUser = $request->getAttribute('authenticated_user');
        if (is_array($authenticatedUser)) {
            return $authenticatedUser;
        }

        $tokenPayload = $request->getAttribute('token_payload');
        if (is_array($tokenPayload) && isset($tokenPayload['user']) && is_array($tokenPayload['user'])) {
            return $tokenPayload['user'];
        }

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
            try {
                $payload = $this->validateToken($token);
                return is_array($payload['user'] ?? null) ? $payload['user'] : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            $payload = $this->validateToken($token);
            return is_array($payload['user'] ?? null) ? $payload['user'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get current user model
     */
    public function getCurrentUserModel(Request $request): ?\CarbonTrack\Models\User
    {
        $userData = $this->getCurrentUser($request);
        if (!$userData) {
            return null;
        }

        $userId = $this->normalizeUserId($userData['id'] ?? null);
        if ($userId !== null) {
            return \CarbonTrack\Models\User::find($userId);
        }

        $userUuid = $this->normalizeUuidValue($userData['uuid'] ?? null);
        if ($userUuid !== null) {
            return \CarbonTrack\Models\User::query()->where('uuid', $userUuid)->first();
        }

        return null;
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
     * Get user ID from request
     * 
     * @param Request $request
     * @return int|null
     */
    public function getUserIdFromRequest(Request $request): ?int
    {
        $user = $this->getCurrentUser($request);
        return $this->normalizeUserId($user['id'] ?? null);
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
        if ($this->db === null) {
            throw new \RuntimeException('Database not set');
        }

        $sql = "SELECT COUNT(*) FROM users WHERE username = ? AND deleted_at IS NULL";
        $params = [$username];

        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return true;
        }
        $stmt->execute($params);

        return $stmt->fetchColumn() == 0;
    }

    /**
     * 检查邮箱是否可用
     */
    public function isEmailAvailable(string $email, ?int $excludeUserId = null): bool
    {
        if ($this->db === null) {
            throw new \RuntimeException('Database not set');
        }

        $sql = "SELECT COUNT(*) FROM users WHERE email = ? AND deleted_at IS NULL";
        $params = [$email];

        if ($excludeUserId) {
            $sql .= " AND id != ?";
            $params[] = $excludeUserId;
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return true;
        }
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
        if ($this->db === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                INSERT INTO login_attempts (username, ip_address, success, attempted_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$username, $ip, $success ? 1 : 0]);
        } catch (\Exception $e) {
            $this->logAudit('auth_login_attempt_record_failed', [
                'username' => $username,
                'ip_address' => $ip,
                'success' => $success,
            ], 'failed');
            $this->logError($e, '/internal/auth/login-attempts', 'Failed to record login attempt', [
                'username' => $username,
                'ip_address' => $ip,
                'success' => $success,
            ]);
            // 记录失败不应该影响主要流程
        }
    }

    /**
     * 检查是否被锁定（防暴力破解）
     */
    public function isAccountLocked(string $username, string $ip): bool
    {
        if ($this->db === null) {
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
            $isLocked = $failedAttempts >= 5;
            if ($isLocked) {
                $this->logAudit('auth_account_locked', [
                    'username' => $username,
                    'ip_address' => $ip,
                    'failed_attempts' => (int) $failedAttempts,
                    'window_minutes' => 15,
                ]);
            }

            return $isLocked;
        } catch (\Exception $e) {
            $this->logAudit('auth_lock_status_check_failed', [
                'username' => $username,
                'ip_address' => $ip,
            ], 'failed');
            $this->logError($e, '/internal/auth/lock-status', 'Failed to check account lock status', [
                'username' => $username,
                'ip_address' => $ip,
            ]);
            return false;
        }
    }

    /**
     * 清理过期的登录尝试记录
     */
    public function cleanupLoginAttempts(): void
    {
        if ($this->db === null) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                DELETE FROM login_attempts 
                WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $deletedCount = $stmt->rowCount();
            if ($deletedCount > 0) {
                $this->logAudit('auth_login_attempts_cleanup_completed', [
                    'deleted_count' => $deletedCount,
                    'retention_hours' => 24,
                ]);
            }
        } catch (\Exception $e) {
            $this->logAudit('auth_login_attempts_cleanup_failed', [
                'retention_hours' => 24,
            ], 'failed');
            $this->logError($e, '/internal/auth/login-attempts/cleanup', 'Failed to cleanup login attempts', [
                'retention_hours' => 24,
            ]);
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

    public function isSupportUser($user): bool
    {
        if (is_array($user)) {
            if (!empty($user['is_admin']) || !empty($user['is_support'])) {
                return true;
            }

            return in_array((string) ($user['role'] ?? 'user'), ['support', 'admin'], true);
        }

        if ($user instanceof Request) {
            $current = $this->getCurrentUser($user);
            return is_array($current) ? $this->isSupportUser($current) : false;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function normalizeUserRoleView(array $user): array
    {
        return $this->normalizeRoleFlags($user);
    }

    /**
     * Normalize a token/user payload into a local authenticated user context.
     *
     * UUID is treated as the stable cross-site subject. The local numeric user ID
     * is resolved lazily from the current site's users table and kept for
     * intra-site business logic.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function normalizeAuthenticatedUser(array $user, ?string $subject = null): array
    {
        $userId = $this->normalizeUserId($user['id'] ?? $user['user_id'] ?? null);
        $userUuid = $this->normalizeUuidValue($user['uuid'] ?? $user['user_uuid'] ?? null);
        $subjectUuid = $this->normalizeUuidValue($subject);

        if ($userUuid === null && $subjectUuid !== null) {
            $userUuid = $subjectUuid;
        }

        if ($userId !== null && $userUuid === null) {
            $userUuid = $this->ensureUserUuidForLocalId($userId, $userUuid) ?? $userUuid;
        }

        $localUser = null;
        if ($userUuid !== null) {
            $localUser = $this->findLocalUserByUuid($userUuid);
            if ($localUser === null) {
                $localUser = $this->provisionLocalUserForUuid($userUuid, $user);
            }
        }

        if ($localUser === null && $userId !== null) {
            $localUser = $this->findLocalUserById($userId);
        }

        if ($localUser !== null) {
            $userId = $this->normalizeUserId($localUser['id'] ?? null) ?? $userId;
            $userUuid = $this->normalizeUuidValue($localUser['uuid'] ?? null) ?? $userUuid;
            $user = array_merge($user, $localUser);
        }

        if ($userId !== null) {
            $user['id'] = $userId;
        }
        if ($userUuid !== null) {
            $user['uuid'] = $userUuid;
        }

        if (array_key_exists('points', $user)) {
            $user['points'] = (int) ($user['points'] ?? 0);
        }

        return $this->normalizeRoleFlags($user);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function normalizeRoleFlags(array $user): array
    {
        $explicitRole = is_string($user['role'] ?? null) ? strtolower(trim((string) $user['role'])) : '';
        if (!in_array($explicitRole, ['user', 'support', 'admin'], true)) {
            $explicitRole = '';
        }

        $isAdmin = !empty($user['is_admin']) || $explicitRole === 'admin';
        $role = $isAdmin ? 'admin' : ($explicitRole !== '' ? $explicitRole : 'user');

        $user['is_admin'] = $isAdmin;
        $user['role'] = $role;
        $user['is_support'] = $isAdmin || $role === 'support';

        return $user;
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : null;
        }

        return null;
    }

    private function normalizeUuidValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));
        if ($trimmed === '' || !Uuid::isValid($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Ensure an existing local user has a persisted UUID.
     */
    private function ensureUserUuidForLocalId(int $userId, ?string $preferredUuid = null): ?string
    {
        if ($this->db === null) {
            return $preferredUuid;
        }

        $row = $this->findLocalUserById($userId);
        if ($row === null) {
            return $preferredUuid;
        }

        $existingUuid = $this->normalizeUuidValue($row['uuid'] ?? null);
        if ($existingUuid !== null) {
            return $existingUuid;
        }

        $finalUuid = $preferredUuid ?? strtolower($this->generateUUID());

        try {
            $stmt = $this->db->prepare('UPDATE users SET uuid = :uuid, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL');
            if (!$stmt) {
                return null;
            }
            $stmt->execute([
                'uuid' => $finalUuid,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $userId,
            ]);
        } catch (\Throwable $e) {
            return null;
        }

        return $finalUuid;
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>|null
     */
    private function provisionLocalUserForUuid(string $userUuid, array $identity): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $candidate = $this->findBindableLocalUser($identity);
        if ($candidate !== null) {
            $candidateId = $this->normalizeUserId($candidate['id'] ?? null);
            $candidateUuid = $this->normalizeUuidValue($candidate['uuid'] ?? null);
            if ($candidateId === null) {
                return null;
            }
            if ($candidateUuid !== null && $candidateUuid !== $userUuid) {
                throw new \RuntimeException('Conflicting UUID for existing local user');
            }

            $stmt = $this->db->prepare('UPDATE users SET uuid = :uuid, updated_at = :updated_at WHERE id = :id');
            if (!$stmt) {
                return null;
            }
            $stmt->execute([
                'uuid' => $userUuid,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $candidateId,
            ]);

            return $this->findLocalUserById($candidateId);
        }

        $username = $this->prepareProvisionedUsername($identity['username'] ?? null, $userUuid);
        $email = $this->prepareProvisionedEmail($identity['email'] ?? null);
        $now = date('Y-m-d H:i:s');
        $password = $this->hashPassword(bin2hex(random_bytes(16)));

        $stmt = $this->db->prepare(
            'INSERT INTO users (uuid, username, email, password, role, status, points, is_admin, created_at, updated_at)
             VALUES (:uuid, :username, :email, :password, :role, :status, :points, :is_admin, :created_at, :updated_at)'
        );
        if (!$stmt) {
            return null;
        }
        $normalizedIdentity = $this->normalizeRoleFlags($identity);
        $stmt->execute([
            'uuid' => $userUuid,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'role' => $normalizedIdentity['role'],
            'status' => isset($identity['status']) && is_string($identity['status']) && trim($identity['status']) !== ''
                ? trim((string) $identity['status'])
                : 'active',
            'points' => 0,
            'is_admin' => !empty($normalizedIdentity['is_admin']) ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findLocalUserById((int) $this->db->lastInsertId());
    }

    /**
     * @param array<string, mixed> $identity
     * @return array<string, mixed>|null
     */
    private function findBindableLocalUser(array $identity): ?array
    {
        $email = $this->prepareProvisionedEmail($identity['email'] ?? null);
        if ($email !== null) {
            $row = $this->findLocalUserByEmail($email);
            if ($row !== null) {
                return $row;
            }
        }

        $username = isset($identity['username']) && is_string($identity['username'])
            ? trim((string) $identity['username'])
            : '';
        if ($username !== '') {
            return $this->findLocalUserByUsername($username);
        }

        return null;
    }

    private function prepareProvisionedEmail(mixed $email): ?string
    {
        if (!is_string($email)) {
            return null;
        }

        $trimmed = trim($email);
        if ($trimmed === '' || !filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $existing = $this->findLocalUserByEmail($trimmed);
        if ($existing !== null) {
            $existingUuid = $this->normalizeUuidValue($existing['uuid'] ?? null);
            if ($existingUuid !== null) {
                return null;
            }
        }

        return $trimmed;
    }

    private function prepareProvisionedUsername(mixed $username, string $userUuid): string
    {
        $base = is_string($username) ? trim($username) : '';
        if ($base === '') {
            $base = 'user-' . substr(str_replace('-', '', $userUuid), 0, 12);
        }

        $candidate = $base;
        $suffix = 1;
        while (!$this->isUsernameAvailable($candidate)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLocalUserById(int $userId): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLocalUserByUuid(string $userUuid): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->execute(['uuid' => $userUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLocalUserByEmail(string $email): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLocalUserByUsername(string $username): ?array
    {
        if ($this->db === null) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = :username AND deleted_at IS NULL LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function logAudit(string $action, array $context = [], string $status = 'success'): void
    {
        if (!$this->auditLogService) {
            return;
        }

        try {
            $this->auditLogService->log([
                'action' => $action,
                'operation_category' => 'authentication',
                'actor_type' => 'system',
                'status' => $status,
                'data' => $context,
            ]);
        } catch (\Throwable $ignore) {
            // ignore audit failures in auth helper service
        }
    }

    private function logError(\Throwable $e, string $path, string $message, array $context = []): void
    {
        if (!$this->errorLogService) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext($path, 'POST', null, [], $context);
            $this->errorLogService->logException($e, $request, ['context_message' => $message] + $context);
        } catch (\Throwable $ignore) {
            // ignore error log failures in auth helper service
        }
    }
}

