<?php

declare(strict_types=1);

use DI\Container;
use Psr\Container\ContainerInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Illuminate\Database\Capsule\Manager as Capsule;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\TurnstileService;
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

return function (Container $container) {
    // Logger
    $container->set(Logger::class, function () {
        try {
            $logger = new Logger('carbontrack');
            
            // 检查环境变量是否设置，如果没有则使用默认值
            $appEnv = $_ENV['APP_ENV'] ?? 'development';
            
            if ($appEnv === 'production') {
                $logPath = __DIR__ . '/../logs/app.log';
                // 确保日志目录存在
                if (!is_dir(dirname($logPath))) {
                    mkdir(dirname($logPath), 0755, true);
                }
                $handler = new RotatingFileHandler($logPath, 0, Logger::INFO);
            } else {
                $handler = new StreamHandler('php://stdout', Logger::DEBUG);
            }
            
            $logger->pushHandler($handler);
            return $logger;
        } catch (\Exception $e) {
            // 如果Logger创建失败，创建一个基本的Logger
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
        
        $capsule->addConnection([
            'driver' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? 3306,
            'database' => $_ENV['DB_DATABASE'] ?? 'carbontrack',
            'username' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ]);
        
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        
        return new DatabaseService($capsule);
    });

    // Auth Service
    $container->set(AuthService::class, function (ContainerInterface $c) {
        $authService = new AuthService(
            $_ENV['JWT_SECRET'],
            $_ENV['JWT_ALGORITHM'] ?? 'HS256',
            (int) ($_ENV['JWT_EXPIRATION'] ?? 86400)
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

    // Email Service
    $container->set(EmailService::class, function (ContainerInterface $c) {
        return new EmailService([
            'host' => $_ENV['MAIL_HOST'] ?? 'smtp.mailtrap.io',
            'port' => (int) ($_ENV['MAIL_PORT'] ?? 2525),
            'username' => $_ENV['MAIL_USERNAME'] ?? 'test',
            'password' => $_ENV['MAIL_PASSWORD'] ?? 'test',
            'encryption' => $_ENV['MAIL_ENCRYPTION'] ?? 'tls',
            'from_address' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'noreply@carbontrack.com',
            'from_name' => $_ENV['MAIL_FROM_NAME'] ?? 'CarbonTrack',
            'debug' => (bool) ($_ENV['APP_DEBUG'] ?? false),
            'subjects' => [
                'verification_code' => 'Your Verification Code',
                'password_reset' => 'Password Reset Request',
                'activity_approved' => 'Your Carbon Activity Approved!'
            ],
            'templates_path' => __DIR__ . '/../templates/emails/'
        ], $c->get(Logger::class));
    });

    // Audit Log Service
    $container->set(AuditLogService::class, function (ContainerInterface $c) {
        return new AuditLogService(
            $c->get(DatabaseService::class),
            $c->get(Logger::class)
        );
    });

    // Message Service
    $container->set(MessageService::class, function (ContainerInterface $c) {
        return new MessageService(
            $c->get(Logger::class),
            $c->get(AuditLogService::class)
        );
    });

    // Turnstile Service
    $container->set(TurnstileService::class, function (ContainerInterface $c) {
        return new TurnstileService(
            $_ENV['TURNSTILE_SECRET_KEY'] ?? '',
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
            $c->get(Logger::class)
        );
    });

    $container->set(UserController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new UserController(
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(MessageService::class),
            $c->get(Avatar::class),
            $c->get(Logger::class),
            $db
        );
    });

    $container->set(AuthController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new AuthController(
            $c->get(AuthService::class),
            $c->get(AuditLogService::class),
            $c->get(EmailService::class),
            $c->get(TurnstileService::class),
            $db
        );
    });

    $container->set(CarbonTrackController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new CarbonTrackController(
            $db,
            $c->get(CarbonCalculatorService::class),
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class)
        );
    });

    $container->set(CarbonActivityController::class, function (ContainerInterface $c) {
        return new CarbonActivityController(
            $c->get(CarbonCalculatorService::class),
            $c->get(AuditLogService::class)
        );
    });

    $container->set(ProductController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new ProductController(
            $db,
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class)
        );
    });

    $container->set(MessageController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        return new MessageController(
            $db,
            $c->get(MessageService::class),
            $c->get(AuditLogService::class),
            $c->get(AuthService::class)
        );
    });

    $container->set(SchoolController::class, function (ContainerInterface $c) {
        $db = $c->get(DatabaseService::class)->getConnection()->getPdo();
        
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
            $c->get(AuditLogService::class)
        );
    });

    $container->set(FileUploadController::class, function (ContainerInterface $c) {
        return new FileUploadController(
            $c->get(CloudflareR2Service::class),
            $c->get(AuditLogService::class),
            $c->get(Logger::class)
        );
    });
};

