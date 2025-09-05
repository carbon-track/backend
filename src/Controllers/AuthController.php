<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\MessageService;
use Monolog\Logger;
use PDO;

class AuthController
{
    private AuthService $authService;
    private EmailService $emailService;
    private TurnstileService $turnstileService;
    private AuditLogService $auditLogService;
    private ErrorLogService $errorLogService;
    private MessageService $messageService;
    private Logger $logger;
    private PDO $db;

    public function __construct(
        AuthService $authService,
        EmailService $emailService,
        TurnstileService $turnstileService,
        AuditLogService $auditLogService,
        ErrorLogService $errorLogService,
        MessageService $messageService,
        Logger $logger,
        PDO $db
    ) {
        $this->authService = $authService;
        $this->emailService = $emailService;
        $this->turnstileService = $turnstileService;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->messageService = $messageService;
        $this->logger = $logger;
        $this->db = $db;
    }

    public function register(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $required = ['username', 'email', 'password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }
            if ($data['password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }
            if (!empty($data['cf_turnstile_response'])) {
                if (!$this->turnstileService->verify((string)$data['cf_turnstile_response'])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }
            $stmt = $this->db->prepare('SELECT id FROM users WHERE username = ? AND deleted_at IS NULL');
            $stmt->execute([$data['username']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Username already exists',
                    'code' => 'USERNAME_EXISTS'
                ], 409);
            }
            $stmt = $this->db->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL');
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Email already exists',
                    'code' => 'EMAIL_EXISTS'
                ], 409);
            }
            if (!empty($data['school_id'])) {
                $stmt = $this->db->prepare('SELECT id FROM schools WHERE id = ? AND deleted_at IS NULL');
                $stmt->execute([$data['school_id']]);
                if (!$stmt->fetch()) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Invalid school ID',
                        'code' => 'INVALID_SCHOOL'
                    ], 400);
                }
            }
            $hashed = password_hash((string)$data['password'], PASSWORD_DEFAULT);
            // 为兼容旧库，这里优先写入 password 列
            $stmt = $this->db->prepare('INSERT INTO users (username, email, password, school_id, class_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $data['username'],
                $data['email'],
                $hashed,
                $data['school_id'] ?? null,
                $data['class_name'] ?? null,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s')
            ]);
            $userId = (int)$this->db->lastInsertId();
            $this->auditLogService->log([
                'user_id' => $userId,
                'action' => 'user_registered',
                'entity_type' => 'user',
                'entity_id' => $userId,
                'new_value' => json_encode([
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'school_id' => $data['school_id'] ?? null,
                    'class_name' => $data['class_name'] ?? null
                ]),
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'User registration'
            ]);
            try {
                $this->emailService->sendWelcomeEmail((string)$data['email'], (string)$data['username']);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send welcome email', ['error' => $e->getMessage()]);
            }
            // 发送站内欢迎消息（与数据库字段对齐，仅使用 title/content/is_read/receiver_id）
            try {
                $title = '欢迎加入CarbonTrack! / Welcome to CarbonTrack!';
                $content = "亲爱的用户，欢迎加入CarbonTrack碳减排追踪平台！\n\n" .
                           "在这里，您可以：\n" .
                           "• 记录您的碳减排活动\n" .
                           "• 获得碳减排积分\n" .
                           "• 兑换环保商品\n" .
                           "• 查看您的环保贡献\n\n" .
                           "让我们一起为地球环保贡献力量！\n\n" .
                           "Dear user, welcome to CarbonTrack!\n\n" .
                           "Here you can:\n" .
                           "• Record your carbon reduction activities\n" .
                           "• Earn carbon reduction points\n" .
                           "• Exchange for eco-friendly products\n" .
                           "• View your environmental contributions\n\n" .
                           "Let's work together for a greener planet!";
                // type/priority 在当前 schema 中未持久化，传入值仅用于审计日志/兼容
                $this->messageService->sendSystemMessage(
                    $userId,
                    $title,
                    $content,
                    'welcome',
                    'normal'
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to send welcome in-app message', ['error' => $e->getMessage()]);
            }
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'User registered successfully',
                'data' => [
                    'user_id' => $userId,
                    'username' => $data['username'],
                    'email' => $data['email']
                ]
            ], 201);
        } catch (\Throwable $e) {
            $this->logger->error('User registration failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Registration failed',
                'code' => 'REGISTRATION_FAILED'
            ], 500);
        }
    }

    public function login(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            // 兼容 identifier / username / email 三种输入
            $identifier = $data['identifier'] ?? ($data['username'] ?? ($data['email'] ?? null));
            if (empty($identifier) || empty($data['password'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Identifier and password are required',
                    'code' => 'MISSING_CREDENTIALS'
                ], 400);
            }
            if (!empty($data['cf_turnstile_response'])) {
                if (!$this->turnstileService->verify((string)$data['cf_turnstile_response'])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
            $field = $isEmail ? 'u.email' : 'u.username';
            $stmt = $this->db->prepare("SELECT u.*, s.name as school_name FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE {$field} = ? AND u.deleted_at IS NULL");
            $stmt->execute([$identifier]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $passwordField = null;
            if ($user) {
                if (!empty($user['password_hash'])) {
                    $passwordField = 'password_hash';
                } elseif (!empty($user['password'])) {
                    $passwordField = 'password';
                }
            }
            if (!$user || !$passwordField || !password_verify((string)$data['password'], (string)$user[$passwordField])) {
                $this->auditLogService->log([
                    'action' => 'login_failed',
                    'entity_type' => 'user',
                    'old_value' => json_encode(['identifier' => $identifier]),
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
            try {
                $upd = $this->db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
                $upd->execute([$user['id']]);
            } catch (\Throwable $e) {
                try {
                    $upd = $this->db->prepare('UPDATE users SET lastlgn = NOW() WHERE id = ?');
                    $upd->execute([$user['id']]);
                } catch (\Throwable $e2) {
                    // ignore
                }
            }
            $token = $this->authService->generateToken($user);
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'user_login',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Successful login'
            ]);
            $userInfo = [
                'id' => $user['id'],
                'uuid' => $user['uuid'] ?? null,
                'username' => $user['username'],
                'email' => $user['email'] ?? null,
                'real_name' => $user['real_name'] ?? null,
                'school_id' => $user['school_id'] ?? null,
                'school_name' => $user['school_name'] ?? null,
                'class_name' => $user['class_name'] ?? null,
                'points' => (int)($user['points'] ?? 0),
                'is_admin' => (bool)($user['is_admin'] ?? 0),
                'avatar_url' => $user['avatar_url'] ?? null,
                'last_login_at' => $user['last_login_at'] ?? ($user['lastlgn'] ?? null)
            ];
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $token,
                    'user' => $userInfo
                ]
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('User login failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Login failed',
                'code' => 'LOGIN_FAILED'
            ], 500);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if ($user) {
                $this->auditLogService->log([
                    'user_id' => $user['id'],
                    'action' => 'user_logout',
                    'entity_type' => 'user',
                    'entity_id' => $user['id'],
                    'ip_address' => $this->getClientIP($request),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'notes' => 'User logout'
                ]);
            }
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Logout successful'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('User logout failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Logout failed'
            ], 500);
        }
    }

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
            $stmt = $this->db->prepare('SELECT u.*, s.name as school_name FROM users u LEFT JOIN schools s ON u.school_id = s.id WHERE u.id = ? AND u.deleted_at IS NULL');
            $stmt->execute([$user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ], 404);
            }
            // Align with messages schema: receiver_id holds the recipient user ID
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0 AND deleted_at IS NULL');
            $stmt->execute([$user['id']]);
            $unread = (int)$stmt->fetchColumn();
            $userData = [
                'id' => $row['id'],
                'uuid' => $row['uuid'] ?? null,
                'username' => $row['username'],
                'email' => $row['email'] ?? null,
                'real_name' => $row['real_name'] ?? null,
                'school_id' => $row['school_id'] ?? null,
                'school_name' => $row['school_name'] ?? null,
                'class_name' => $row['class_name'] ?? null,
                'points' => (int)($row['points'] ?? 0),
                'is_admin' => (bool)($row['is_admin'] ?? 0),
                'avatar_url' => $row['avatar_url'] ?? null,
                'last_login_at' => $row['last_login_at'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'unread_messages' => $unread
            ];
            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $userData
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Get current user failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to get user info'
            ], 500);
        }
    }

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
            if (!empty($data['cf_turnstile_response'])) {
                if (!$this->turnstileService->verify((string)$data['cf_turnstile_response'])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => 'Turnstile verification failed',
                        'code' => 'TURNSTILE_FAILED'
                    ], 400);
                }
            }
            $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL');
            $stmt->execute([$data['email']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($user) {
                $resetToken = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);
                $upd = $this->db->prepare('UPDATE users SET reset_token = ?, reset_token_expires_at = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$resetToken, $expiresAt, $user['id']]);
                try {
                    $this->emailService->sendPasswordResetEmail((string)$user['email'], (string)($user['real_name'] ?? $user['username']), $resetToken);
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to send password reset email', ['error' => $e->getMessage()]);
                }
                $this->auditLogService->log([
                    'user_id' => $user['id'],
                    'action' => 'password_reset_requested',
                    'entity_type' => 'user',
                    'entity_id' => $user['id'],
                    'ip_address' => $this->getClientIP($request),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'notes' => 'Password reset requested'
                ]);
            }
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Forgot password failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to process password reset request'
            ], 500);
        }
    }

    public function resetPassword(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $required = ['token', 'password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }
            if ($data['password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }
            $stmt = $this->db->prepare('SELECT * FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW() AND deleted_at IS NULL');
            $stmt->execute([$data['token']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid or expired reset token',
                    'code' => 'INVALID_TOKEN'
                ], 400);
            }
            $hashed = password_hash((string)$data['password'], PASSWORD_DEFAULT);
            try {
                $upd = $this->db->prepare('UPDATE users SET password_hash = ?, password = ?, reset_token = NULL, reset_token_expires_at = NULL, updated_at = NOW() WHERE id = ?');
                $upd->execute([$hashed, $hashed, $user['id']]);
            } catch (\Throwable $e) {
                try {
                    $upd = $this->db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL, updated_at = NOW() WHERE id = ?');
                    $upd->execute([$hashed, $user['id']]);
                } catch (\Throwable $e2) {
                    $upd = $this->db->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_token_expires_at = NULL, updated_at = NOW() WHERE id = ?');
                    $upd->execute([$hashed, $user['id']]);
                }
            }
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'password_reset_completed',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Password reset completed'
            ]);
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Password reset successful'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Password reset failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Password reset failed'
            ], 500);
        }
    }

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
            $required = ['current_password', 'new_password', 'confirm_password'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                        'code' => 'MISSING_FIELD'
                    ], 400);
                }
            }
            if ($data['new_password'] !== $data['confirm_password']) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'New password confirmation does not match',
                    'code' => 'PASSWORD_MISMATCH'
                ], 400);
            }
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
            $stmt->execute([$user['id']]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$current) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'User not found',
                    'code' => 'USER_NOT_FOUND'
                ], 404);
            }
            $passwordField = !empty($current['password_hash']) ? 'password_hash' : (!empty($current['password']) ? 'password' : null);
            if (!$passwordField || !password_verify((string)$data['current_password'], (string)$current[$passwordField])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Current password is incorrect',
                    'code' => 'INVALID_CURRENT_PASSWORD'
                ], 400);
            }
            $hashed = password_hash((string)$data['new_password'], PASSWORD_DEFAULT);
            try {
                $upd = $this->db->prepare('UPDATE users SET password_hash = ?, password = ?, updated_at = NOW() WHERE id = ?');
                $upd->execute([$hashed, $hashed, $user['id']]);
            } catch (\Throwable $e) {
                try {
                    $upd = $this->db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
                    $upd->execute([$hashed, $user['id']]);
                } catch (\Throwable $e2) {
                    $upd = $this->db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
                    $upd->execute([$hashed, $user['id']]);
                }
            }
            $this->auditLogService->log([
                'user_id' => $user['id'],
                'action' => 'password_changed',
                'entity_type' => 'user',
                'entity_id' => $user['id'],
                'ip_address' => $this->getClientIP($request),
                'user_agent' => $request->getHeaderLine('User-Agent'),
                'notes' => 'Password changed by user'
            ]);
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Change password failed', ['error' => $e->getMessage()]);
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to change password'
            ], 500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function getClientIP(Request $request): string
    {
        $server = $request->getServerParams();
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff) {
            $parts = explode(',', $xff);
            return trim($parts[0]);
        }
        $cf = $request->getHeaderLine('CF-Connecting-IP');
        if ($cf) {
            return $cf;
        }
        return $server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

