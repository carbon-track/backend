<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\CloudflareR2Service;
use CarbonTrack\Services\ErrorLogService;
use PDO;

class ProductController
{
    private PDO $db;
    private MessageService $messageService;
    private AuditLogService $auditLog;
    private AuthService $authService;
    private ?ErrorLogService $errorLogService;
    private ?CloudflareR2Service $r2Service;

    private const ERR_INTERNAL = 'Internal server error';
    private const ERR_ADMIN_REQUIRED = 'Admin access required';
    private const ERRLOG_PREFIX = 'ErrorLogService failed: ';

    public function __construct(
        PDO $db,
        MessageService $messageService,
        AuditLogService $auditLog,
        AuthService $authService,
        ErrorLogService $errorLogService = null,
        CloudflareR2Service $r2Service = null
    ) {
        $this->db = $db;
        $this->messageService = $messageService;
        $this->auditLog = $auditLog;
        $this->authService = $authService;
        $this->errorLogService = $errorLogService;
        $this->r2Service = $r2Service;
    }

    /**
     * 获取商品列表
     */
    public function getProducts(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            // 管理端调用该方法时，会经过 AdminMiddleware，这里再做一次判定用于放宽筛选条件
            $currentUser = null;
            try { $currentUser = $this->authService->getCurrentUser($request); } catch (\Throwable $ignore) {}
            $isAdminCall = $currentUser && $this->authService->isAdminUser($currentUser);
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(50, max(10, intval($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $where = ['p.deleted_at IS NULL'];
            $bindings = [];

            // 前台商品列表默认仅展示 active；管理员列表可查看所有或按 status 过滤
            if (!$isAdminCall) {
                $where[] = 'p.status = "active"';
            } else if (!empty($params['status'])) {
                $where[] = 'p.status = :status';
                $bindings['status'] = $params['status'];
            }

            if (!empty($params['category'])) {
                $where[] = 'p.category = :category';
                $bindings['category'] = $params['category'];
            }

            if (!empty($params['search'])) {
                $where[] = '(p.name LIKE :search OR p.description LIKE :search)';
                $bindings['search'] = '%' . $params['search'] . '%';
            }

            if (isset($params['min_points'])) {
                $where[] = 'p.points_required >= :min_points';
                $bindings['min_points'] = intval($params['min_points']);
            }

            if (isset($params['max_points'])) {
                $where[] = 'p.points_required <= :max_points';
                $bindings['max_points'] = intval($params['max_points']);
            }

            $whereClause = implode(' AND ', $where);

            // 获取总数
            $countSql = "SELECT COUNT(*) as total FROM products p WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindings);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取商品列表
            $sql = "
                SELECT 
                    p.*,
                    COALESCE(e.total_exchanged, 0) as total_exchanged
                FROM products p
                LEFT JOIN (
                    SELECT product_id, COUNT(*) as total_exchanged
                    FROM point_exchanges 
                    WHERE status = 'completed'
                    GROUP BY product_id
                ) e ON p.id = e.product_id
                WHERE {$whereClause}
                ORDER BY p.sort_order ASC, p.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理图片字段
            foreach ($products as &$product) {
                $product['images'] = $product['images'] ? json_decode($product['images'], true) : [];
                $product['is_available'] = $product['stock'] > 0 || $product['stock'] === -1; // -1表示无限库存
                // 兼容前端显示字段：提供 image_url 与 price 的别名
                if (empty($product['image_url'])) {
                    // 优先使用 image_path，其次 images[0].public_url
                    $firstImage = null;
                    if (is_array($product['images']) && count($product['images']) > 0) {
                        $first = $product['images'][0];
                        if (is_array($first)) {
                            $firstImage = $first['public_url'] ?? ($first['url'] ?? null);
                        } elseif (is_string($first)) {
                            $firstImage = $first;
                        }
                    }
                    $imagePath = $product['image_path'] ?? null;
                    $product['image_url'] = $imagePath ?: ($firstImage ?: null);
                }
                $product['price'] = isset($product['points_required']) ? (int)$product['points_required'] : null;
            }

            $pages = (int)ceil($total / $limit);
            return $this->json($response, [
                'success' => true,
                'data' => [
                    'products' => $products,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => intval($total),
                        'pages' => $pages,
                        // 别名，方便前端统一解析
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total_items' => intval($total),
                        'total_pages' => $pages
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            // Log original error for debugging in tests
            error_log('ProductController::getProducts error: ' . $e->getMessage());
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 获取商品详情
     */
    public function getProductDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $productId = $args['id'];

            $sql = "
                SELECT 
                    p.*,
                    COALESCE(e.total_exchanged, 0) as total_exchanged
                FROM products p
                LEFT JOIN (
                    SELECT product_id, COUNT(*) as total_exchanged
                    FROM point_exchanges 
                    WHERE status = 'completed'
                    GROUP BY product_id
                ) e ON p.id = e.product_id
                WHERE p.id = :product_id AND p.deleted_at IS NULL
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['product_id' => $productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                return $this->json($response, ['error' => 'Product not found'], 404);
            }

            // 处理图片字段
            $product['images'] = $product['images'] ? json_decode($product['images'], true) : [];
            $product['is_available'] = $product['stock'] > 0 || $product['stock'] === -1;
            // 别名字段，兼容前端
            if (empty($product['image_url'])) {
                $firstImage = null;
                if (is_array($product['images']) && count($product['images']) > 0) {
                    $first = $product['images'][0];
                    if (is_array($first)) {
                        $firstImage = $first['public_url'] ?? ($first['url'] ?? null);
                    } elseif (is_string($first)) {
                        $firstImage = $first;
                    }
                }
                $imagePath = $product['image_path'] ?? null;
                $product['image_url'] = $imagePath ?: ($firstImage ?: null);
            }
            $product['price'] = isset($product['points_required']) ? (int)$product['points_required'] : null;

            return $this->json($response, [
                'success' => true,
                'data' => $product
            ]);

        } catch (\Exception $e) {
            error_log('ProductController::getProductDetail error: ' . $e->getMessage());
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 兑换商品
     */
    public function exchangeProduct(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $data = $request->getParsedBody();
            if (!is_array($data)) { $data = []; }

            // 字段同义词兼容：shipping_address -> delivery_address, address -> delivery_address
            // phone|mobile|contact -> contact_phone, remark|comments -> notes
            $synonyms = [
                'delivery_address' => ['shipping_address', 'address', 'ship_address'],
                'contact_phone' => ['phone', 'mobile', 'tel', 'contact'],
                'notes' => ['remark', 'remarks', 'comment', 'comments', 'note']
            ];
            foreach ($synonyms as $primary => $alts) {
                if (!array_key_exists($primary, $data) || $data[$primary] === '' || $data[$primary] === null) {
                    foreach ($alts as $alt) {
                        if (array_key_exists($alt, $data) && $data[$alt] !== '' && $data[$alt] !== null) {
                            $data[$primary] = $data[$alt];
                            break;
                        }
                    }
                }
            }

            if (!isset($data['product_id'])) {
                return $this->json($response, ['error' => 'Product ID is required'], 400);
            }

            $productId = $data['product_id'];
            $quantity = max(1, intval($data['quantity'] ?? 1));

            // 开始事务
            $this->db->beginTransaction();

            try {
                // 获取商品信息并锁定
                $sql = "SELECT * FROM products WHERE id = :id AND deleted_at IS NULL";
                try {
                    $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
                } catch (\Throwable $driverError) {
                    $driver = null;
                }
                if ($driver !== 'sqlite') {
                    $sql .= ' FOR UPDATE';
                }
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['id' => $productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$product) {
                    throw new \Exception('Product not found');
                }

                if ($product['status'] !== 'active') {
                    throw new \Exception('Product is not available');
                }

                // 检查库存
                if ($product['stock'] !== -1 && $product['stock'] < $quantity) {
                    throw new \Exception('Insufficient stock');
                }

                $totalPoints = $product['points_required'] * $quantity;

                // 检查用户积分
                if ($user['points'] < $totalPoints) {
                    throw new \Exception('Insufficient points');
                }

                // 扣除用户积分
                $sql = "UPDATE users SET points = points - :points WHERE id = :user_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['points' => $totalPoints, 'user_id' => $user['id']]);

                // 更新商品库存
                if ($product['stock'] !== -1) {
                    $sql = "UPDATE products SET stock = stock - :quantity WHERE id = :product_id";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute(['quantity' => $quantity, 'product_id' => $productId]);
                }

                // 创建兑换记录
                $exchangeId = $this->createExchangeRecord([
                    'user_id' => $user['id'],
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'points_used' => $totalPoints,
                    'product_name' => $product['name'],
                    'product_price' => $product['points_required'],
                    'delivery_address' => $data['delivery_address'] ?? null,
                    'contact_phone' => $data['contact_phone'] ?? null,
                    'notes' => $data['notes'] ?? null
                ]);

                // 记录积分交易
                $this->recordPointTransaction(
                    $user['id'],
                    -$totalPoints,
                    'product_exchange',
                    "兑换商品：{$product['name']} x{$quantity}",
                    'point_exchanges',
                    $exchangeId
                );

                // 记录审计日志
                $this->auditLog->log(
                    $user['id'],
                    'product_exchanged',
                    'point_exchanges',
                    $exchangeId,
                    [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'points_used' => $totalPoints
                    ]
                );

                // 发送站内信
                $this->messageService->sendMessage(
                    $user['id'],
                    'product_exchanged',
                    '商品兑换成功',
                    "您已成功兑换 {$product['name']} x{$quantity}，消耗 {$totalPoints} 积分。我们将尽快为您安排发货。",
                    'normal'
                );

                // 通知管理员
                $this->notifyAdminsNewExchange($exchangeId, $user, $product, $quantity);

                $this->db->commit();

                return $this->json($response, [
                    'success' => true,
                    'exchange_id' => $exchangeId,
                    'points_used' => $totalPoints,
                    'remaining_points' => $user['points'] - $totalPoints,
                    'message' => 'Product exchanged successfully'
                ]);

            } catch (\Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, [
                'success' => false,
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
                'code' => 'EXCHANGE_FAILED'
            ], 400);
        }
    }

    /**
     * 获取当前用户兑换历史（路由别名，复用 getUserExchanges）
     */
    public function getExchangeTransactions(Request $request, Response $response): Response
    {
        return $this->getUserExchanges($request, $response);
    }

    /**
     * 获取当前用户某条兑换详情
     */
    public function getExchangeTransaction(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }
            $exchangeId = $args['id'];
            $sql = "SELECT * FROM point_exchanges WHERE id = :id AND user_id = :uid AND deleted_at IS NULL";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $exchangeId, 'uid' => $user['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return $this->json($response, ['error' => 'Exchange not found'], 404);
            }
            return $this->json($response, ['success' => true, 'data' => $row]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 获取用户兑换历史
     */
    public function getUserExchanges(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->json($response, ['error' => 'Unauthorized'], 401);
            }

            $params = $request->getQueryParams();
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(50, max(10, intval($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // 获取总数
            $countSql = "
                SELECT COUNT(*) as total
                FROM point_exchanges 
                WHERE user_id = :user_id AND deleted_at IS NULL
            ";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute(['user_id' => $user['id']]);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取兑换记录
            $sql = "
                SELECT 
                    e.*,
                    p.name as current_product_name,
                    p.images as current_product_images
                FROM point_exchanges e
                LEFT JOIN products p ON e.product_id = p.id
                WHERE e.user_id = :user_id AND e.deleted_at IS NULL
                ORDER BY e.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue('user_id', $user['id']);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $exchanges = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 处理图片字段
            foreach ($exchanges as &$exchange) {
                $exchange['current_product_images'] = $exchange['current_product_images'] 
                    ? json_decode($exchange['current_product_images'], true) 
                    : [];
            }

            return $this->json($response, [
                'success' => true,
                'data' => $exchanges,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => intval($total),
                    'pages' => ceil($total / $limit)
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员获取兑换记录
     */
    public function getExchangeRecords(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $params = $request->getQueryParams();
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(50, max(10, intval($params['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            // 构建查询条件
            $where = ['e.deleted_at IS NULL'];
            $bindings = [];

            if (!empty($params['status'])) {
                $where[] = 'e.status = :status';
                $bindings['status'] = $params['status'];
            }

            if (!empty($params['user_id'])) {
                $where[] = 'e.user_id = :user_id';
                $bindings['user_id'] = $params['user_id'];
            }

            $whereClause = implode(' AND ', $where);

            // 获取总数
            $countSql = "SELECT COUNT(*) as total FROM point_exchanges e WHERE {$whereClause}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindings);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // 获取兑换记录
            $sql = "
                SELECT 
                    e.*,
                    u.username,
                    u.email,
                    p.name as current_product_name,
                    p.image_path as current_product_image_path,
                    p.images as current_product_images
                FROM point_exchanges e
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN products p ON e.product_id = p.id
                WHERE {$whereClause}
                ORDER BY e.created_at DESC
                LIMIT :limit OFFSET :offset
            ";

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $exchanges = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 映射前端期望字段
            foreach ($exchanges as &$ex) {
                // 别名：用户与积分
                $ex['user_username'] = $ex['username'] ?? null;
                $ex['user_email'] = $ex['email'] ?? null;
                if (!isset($ex['total_points']) && isset($ex['points_used'])) {
                    $ex['total_points'] = (int)$ex['points_used'];
                }
                if (!isset($ex['shipping_address']) && isset($ex['delivery_address'])) {
                    $ex['shipping_address'] = $ex['delivery_address'];
                }
                if (!isset($ex['admin_notes']) && isset($ex['notes'])) {
                    $ex['admin_notes'] = $ex['notes'];
                }

                // 产品图片URL
                $imgUrl = null;
                if (!empty($ex['current_product_images'])) {
                    $imgs = json_decode($ex['current_product_images'], true);
                    if (is_array($imgs) && count($imgs) > 0) {
                        $first = $imgs[0];
                        if (is_array($first)) {
                            $imgUrl = $first['public_url'] ?? ($first['url'] ?? null);
                        } elseif (is_string($first)) {
                            $imgUrl = $first;
                        }
                    }
                }
                if (!$imgUrl && !empty($ex['current_product_image_path'])) {
                    $imgUrl = $ex['current_product_image_path'];
                }
                if (!isset($ex['product_image_url'])) {
                    $ex['product_image_url'] = $imgUrl;
                }
            }

            $pages = (int)ceil($total / $limit);
            return $this->json($response, [
                'success' => true,
                'data' => $exchanges,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => intval($total),
                    'pages' => $pages,
                    // 别名
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_items' => intval($total),
                    'total_pages' => $pages
                ]
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员获取单个兑换记录详情
     */
    public function getExchangeRecordDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $exchangeId = $args['id'];
            $sql = "
                SELECT 
                    e.*,
                    u.username,
                    u.email,
                    p.name as current_product_name,
                    p.image_path as current_product_image_path,
                    p.images as current_product_images
                FROM point_exchanges e
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN products p ON e.product_id = p.id
                WHERE e.id = :id AND e.deleted_at IS NULL
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $exchangeId]);
            $exchange = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$exchange) {
                return $this->json($response, ['error' => 'Exchange not found'], 404);
            }

            // 映射别名
            $exchange['user_username'] = $exchange['username'] ?? null;
            $exchange['user_email'] = $exchange['email'] ?? null;
            if (!isset($exchange['total_points']) && isset($exchange['points_used'])) {
                $exchange['total_points'] = (int)$exchange['points_used'];
            }
            if (!isset($exchange['shipping_address']) && isset($exchange['delivery_address'])) {
                $exchange['shipping_address'] = $exchange['delivery_address'];
            }
            if (!isset($exchange['admin_notes']) && isset($exchange['notes'])) {
                $exchange['admin_notes'] = $exchange['notes'];
            }
            // 产品图片
            $imgUrl = null;
            if (!empty($exchange['current_product_images'])) {
                $imgs = json_decode($exchange['current_product_images'], true);
                if (is_array($imgs) && count($imgs) > 0) {
                    $first = $imgs[0];
                    if (is_array($first)) {
                        $imgUrl = $first['public_url'] ?? ($first['url'] ?? null);
                    } elseif (is_string($first)) {
                        $imgUrl = $first;
                    }
                }
            }
            if (!$imgUrl && !empty($exchange['current_product_image_path'])) {
                $imgUrl = $exchange['current_product_image_path'];
            }
            $exchange['product_image_url'] = $exchange['product_image_url'] ?? $imgUrl;

            return $this->json($response, [
                'success' => true,
                'data' => $exchange
            ]);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) { error_log(self::ERRLOG_PREFIX . $ignore->getMessage()); }
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员更新兑换状态
     */
    public function updateExchangeStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $exchangeId = $args['id'];
            $data = $request->getParsedBody();

            if (!isset($data['status']) || !in_array($data['status'], ['processing', 'shipped', 'completed', 'cancelled'])) {
                return $this->json($response, ['error' => 'Invalid status'], 400);
            }

            $status = $data['status'];
            $notes = $data['notes'] ?? null;
            $trackingNumber = $data['tracking_number'] ?? null;

            // 更新兑换状态
            $sql = "
                UPDATE point_exchanges 
                SET status = :status, 
                    notes = :notes,
                    tracking_number = :tracking_number,
                    updated_at = NOW()
                WHERE id = :exchange_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'status' => $status,
                'notes' => $notes,
                'tracking_number' => $trackingNumber,
                'exchange_id' => $exchangeId
            ]);

            // 获取兑换信息用于通知
            $exchange = $this->getExchangeRecord($exchangeId);
            if ($exchange) {
                // 发送状态更新通知
                $this->sendStatusUpdateNotification($exchange, $status, $notes, $trackingNumber);
            }

            // 记录审计日志
            $this->auditLog->log(
                $user['id'],
                'exchange_status_updated',
                'point_exchanges',
                $exchangeId,
                ['status' => $status, 'notes' => $notes]
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Exchange status updated successfully'
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 获取商品分类
     */
    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $sql = "
                SELECT 
                    category,
                    COUNT(*) as product_count
                FROM products 
                WHERE deleted_at IS NULL
                GROUP BY category
                ORDER BY category
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $this->json($response, [
                'success' => true,
                'data' => $categories
            ]);

        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员创建商品
     */
    public function createProduct(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $data = $request->getParsedBody() ?: [];

            // 必填校验
            if (empty($data['name']) || !isset($data['points_required']) || !isset($data['stock'])) {
                return $this->json($response, ['error' => 'Missing required fields: name, points_required, stock'], 400);
            }

            $sql = "
                INSERT INTO products (
                    name, category, points_required, description, image_path, images,
                    stock, status, sort_order, created_at
                ) VALUES (
                    :name, :category, :points_required, :description, :image_path, :images,
                    :stock, :status, :sort_order, NOW()
                )
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'name' => $data['name'],
                'category' => $data['category'] ?? null,
                'points_required' => (int)$data['points_required'],
                'description' => $data['description'] ?? '',
                'image_path' => $data['image_path'] ?? ($data['image_url'] ?? null),
                'images' => isset($data['images']) ? (is_string($data['images']) ? $data['images'] : json_encode($data['images'])) : null,
                'stock' => (int)$data['stock'],
                'status' => in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active',
                'sort_order' => (int)($data['sort_order'] ?? 0),
            ]);

            $newId = (int)$this->db->lastInsertId();

            // 审计日志
            $this->auditLog->log($user['id'], 'product_created', 'products', (string)$newId, [
                'name' => $data['name']
            ]);

            return $this->json($response, ['success' => true, 'id' => $newId, 'message' => 'Product created successfully']);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员更新商品
     */
    public function updateProduct(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->json($response, ['error' => 'Invalid product id'], 400);
            }

            $data = $request->getParsedBody() ?: [];

            $fields = [];
            $params = ['id' => $id];

            $assign = function(string $column, $value) use (&$fields, &$params) {
                $fields[] = "$column = :$column";
                $params[$column] = $value;
            };

            foreach (['name','category','description'] as $col) {
                if (array_key_exists($col, $data)) { $assign($col, $data[$col]); }
            }
            if (array_key_exists('points_required', $data)) { $assign('points_required', (int)$data['points_required']); }
            if (array_key_exists('stock', $data)) { $assign('stock', (int)$data['stock']); }
            if (array_key_exists('status', $data)) {
                $status = in_array($data['status'], ['active','inactive'], true) ? $data['status'] : 'inactive';
                $assign('status', $status);
            }
            if (array_key_exists('sort_order', $data)) { $assign('sort_order', (int)$data['sort_order']); }
            if (array_key_exists('image_path', $data) || array_key_exists('image_url', $data)) {
                $assign('image_path', $data['image_path'] ?? $data['image_url']);
            }
            if (array_key_exists('images', $data)) {
                $images = is_string($data['images']) ? $data['images'] : json_encode($data['images']);
                $assign('images', $images);
            }

            if (empty($fields)) {
                return $this->json($response, ['error' => 'No fields to update'], 400);
            }

            $sql = 'UPDATE products SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id AND deleted_at IS NULL';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $this->auditLog->log($user['id'], 'product_updated', 'products', (string)$id, ['updated_fields' => array_keys($data)]);

            return $this->json($response, ['success' => true, 'message' => 'Product updated successfully']);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 管理员删除商品（软删除）
     */
    public function deleteProduct(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->json($response, ['error' => self::ERR_ADMIN_REQUIRED], 403);
            }

            $id = (int)($args['id'] ?? 0);
            if ($id <= 0) {
                return $this->json($response, ['error' => 'Invalid product id'], 400);
            }

            $stmt = $this->db->prepare('UPDATE products SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $id]);

            $this->auditLog->log($user['id'], 'product_deleted', 'products', (string)$id, []);

            return $this->json($response, ['success' => true, 'message' => 'Product deleted successfully']);
        } catch (\Exception $e) {
            try { $this->errorLogService->logException($e, $request); } catch (\Throwable $ignore) {}
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 创建兑换记录
     */
    private function createExchangeRecord(array $data): string
    {
        $sql = "
            INSERT INTO point_exchanges (
                id, user_id, product_id, quantity, points_used, 
                product_name, product_price, delivery_address, 
                contact_phone, notes, status, created_at
            ) VALUES (
                :id, :user_id, :product_id, :quantity, :points_used,
                :product_name, :product_price, :delivery_address,
                :contact_phone, :notes, 'pending', NOW()
            )
        ";

        $exchangeId = $this->generateUuid();
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $exchangeId,
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id'],
            'quantity' => $data['quantity'],
            'points_used' => $data['points_used'],
            'product_name' => $data['product_name'],
            'product_price' => $data['product_price'],
            'delivery_address' => $data['delivery_address'],
            'contact_phone' => $data['contact_phone'],
            'notes' => $data['notes']
        ]);

        return $exchangeId;
    }

    /**
     * 记录积分交易
     */
    private function recordPointTransaction(int $userId, float $points, string $type, string $description, ?string $relatedTable = null, ?string $relatedId = null): void
    {
        $sql = "
            INSERT INTO points_transactions (
                id, user_id, points, type, description, 
                related_table, related_id, created_at
            ) VALUES (
                :id, :user_id, :points, :type, :description,
                :related_table, :related_id, NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $this->generateUuid(),
            'user_id' => $userId,
            'points' => $points,
            'type' => $type,
            'description' => $description,
            'related_table' => $relatedTable,
            'related_id' => $relatedId
        ]);
    }

    /**
     * 获取兑换记录
     */
    private function getExchangeRecord(string $exchangeId): ?array
    {
        $sql = "
            SELECT e.*, u.username, u.email
            FROM point_exchanges e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.id = :id AND e.deleted_at IS NULL
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $exchangeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * 通知管理员新兑换
     */
    private function notifyAdminsNewExchange(string $exchangeId, array $user, array $product, int $quantity): void
    {
        // 获取所有管理员
        $sql = "SELECT id FROM users WHERE is_admin = 1 AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($admins as $admin) {
            $this->messageService->sendMessage(
                $admin['id'],
                'new_exchange_pending',
                '新的商品兑换订单',
                "用户 {$user['username']} 兑换了 {$product['name']} x{$quantity}，请及时处理。",
                'high'
            );
        }
    }

    /**
     * 发送状态更新通知
     */
    private function sendStatusUpdateNotification(array $exchange, string $status, ?string $notes, ?string $trackingNumber): void
    {
        $statusMessages = [
            'processing' => '您的兑换订单正在处理中',
            'shipped' => '您的兑换商品已发货',
            'completed' => '您的兑换订单已完成',
            'cancelled' => '您的兑换订单已取消'
        ];

        $title = $statusMessages[$status] ?? '兑换状态更新';
        $message = "您的兑换订单（{$exchange['product_name']} x{$exchange['quantity']}）状态已更新为：{$title}";

        if ($trackingNumber) {
            $message .= "\n物流单号：{$trackingNumber}";
        }

        if ($notes) {
            $message .= "\n备注：{$notes}";
        }

        $this->messageService->sendMessage(
            $exchange['user_id'],
            'exchange_status_updated',
            $title,
            $message,
            'normal'
        );
    }

    /**
     * 生成UUID
     */
    private function generateUuid(): string
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
    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}

