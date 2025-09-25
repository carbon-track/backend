<?php

declare(strict_types=1);

use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use CarbonTrack\Controllers\AuthController;
use CarbonTrack\Controllers\UserController;
use CarbonTrack\Controllers\CarbonTrackController;
use CarbonTrack\Controllers\CarbonActivityController;
use CarbonTrack\Controllers\ProductController;
use CarbonTrack\Controllers\MessageController;
use CarbonTrack\Controllers\SchoolController;
use CarbonTrack\Controllers\AdminController;
use CarbonTrack\Controllers\FileUploadController;
use CarbonTrack\Controllers\AvatarController;
use CarbonTrack\Controllers\BadgeController;
use CarbonTrack\Controllers\AdminBadgeController;
use CarbonTrack\Controllers\SystemLogController;
use CarbonTrack\Controllers\LogSearchController;
use CarbonTrack\Middleware\AuthMiddleware;
use CarbonTrack\Middleware\AdminMiddleware;
use CarbonTrack\Middleware\RequestLoggingMiddleware;

// Constants to avoid duplicated literals
defined('CONTENT_TYPE_JSON') || define('CONTENT_TYPE_JSON', 'application/json');
defined('API_V1_PREFIX') || define('API_V1_PREFIX', '/api/v1');
defined('PATH_AVATARS') || define('PATH_AVATARS', '/avatars');
defined('PATH_AVATAR_ID') || define('PATH_AVATAR_ID', '/avatars/{id:[0-9]+}');
defined('PATH_CARBON_ACTIVITIES') || define('PATH_CARBON_ACTIVITIES', '/carbon-activities');
defined('PATH_CARBON_ACTIVITY_ID') || define('PATH_CARBON_ACTIVITY_ID', '/carbon-activities/{id}');
defined('PATH_TRANSACTIONS_ID_UUID') || define('PATH_TRANSACTIONS_ID_UUID', '/transactions/{id:[0-9a-fA-F\-]+}');
defined('PATH_STATS') || define('PATH_STATS', '/stats');
defined('PATH_PRODUCTS') || define('PATH_PRODUCTS', '/products');
defined('PATH_SCHOOLS') || define('PATH_SCHOOLS', '/schools');
defined('PATH_CLASSES_SUFFIX') || define('PATH_CLASSES_SUFFIX', '/classes');
defined('PATTERN_ID_NUMERIC') || define('PATTERN_ID_NUMERIC', '/{id:[0-9]+}');
defined('PATH_AUTH') || define('PATH_AUTH', '/auth');
defined('PATH_USERS') || define('PATH_USERS', '/users');


return function (App $app) {
    // 全局请求日志中间件（放在最前，捕获所有请求）
    try { $app->add(RequestLoggingMiddleware::class); } catch (\Throwable $e) { /* ignore if not resolvable */ }
    // 所有 helper 函数仅在闭包内部声明，避免全局污染
    $registerHealthCheck = function (App $app) {
        $app->get('/', function ($request, $response) {
            $request->getMethod();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'CarbonTrack API is running',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s')
            ]));
            return $response->withHeader('Content-Type', CONTENT_TYPE_JSON);
        });
    };

    $registerApiV1Root = function (RouteCollectorProxy $group) {
        $group->get('', function ($request, $response) {
            $request->getMethod();
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'CarbonTrack API v1',
                'version' => '1.0.0',
                'timestamp' => date('Y-m-d H:i:s'),
                'endpoints' => [
                    'auth' => API_V1_PREFIX . PATH_AUTH,
                    'users' => API_V1_PREFIX . PATH_USERS,
                    'carbon-activities' => API_V1_PREFIX . PATH_CARBON_ACTIVITIES,
                    'carbon-track' => API_V1_PREFIX . '/carbon-track',
                    'products' => API_V1_PREFIX . PATH_PRODUCTS,
                    'exchange' => API_V1_PREFIX . '/exchange',
                    'messages' => API_V1_PREFIX . '/messages',
                    'avatars' => API_V1_PREFIX . PATH_AVATARS,
                    'schools' => API_V1_PREFIX . PATH_SCHOOLS,
                    'files' => API_V1_PREFIX . '/files',
                    'admin' => API_V1_PREFIX . '/admin'
                ]
            ]));
            return $response->withHeader('Content-Type', CONTENT_TYPE_JSON);
        });
    };

    $registerAuthRoutes = function (RouteCollectorProxy $group) {
        $group->group(PATH_AUTH, function (RouteCollectorProxy $auth) {
            $auth->post('/register', [AuthController::class, 'register']);
            $auth->post('/login', [AuthController::class, 'login']);
            $auth->post('/logout', [AuthController::class, 'logout']);
            $auth->post('/send-verification-code', [AuthController::class, 'sendVerificationCode']);
            $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
            $auth->post('/verify-email', [AuthController::class, 'verifyEmail']);
        });
    };

    $registerUserRoutes = function (RouteCollectorProxy $group) {
        $group->group(PATH_USERS, function (RouteCollectorProxy $users) {
            $users->get('/me', [UserController::class, 'getCurrentUser']);
            $users->put('/me', [UserController::class, 'updateCurrentUser']);
            $users->put('/me/profile', [UserController::class, 'updateProfile']);
            $users->put('/me/avatar', [UserController::class, 'selectAvatar']);
            $users->get('/me/badges', [BadgeController::class, 'myBadges']);
            $users->get('/me/points-history', [UserController::class, 'getPointsHistory']);
            $users->get('/me/stats', [UserController::class, 'getUserStats']);
            $users->get('/me/chart-data', [UserController::class, 'getChartData']);
            $users->get('/me/activities', [UserController::class, 'getRecentActivities']);
            $users->get(PATTERN_ID_NUMERIC, [UserController::class, 'getUser']);
            $users->put(PATTERN_ID_NUMERIC, [UserController::class, 'updateUser']);
            $users->delete(PATTERN_ID_NUMERIC, [UserController::class, 'deleteUser']);
        })->add(AuthMiddleware::class);
    };

    $registerAvatarRoutes = function (RouteCollectorProxy $group) {
        $group->get(PATH_AVATARS, [AvatarController::class, 'getAvatars']);
        $group->get(PATH_AVATARS . '/categories', [AvatarController::class, 'getAvatarCategories']);
    };

    $registerBadgeRoutes = function (RouteCollectorProxy $group) {
        $group->group('/badges', function (RouteCollectorProxy $badges) {
            $badges->get('', [BadgeController::class, 'list']);
            $badges->post('/auto-trigger', [BadgeController::class, 'triggerAuto']);
        })->add(AuthMiddleware::class);
    };

    $registerCarbonActivitiesRoutes = function (RouteCollectorProxy $group) {
        $group->get(PATH_CARBON_ACTIVITIES, [CarbonActivityController::class, 'getActivities']);
        $group->get(PATH_CARBON_ACTIVITY_ID, [CarbonActivityController::class, 'getActivity']);
    };

    $registerCarbonTrackRoutes = function (RouteCollectorProxy $group) {
        $group->group('/carbon-track', function (RouteCollectorProxy $carbon) {
            $carbon->post('/calculate', [CarbonTrackController::class, 'calculate']);
            $carbon->post('/record', [CarbonTrackController::class, 'submitRecord']);
            $carbon->get('/transactions', [CarbonTrackController::class, 'getUserRecords']);
            $carbon->get(PATH_TRANSACTIONS_ID_UUID, [CarbonTrackController::class, 'getRecordDetail']);
            $carbon->put(PATH_TRANSACTIONS_ID_UUID, [CarbonTrackController::class, 'reviewRecord']);
            $carbon->put('/transactions/{id:[0-9a-fA-F\-]+}/approve', [CarbonTrackController::class, 'reviewRecord']);
            $carbon->put('/transactions/{id:[0-9a-fA-F\-]+}/reject', [CarbonTrackController::class, 'reviewRecord']);
            $carbon->delete(PATH_TRANSACTIONS_ID_UUID, [CarbonTrackController::class, 'deleteTransaction']);
            $carbon->get('/factors', [CarbonTrackController::class, 'getCarbonFactors']);
            $carbon->get(PATH_STATS, [CarbonTrackController::class, 'getUserStats']);
        })->add(AuthMiddleware::class);

    // New standardized endpoint documented in OpenAPI replacing legacy /carbon-track/record
    // Enforces image requirement in controller based on path containing '/api/v1/carbon-records'
    $group->post('/carbon-records', [CarbonTrackController::class, 'submitRecord'])->add(AuthMiddleware::class);
    };

    $registerProductRoutes = function (RouteCollectorProxy $group) {
        $group->group(PATH_PRODUCTS, function (RouteCollectorProxy $products) {
            $products->get('', [ProductController::class, 'getProducts']);
            $products->get('/tags', [ProductController::class, 'searchProductTags']);
            $products->get(PATTERN_ID_NUMERIC, [ProductController::class, 'getProductDetail']);
            $products->get('/categories', [ProductController::class, 'getCategories']);
            $products->post('', [ProductController::class, 'createProduct']);
            $products->put(PATTERN_ID_NUMERIC, [ProductController::class, 'updateProduct']);
            $products->delete(PATTERN_ID_NUMERIC, [ProductController::class, 'deleteProduct']);
        });
    };

    $registerExchangeRoutes = function (RouteCollectorProxy $group) {
        $group->group('/exchange', function (RouteCollectorProxy $exchange) {
            $exchange->post('', [ProductController::class, 'exchangeProduct']);
            $exchange->get('/transactions', [ProductController::class, 'getExchangeTransactions']);
            $exchange->get(PATH_TRANSACTIONS_ID_UUID, [ProductController::class, 'getExchangeTransaction']);
        })->add(AuthMiddleware::class);
    };

    $registerMessageRoutes = function (RouteCollectorProxy $group) {
        $group->group('/messages', function (RouteCollectorProxy $messages) {
            $messages->get('', [MessageController::class, 'getUserMessages']);
            $messages->get(PATTERN_ID_NUMERIC, [MessageController::class, 'getMessageDetail']);
            $messages->put(PATTERN_ID_NUMERIC . '/read', [MessageController::class, 'markAsRead']);
            $messages->delete(PATTERN_ID_NUMERIC, [MessageController::class, 'deleteMessage']);
            $messages->get('/unread-count', [MessageController::class, 'getUnreadCount']);
            $messages->put('/mark-all-read', [MessageController::class, 'markAllAsRead']);
        })->add(AuthMiddleware::class);
    };

    $registerSchoolRoutes = function (RouteCollectorProxy $group) {
        $group->get(PATH_SCHOOLS, [SchoolController::class, 'index']);
        $group->post(PATH_SCHOOLS, [SchoolController::class, 'createOrFetch'])->add(AuthMiddleware::class);
        $group->get(PATH_SCHOOLS . PATTERN_ID_NUMERIC . PATH_CLASSES_SUFFIX, [SchoolController::class, 'listClasses']);
        $group->post(PATH_SCHOOLS . PATTERN_ID_NUMERIC . PATH_CLASSES_SUFFIX, [SchoolController::class, 'createClass'])->add(AuthMiddleware::class);
    };

    $registerAdminRoutes = function (RouteCollectorProxy $group) {
        $group->group('/admin', function (RouteCollectorProxy $admin) {
            $admin->get(PATH_USERS, [AdminController::class, 'getUsers']);
            $admin->get(PATH_USERS . PATTERN_ID_NUMERIC . '/badges', [AdminController::class, 'getUserBadges']);
            $admin->get(PATH_USERS . PATTERN_ID_NUMERIC . '/overview', [AdminController::class, 'getUserOverview']);
            // 用户管理
            $admin->put(PATH_USERS . PATTERN_ID_NUMERIC, [AdminController::class, 'updateUser']);
            $admin->delete(PATH_USERS . PATTERN_ID_NUMERIC, [AdminController::class, 'deleteUser']);
            $admin->post(PATH_USERS . PATTERN_ID_NUMERIC . '/points/adjust', [AdminController::class, 'adjustUserPoints']);
            $admin->get('/transactions/pending', [AdminController::class, 'getPendingTransactions']);
            $admin->get(PATH_STATS, [AdminController::class, 'getStats']);
            $admin->get('/logs', [AdminController::class, 'getLogs']);
            $admin->post(PATH_SCHOOLS, [SchoolController::class, 'store']);
            $admin->put(PATH_SCHOOLS . PATTERN_ID_NUMERIC, [SchoolController::class, 'update']);
            $admin->delete(PATH_SCHOOLS . PATTERN_ID_NUMERIC, [SchoolController::class, 'delete']);
            $admin->get(PATH_CARBON_ACTIVITIES, [CarbonActivityController::class, 'getActivitiesForAdmin']);
            $admin->post(PATH_CARBON_ACTIVITIES, [CarbonActivityController::class, 'createActivity']);
            $admin->get(PATH_CARBON_ACTIVITIES . '/statistics', [CarbonActivityController::class, 'getActivityStatistics']);
            $admin->put(PATH_CARBON_ACTIVITIES . '/sort-orders', [CarbonActivityController::class, 'updateSortOrders']);
            $admin->put(PATH_CARBON_ACTIVITY_ID, [CarbonActivityController::class, 'updateActivity']);
            $admin->delete(PATH_CARBON_ACTIVITY_ID, [CarbonActivityController::class, 'deleteActivity']);
            $admin->post(PATH_CARBON_ACTIVITY_ID . '/restore', [CarbonActivityController::class, 'restoreActivity']);
            $admin->get(PATH_CARBON_ACTIVITY_ID . '/statistics', [CarbonActivityController::class, 'getActivityStatistics']);
            $admin->get('/activities', [CarbonTrackController::class, 'getPendingRecords']);
            // 兼容别名：/admin/carbon-activities/pending 与 /admin/carbon-records
            $admin->get('/carbon-activities/pending', [CarbonTrackController::class, 'getPendingRecords']);
            $admin->get('/carbon-records', [CarbonTrackController::class, 'getPendingRecords']);
            // 系统请求日志
            $admin->get('/system-logs', [SystemLogController::class, 'list']);
            $admin->get('/system-logs/{id:[0-9]+}', [SystemLogController::class, 'detail']);
            $admin->get('/logs/search', [LogSearchController::class, 'search']);
            // Unified logs export & related (previously missing, causing 404 in frontend)
            $admin->get('/logs/export', [LogSearchController::class, 'export']);
            $admin->get('/logs/related', [LogSearchController::class, 'related']);
            $admin->put('/activities/{id:[0-9a-fA-F\-]+}/review', [CarbonTrackController::class, 'reviewRecord']);
            $admin->get('/exchanges', [ProductController::class, 'getExchangeRecords']);
            $admin->get('/exchanges/{id:[0-9a-fA-F\-]+}', [ProductController::class, 'getExchangeRecordDetail']);
            $admin->put('/exchanges/{id:[0-9a-fA-F\-]+}/status', [ProductController::class, 'updateExchangeStatus']);
            $admin->put('/exchanges/{id:[0-9a-fA-F\-]+}', [ProductController::class, 'updateExchangeStatus']);
            // 站内信广播
            $admin->post('/messages/broadcast', [MessageController::class, 'sendSystemMessage']);
            $admin->get('/messages/broadcast/recipients', [MessageController::class, 'searchBroadcastRecipients']);
            $admin->get('/messages/broadcasts', [MessageController::class, 'getBroadcastHistory']);
            $admin->get(PATH_PRODUCTS, [ProductController::class, 'getProducts']);
            $admin->get(PATH_PRODUCTS . '/tags', [ProductController::class, 'searchProductTags']);
            $admin->post(PATH_PRODUCTS, [ProductController::class, 'createProduct']);
            $admin->put(PATH_PRODUCTS . PATTERN_ID_NUMERIC, [ProductController::class, 'updateProduct']);
            $admin->delete(PATH_PRODUCTS . PATTERN_ID_NUMERIC, [ProductController::class, 'deleteProduct']);
            $admin->get(PATH_AVATARS, [AvatarController::class, 'getAvatars']);
            $admin->post(PATH_AVATARS, [AvatarController::class, 'createAvatar']);
            $admin->put(PATH_AVATARS . '/sort-orders', [AvatarController::class, 'updateSortOrders']);
            $admin->get(PATH_AVATARS . '/usage-stats', [AvatarController::class, 'getAvatarUsageStats']);
            $admin->post(PATH_AVATARS . '/upload', [AvatarController::class, 'uploadAvatarFile']);
            $admin->get(PATH_AVATAR_ID, [AvatarController::class, 'getAvatar']);
            $admin->put(PATH_AVATAR_ID, [AvatarController::class, 'updateAvatar']);
            $admin->delete(PATH_AVATAR_ID, [AvatarController::class, 'deleteAvatar']);
            $admin->get('/badges', [AdminBadgeController::class, 'list']);
            $admin->get('/badges/{id:[0-9]+}', [AdminBadgeController::class, 'detail']);
            $admin->post('/badges', [AdminBadgeController::class, 'create']);
            $admin->put('/badges/{id:[0-9]+}', [AdminBadgeController::class, 'update']);
            $admin->post('/badges/{id:[0-9]+}/award', [AdminBadgeController::class, 'award']);
            $admin->post('/badges/{id:[0-9]+}/revoke', [AdminBadgeController::class, 'revoke']);
            $admin->get('/badges/{id:[0-9]+}/recipients', [AdminBadgeController::class, 'recipients']);
            $admin->post('/badges/auto-trigger', [AdminBadgeController::class, 'triggerAuto']);
            $admin->post(PATH_AVATAR_ID . '/restore', [AvatarController::class, 'restoreAvatar']);
            $admin->put(PATH_AVATAR_ID . '/set-default', [AvatarController::class, 'setDefaultAvatar']);
        })->add(AuthMiddleware::class)->add(AdminMiddleware::class);
    };

    $registerFileRoutes = function (RouteCollectorProxy $group) {
        $group->group('/files', function (RouteCollectorProxy $files) {
            // 前端直传：获取预签名、确认
            $files->post('/presign', [FileUploadController::class, 'getDirectUploadPresign']);
            $files->post('/confirm', [FileUploadController::class, 'confirmDirectUpload']);
            // 多分片上传
            $files->post('/multipart/init', [FileUploadController::class, 'initMultipartUpload']);
            $files->get('/multipart/part', [FileUploadController::class, 'getMultipartPartUrl']);
            $files->post('/multipart/complete', [FileUploadController::class, 'completeMultipartUpload']);
            $files->post('/multipart/abort', [FileUploadController::class, 'abortMultipartUpload']);
            $files->post('/upload', [FileUploadController::class, 'uploadFile']);
            $files->post('/upload-multiple', [FileUploadController::class, 'uploadMultipleFiles']);
            $files->get('/r2/diagnostics', [FileUploadController::class, 'r2Diagnostics']);
            $files->delete('/{path:.+}', [FileUploadController::class, 'deleteFile']);
            $files->get('/{path:.+}/info', [FileUploadController::class, 'getFileInfo']);
            $files->get('/{path:.+}/presigned-url', [FileUploadController::class, 'generatePresignedUrl']);
        })->add(AuthMiddleware::class);
    };

    // Health check
    $registerHealthCheck($app);

    // API v1 routes
    $app->group(API_V1_PREFIX, function (RouteCollectorProxy $group) use (
        $registerApiV1Root,
        $registerAuthRoutes,
        $registerUserRoutes,
        $registerAvatarRoutes,
        $registerBadgeRoutes,
        $registerCarbonActivitiesRoutes,
        $registerCarbonTrackRoutes,
        $registerProductRoutes,
        $registerExchangeRoutes,
        $registerMessageRoutes,
        $registerSchoolRoutes,
        $registerAdminRoutes,
        $registerFileRoutes
    ) {
        $registerApiV1Root($group);
        $registerAuthRoutes($group);
        $registerUserRoutes($group);
        $registerAvatarRoutes($group);
        $registerBadgeRoutes($group);
        $registerCarbonActivitiesRoutes($group);
        $registerCarbonTrackRoutes($group);
        $registerProductRoutes($group);
        $registerExchangeRoutes($group);
        $registerMessageRoutes($group);
        $registerSchoolRoutes($group);
        $registerAdminRoutes($group);
        $registerFileRoutes($group);

        // Admin file management routes (separate prefix)
        $group->group('/admin/files', function (RouteCollectorProxy $adminFiles) {
            $adminFiles->get('', [FileUploadController::class, 'getFilesList']);
            $adminFiles->get(PATH_STATS, [FileUploadController::class, 'getStorageStats']);
            $adminFiles->post('/cleanup', [FileUploadController::class, 'cleanupExpiredFiles']);
        })->add(AuthMiddleware::class)->add(AdminMiddleware::class);

        // Backward-compatible aliases for activities listing and categories
        $group->get('/activities', [CarbonTrackController::class, 'getUserRecords'])->add(AuthMiddleware::class);
        $group->get('/activities/categories', [CarbonActivityController::class, 'getActivities']);
    });

    // Backward-compatible alias group for clients calling /api/auth/* (without version prefix)
    $app->group('/api', function (RouteCollectorProxy $api) use ($registerSchoolRoutes) {
        $api->group('/auth', function (RouteCollectorProxy $auth) {
            $auth->post('/register', [AuthController::class, 'register']);
            $auth->post('/login', [AuthController::class, 'login']);
            $auth->post('/logout', [AuthController::class, 'logout']);
            $auth->post('/send-verification-code', [AuthController::class, 'sendVerificationCode']);
            $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
            $auth->post('/verify-email', [AuthController::class, 'verifyEmail']);
        });

        // Backward-compatible aliases for schools endpoints (mirror /api/v1)
        $registerSchoolRoutes($api);
    });

    // Catch-all route for 404
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
        $request->getMethod();
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Route not found',
            'code' => 'ROUTE_NOT_FOUND'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', CONTENT_TYPE_JSON);
    });
};

