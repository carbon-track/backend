<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\MessageService;
use Monolog\Logger;
use PDO;

class AuthController
{
    private AuthService $authService;
    private EmailService $emailService;
    private TurnstileService $turnstileService;
    private AuditLogService $auditLogService;
    private MessageService $messageService;
    private Logger $logger;
    private PDO $db;

    public function __construct(
        AuthService $authService,
        EmailService $emailService,
        TurnstileService $turnstileService,
        AuditLogService $auditLogService,
        MessageService $messageService,
        Logger $logger,
        PDO $db
    ) {
        $this->authService = $authService;
        $this->emailService = $emailService;
        $this->turnstileService = $turnstileService;
        $this->auditLogService = $auditLogService;
        $this->messageService = $messageService;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * 用户注册
     */
    public function register(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            // 验证必需字段
            $requiredFields = ['username', 'email', 'password', 'confirm_password', 'real_name', 'school_id', 'class_name'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }

            // 验证密码确认
            if ($data['password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }

            // 验证Turnstile
            if (!empty($data['turnstile_token'])) {
                $turnstileValid = $this->turnstileService->verify($data['turnstile_token']);
                if (!$turnstileValid) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }

            // 检查用户名是否已存在
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ? AND deleted_at IS NULL");
            $stmt->execute([$data['username']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Username already exists',
                    'code' => 'USERNAME_EXISTS'
                ], 409);
            }

            // 检查邮箱是否已存在
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email already exists',
                    'code' => 'EMAIL_EXISTS'
                ], 409);
            }

            // 验证学校是否存在
            $stmt = $this->db->prepare("SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$data['school_id']]);
            if (!$stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid school ID',
                    'code' => 'INVALID_SCHOOL'
                ], 400);
            }

            // 创建用户
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $uuid = $this->generateUUID();
            
            $stmt = $this->db->prepare("
                INSERT INTO users (
                    uuid, username, email, password_hash, real_name, 
                    school_id, class_name, points, is_admin, 
                    created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW(), NOW())
            ");
            
            $stmt->execute([
                $uuid,
                $data['username'],
                $data['email'],
                $hashedPassword,
                $data['real_name'],
                $data['school_id'],
                $data['class_name']
            ]);

            $userId = $this->db->lastInsertId();

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $userId,
                'action' => 'user_registered',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'new_value' => json_encode([
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'real_name' => $data['real_name'],
                    'school_id' => $data['school_id'],
                    'class_name' => $data['class_name']
                ]),
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'User registration'
            ]);

            // 发送欢迎消息
            $this->messageService->sendMessage([
                'user_id' => $userId,
                'type' => 'welcome',
                'priority' => 'normal',
                'title' => '欢迎加入CarbonTrack！',
                'content' => '欢迎您加入CarbonTrack碳减排追踪平台！开始您的环保之旅，记录每一次碳减排行动，为地球贡献力量。',
                'sender_type' => 'system'
            ]);

            // 发送欢迎邮件
            try {
                $this->emailService->sendWelcomeEmail($data['email'], $data['real_name']);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send welcome email', [
                    'user_id' => $userId,
                    'email' => $data['email'],
                    'error' => $e->getMessage()
                ]);
            }

            $this->logger->info('User registered successfully', [
                'user_id' => $userId,
                'username' => $data['username'],
                'email' => $data['email']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user_id' => $userId,
                    'username' => $data['username'],
                    'email' => $data['email']
                ]
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('User registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Registration failed',
                'code' => 'REGISTRATION_FAILED'
            ], 500);
        }
    }

    /**
     * 用户登录
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            // 验证必需字段
            if (empty($data['username']) || empty($data['password'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Username and password are required',
                    'code' => 'MISSING_CREDENTIALS'
                ], 400);
            }

            // 验证Turnstile
            if (!empty($data['turnstile_token'])) {
                $turnstileValid = $this->turnstileService->verify($data['turnstile_token']);
                if (!$turnstileValid) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }

            // 查找用户
            $stmt = $this->db->prepare("
                SELECT u.*, s.name as school_name 
                FROM users u 
                LEFT JOIN schools s ON u.school_id = s.id 
                WHERE u.username = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$data['username']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || !password_verify($data['password'], $user['password_hash'])) {
                // 记录失败的登录尝试
                $this->auditLogService->log([
                    'action' => 'login_failed',
                    'entity_type' => 'user',
                    'old_value' => json_encode(['username' => $data['username']]),
                    'ip_address' => $this->getClientIP($request),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'notes' => 'Invalid credentials'
                ]);

                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid credentials',
                    'code' => 'INVALID_CREDENTIALS'
                ], 401);
            }

            // 更新最后登录时间
            $stmt = $this->db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            $stmt->execute([$user['id']]);

            // 生成JWT token
            $token = $this->authService->generateToken($user);

            // 记录成功登录
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'user_login',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Successful login'
            ]);

            $this->logger->info('User logged in successfully', [
                'user_id' => $user['id'],
                'username' => $user['username']
            ]);

            // 准备返回的用户信息
            $userInfo = [
                'id' => $user['id'],
                'uuid' => $user['uuid'],
                'username' => $user['username'],
                'email' => $user['email'],
                'real_name' => $user['real_name'],
                'school_id' => $user['school_id'],
                'school_name' => $user['school_name'],
                'class_name' => $user['class_name'],
                'points' => $user['points'],
                'is_admin' => (bool)$user['is_admin'],
                'avatar_url' => $user['avatar_url'],
                'last_login_at' => $user['last_login_at']
            ];

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => $userInfo
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('User login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'username' => $data['username'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Login failed',
                'code' => 'LOGIN_FAILED'
            ], 500);
        }
    }

    /**
     * 用户登出
     */
    public function logout(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            
            if ($user) {
                // 记录登出
                $this->auditLogService->log([
                    'user_id' => $user['id'],
                    'action' => 'user_logout',
                    'entity_type' => 'user',
                    'entity_id' => $user['id'],
                    'ip_address' => $this->getClientIP($request),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'notes' => 'User logout'
                ]);

                $this->logger->info('User logged out', [
                    'user_id' => $user['id'],
                    'username' => $user['username']
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Logout successful'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('User logout failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }

    /**
     * 获取当前用户信息
     */
    public function me(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED'
                ], 401);
            }

            // 获取完整用户信息
            $stmt = $this->db->prepare("
                SELECT u.*, s.name as school_name 
                FROM users u 
                LEFT JOIN schools s ON u.school_id = s.id 
                WHERE u.id = ? AND u.deleted_at IS NULL
            ");
            $stmt->execute([$user['id']]);
            $userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userInfo) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ], 404);
            }

            // 获取未读消息数量
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as unread_count 
                FROM messages 
                WHERE user_id = ? AND is_read = 0 AND deleted_at IS NULL
            ");
            $stmt->execute([$user['id']]);
            $unreadMessages = $stmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

            $userData = [
                'id' => $userInfo['id'],
                'uuid' => $userInfo['uuid'],
                'username' => $userInfo['username'],
                'email' => $userInfo['email'],
                'real_name' => $userInfo['real_name'],
                'school_id' => $userInfo['school_id'],
                'school_name' => $userInfo['school_name'],
                'class_name' => $userInfo['class_name'],
                'points' => $userInfo['points'],
                'is_admin' => (bool)$userInfo['is_admin'],
                'avatar_url' => $userInfo['avatar_url'],
                'last_login_at' => $userInfo['last_login_at'],
                'created_at' => $userInfo['created_at'],
                'unread_messages' => $unreadMessages
            ];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $userData
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get current user failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get user info'
            ], 500);
        }
    }

    /**
     * 忘记密码
     */
    public function forgotPassword(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            if (empty($data['email'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email is required',
                    'code' => 'MISSING_EMAIL'
                ], 400);
            }

            // 验证Turnstile
            if (!empty($data['turnstile_token'])) {
                $turnstileValid = $this->turnstileService->verify($data['turnstile_token']);
                if (!$turnstileValid) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }

            // 查找用户
            $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // 无论用户是否存在，都返回成功消息（安全考虑）
            if ($user) {
                // 生成重置令牌
                $resetToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1小时后过期

                // 保存重置令牌
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET reset_token = ?, reset_token_expires_at = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$resetToken, $expiresAt, $user['id']]);

                // 发送重置密码邮件
                try {
                    $this->emailService->sendPasswordResetEmail(
                        $user['email'], 
                        $user['real_name'], 
                        $resetToken
                    );
                } catch (\Exception $e) {
                    $this->logger->error('Failed to send password reset email', [
                        'user_id' => $user['id'],
                        'email' => $user['email'],
                        'error' => $e->getMessage()
                    ]);
                }

                // 记录审计日志
                $this->auditLogService->log([
                    'user_id' => $user['id'],
                    'action' => 'password_reset_requested',
                    'entity_type' => 'user',
                    'entity_id' => $user['id'],
                    'ip_address' => $this->getClientIP($request),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'notes' => 'Password reset requested'
                ]);

                $this->logger->info('Password reset requested', [
                    'user_id' => $user['id'],
                    'email' => $user['email']
                ]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Forgot password failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'email' => $data['email'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to process password reset request'
            ], 500);
        }
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            // 验证必需字段
            $requiredFields = ['token', 'password', 'confirm_password'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }

            // 验证密码确认
            if ($data['password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }

            // 查找有效的重置令牌
            $stmt = $this->db->prepare("
                SELECT * FROM users 
                WHERE reset_token = ? 
                AND reset_token_expires_at > NOW() 
                AND deleted_at IS NULL
            ");
            $stmt->execute([$data['token']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid or expired reset token',
                    'code' => 'INVALID_TOKEN'
                ], 400);
            }

            // 更新密码
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("
                UPDATE users 
                SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$hashedPassword, $user['id']]);

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'password_reset_completed',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Password reset completed'
            ]);

            // 发送密码重置成功通知
            $this->messageService->sendMessage([
                'user_id' => $user['id'],
                'type' => 'notification',
                'priority' => 'high',
                'title' => '密码重置成功',
                'content' => '您的密码已成功重置。如果这不是您的操作，请立即联系管理员。',
                'sender_type' => 'system'
            ]);

            $this->logger->info('Password reset completed', [
                'user_id' => $user['id'],
                'username' => $user['username']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Password reset successful'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Password reset failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Password reset failed'
            ], 500);
        }
    }

    /**
     * 修改密码
     */
    public function changePassword(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED'
                ], 401);
            }

            $data = $request->getParsedBody();
            
            // 验证必需字段
            $requiredFields = ['current_password', 'new_password', 'confirm_password'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }

            // 验证新密码确认
            if ($data['new_password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'New password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }

            // 获取当前用户信息
            $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$user['id']]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

            // 验证当前密码
            if (!password_verify($data['current_password'], $currentUser['password_hash'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Current password is incorrect',
                    'code' => 'INVALID_CURRENT_PASSWORD'
                ], 400);
            }

            // 更新密码
            $hashedPassword = password_hash($data['new_password'], PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$hashedPassword, $user['id']]);

            // 记录审计日志
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'password_changed',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Password changed by user'
            ]);

            $this->logger->info('Password changed', [
                'user_id' => $user['id'],
                'username' => $user['username']
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Password changed successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Change password failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $user['id'] ?? null
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to change password'
            ], 500);
        }
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

    /**
     * 获取客户端IP地址
     */
    private function getClientIP(Request $request): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $ip = $request->getHeaderLine($header);
                if (!empty($ip) && $ip !== 'unknown') {
                    // 如果有多个IP，取第一个
                    $ip = explode(',', $ip)[0];
                    return trim($ip);
                }
            }
        }

        return '0.0.0.0';
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

