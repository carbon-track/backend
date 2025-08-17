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
use CarbonTrack\Middleware\AuthMiddleware;
use CarbonTrack\Middleware\AdminMiddleware;

return function (App $app) {
    // Health check
    $app->get('/', function ($request, $response) {
        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'CarbonTrack API is running',
            'version' => '1.0.0',
            'timestamp' => date('Y-m-d H:i:s')
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // API v1 routes
    $app->group('/api/v1', function (RouteCollectorProxy $group) {
        
        // Authentication routes (public)
        $group->group('/auth', function (RouteCollectorProxy $auth) {
            $auth->post('/register', [AuthController::class, 'register']);
            $auth->post('/login', [AuthController::class, 'login']);
            $auth->post('/logout', [AuthController::class, 'logout']);
            $auth->post('/send-verification-code', [AuthController::class, 'sendVerificationCode']);
            $auth->post('/reset-password', [AuthController::class, 'resetPassword']);
            $auth->post('/verify-email', [AuthController::class, 'verifyEmail']);
        });

        // User routes
        $group->group('/users', function (RouteCollectorProxy $users) {
            $users->get('/me', [UserController::class, 'getCurrentUser']);
            $users->put('/me', [UserController::class, 'updateCurrentUser']);
            $users->put('/me/profile', [UserController::class, 'updateProfile']);
            $users->put('/me/avatar', [UserController::class, 'selectAvatar']);
            $users->get('/me/points-history', [UserController::class, 'getPointsHistory']);
            $users->get('/me/stats', [UserController::class, 'getUserStats']);
            $users->get('/me/chart-data', [UserController::class, 'getChartData']);
            $users->get('/me/activities', [UserController::class, 'getRecentActivities']);
            $users->get('/{id:[0-9]+}', [UserController::class, 'getUser']);
            $users->put('/{id:[0-9]+}', [UserController::class, 'updateUser']);
            $users->delete('/{id:[0-9]+}', [UserController::class, 'deleteUser']);
        })->add(AuthMiddleware::class);

        // Avatar routes (public for listing, authenticated for selection)
        $group->get('/avatars', [AvatarController::class, 'getAvatars']);
        $group->get('/avatars/categories', [AvatarController::class, 'getAvatarCategories']);

        // Carbon activities routes (public for listing)
        $group->get('/carbon-activities', [CarbonActivityController::class, 'getActivities']);
        $group->get('/carbon-activities/{id}', [CarbonActivityController::class, 'getActivity']);

        // Carbon tracking routes
        $group->group('/carbon-track', function (RouteCollectorProxy $carbon) {
            // Calculate carbon savings
            $carbon->post('/calculate', [CarbonTrackController::class, 'calculate']);
            // Submit record
            $carbon->post('/record', [CarbonTrackController::class, 'submitRecord']);
            // User transactions
            $carbon->get('/transactions', [CarbonTrackController::class, 'getUserRecords']);
            $carbon->get('/transactions/{id:[0-9a-fA-F\-]+}', [CarbonTrackController::class, 'getRecordDetail']);
            // Admin review actions
            $carbon->put('/transactions/{id:[0-9a-fA-F\-]+}/approve', [CarbonTrackController::class, 'reviewRecord']);
            $carbon->put('/transactions/{id:[0-9a-fA-F\-]+}/reject', [CarbonTrackController::class, 'reviewRecord']);
            // Optional: delete (soft delete) transaction
            $carbon->delete('/transactions/{id:[0-9a-fA-F\-]+}', [CarbonTrackController::class, 'deleteTransaction']);
            $carbon->get('/factors', [CarbonTrackController::class, 'getCarbonFactors']);
            $carbon->get('/stats', [CarbonTrackController::class, 'getUserStats']);
        })->add(AuthMiddleware::class);

        // Product routes
        $group->group('/products', function (RouteCollectorProxy $products) {
            $products->get('', [ProductController::class, 'getProducts']);
            $products->get('/{id:[0-9]+}', [ProductController::class, 'getProductDetail']);
            $products->get('/categories', [ProductController::class, 'getCategories']);
            $products->post('', [ProductController::class, 'createProduct']);
            $products->put('/{id:[0-9]+}', [ProductController::class, 'updateProduct']);
            $products->delete('/{id:[0-9]+}', [ProductController::class, 'deleteProduct']);
        });

        // Exchange routes
        $group->group('/exchange', function (RouteCollectorProxy $exchange) {
            $exchange->post('', [ProductController::class, 'exchangeProduct']);
            $exchange->get('/transactions', [ProductController::class, 'getExchangeTransactions']);
            $exchange->get('/transactions/{id:[0-9]+}', [ProductController::class, 'getExchangeTransaction']);
        })->add(AuthMiddleware::class);

        // Message routes
        $group->group('/messages', function (RouteCollectorProxy $messages) {
            $messages->get('', [MessageController::class, 'getUserMessages']);
            $messages->get('/{id:[0-9]+}', [MessageController::class, 'getMessageDetail']);
            $messages->put('/{id:[0-9]+}/read', [MessageController::class, 'markAsRead']);
            $messages->delete('/{id:[0-9]+}', [MessageController::class, 'deleteMessage']);
            $messages->get('/unread-count', [MessageController::class, 'getUnreadCount']);
            $messages->put('/mark-all-read', [MessageController::class, 'markAllAsRead']);
        })->add(AuthMiddleware::class);

        // School routes (public for listing)
        $group->get('/schools', [SchoolController::class, 'getSchools']);
        
        // Admin routes
        $group->group('/admin', function (RouteCollectorProxy $admin) {
            $admin->get('/users', [AdminController::class, 'getUsers']);
            $admin->put('/users/{id:[0-9]+}', [AdminController::class, 'updateUser']);
            $admin->delete('/users/{id:[0-9]+}', [AdminController::class, 'deleteUser']);
            $admin->get('/transactions/pending', [AdminController::class, 'getPendingTransactions']);
            $admin->get('/stats', [AdminController::class, 'getStats']);
            $admin->get('/logs', [AdminController::class, 'getLogs']);
            $admin->post('/schools', [SchoolController::class, 'createSchool']);
            $admin->put('/schools/{id:[0-9]+}', [SchoolController::class, 'updateSchool']);
            $admin->delete('/schools/{id:[0-9]+}', [SchoolController::class, 'deleteSchool']);
            
            // Carbon activities management
            $admin->get('/carbon-activities', [CarbonActivityController::class, 'getActivitiesForAdmin']);
            $admin->post('/carbon-activities', [CarbonActivityController::class, 'createActivity']);
            $admin->put('/carbon-activities/{id}', [CarbonActivityController::class, 'updateActivity']);
            $admin->delete('/carbon-activities/{id}', [CarbonActivityController::class, 'deleteActivity']);
            $admin->post('/carbon-activities/{id}/restore', [CarbonActivityController::class, 'restoreActivity']);
            $admin->get('/carbon-activities/{id}/statistics', [CarbonActivityController::class, 'getActivityStatistics']);
            $admin->get('/carbon-activities/statistics', [CarbonActivityController::class, 'getActivityStatistics']);
            $admin->put('/carbon-activities/sort-orders', [CarbonActivityController::class, 'updateSortOrders']);

            // Admin activities review (alias for pending records)
            $admin->get('/activities', [CarbonTrackController::class, 'getPendingRecords']);
            $admin->put('/activities/{id:[0-9a-fA-F\-]+}/review', [CarbonTrackController::class, 'reviewRecord']);

            // Admin exchanges
            $admin->get('/exchanges', [ProductController::class, 'getExchangeRecords']);
            $admin->put('/exchanges/{id:[0-9]+}/status', [ProductController::class, 'updateExchangeStatus']);

            // Admin products
            $admin->get('/products', [ProductController::class, 'getProducts']);
            $admin->post('/products', [ProductController::class, 'createProduct']);
            $admin->put('/products/{id:[0-9]+}', [ProductController::class, 'updateProduct']);
            $admin->delete('/products/{id:[0-9]+}', [ProductController::class, 'deleteProduct']);
            
            // Avatar management
            $admin->get('/avatars', [AvatarController::class, 'getAvatars']);
            $admin->get('/avatars/{id:[0-9]+}', [AvatarController::class, 'getAvatar']);
            $admin->post('/avatars', [AvatarController::class, 'createAvatar']);
            $admin->put('/avatars/{id:[0-9]+}', [AvatarController::class, 'updateAvatar']);
            $admin->delete('/avatars/{id:[0-9]+}', [AvatarController::class, 'deleteAvatar']);
            $admin->post('/avatars/{id:[0-9]+}/restore', [AvatarController::class, 'restoreAvatar']);
            $admin->put('/avatars/{id:[0-9]+}/set-default', [AvatarController::class, 'setDefaultAvatar']);
            $admin->put('/avatars/sort-orders', [AvatarController::class, 'updateSortOrders']);
            $admin->get('/avatars/usage-stats', [AvatarController::class, 'getAvatarUsageStats']);
            $admin->post('/avatars/upload', [AvatarController::class, 'uploadAvatarFile']);
        })->add(AuthMiddleware::class)->add(AdminMiddleware::class);

        // File upload routes (authenticated users)
        $group->group('/files', function (RouteCollectorProxy $files) {
            $files->post('/upload', [FileUploadController::class, 'uploadFile']);
            $files->post('/upload-multiple', [FileUploadController::class, 'uploadMultipleFiles']);
            $files->delete('/{path:.+}', [FileUploadController::class, 'deleteFile']);
            $files->get('/{path:.+}/info', [FileUploadController::class, 'getFileInfo']);
            $files->get('/{path:.+}/presigned-url', [FileUploadController::class, 'generatePresignedUrl']);
        })->add(AuthMiddleware::class);

        // Admin file management routes
        $group->group('/admin/files', function (RouteCollectorProxy $adminFiles) {
            $adminFiles->get('/stats', [FileUploadController::class, 'getStorageStats']);
            $adminFiles->post('/cleanup', [FileUploadController::class, 'cleanupExpiredFiles']);
        })->add(AuthMiddleware::class)->add(AdminMiddleware::class);

        // Backward-compatible aliases for activities listing and categories
        $group->get('/activities', [CarbonTrackController::class, 'getUserRecords'])->add(AuthMiddleware::class);
        $group->get('/activities/categories', [CarbonActivityController::class, 'getActivities']);
    });

    // Catch-all route for 404
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
        $response->getBody()->write(json_encode([
            'success' => false,
            'message' => 'Route not found',
            'code' => 'ROUTE_NOT_FOUND'
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    });
};

