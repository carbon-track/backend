<?php

declare(strict_types=1);

use DI\Container;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\ErrorLogHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\TurnstileService;
use CarbonTrack\Services\SystemLogService;
use CarbonTrack\Services\NotificationPreferenceService;
use CarbonTrack\Controllers\SystemLogController;
use CarbonTrack\Controllers\LogSearchController;
use CarbonTrack\Services\FileMetadataService;
use CarbonTrack\Models\Avatar;
use CarbonTrack\Controllers\AvatarController;
use CarbonTrack\Controllers\UserController;
use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Controllers\CarbonTrackController;
use CarbonTrack\Controllers\CarbonActivityController;
use CarbonTrack\Controllers\ProductController;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Controllers\SchoolController;
use CarbonTrack\Controllers\AdminController;
use CarbonTrack\Controllers\FileUploadController;
use CarbonTrack\Services\BadgeService;
use CarbonTrack\Services\StatisticsService;
use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Controllers\BadgeController;
use CarbonTrack\Controllers\AdminBadgeController;
use CarbonTrack\Middleware\RequestLoggingMiddleware;
use CarbonTrack\Controllers\StatsController;
use CarbonTrack\Services\Ai\OpenAiClientAdapter;
use CarbonTrack\Controllers\AdminAiController;
use CarbonTrack\Services\AdminAiCommandRepository;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use OpenAI\Factory as OpenAiFactory;

$__deps_initializer = function (Container $container) {
    // Logger
    $container->set(Logger::class, function () {
        try {
            $logger = new Logger('carbontrack');
            
            // 检查环境变量是否设置，如果没有则使用默认值
            $appEnv = $_ENV['APP_ENV'] ?? 'development';
            
            // 检查是否为Windows环境
            $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
            
            if ($appEnv === 'production' && !$isWindows) {
                // 生产环境且非Windows系统
                $logPath = __DIR__ . '/../logs/app.log';
                $logDir = dirname($logPath);
                
                // 确保日志目录存在并且有正确的权限
                if (!is_dir($logDir)) {
                    if (!mkdir($logDir, 0755, true)) {
                        throw new \Exception("无法创建日志目录: {$logDir}");
                    }
                }
                
                // 检查目录是否可写
                if (!is_writable($logDir)) {
                    throw new \Exception("日志目录不可写: {$logDir}");
                }
                
                // 尝试创建或写入日志文件
                if (!file_exists($logPath)) {
                    if (!touch($logPath)) {
                        throw new \Exception("无法创建日志文件: {$logPath}");
                    }
                    chmod($logPath, 0644);
                }
                
                $handler = new RotatingFileHandler($logPath, 0, Logger::INFO);
            } else {
                // 开发环境：Windows 下使用系统错误日志，避免 FastCGI 下 stdout 句柄问题
                if ($isWindows) {
                    $handler = new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, Logger::DEBUG);
                } else {
                    $handler = new StreamHandler('php://stdout', Logger::DEBUG);
                }
            }
            
            $logger->pushHandler($handler);
            return $logger;
        } catch (\Exception $e) {
            // 如果Logger创建失败，创建一个基本的Logger到标准错误输出
            $fallbackLogger = new Logger('carbontrack');
            $fallbackLogger->pushHandler(new StreamHandler('php://stderr', Logger::ERROR));
            $fallbackLogger->error('Failed to create logger with configured handlers: ' . $e->getMessage());
            return $fallbackLogger;
        }
    });

    // Allow retrieving logger via interface
    $container->set(LoggerInterface::class, function (Container $c) {
        return $c->get(Logger::class);
    });

    // Database
    $container->set(DatabaseService::class, function () {
        $capsule = new Capsule;

        $dbConnection = $_ENV['DB_CONNECTION'] ?? 'mysql';
        
        if ($dbConnection === 'sqlite') {
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => $_ENV['DB_DATABASE'] ?? '/tmp/test.db',
                'prefix' => '',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            ]);
        } else {
            $capsule->addConnection([
                'driver' => 'mysql',
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => $_ENV['DB_PORT'] ?? 3306,
                'database' => $_ENV['DB_DATABASE'] ?? 'carbontrack',
                // Support both DB_USERNAME/DB_PASSWORD and legacy DB_USER/DB_PASS
                'username' => $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            ]);
        }

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        return new DatabaseService($capsule);
    });

    // PDO Service (for services that need direct PDO access)
    $container->set(PDO::class, function (ContainerInterface $c) {
        return $c->get(DatabaseService::class)->getConnection()->getPdo();
    });

    // Auth Service
    $container->set(AuthService::class, function (ContainerInterface $c) {
        // Support both JWT_EXPIRATION and JWT_EXPIRES_IN
        $jwtTtl = $_ENV['JWT_EXPIRATION'] ?? $_ENV['JWT_EXPIRES_IN'] ?? 86400;
        $authService = new AuthService(
            $_ENV['JWT_SECRET'],
            $_ENV['JWT_ALGORITHM'] ?? 'HS256',
            (int) $jwtTtl
        );
        
        // 设置数据库连接
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        $authService->setDatabase($db);
        
        return $authService;
    });

    // Carbon Calculator Service
    $container->set(CarbonCalculatorService::class, function () {
        return new CarbonCalculatorService();
    });

    // Cloudflare R2 Service
    $container->set(CloudflareR2Service::class, function (ContainerInterface $c) {
        return new CloudflareR2Service(
            $_ENV['R2_ACCESS_KEY_ID'],
            $_ENV['R2_SECRET_ACCESS_KEY'],
            $_ENV['R2_ENDPOINT'],
            $_ENV['R2_BUCKET_NAME'],
            $_ENV['R2_PUBLIC_URL'],
            $c->get(Logger::class),
            $c->get(AuditLogService::class)
        );
    });

    // Badge Service
    $container->set(BadgeService::class, function (ContainerInterface $c) {
        return new BadgeService(
            $c->get(DatabaseService::class)->getConnection(),
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(Logger::class)
        );
    });

    $container->set(StatisticsService::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new StatisticsService($db);
    });

    // AI LLM client adapter (optional if API key is not configured)
    $container->set('ai.llmClient', function () {
        $apiKey = trim((string) ($_ENV['LLM_API_KEY'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        try {
            $factory = new OpenAiFactory();
            $factory = $factory->withApiKey($apiKey);

            $handlerStack = HandlerStack::create();

            $handlerStack->push(Middleware::mapResponse(function (ResponseInterface $response) {
                $headers = $response->getHeader('x-request-id');
                if (!empty($headers)) {
                    return $response;
                }

                $stream = (string) $response->getBody();
                $body = json_decode($stream, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($body)) {
                    $requestId = $body['id'] ?? ($body['metadata']['request_id'] ?? null);
                    if (!is_string($requestId) || $requestId === '') {
                        try {
                            $requestId = 'llm-' . bin2hex(random_bytes(8));
                        } catch (\Throwable) {
                            $requestId = 'llm-' . bin2hex(openssl_random_pseudo_bytes(8));
                        }
                        if (!isset($body['metadata']) || !is_array($body['metadata'])) {
                            $body['metadata'] = [];
                        }
                        $body['metadata']['request_id'] = $requestId;
                        $body['id'] = $body['id'] ?? $requestId;
                        $stream = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $stream;
                    }

                    return $response
                        ->withHeader('x-request-id', $requestId)
                        ->withoutHeader('Content-Length')
                        ->withBody(Utils::streamFor($stream));
                }

                return $response->withBody(Utils::streamFor($stream))->withoutHeader('Content-Length');
            }));

            $httpClient = new GuzzleClient([
                'timeout' => 15,
                'connect_timeout' => 5,
                'handler' => $handlerStack,
            ]);

            $factory = $factory->withHttpClient($httpClient);

            $baseUrl = trim((string) ($_ENV['LLM_API_BASE_URL'] ?? ''));
            if ($baseUrl !== '') {
                $factory = $factory->withBaseUri($baseUrl);
            }

            $organization = trim((string) ($_ENV['LLM_API_ORG'] ?? ''));
            if ($organization !== '') {
                $factory = $factory->withOrganization($organization);
            }

            $client = $factory->make();
        } catch (\Throwable $exception) {
            error_log('Failed to initialize OpenAI client: ' . $exception->getMessage());
            return null;
        }

        return new OpenAiClientAdapter($client);
    });

    $container->set(AdminAiCommandRepository::class, function (ContainerInterface $c) {
        $baseDir = dirname(__DIR__, 1);
        $defaultPath = $baseDir . '/config/admin_ai_commands.php';

        $configuredPath = trim((string) ($_ENV['ADMIN_AI_COMMANDS_PATH'] ?? ''));
        $paths = [];

        if ($configuredPath !== '') {
            $isAbsolute = false;
            if (preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPath) === 1) {
                $isAbsolute = true;
            } elseif ($configuredPath[0] === '/' || $configuredPath[0] === '\\') {
                $isAbsolute = true;
            }

            if ($isAbsolute) {
                $paths[] = $configuredPath;
            } else {
                $paths[] = $baseDir . DIRECTORY_SEPARATOR . ltrim($configuredPath, '/\\');
            }
        }

        $paths[] = $defaultPath;

        return new AdminAiCommandRepository($paths);
    });

    $container->set(AdminAiIntentService::class, function (ContainerInterface $c) {
        /** @var \CarbonTrack\Services\Ai\LlmClientInterface|null $llmClient */
        $llmClient = $c->get('ai.llmClient');

        $config = [
            'model' => $_ENV['LLM_API_MODEL'] ?? null,
            'temperature' => $_ENV['LLM_API_TEMPERATURE'] ?? null,
            'max_tokens' => $_ENV['LLM_API_MAX_TOKENS'] ?? null,
        ];

        return new AdminAiIntentService(
            $llmClient,
            $c->get(LoggerInterface::class),
            $config,
            $c->get(AdminAiCommandRepository::class)->getConfig()
        );
    });

    // Email Service
    $container->set(EmailService::class, function (ContainerInterface $c) {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? ($_ENV['APP_URL'] ?? '');
        $supportEmail = $_ENV['SUPPORT_EMAIL'] ?? ($_ENV['MAIL_FROM_ADDRESS'] ?? 'support@carbontrackapp.com');

        return new EmailService([
            'host' => $_ENV['MAIL_HOST'],
            'port' => (int) ($_ENV['MAIL_PORT']),
            'username' => $_ENV['MAIL_USERNAME'],
            'password' => $_ENV['MAIL_PASSWORD'] ?? 'test',
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
            'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@carbontrack.com',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'CarbonTrack',
            'debug' => (strtolower((string) ($_ENV['APP_ENV'] ?? 'development')) !== 'production'),
            'force_simulation' => $_ENV['MAIL_SIMULATE'] ?? false,
            'smtp_debug' => isset($_ENV['MAIL_SMTP_DEBUG']) ? (int) $_ENV['MAIL_SMTP_DEBUG'] : 0,
            'subjects' => [
                'verification_code' => 'Your Verification Code',
                'password_reset' => 'Password Reset Request',
                'activity_approved' => 'Your Carbon Activity Approved!'
            ],
            'templates_path' => __DIR__ . '/../templates/emails/',
            'app_name' => $_ENV['APP_NAME'] ?? ($_ENV['MAIL_FROM_NAME'] ?? 'CarbonTrack'),
            'support_email' => $supportEmail,
            'frontend_url' => $frontendUrl,
            'reset_link_base' => $frontendUrl,
        ], $c->get(Logger::class), $c->get(NotificationPreferenceService::class));
    });

    // Audit Log Service
    $container->set(AuditLogService::class, function (ContainerInterface $c) {
        return new AuditLogService(
            $c->get(PDO::class),
            $c->get(Logger::class)
        );
    });

    // Error Log Service
    $container->set(ErrorLogService::class, function (ContainerInterface $c) {
        return new ErrorLogService(
            $c->get(PDO::class),
            $c->get(Logger::class)
        );
    });

    // Message Service
    $container->set(MessageService::class, function (ContainerInterface $c) {
        return new MessageService(
            $c->get(Logger::class),
            $c->get(AuditLogService::class),
            $c->get(EmailService::class)
        );
    });

    // Notification preferences
    $container->set(NotificationPreferenceService::class, function (ContainerInterface $c) {
        return new NotificationPreferenceService(
            $c->get(Logger::class)
        );
    });

    // Turnstile Service
    $container->set(TurnstileService::class, function (ContainerInterface $c) {
        return new TurnstileService(
            $_ENV['TURNSTILE_SECRET_KEY'] ?? '',
            $c->get(Logger::class)
        );
    });

    // System Log Service
    $container->set(SystemLogService::class, function (ContainerInterface $c) {
        return new SystemLogService(
            $c->get(PDO::class),
            $c->get(Logger::class)
        );
    });

    // File Metadata Service (for deduplication)
    $container->set(FileMetadataService::class, function (ContainerInterface $c) {
        return new FileMetadataService(
            $c->get(Logger::class)
        );
    });

    // Models
    $container->set(Avatar::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new Avatar($db);
    });

    // Controllers
    $container->set(AvatarController::class, function (ContainerInterface $c) {
        return new AvatarController(
            $c->get(Avatar::class),
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(CloudflareR2Service::class),
            $c->get(Logger::class),
            $c->get(ErrorLogService::class)
        );
    });

    $container->set(UserController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new UserController(
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(MessageService::class),
            $c->get(Avatar::class),
            $c->get(NotificationPreferenceService::class),
            $c->get(EmailService::class),
            $c->get(Logger::class),
            $db,
            $c->get(ErrorLogService::class),
            $c->has(CloudflareR2Service::class) ? $c->get(CloudflareR2Service::class) : null
        );
    });

    $container->set(AuthController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new AuthController(
            $c->get(AuthService::class),
            $c->get(EmailService::class),
            $c->get(TurnstileService::class),
            $c->get(AuditLogService::class),
            $c->get(MessageService::class),
            $c->has(CloudflareR2Service::class) ? $c->get(CloudflareR2Service::class) : null,
            $c->get(Logger::class),
            $db,
            $c->get(ErrorLogService::class)
        );
    });

    $container->set(CarbonTrackController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new CarbonTrackController(
            $db,
            $c->get(CarbonCalculatorService::class),
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class),
            $c->get(ErrorLogService::class),
            $c->get(CloudflareR2Service::class)
        );
    });

    $container->set(CarbonActivityController::class, function (ContainerInterface $c) {
        return new CarbonActivityController(
            $c->get(CarbonCalculatorService::class),
            $c->get(AuditLogService::class),
            $c->get(ErrorLogService::class)
        );
    });

    $container->set(ProductController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new ProductController(
            $db,
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class),
            $c->get(ErrorLogService::class),
            $c->get(CloudflareR2Service::class)
        );
    });

    $container->set(MessageController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new MessageController(
            $db,
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class),
            $c->get(EmailService::class),
            $c->get(ErrorLogService::class)
        );
    });

    $container->set(SchoolController::class, function (ContainerInterface $c) {
        // Create a mock container with the required services
        $mockContainer = new class($c) {
            private $container;
            
            public function __construct($container) {
                $this->container = $container;
            }
            
            public function get($service) {
                return $this->container->get($service);
            }
        };
        
        return new SchoolController($mockContainer);
    });

    $container->set(AdminController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new AdminController(
            $db,
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(BadgeService::class),
            $c->get(StatisticsService::class),
            $c->get(ErrorLogService::class),
            $c->get(CloudflareR2Service::class)
        );
    });

    $container->set(AdminAiController::class, function (ContainerInterface $c) {
        return new AdminAiController(
            $c->get(AuthService::class),
            $c->get(AdminAiIntentService::class),
            $c->get(AdminAiCommandRepository::class),
            $c->get(ErrorLogService::class),
            $c->get(LoggerInterface::class)
        );
    });

    $container->set(StatsController::class, function (ContainerInterface $c) {
        return new StatsController(
            $c->get(StatisticsService::class)
        );
    });

    // System Log Controller
    $container->set(SystemLogController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new SystemLogController(
            $db,
            $c->get(AuthService::class),
            $c->get(ErrorLogService::class)
        );
    });

    // Unified Log Search Controller
    $container->set(LogSearchController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new LogSearchController(
            $db,
            $c->get(AuthService::class),
            $c->get(ErrorLogService::class)
        );
    });

    $container->set(FileUploadController::class, function (ContainerInterface $c) {
        return new FileUploadController(
            $c->get(CloudflareR2Service::class),
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(Logger::class),
            $c->get(ErrorLogService::class),
            $c->get(FileMetadataService::class)
        );
    });

    $container->set(BadgeController::class, function (ContainerInterface $c) {
        return new BadgeController(
            $c->get(AuthService::class),
            $c->get(BadgeService::class),
            $c->get(AuditLogService::class),
            $c->get(CloudflareR2Service::class),
            $c->get(ErrorLogService::class),
            $c->get(Logger::class)
        );
    });

    $container->set(AdminBadgeController::class, function (ContainerInterface $c) {
        return new AdminBadgeController(
            $c->get(AuthService::class),
            $c->get(BadgeService::class),
            $c->get(AuditLogService::class),
            $c->get(CloudflareR2Service::class),
            $c->get(ErrorLogService::class),
            $c->get(Logger::class)
        );
    });

    // Request Logging Middleware
    $container->set(RequestLoggingMiddleware::class, function (ContainerInterface $c) {
        return new RequestLoggingMiddleware(
            $c->get(SystemLogService::class),
            $c->get(AuthService::class),
            $c->get(Logger::class)
        );
    });
};

// If this file is included in a scope that already has a $container (e.g., tests),
// initialize it immediately for convenience. Still return the initializer for normal usage.
if (isset($container) && $container instanceof Container) {
    $__deps_initializer($container);
}

return $__deps_initializer;



