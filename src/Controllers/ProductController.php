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
use PDOException;

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

            $tagSlugs = [];
            if (isset($params['tag'])) {
                if (is_array($params['tag'])) {
                    $tagSlugs = array_merge($tagSlugs, $params['tag']);
                } else {
                    $tagSlugs[] = (string)$params['tag'];
                }
            }
            if (isset($params['tags'])) {
                if (is_array($params['tags'])) {
                    $tagSlugs = array_merge($tagSlugs, $params['tags']);
                } else {
                    $tagSlugs = array_merge($tagSlugs, explode(',', (string)$params['tags']));
                }
            }
            $tagSlugs = array_values(array_unique(array_filter(array_map('trim', $tagSlugs), static function ($slug) {
                return $slug !== '';
            })));

            // 前台商品列表默认仅展示 active；管理员列表可查看所有或按 status 过滤
            if (!$isAdminCall) {
                $where[] = 'p.status = "active"';
            } else if (!empty($params['status'])) {
                $where[] = 'p.status = :status';
                $bindings['status'] = $params['status'];
            }

            if (!empty($params['category'])) {
                $rawCategory = trim((string)$params['category']);
                if ($rawCategory !== '') {
                    $categorySlug = $this->normalizeSlug($rawCategory);
                    $categoryNames = [];
                    if ($categorySlug !== '') {
                        $resolvedCategories = $this->fetchCategoriesBySlugs([$categorySlug]);
                        if (isset($resolvedCategories[$categorySlug])) {
                            $categoryNames[] = $resolvedCategories[$categorySlug]['name'];
                        }
                    }
                    $categoryNames[] = $rawCategory;

                    $where[] = '(
                        p.category_slug = :filter_category_slug
                        OR p.category = :filter_category_name
                        OR p.category = :filter_category_raw
                    )';
                    $bindings['filter_category_slug'] = $categorySlug ?: $this->slugifyCategoryName($rawCategory);
                    $bindings['filter_category_name'] = $categoryNames[0];
                    $bindings['filter_category_raw'] = $rawCategory;
                }
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

            if (!empty($tagSlugs)) {
                $tagPlaceholders = [];
                foreach ($tagSlugs as $index => $slug) {
                    $paramKey = 'tag_slug_' . $index;
                    $tagPlaceholders[] = ':' . $paramKey;
                    $bindings[$paramKey] = $slug;
                }
                $where[] = 'EXISTS (
                    SELECT 1
                    FROM product_tag_map ptm
                    INNER JOIN product_tags pt ON ptm.tag_id = pt.id
                    WHERE ptm.product_id = p.id
                    AND pt.slug IN (' . implode(', ', $tagPlaceholders) . ')
                )';
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

            $productIds = array_map(static function ($item) {
                return isset($item['id']) ? (int)$item['id'] : null;
            }, $products);
            $productIds = array_values(array_filter($productIds, static fn($v) => $v !== null));
            $tagsMap = $productIds ? $this->loadTagsForProducts($productIds) : [];

            foreach ($products as &$product) {
                $product = $this->prepareProductPayload($product, $isAdminCall, $request);
                $product['tags'] = $tagsMap[$product['id']] ?? [];
            }
            unset($product);

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
            $this->logControllerException($e, $request, 'ProductController::getProducts error: ' . $e->getMessage());
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 获取商品详情
     */
    public function getProductDetail(Request $request, Response $response, array $args): Response
    {
        try {
            $currentUser = null;
            try { $currentUser = $this->authService->getCurrentUser($request); } catch (\Throwable $ignore) {}
            $isAdminCall = $currentUser && $this->authService->isAdminUser($currentUser);

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

            $product = $this->prepareProductPayload($product, $isAdminCall, $request);

            $tagMap = $this->loadTagsForProducts([(int)$product['id']]);
            $product['tags'] = $tagMap[$product['id']] ?? [];

            return $this->json($response, [
                'success' => true,
                'data' => $product
            ]);

        } catch (\Exception $e) {
            $this->logControllerException($e, $request, 'ProductController::getProductDetail error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::exchangeProduct error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::getExchangeTransaction error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::getUserExchanges error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::getExchangeRecords error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::getExchangeRecordDetail error: ' . $e->getMessage());
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
            $this->logControllerException($e, $request, 'ProductController::updateExchangeStatus error: ' . $e->getMessage());
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    /**
     * 获取商品分类
     */
    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $search = trim((string)($params['search'] ?? ''));
            $limitParam = isset($params['limit']) ? (int)$params['limit'] : 50;
            $limit = max(5, min(100, $limitParam));

            $sql = "
                SELECT
                    pc.id,
                    pc.name,
                    pc.slug,
                    COALESCE(stats.product_count, 0) AS product_count
                FROM product_categories pc
                LEFT JOIN (
                    SELECT category_slug, COUNT(*) AS product_count
                    FROM products
                    WHERE deleted_at IS NULL
                    GROUP BY category_slug
                ) AS stats ON stats.category_slug = pc.slug
            ";

            $bindings = [];
            if ($search !== '') {
                $sql .= ' WHERE pc.name LIKE :search OR pc.slug LIKE :search';
                $bindings['search'] = '%' . $search . '%';
            }

            $sql .= ' ORDER BY pc.name ASC LIMIT :limit';

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $categoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $map = [];
            foreach ($categoryRows as $row) {
                $name = $row['name'] ?? '';
                $slug = $row['slug'] ?? '';
                $key = strtolower($slug !== '' ? $slug : $name);
                $map[$key] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : null,
                    'name' => $name !== '' ? $name : ($slug ?: ''),
                    'slug' => $slug,
                    'product_count' => (int)($row['product_count'] ?? 0),
                ];
            }

            $fallbackLimit = $limit * 2;
            $fallbackSql = "
                SELECT
                    COALESCE(NULLIF(category_slug, ''), NULL) AS slug,
                    category AS name,
                    COUNT(*) AS product_count
                FROM products
                WHERE deleted_at IS NULL
                  AND category IS NOT NULL
                  AND category <> ''
                GROUP BY category, category_slug
                ORDER BY category ASC
                LIMIT :fallback_limit
            ";

            $fallbackStmt = $this->db->prepare($fallbackSql);
            $fallbackStmt->bindValue('fallback_limit', $fallbackLimit, PDO::PARAM_INT);
            $fallbackStmt->execute();
            $fallbackRows = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $searchLower = strtolower($search);
            foreach ($fallbackRows as $row) {
                $name = isset($row['name']) ? trim((string)$row['name']) : '';
                $slug = isset($row['slug']) ? (string)$row['slug'] : '';
                $slug = $slug !== '' ? $this->normalizeSlug($slug) : '';
                if ($slug === '' && $name !== '') {
                    $slug = $this->slugifyCategoryName($name);
                }
                if ($name === '' && $slug === '') {
                    continue;
                }
                $key = strtolower($slug !== '' ? $slug : $name);

                if ($searchLower !== '') {
                    $matchesSearch = (strpos(strtolower($name), $searchLower) !== false)
                        || ($slug !== '' && strpos($slug, $searchLower) !== false);
                    if (!$matchesSearch) {
                        continue;
                    }
                }

                $count = (int)($row['product_count'] ?? 0);

                if (isset($map[$key])) {
                    $map[$key]['product_count'] += $count;
                    if ($map[$key]['name'] === '' && $name !== '') {
                        $map[$key]['name'] = $name;
                    }
                    if ($map[$key]['slug'] === '' && $slug !== '') {
                        $map[$key]['slug'] = $slug;
                    }
                    continue;
                }

                $map[$key] = [
                    'id' => null,
                    'name' => $name !== '' ? $name : $slug,
                    'slug' => $slug,
                    'product_count' => $count,
                ];
            }

            $categories = array_values($map);
            usort($categories, static function ($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            if (count($categories) > $limit) {
                $categories = array_slice($categories, 0, $limit);
            }

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'categories' => $categories,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logControllerException($e, $request, 'ProductController::getCategories error: ' . $e->getMessage());
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

            // 输入校验
            if (empty($data['name']) || !isset($data['points_required']) || !isset($data['stock'])) {
                return $this->json($response, ['error' => 'Missing required fields: name, points_required, stock'], 400);
            }
            $tagPayload = $data['tags'] ?? [];
            $rawCategory = $data['category'] ?? null;
            $categoryRecord = $this->resolveCategoryFromPayload($rawCategory);
            $imagePath = $this->extractPrimaryImagePath($data);
            $imagesPayload = isset($data['images']) ? (is_string($data['images']) ? $data['images'] : json_encode($data['images'])) : null;
            $statusInput = $data['status'] ?? 'active';
            $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';

            $categoryName = $categoryRecord['name'] ?? (is_string($rawCategory) ? trim($rawCategory) : null);
            if ($categoryName === '') {
                $categoryName = null;
            }
            $categorySlug = $categoryRecord['slug'] ?? null;
            if (!$categorySlug && $categoryName) {
                $categorySlug = $this->slugifyCategoryName($categoryName);
            }

            $this->db->beginTransaction();
            try {
                $sql = "
                    INSERT INTO products (
                        name, category, category_slug, points_required, description, image_path, images,
                        stock, status, sort_order, created_at
                    ) VALUES (
                        :name, :category, :category_slug, :points_required, :description, :image_path, :images,
                        :stock, :status, :sort_order, NOW()
                    )
                ";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'name' => $data['name'],
                    'category' => $categoryName,
                    'category_slug' => $categorySlug,
                    'points_required' => (int)$data['points_required'],
                    'description' => $data['description'] ?? '',
                    'image_path' => $imagePath,
                    'images' => $imagesPayload,
                    'stock' => (int)$data['stock'],
                    'status' => $status,
                    'sort_order' => (int)($data['sort_order'] ?? 0),
                ]);

                $newId = (int)$this->db->lastInsertId();

                $normalizedTags = $this->resolveTagsFromPayload($tagPayload);
                $this->syncProductTags($newId, $normalizedTags);

                $this->db->commit();

                // 写入审计日志
                $this->auditLog->log($user['id'], 'product_created', 'products', (string)$newId, [
                    'name' => $data['name'],
                    'category' => $categoryName,
                    'category_slug' => $categorySlug,
                    'tags' => array_map(static fn($tag) => $tag['name'], $normalizedTags),
                ]);

                return $this->json($response, ['success' => true, 'id' => $newId, 'message' => 'Product created successfully'], 201);
            } catch (\Throwable $txError) {
                try { $this->db->rollBack(); } catch (\Throwable $ignore) {}
                throw $txError;
            }
        } catch (\Exception $e) {
            $this->logControllerException($e, $request, 'ProductController::createProduct error: ' . $e->getMessage());
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

            foreach (['name','description'] as $col) {
                if (array_key_exists($col, $data)) { $assign($col, $data[$col]); }
            }

            $categoryName = null;
            $categorySlug = null;
            $hasCategoryPayload = array_key_exists('category', $data);
            if ($hasCategoryPayload) {
                $categoryRecord = $this->resolveCategoryFromPayload($data['category']);
                $categoryName = $categoryRecord['name'] ?? (is_string($data['category']) ? trim((string)$data['category']) : null);
                if ($categoryName === '') {
                    $categoryName = null;
                }
                $categorySlug = $categoryRecord['slug'] ?? null;
                if (!$categorySlug && $categoryName) {
                    $categorySlug = $this->slugifyCategoryName($categoryName);
                }
                $assign('category', $categoryName);
                $assign('category_slug', $categorySlug);
            }

            if (array_key_exists('points_required', $data)) { $assign('points_required', (int)$data['points_required']); }
            if (array_key_exists('stock', $data)) { $assign('stock', (int)$data['stock']); }
            if (array_key_exists('status', $data)) {
                $status = in_array($data['status'], ['active','inactive'], true) ? $data['status'] : 'inactive';
                $assign('status', $status);
            }
            if (array_key_exists('sort_order', $data)) { $assign('sort_order', (int)$data['sort_order']); }
            $imagePath = null;
            $hasImagePayload = array_key_exists('image_path', $data) || array_key_exists('image_url', $data) || array_key_exists('image', $data);
            if ($hasImagePayload) {
                $imagePath = $this->extractPrimaryImagePath($data);
                $assign('image_path', $imagePath);
            }
            if (array_key_exists('images', $data)) {
                $images = is_string($data['images']) ? $data['images'] : json_encode($data['images']);
                $assign('images', $images);
            }

            $shouldUpdateTags = array_key_exists('tags', $data);
            if (empty($fields) && !$shouldUpdateTags) {
                return $this->json($response, ['error' => 'No fields to update'], 400);
            }

            $normalizedTags = $shouldUpdateTags ? $this->resolveTagsFromPayload($data['tags']) : [];

            $this->db->beginTransaction();
            try {
                if (!empty($fields)) {
                    $sql = 'UPDATE products SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = :id AND deleted_at IS NULL';
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                }

                if ($shouldUpdateTags) {
                    $this->syncProductTags($id, $normalizedTags);
                }

                $this->db->commit();

                $this->auditLog->log($user['id'], 'product_updated', 'products', (string)$id, [
                    'updated_fields' => array_keys($data),
                    'category' => $hasCategoryPayload ? $categoryName : null,
                    'category_slug' => $hasCategoryPayload ? $categorySlug : null,
                    'tags' => $shouldUpdateTags ? array_map(static fn($tag) => $tag['name'], $normalizedTags) : null,
                ]);

                return $this->json($response, ['success' => true, 'message' => 'Product updated successfully']);
            } catch (\Throwable $txError) {
                try { $this->db->rollBack(); } catch (\Throwable $ignore) {}
                throw $txError;
            }
        } catch (\Exception $e) {
            $this->logControllerException($e, $request, 'ProductController::updateProduct error: ' . $e->getMessage());
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }


    /**
     * 获取商品标签（用于自动补全）
     */
    public function searchProductTags(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $search = trim((string)($params['search'] ?? ''));
            $limit = min(50, max(5, (int)($params['limit'] ?? 20)));

            $sql = 'SELECT id, name, slug FROM product_tags';
            $bindings = [];
            if ($search !== '') {
                $sql .= ' WHERE name LIKE :search OR slug LIKE :search';
                $bindings['search'] = '%' . $search . '%';
            }
            $sql .= ' ORDER BY name ASC LIMIT :limit';

            $stmt = $this->db->prepare($sql);
            foreach ($bindings as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return $this->json($response, [
                'success' => true,
                'data' => [
                    'tags' => array_map(static function ($row) {
                        return [
                            'id' => isset($row['id']) ? (int)$row['id'] : null,
                            'name' => $row['name'] ?? '',
                            'slug' => $row['slug'] ?? '',
                        ];
                    }, $rows)
                ]
            ]);
        } catch (\Exception $e) {
            $this->logControllerException($e, $request, 'ProductController::searchProductTags error: ' . $e->getMessage());
            return $this->json($response, ['error' => self::ERR_INTERNAL], 500);
        }
    }

    private function prepareProductPayload(array $product, bool $withProtectedUrls = false, ?Request $request = null): array
    {
        $product['images'] = $this->normalizeProductImagesList($product['images'] ?? null, $withProtectedUrls, $request);

        $imagePathRaw = $product['image_path'] ?? null;
        $normalizedImagePath = null;
        if (is_string($imagePathRaw) && $imagePathRaw !== '') {
            $normalizedImagePath = ltrim(trim($imagePathRaw), '/');
            $product['image_path'] = $normalizedImagePath;
        }

        $imageUrl = $product['image_url'] ?? null;
        if ((!is_string($imageUrl) || $imageUrl === '') && $normalizedImagePath) {
            $publicUrl = $this->buildPublicUrl($normalizedImagePath, $request);
            if ($publicUrl) {
                $imageUrl = $publicUrl;
            }
        }

        $existingPresigned = $product['image_presigned_url'] ?? null;
        $presignedUrl = $existingPresigned && is_string($existingPresigned) ? $existingPresigned : null;
        if ($withProtectedUrls && $normalizedImagePath) {
            $freshPresigned = $this->buildPresignedUrl($normalizedImagePath, 600, $request);
            if ($freshPresigned) {
                $presignedUrl = $freshPresigned;
            }
        }

        if (!is_string($imageUrl) || $imageUrl === '') {
            if (!empty($product['images'])) {
                $firstImage = $product['images'][0];
                if (is_array($firstImage)) {
                    if (!empty($firstImage['url'])) {
                        $imageUrl = $firstImage['url'];
                    } elseif (!empty($firstImage['file_path'])) {
                        $fallbackUrl = $this->buildPublicUrl($firstImage['file_path'], $request);
                        if ($fallbackUrl) {
                            $imageUrl = $fallbackUrl;
                        }
                    }
                    if ($withProtectedUrls && !$presignedUrl && !empty($firstImage['file_path'])) {
                        $presignedUrl = $this->buildPresignedUrl($firstImage['file_path'], 600, $request);
                    }
                } elseif (is_string($firstImage) && $firstImage !== '') {
                    $imageUrl = $firstImage;
                }
            }
        } else {
            if ($withProtectedUrls && !$presignedUrl && $normalizedImagePath) {
                $presignedUrl = $this->buildPresignedUrl($normalizedImagePath, 600, $request);
            }
        }

        if ($withProtectedUrls && !$presignedUrl && !empty($product['images'])) {
            foreach ($product['images'] as $imageMeta) {
                if (!empty($imageMeta['presigned_url'])) {
                    $presignedUrl = $imageMeta['presigned_url'];
                    break;
                }
            }
        }

        if (!is_string($imageUrl) || $imageUrl === '') {
            if ($normalizedImagePath) {
                $imageUrl = $normalizedImagePath;
            }
        }

        if (is_string($imageUrl) && $imageUrl !== '') {
            $product['image_url'] = $imageUrl;
        }

        if ($withProtectedUrls) {
            $product['image_presigned_url'] = $presignedUrl ?: '';
        } else {
            unset($product['image_presigned_url']);
        }

        if (isset($product['stock'])) {
            $product['stock'] = (int)$product['stock'];
        }
        if (isset($product['points_required'])) {
            $product['points_required'] = (int)$product['points_required'];
        }

        $stockValue = $product['stock'] ?? null;
        $product['is_available'] = $stockValue === -1 || ($stockValue > 0);

        if (!isset($product['price']) && isset($product['points_required'])) {
            $product['price'] = (int)$product['points_required'];
        }

        return $product;
    }

    private function normalizeProductImagesList($rawImages, bool $withProtectedUrls = false, ?Request $request = null): array
    {
        if (empty($rawImages)) {
            return [];
        }

        if (is_string($rawImages)) {
            $decoded = json_decode($rawImages, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $rawImages = $decoded;
            } else {
                $rawImages = [$rawImages];
            }
        }

        if (!is_array($rawImages)) {
            return [];
        }

        $normalized = [];
        foreach ($rawImages as $item) {
            $image = $this->normalizeProductImageItem($item, $withProtectedUrls, $request);
            if ($image !== null) {
                $normalized[] = $image;
            }
        }

        return $normalized;
    }

    private function normalizeProductImageItem($item, bool $withProtectedUrls = false, ?Request $request = null): ?array
    {
        if (is_string($item)) {
            $item = ['file_path' => $item];
        } elseif (!is_array($item)) {
            return null;
        }

        $filePath = $item['file_path'] ?? ($item['path'] ?? null);
        if (is_string($filePath) && $filePath !== '') {
            $filePath = ltrim(trim($filePath), '/');
        } else {
            $filePath = null;
        }

        $url = $item['url'] ?? ($item['public_url'] ?? null);
        if ((!is_string($url) || $url === '') && $filePath) {
            $url = $this->buildPublicUrl($filePath, $request) ?? $url;
        }

        $existingPresigned = $item['presigned_url'] ?? null;
        $presignedUrl = null;
        if ($withProtectedUrls && $filePath) {
            $freshPresigned = $this->buildPresignedUrl($filePath, 600, $request);
            if ($freshPresigned) {
                $presignedUrl = $freshPresigned;
            } elseif (is_string($existingPresigned) && $existingPresigned !== '') {
                $presignedUrl = $existingPresigned;
            }
        } elseif ($withProtectedUrls && is_string($existingPresigned) && $existingPresigned !== '') {
            $presignedUrl = $existingPresigned;
        }

        $normalized = [
            'file_path' => $filePath,
            'url' => $url,
        ];

        if ($withProtectedUrls) {
            $normalized['presigned_url'] = $presignedUrl ?: null;
        }
        if (isset($item['thumbnail_path'])) {
            $normalized['thumbnail_path'] = $item['thumbnail_path'];
        }
        if (isset($item['original_name'])) {
            $normalized['original_name'] = $item['original_name'];
        }
        if (isset($item['mime_type'])) {
            $normalized['mime_type'] = $item['mime_type'];
        }
        $size = $item['size'] ?? ($item['file_size'] ?? null);
        if ($size !== null) {
            $normalized['size'] = $size;
        }
        if (isset($item['duplicate'])) {
            $normalized['duplicate'] = $item['duplicate'];
        }

        return $normalized;
    }

    private function logControllerException(\Throwable $exception, Request $request, string $contextMessage = ''): void
    {
        if ($this->errorLogService) {
            try {
                $extra = $contextMessage !== '' ? ['context_message' => $contextMessage] : [];
                $this->errorLogService->logException($exception, $request, $extra);
                return;
            } catch (\Throwable $loggingError) {
                error_log(self::ERRLOG_PREFIX . $loggingError->getMessage());
            }
        }
        $fallbackMessage = $contextMessage !== '' ? $contextMessage : $exception->getMessage();
        error_log($fallbackMessage);
    }

    private function buildPresignedUrl(?string $filePath, int $ttlSeconds = 600, ?Request $request = null): ?string
    {
        if (!$filePath || !$this->r2Service) {
            return null;
        }
        try {
            return $this->r2Service->generatePresignedUrl($filePath, $ttlSeconds);
        } catch (\Throwable $ignore) {
            return null;
        }
    }

    private function buildPublicUrl(?string $filePath, ?Request $request = null): ?string
    {
        if (!$filePath || !$this->r2Service) {
            return null;
        }
        try {
            return $this->r2Service->getPublicUrl($filePath);
        } catch (\Throwable $ignore) {
            return null;
        }
    }

    private function extractPrimaryImagePath(array $payload): ?string
    {
        $candidates = [];
        if (array_key_exists('image_path', $payload)) {
            $candidates[] = $payload['image_path'];
        }
        if (array_key_exists('image_url', $payload)) {
            $candidates[] = $payload['image_url'];
        }
        if (array_key_exists('image', $payload)) {
            $candidates[] = $payload['image'];
        }
        if (array_key_exists('main_image', $payload)) {
            $candidates[] = $payload['main_image'];
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $filePath = $candidate['file_path'] ?? ($candidate['path'] ?? ($candidate['value'] ?? null));
                if (is_string($filePath) && trim($filePath) !== '') {
                    return trim($filePath);
                }
            } elseif (is_string($candidate)) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function resolveCategoryFromPayload($rawCategory): ?array
    {
        if ($rawCategory === null || $rawCategory === '') {
            return null;
        }

        $candidate = $this->normalizeCategoryCandidate($rawCategory);
        if (!$candidate) {
            return null;
        }

        if ($candidate['id'] !== null) {
            $byId = $this->fetchCategoriesByIds([$candidate['id']]);
            if (isset($byId[$candidate['id']])) {
                return $byId[$candidate['id']];
            }
        }

        if ($candidate['slug'] !== '') {
            $bySlug = $this->fetchCategoriesBySlugs([$candidate['slug']]);
            if (isset($bySlug[$candidate['slug']])) {
                return $bySlug[$candidate['slug']];
            }
        }

        $name = $candidate['name'] !== '' ? $candidate['name'] : null;
        if ($name === null && $candidate['slug'] !== '') {
            $name = $candidate['slug'];
        }
        if ($name === null) {
            return null;
        }

        $slug = $candidate['slug'] !== '' ? $candidate['slug'] : $this->slugifyCategoryName($name);

        return $this->createProductCategory($name, $slug);
    }

    private function normalizeCategoryCandidate($category): ?array
    {
        if (is_string($category)) {
            $name = trim($category);
            if ($name === '') {
                return null;
            }
            return [
                'id' => null,
                'name' => $name,
                'slug' => $this->slugifyCategoryName($name),
            ];
        }

        if (!is_array($category)) {
            return null;
        }

        $id = null;
        foreach (['id', 'category_id'] as $idKey) {
            if (isset($category[$idKey]) && $category[$idKey] !== '') {
                $id = (int)$category[$idKey];
                break;
            }
        }

        $name = '';
        foreach (['name', 'category', 'label', 'value'] as $nameKey) {
            if (isset($category[$nameKey]) && is_string($category[$nameKey])) {
                $candidate = trim($category[$nameKey]);
                if ($candidate !== '') {
                    $name = $candidate;
                    break;
                }
            }
        }

        $slug = '';
        foreach (['slug', 'category_slug', 'value'] as $slugKey) {
            if (isset($category[$slugKey]) && is_string($category[$slugKey])) {
                $candidate = $this->normalizeSlug($category[$slugKey]);
                if ($candidate !== '') {
                    $slug = $candidate;
                    break;
                }
            }
        }

        if ($slug === '' && $name !== '') {
            $slug = $this->slugifyCategoryName($name);
        }

        if ($id === null && $slug === '' && $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
        ];
    }

    private function fetchCategoriesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT id, name, slug FROM product_categories WHERE id IN (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($row['id'])) {
                continue;
            }
            $identifier = (int)$row['id'];
            $result[$identifier] = [
                'id' => $identifier,
                'name' => $row['name'] ?? '',
                'slug' => $row['slug'] ?? '',
            ];
        }

        return $result;
    }

    private function createProductCategory(string $name, string $slug): array
    {
        $name = trim($name) !== '' ? trim($name) : $slug;
        $normalizedSlug = $this->slugifyCategoryName($slug ?: $name);
        $baseSlug = $normalizedSlug;
        $attempts = 0;

        while ($attempts < 5) {
            try {
                $stmt = $this->db->prepare('INSERT INTO product_categories (name, slug, created_at) VALUES (:name, :slug, NOW())');
                $stmt->execute([
                    'name' => $name,
                    'slug' => $normalizedSlug,
                ]);
                $id = (int)$this->db->lastInsertId();

                return [
                    'id' => $id,
                    'name' => $name,
                    'slug' => $normalizedSlug,
                ];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $existing = $this->fetchCategoriesBySlugs([$normalizedSlug]);
                if (isset($existing[$normalizedSlug])) {
                    return $existing[$normalizedSlug];
                }

                ++$attempts;
                $normalizedSlug = $baseSlug . '-' . $attempts;
            }
        }

        $normalizedSlug = $baseSlug . '-' . substr(md5((string)microtime(true)), 0, 6);
        $stmt = $this->db->prepare('INSERT INTO product_categories (name, slug, created_at) VALUES (:name, :slug, NOW())');
        $stmt->execute([
            'name' => $name,
            'slug' => $normalizedSlug,
        ]);
        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $normalizedSlug,
        ];
    }

    private function resolveTagsFromPayload($rawTags): array
    {
        if (!is_array($rawTags)) {
            return [];
        }

        $normalized = [];
        foreach ($rawTags as $tag) {
            $candidate = $this->normalizeTagCandidate($tag);
            if (!$candidate) {
                continue;
            }
            $key = $candidate['id'] !== null
                ? 'id-' . $candidate['id']
                : 'slug-' . $candidate['slug'];
            $normalized[$key] = $candidate;
        }

        if (empty($normalized)) {
            return [];
        }

        $byId = [];
        $bySlug = [];
        $idList = [];
        $slugList = [];
        foreach ($normalized as $candidate) {
            if ($candidate['id'] !== null) {
                $idList[$candidate['id']] = true;
            }
            $slugList[$candidate['slug']] = true;
        }

        if (!empty($idList)) {
            $byId = $this->fetchTagsByIds(array_keys($idList));
        }
        if (!empty($slugList)) {
            $bySlug = $this->fetchTagsBySlugs(array_keys($slugList));
        }

        $resolved = [];
        foreach (array_values($normalized) as $candidate) {
            if ($candidate['id'] !== null && isset($byId[$candidate['id']])) {
                $record = $byId[$candidate['id']];
                $resolved[$record['id']] = $record;
                continue;
            }

            if (isset($bySlug[$candidate['slug']])) {
                $record = $bySlug[$candidate['slug']];
                $resolved[$record['id']] = $record;
                continue;
            }

            $record = $this->createProductTag($candidate['name'], $candidate['slug']);
            $resolved[$record['id']] = $record;
            $bySlug[$record['slug']] = $record;
        }

        return array_values($resolved);
    }

    private function normalizeTagCandidate($tag): ?array
    {
        if (is_string($tag)) {
            $name = trim($tag);
            if ($name === '') {
                return null;
            }
            return [
                'id' => null,
                'name' => $name,
                'slug' => $this->slugifyTagName($name),
            ];
        }

        if (!is_array($tag)) {
            return null;
        }

        $id = null;
        if (isset($tag['id']) && $tag['id'] !== '') {
            $id = (int)$tag['id'];
        }

        $name = '';
        if (isset($tag['name']) && is_string($tag['name'])) {
            $name = trim($tag['name']);
        } elseif (isset($tag['label']) && is_string($tag['label'])) {
            $name = trim($tag['label']);
        } elseif (isset($tag['value']) && is_string($tag['value'])) {
            $name = trim($tag['value']);
        }

        $slug = null;
        if (isset($tag['slug']) && is_string($tag['slug'])) {
            $slug = $this->normalizeSlug($tag['slug']);
        }

        if ($id !== null && $slug === null) {
            $slug = $name !== '' ? $this->slugifyTagName($name) : 'tag-' . $id;
        }

        if ($slug === null && $name !== '') {
            $slug = $this->slugifyTagName($name);
        }

        if ($id === null && $name === '') {
            return null;
        }

        if ($slug === null) {
            $slug = $this->slugifyTagName($name ?: ('tag-' . md5(json_encode($tag))));
        }

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
        ];
    }

    private function syncProductTags(int $productId, array $tags): void
    {
        $desiredIds = array_map(static fn($tag) => (int)$tag['id'], $tags);
        $desiredIds = array_values(array_unique(array_filter($desiredIds, static fn($id) => $id > 0)));

        $stmt = $this->db->prepare('SELECT tag_id FROM product_tag_map WHERE product_id = :product_id');
        $stmt->execute(['product_id' => $productId]);
        $existingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $toDelete = array_diff($existingIds, $desiredIds);
        $toInsert = array_diff($desiredIds, $existingIds);

        if (!empty($toDelete)) {
            $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
            $sql = 'DELETE FROM product_tag_map WHERE product_id = ? AND tag_id IN (' . $placeholders . ')';
            $delStmt = $this->db->prepare($sql);
            $delStmt->execute(array_merge([$productId], array_values($toDelete)));
        }

        if (!empty($toInsert)) {
            $insertSql = 'INSERT INTO product_tag_map (product_id, tag_id, created_at) VALUES (:product_id, :tag_id, NOW())';
            $insStmt = $this->db->prepare($insertSql);
            foreach ($toInsert as $tagId) {
                $insStmt->execute([
                    'product_id' => $productId,
                    'tag_id' => $tagId,
                ]);
            }
        }
    }

    private function loadTagsForProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn($id) => $id > 0)));
        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = 'SELECT ptm.product_id, pt.id, pt.name, pt.slug
                FROM product_tag_map ptm
                INNER JOIN product_tags pt ON pt.id = ptm.tag_id
                WHERE ptm.product_id IN (' . $placeholders . ')
                ORDER BY pt.name ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($productIds);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $map = [];
        foreach ($rows as $row) {
            $productId = isset($row['product_id']) ? (int)$row['product_id'] : null;
            if ($productId === null) {
                continue;
            }
            $map[$productId] ??= [];
            $map[$productId][] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'name' => $row['name'] ?? '',
                'slug' => $row['slug'] ?? '',
            ];
        }

        return $map;
    }

    private function fetchTagsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'SELECT id, name, slug FROM product_tags WHERE id IN (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = isset($row['id']) ? (int)$row['id'] : null;
            if ($id === null) {
                continue;
            }
            $result[$id] = [
                'id' => $id,
                'name' => $row['name'] ?? '',
                'slug' => $row['slug'] ?? '',
            ];
        }

        return $result;
    }

    private function fetchTagsBySlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs, static fn($slug) => is_string($slug) && $slug !== '')));
        if (empty($slugs)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $sql = 'SELECT id, name, slug FROM product_tags WHERE slug IN (' . $placeholders . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($slugs);

        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!isset($row['slug'])) {
                continue;
            }
            $slug = $row['slug'];
            $result[$slug] = [
                'id' => isset($row['id']) ? (int)$row['id'] : null,
                'name' => $row['name'] ?? '',
                'slug' => $slug,
            ];
        }

        return $result;
    }

    private function fetchCategoriesBySlugs(array $slugs): array
    {
        $slugs = array_values(array_unique(array_filter($slugs, static fn($slug) => is_string($slug) && $slug !== '')));
        if (empty($slugs)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $sql = 'SELECT id, name, slug FROM product_categories WHERE slug IN (' . $placeholders . ')';

        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }

            $stmt->execute($slugs);

            $result = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!isset($row['slug'])) {
                    continue;
                }
                $slug = $row['slug'];
                $result[$slug] = [
                    'id' => isset($row['id']) ? (int)$row['id'] : null,
                    'name' => $row['name'] ?? '',
                    'slug' => $slug,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function slugifyCategoryName(string $name): string
    {
        $slug = $this->normalizeSlug($name);
        if ($slug === '') {
            $slug = 'category-' . substr(md5($name), 0, 8);
        }
        return $slug;
    }
    private function createProductTag(string $name, string $slug): array
    {
        $name = trim($name) !== '' ? trim($name) : $slug;
        $slug = $this->normalizeSlug($slug);
        $baseSlug = $slug;
        $attempts = 0;

        while ($attempts < 5) {
            try {
                $stmt = $this->db->prepare('INSERT INTO product_tags (name, slug, created_at, updated_at) VALUES (:name, :slug, NOW(), NOW())');
                $stmt->execute([
                    'name' => $name,
                    'slug' => $slug,
                ]);
                $id = (int)$this->db->lastInsertId();

                return [
                    'id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                ];
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                $existing = $this->fetchTagsBySlugs([$slug]);
                if (isset($existing[$slug])) {
                    return $existing[$slug];
                }

                ++$attempts;
                $slug = $baseSlug . '-' . $attempts;
            }
        }

        $slug = $baseSlug . '-' . substr(md5((string)microtime(true)), 0, 6);
        $stmt = $this->db->prepare('INSERT INTO product_tags (name, slug, created_at, updated_at) VALUES (:name, :slug, NOW(), NOW())');
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
        ]);
        $id = (int)$this->db->lastInsertId();

        return [
            'id' => $id,
            'name' => $name,
            'slug' => $slug,
        ];
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }
        $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $slug);
        $slug = strtolower(trim($slug, '-'));
        return $slug;
    }

    private function slugifyTagName(string $name): string
    {
        $trimmed = trim($name);
        $slug = function_exists('mb_strtolower') ? mb_strtolower($trimmed) : strtolower($trimmed);
        $slug = preg_replace('/[^\p{L}\p{N}\-]+/u', '-', $slug);
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'tag-' . substr(md5($name), 0, 8);
        }
        return $slug;
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
            $this->logControllerException($e, $request, 'ProductController::deleteProduct error: ' . $e->getMessage());
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


