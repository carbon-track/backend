<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Controllers\ProductController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

class AdminProductExchangeFlowTest extends TestCase
{
    public function testAdminProductLifecycle(): void
    {
        $pdo = $this->createConnection();
        $this->createSchema($pdo);
        $this->seedUsers($pdo);

        $messageService = $this->createMock(MessageService::class);
        $auditLog = $this->createMock(AuditLogService::class);
        $auditLog->method('log')->willReturn(true);
        $authService = $this->makeAdminAuthService();

        $controller = new ProductController($pdo, $messageService, $auditLog, $authService);

        $createRequest = makeRequest('POST', '/admin/products', [
            'name' => 'Solar Charger',
            'description' => 'Portable solar charger',
            'points_required' => 450,
            'stock' => 25,
            'category' => 'Outdoor',
            'tags' => ['Eco', ['name' => '热门', 'slug' => 'hot']],
            'sort_order' => 3
        ]);
        $createResponse = new Response();

        $created = $controller->createProduct($createRequest, $createResponse);
        $this->assertSame(201, $created->getStatusCode(), (string)$created->getBody());

        $payload = json_decode((string)$created->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $productId = (int)$payload['id'];
        $this->assertGreaterThan(0, $productId);

        $productRow = $pdo->query('SELECT * FROM products WHERE id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('Solar Charger', $productRow['name']);
        $this->assertSame('Outdoor', $productRow['category']);
        $this->assertNotEmpty($productRow['category_slug']);
        $this->assertSame('active', $productRow['status']);
        $this->assertSame('25', (string)$productRow['stock']);

        $tagCount = (int)$pdo->query('SELECT COUNT(*) FROM product_tags')->fetchColumn();
        $this->assertSame(2, $tagCount);

        $map = $pdo->query('SELECT tag_id FROM product_tag_map WHERE product_id = ' . $productId)->fetchAll(PDO::FETCH_COLUMN);
        $this->assertCount(2, $map);

        $existingTagId = (int)$pdo->query("SELECT id FROM product_tags WHERE slug = 'hot'")->fetchColumn();

        $updateRequest = makeRequest('PUT', '/admin/products/' . $productId, [
            'status' => 'inactive',
            'stock' => 10,
            'category' => 'Electronics',
            'tags' => [
                ['id' => $existingTagId, 'name' => '热门']
            ]
        ]);
        $updateResponse = new Response();

        $updated = $controller->updateProduct($updateRequest, $updateResponse, ['id' => $productId]);
        $this->assertSame(200, $updated->getStatusCode(), (string)$updated->getBody());

        $updatePayload = json_decode((string)$updated->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($updatePayload['success']);

        $productAfterUpdate = $pdo->query('SELECT * FROM products WHERE id = ' . $productId)->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('inactive', $productAfterUpdate['status']);
        $this->assertSame('10', (string)$productAfterUpdate['stock']);
        $this->assertSame('Electronics', $productAfterUpdate['category']);

        $mapAfterUpdate = $pdo->query('SELECT tag_id FROM product_tag_map WHERE product_id = ' . $productId)->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([$existingTagId], array_map('intval', $mapAfterUpdate));
    }

    public function testAdminCanListAndUpdateExchangeStatus(): void
    {
        $pdo = $this->createConnection();
        $this->createSchema($pdo);
        $this->seedUsers($pdo);
        $this->seedProduct($pdo);
        $this->seedExchange($pdo);

        $messageService = $this->createMock(MessageService::class);
        $messageService->expects($this->once())
            ->method('sendMessage')
            ->with(
                $this->equalTo(2),
                $this->equalTo('exchange_status_updated'),
                $this->equalTo('您的兑换商品已发货'),
                $this->stringContains('状态已更新为'),
                $this->equalTo('normal')
            );

        $auditLog = $this->createMock(AuditLogService::class);
        $auditLog->method('log')->willReturn(true);

        $authService = $this->makeAdminAuthService();
        $controller = new ProductController($pdo, $messageService, $auditLog, $authService);

        $listRequest = makeRequest('GET', '/admin/exchanges', null, ['status' => 'pending']);
        $listResponse = new Response();
        $listResult = $controller->getExchangeRecords($listRequest, $listResponse);
        $this->assertSame(200, $listResult->getStatusCode(), (string)$listResult->getBody());

        $listPayload = json_decode((string)$listResult->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($listPayload['success']);
        $this->assertCount(1, $listPayload['data']);
        $exchangeRow = $listPayload['data'][0];
        $this->assertSame('pending', $exchangeRow['status']);
        $this->assertSame('eco-bottle', $exchangeRow['current_product_image_path']);

        $updateRequest = makeRequest('PUT', '/admin/exchanges/ex-1', [
            'status' => 'shipped',
            'tracking_number' => 'TRACK123',
            'notes' => '发货完成'
        ]);
        $updateResponse = new Response();
        $updateResult = $controller->updateExchangeStatus($updateRequest, $updateResponse, ['id' => 'ex-1']);
        $this->assertSame(200, $updateResult->getStatusCode(), (string)$updateResult->getBody());

        $updatePayload = json_decode((string)$updateResult->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($updatePayload['success']);

        $dbExchange = $pdo->query("SELECT status, tracking_number, notes FROM point_exchanges WHERE id = 'ex-1'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('shipped', $dbExchange['status']);
        $this->assertSame('TRACK123', $dbExchange['tracking_number']);
        $this->assertSame('发货完成', $dbExchange['notes']);
    }

    private function createConnection(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'));
        }
        return $pdo;
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT,
            email TEXT,
            points INTEGER,
            is_admin INTEGER,
            status TEXT,
            created_at TEXT,
            deleted_at TEXT
        )');

        $pdo->exec('CREATE TABLE product_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            slug TEXT UNIQUE,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            category TEXT,
            category_slug TEXT,
            points_required INTEGER,
            description TEXT,
            image_path TEXT,
            images TEXT,
            stock INTEGER,
            status TEXT,
            sort_order INTEGER,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $pdo->exec('CREATE TABLE product_tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            slug TEXT UNIQUE,
            created_at TEXT,
            updated_at TEXT
        )');

        $pdo->exec('CREATE TABLE product_tag_map (
            product_id INTEGER,
            tag_id INTEGER,
            created_at TEXT
        )');

        $pdo->exec('CREATE TABLE point_exchanges (
            id TEXT PRIMARY KEY,
            user_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            points_used INTEGER,
            product_name TEXT,
            product_price INTEGER,
            delivery_address TEXT,
            contact_area_code TEXT,
            contact_phone TEXT,
            notes TEXT,
            status TEXT,
            tracking_number TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
    }

    private function seedUsers(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO users (id, username, email, points, is_admin, status, created_at) VALUES
            (1, 'admin_user', 'admin@example.com', 1000, 1, 'active', '$now'),
            (2, 'normal_user', 'user@example.com', 320, 0, 'active', '$now')
        ");
    }

    private function seedProduct(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO products (id, name, category, category_slug, points_required, description, image_path, images, stock, status, sort_order, created_at)
            VALUES (1, 'Eco Bottle', 'Lifestyle', 'lifestyle', 150, 'Reusable bottle', 'eco-bottle', '[\"eco-bottle\"]', 20, 'active', 1, '$now')
        ");
    }

    private function seedExchange(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO point_exchanges (
            id, user_id, product_id, quantity, points_used, product_name, product_price,
            status, created_at
        ) VALUES (
            'ex-1', 2, 1, 1, 150, 'Eco Bottle', 150, 'pending', '$now'
        )");
    }

    private function makeAdminAuthService(): AuthService
    {
        $adminUser = [
            'id' => 1,
            'username' => 'admin_user',
            'email' => 'admin@example.com',
            'is_admin' => true,
            'points' => 1000
        ];

        return new class('secret', 'HS256', 3600, $adminUser) extends AuthService {
            private array $user;

            public function __construct(string $secret, string $alg, int $exp, array $user)
            {
                parent::__construct($secret, $alg, $exp);
                $this->user = $user;
            }

            public function getCurrentUser(ServerRequestInterface $request): ?array
            {
                return $this->user;
            }

            public function isAdminUser($user): bool
            {
                return true;
            }
        };
    }
}
