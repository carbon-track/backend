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

class ProductExchangeQueryTest extends TestCase
{
    public function testUserCanViewExchangeHistoryAndDetail(): void
    {
        $pdo = $this->createConnection();
        $this->createSchema($pdo);
        $this->seedUsers($pdo);
        $this->seedProducts($pdo);
        $this->seedUserExchanges($pdo);

        $messageService = $this->createMock(MessageService::class);
        $auditLog = $this->createMock(AuditLogService::class);
        $authService = $this->makeUserAuthService(10);

        $controller = new ProductController($pdo, $messageService, $auditLog, $authService);

        $listRequest = makeRequest('GET', '/me/exchanges', null, ['limit' => 10]);
        $listResponse = new Response();
        $listResult = $controller->getExchangeTransactions($listRequest, $listResponse);

        $this->assertSame(200, $listResult->getStatusCode(), (string)$listResult->getBody());
        $payload = json_decode((string)$listResult->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['pagination']['total']);
        $this->assertCount(2, $payload['data']);

        $ids = array_column($payload['data'], 'id');
        $this->assertSame(['ex-user-1', 'ex-user-2'], $ids);

        $byId = [];
        foreach ($payload['data'] as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertSame('pending', $byId['ex-user-1']['status']);
        $this->assertSame('completed', $byId['ex-user-2']['status']);
        $this->assertSame(2, (int)$byId['ex-user-2']['quantity']);

        $detailRequest = makeRequest('GET', '/me/exchanges/ex-user-1');
        $detailResponse = new Response();
        $detailResult = $controller->getExchangeTransaction($detailRequest, $detailResponse, ['id' => 'ex-user-1']);

        $this->assertSame(200, $detailResult->getStatusCode(), (string)$detailResult->getBody());
        $detailPayload = json_decode((string)$detailResult->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($detailPayload['success']);
        $this->assertSame('pending', $detailPayload['data']['status']);
        $this->assertSame(150, (int)$detailPayload['data']['points_used']);

        $otherRequest = makeRequest('GET', '/me/exchanges/ex-other');
        $otherResponse = new Response();
        $otherResult = $controller->getExchangeTransaction($otherRequest, $otherResponse, ['id' => 'ex-other']);
        $this->assertSame(404, $otherResult->getStatusCode());
    }

    public function testAdminCanViewExchangeRecordDetail(): void
    {
        $pdo = $this->createConnection();
        $this->createSchema($pdo);
        $this->seedUsers($pdo);
        $this->seedProducts($pdo);
        $this->seedAdminExchange($pdo);

        $messageService = $this->createMock(MessageService::class);
        $auditLog = $this->createMock(AuditLogService::class);
        $authService = $this->makeAdminAuthService();

        $controller = new ProductController($pdo, $messageService, $auditLog, $authService);

        $detailRequest = makeRequest('GET', '/admin/exchanges/ex-admin-1');
        $detailResponse = new Response();

        $result = $controller->getExchangeRecordDetail($detailRequest, $detailResponse, ['id' => 'ex-admin-1']);

        $this->assertSame(200, $result->getStatusCode(), (string)$result->getBody());
        $payload = json_decode((string)$result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);

        $data = $payload['data'];
        $this->assertSame('shipped', $data['status']);
        $this->assertSame('admin_user', $data['username']);
        $this->assertSame('admin@example.com', $data['email']);
        $this->assertSame('Eco Bottle', $data['product_name']);
        $this->assertSame('eco-bottle-img', $data['current_product_image_path']);
        $this->assertSame('Warehouse A', $data['delivery_address']);
        $this->assertSame('TRACK-ADMIN', $data['tracking_number']);
        $this->assertSame('Warehouse A', $data['shipping_address']);
        $this->assertSame('admin_user', $data['user_username']);
        $this->assertSame('admin@example.com', $data['user_email']);
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

        $pdo->exec('CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            name TEXT,
            images TEXT,
            image_path TEXT,
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
            (10, 'user_a', 'user_a@example.com', 300, 0, 'active', '$now'),
            (11, 'user_b', 'user_b@example.com', 120, 0, 'active', '$now')
        ");
    }

    private function seedProducts(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO products (id, name, images, image_path, created_at) VALUES
            (100, 'Eco Bottle', '[\"eco-bottle-img\"]', 'eco-bottle-img', '$now'),
            (101, 'Solar Charger', '[\"solar-img\"]', 'solar-img', '$now')
        ");
    }

    private function seedUserExchanges(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO point_exchanges (
            id, user_id, product_id, quantity, points_used, product_name, product_price,
            status, created_at
        ) VALUES
            ('ex-user-1', 10, 100, 1, 150, 'Eco Bottle', 150, 'pending', '$now'),
            ('ex-user-2', 10, 101, 2, 400, 'Solar Charger', 200, 'completed', datetime('$now','-1 day')),
            ('ex-other', 11, 100, 1, 150, 'Eco Bottle', 150, 'pending', datetime('$now','-2 day'))
        ");
    }

    private function seedAdminExchange(PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $pdo->exec("INSERT INTO point_exchanges (
            id, user_id, product_id, quantity, points_used, product_name, product_price,
            delivery_address, contact_area_code, contact_phone, notes,
            status, tracking_number, created_at, updated_at
        ) VALUES (
            'ex-admin-1', 1, 100, 1, 150, 'Eco Bottle', 150,
            'Warehouse A', '021', '12345678', 'Handle with care',
            'shipped', 'TRACK-ADMIN', '$now', '$now'
        )");
    }

    private function makeUserAuthService(int $userId): AuthService
    {
        $userRow = [
            'id' => $userId,
            'username' => $userId === 10 ? 'user_a' : 'user_b',
            'email' => $userId === 10 ? 'user_a@example.com' : 'user_b@example.com',
            'points' => 300,
            'is_admin' => false
        ];

        return new class('secret', 'HS256', 3600, $userRow) extends AuthService {
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
                return false;
            }
        };
    }

    private function makeAdminAuthService(): AuthService
    {
        $adminUser = [
            'id' => 1,
            'username' => 'admin_user',
            'email' => 'admin@example.com',
            'points' => 1000,
            'is_admin' => true
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
