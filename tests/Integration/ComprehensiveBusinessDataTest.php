<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use DI\Container;
use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Services\AuthService;

/**
 * Comprehensive business data tests that simulate real-world usage patterns
 * This test suite validates the backend against OpenAPI specifications
 * using realistic business scenarios and data
 */
class ComprehensiveBusinessDataTest extends TestCase
{
    protected App $app;
    protected \PDO $pdo;
    protected AuthService $authService;
    protected array $testUsers = [];
    protected array $testProducts = [];
    protected array $testCarbonActivities = [];

    protected function setUp(): void
    {
        // Load environment variables for testing
        if (file_exists(__DIR__ . '/../../.env.testing')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..', '.env.testing');
            $dotenv->load();
        } elseif (file_exists(__DIR__ . '/../../.env')) {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/../..');
            $dotenv->load();
        }

        // Set up test environment variables with proper database configuration
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DATABASE_PATH'] = __DIR__ . '/../../test.db';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_ENV['DATABASE_PATH'];
        $_ENV['JWT_SECRET'] = 'test_secret_key_for_jwt_token_generation';
        $_ENV['JWT_ALGORITHM'] = 'HS256';
        $_ENV['JWT_EXPIRATION'] = '86400';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'test_turnstile_secret';

        try {
            // Create container and set up dependencies
            $container = new Container();
            
            // Set up database configuration for Illuminate
            $config = [
                'database' => [
                    'default' => 'sqlite',
                    'connections' => [
                        'sqlite' => [
                            'driver' => 'sqlite',
                            'database' => $_ENV['DATABASE_PATH'],
                            'prefix' => '',
                        ]
                    ]
                ]
            ];
            
            // Store config in container for dependencies.php
            $container->set('config', $config);
            
            require __DIR__ . '/../../src/dependencies.php';

            // Create Slim app
            $this->app = \Slim\Factory\AppFactory::createFromContainer($container);
            $this->app->addErrorMiddleware(true, true, true);
            $this->app->addBodyParsingMiddleware();
            $this->app->addRoutingMiddleware();

            // Add routes
            $routes = require __DIR__ . '/../../src/routes.php';
            $routes($this->app);

            // Get services
            $dbService = $container->get(DatabaseService::class);
            $this->pdo = $dbService->getConnection()->getPdo();
            $this->authService = $container->get(AuthService::class);

            // Set up test data
            $this->setUpTestData();
            
        } catch (\Exception $e) {
            echo "Setup error: " . $e->getMessage() . "\n";
            echo "Trace: " . $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    protected function setUpTestData(): void
    {
        // Clear existing test data
        $this->pdo->exec("DELETE FROM users WHERE email LIKE '%@testdomain.com'");
        $this->pdo->exec("DELETE FROM products WHERE name LIKE 'Test Product%'");
        $this->pdo->exec("DELETE FROM point_exchanges WHERE id LIKE 'test-%'");
        $this->pdo->exec("DELETE FROM carbon_tracking WHERE id LIKE 'test-%'");

        // Create realistic test users
        $this->createTestUsers();
        
        // Create realistic test products
        $this->createTestProducts();
        
        // Create realistic carbon activities
        $this->createTestCarbonActivities();
    }

    protected function createTestUsers(): void
    {
        $testUserData = [
            [
                'username' => 'student_zhang',
                'email' => 'zhang.wei@testdomain.com',
                'real_name' => '张伟',
                'phone' => '13800138001',
                'school_id' => 1,
                'class_name' => '计算机科学与技术2021级1班',
                'role' => 'user',
                'status' => 'active',
                'points' => 150,
                'avatar_id' => 1
            ],
            [
                'username' => 'student_li',
                'email' => 'li.ming@testdomain.com',
                'real_name' => '李明',
                'phone' => '13800138002',
                'school_id' => 1,
                'class_name' => '环境工程2021级2班',
                'role' => 'user',
                'status' => 'active',
                'points' => 300,
                'avatar_id' => 1
            ],
            [
                'username' => 'teacher_wang',
                'email' => 'wang.fang@testdomain.com',
                'real_name' => '王芳',
                'phone' => '13800138003',
                'school_id' => 1,
                'class_name' => null,
                'role' => 'admin',
                'status' => 'active',
                'points' => 500,
                'avatar_id' => 1
            ]
        ];

        foreach ($testUserData as $userData) {
            $hashedPassword = password_hash('password123', PASSWORD_BCRYPT);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO users (username, email, password, real_name, phone, school_id, class_name, role, status, points, avatar_id, created_at, updated_at)
                VALUES (:username, :email, :password, :real_name, :phone, :school_id, :class_name, :role, :status, :points, :avatar_id, datetime('now'), datetime('now'))
            ");
            
            $stmt->execute([
                'username' => $userData['username'],
                'email' => $userData['email'],
                'password' => $hashedPassword,
                'real_name' => $userData['real_name'],
                'phone' => $userData['phone'],
                'school_id' => $userData['school_id'],
                'class_name' => $userData['class_name'],
                'role' => $userData['role'],
                'status' => $userData['status'],
                'points' => $userData['points'],
                'avatar_id' => $userData['avatar_id']
            ]);
            
            $userData['id'] = $this->pdo->lastInsertId();
            $this->testUsers[] = $userData;
        }
    }

    protected function createTestProducts(): void
    {
        $testProductData = [
            [
                'name' => 'Test Product 环保水杯',
                'description' => '可重复使用的环保水杯，材质安全，容量500ml，适合日常使用',
                'category' => 'daily',
                'images' => json_encode(['/images/products/eco_bottle_1.jpg', '/images/products/eco_bottle_2.jpg']),
                'stock' => 50,
                'points_required' => 100,
                'status' => 'active',
                'sort_order' => 1
            ],
            [
                'name' => 'Test Product 竹制餐具套装',
                'description' => '可降解竹制餐具，包含筷子、勺子、叉子，便携环保',
                'category' => 'daily',
                'images' => json_encode(['/images/products/bamboo_utensils.jpg']),
                'stock' => 30,
                'points_required' => 150,
                'status' => 'active',
                'sort_order' => 2
            ],
            [
                'name' => 'Test Product 太阳能充电宝',
                'description' => '10000mAh太阳能充电宝，支持快充，环保节能',
                'category' => 'electronics',
                'images' => json_encode(['/images/products/solar_powerbank.jpg']),
                'stock' => 20,
                'points_required' => 500,
                'status' => 'active',
                'sort_order' => 3
            ]
        ];

        foreach ($testProductData as $productData) {
            $stmt = $this->pdo->prepare("
                INSERT INTO products (name, description, category, images, stock, points_required, status, sort_order, created_at, updated_at)
                VALUES (:name, :description, :category, :images, :stock, :points_required, :status, :sort_order, datetime('now'), datetime('now'))
            ");
            
            $stmt->execute($productData);
            $productData['id'] = $this->pdo->lastInsertId();
            $this->testProducts[] = $productData;
        }
    }

    protected function createTestCarbonActivities(): void
    {
        // Use existing carbon activities from the database
        $stmt = $this->pdo->prepare("SELECT * FROM carbon_activities WHERE is_active = 1 LIMIT 5");
        $stmt->execute();
        $this->testCarbonActivities = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    protected function createRequest(string $method, string $uri, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);
        
        if (!empty($data)) {
            $request = $request->withParsedBody($data);
        }
        
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        
        return $request;
    }

    protected function getAuthToken(string $email): string
    {
        $user = $this->getTestUserByEmail($email);
        return $this->authService->generateJwtToken([
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ]);
    }

    protected function getTestUserByEmail(string $email): array
    {
        foreach ($this->testUsers as $user) {
            if ($user['email'] === $email) {
                return $user;
            }
        }
        throw new \Exception("Test user with email {$email} not found");
    }

    public function testUserRegistrationWithRealisticData(): void
    {
        $requestData = [
            'username' => 'new_student_chen',
            'email' => 'chen.xiaoming@testdomain.com',
            'password' => 'SecurePassword123!',
            'real_name' => '陈小明',
            'phone' => '13900139001',
            'school_id' => 1,
            'class_name' => '软件工程2024级1班',
            'cf_turnstile_response' => 'test_turnstile_token'
        ];

        $request = $this->createRequest('POST', '/api/v1/auth/register', $requestData);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('user', $data['data']);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertEquals($requestData['username'], $data['data']['user']['username']);
        $this->assertEquals($requestData['email'], $data['data']['user']['email']);
        
        // Clean up
        $this->pdo->exec("DELETE FROM users WHERE email = '{$requestData['email']}'");
    }

    public function testUserLoginWithRealisticCredentials(): void
    {
        $requestData = [
            'email' => 'zhang.wei@testdomain.com',
            'password' => 'password123',
            'cf_turnstile_response' => 'test_turnstile_token'
        ];

        $request = $this->createRequest('POST', '/api/v1/auth/login', $requestData);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('user', $data['data']);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertEquals($requestData['email'], $data['data']['user']['email']);
    }

    public function testGetCurrentUserProfile(): void
    {
        $token = $this->getAuthToken('zhang.wei@testdomain.com');
        
        $request = $this->createRequest('GET', '/api/v1/users/me', [], [
            'Authorization' => 'Bearer ' . $token
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertEquals('zhang.wei@testdomain.com', $data['data']['email']);
        $this->assertEquals('张伟', $data['data']['real_name']);
        $this->assertEquals(150, $data['data']['points']);
    }

    public function testCarbonTrackingWorkflow(): void
    {
        $token = $this->getAuthToken('zhang.wei@testdomain.com');
        $activity = $this->testCarbonActivities[0];
        
        // Step 1: Calculate carbon savings
        $calculateData = [
            'activity_id' => $activity['id'],
            'amount' => 2.5,
            'unit' => $activity['unit']
        ];
        
        $request = $this->createRequest('POST', '/api/v1/carbon-track/calculate', $calculateData, [
            'Authorization' => 'Bearer ' . $token
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('carbon_saved', $data['data']);
        $this->assertArrayHasKey('points_earned', $data['data']);
        
        $carbonSaved = $data['data']['carbon_saved'];
        $pointsEarned = $data['data']['points_earned'];
        
        // Step 2: Submit the record
        $recordData = [
            'activity_id' => $activity['id'],
            'amount' => 2.5,
            'description' => '今天上班自带水杯，减少了塑料瓶的使用',
            'proof_images' => ['/uploads/proof/water_bottle_20241201.jpg'],
            'request_id' => 'test-' . uniqid()
        ];
        
        $request = $this->createRequest('POST', '/api/v1/carbon-track/record', $recordData, [
            'Authorization' => 'Bearer ' . $token,
            'X-Request-ID' => $recordData['request_id']
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('transaction_id', $data['data']);
        
        $transactionId = $data['data']['transaction_id'];
        
        // Step 3: Get user's transactions
        $request = $this->createRequest('GET', '/api/v1/carbon-track/transactions', [], [
            'Authorization' => 'Bearer ' . $token
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        
        // Find our transaction
        $foundTransaction = null;
        foreach ($data['data'] as $transaction) {
            if ($transaction['id'] === $transactionId) {
                $foundTransaction = $transaction;
                break;
            }
        }
        
        $this->assertNotNull($foundTransaction);
        $this->assertEquals('pending', $foundTransaction['status']);
        $this->assertEquals($recordData['description'], $foundTransaction['description']);
    }

    public function testProductListingAndExchange(): void
    {
        $token = $this->getAuthToken('li.ming@testdomain.com'); // User with 300 points
        
        // Step 1: Get product list
        $request = $this->createRequest('GET', '/api/v1/products?category=daily&limit=10', [], [
            'Authorization' => 'Bearer ' . $token
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('products', $data['data']);
        $this->assertArrayHasKey('pagination', $data['data']);
        
        // Find a product the user can afford
        $affordableProduct = null;
        foreach ($data['data']['products'] as $product) {
            if ($product['points_required'] <= 300 && $product['is_available']) {
                $affordableProduct = $product;
                break;
            }
        }
        
        $this->assertNotNull($affordableProduct, 'User should be able to afford at least one product');
        
        // Step 2: Exchange for the product
        $exchangeData = [
            'product_id' => $affordableProduct['id'],
            'quantity' => 1,
            'shipping_address' => [
                'recipient_name' => '李明',
                'phone' => '13800138002',
                'address' => '北京市海淀区清华大学东门',
                'postal_code' => '100084'
            ],
            'request_id' => 'test-exchange-' . uniqid()
        ];
        
        $request = $this->createRequest('POST', '/api/v1/exchange', $exchangeData, [
            'Authorization' => 'Bearer ' . $token,
            'X-Request-ID' => $exchangeData['request_id']
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('exchange_id', $data['data']);
        $this->assertArrayHasKey('remaining_points', $data['data']);
        
        $expectedRemainingPoints = 300 - $affordableProduct['points_required'];
        $this->assertEquals($expectedRemainingPoints, $data['data']['remaining_points']);
    }

    public function testAdminWorkflow(): void
    {
        $adminToken = $this->getAuthToken('wang.fang@testdomain.com');
        
        // Step 1: Get pending carbon tracking records
        $request = $this->createRequest('GET', '/api/v1/admin/carbon-activities/pending', [], [
            'Authorization' => 'Bearer ' . $adminToken
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        // Step 2: Get user list for management
        $request = $this->createRequest('GET', '/api/v1/admin/users?page=1&limit=10', [], [
            'Authorization' => 'Bearer ' . $adminToken
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
        
        // Step 3: Get exchange records for admin review
        $request = $this->createRequest('GET', '/api/v1/admin/exchanges', [], [
            'Authorization' => 'Bearer ' . $adminToken
        ]);
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
    }

    public function testApiErrorHandling(): void
    {
        // Test 1: Unauthorized access
        $request = $this->createRequest('GET', '/api/v1/users/me');
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
        
        // Test 2: Invalid login credentials
        $requestData = [
            'email' => 'nonexistent@testdomain.com',
            'password' => 'wrongpassword',
            'cf_turnstile_response' => 'test_turnstile_token'
        ];
        
        $request = $this->createRequest('POST', '/api/v1/auth/login', $requestData);
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
        
        // Test 3: Insufficient points for exchange
        $token = $this->getAuthToken('zhang.wei@testdomain.com'); // User with 150 points
        $expensiveProduct = null;
        
        foreach ($this->testProducts as $product) {
            if ($product['points_required'] > 150) {
                $expensiveProduct = $product;
                break;
            }
        }
        
        if ($expensiveProduct) {
            $exchangeData = [
                'product_id' => $expensiveProduct['id'],
                'quantity' => 1,
                'shipping_address' => [
                    'recipient_name' => '张伟',
                    'phone' => '13800138001',
                    'address' => '北京市朝阳区某某路123号',
                    'postal_code' => '100000'
                ],
                'request_id' => 'test-insufficient-' . uniqid()
            ];
            
            $request = $this->createRequest('POST', '/api/v1/exchange', $exchangeData, [
                'Authorization' => 'Bearer ' . $token,
                'X-Request-ID' => $exchangeData['request_id']
            ]);
            $response = $this->app->handle($request);

            $this->assertEquals(400, $response->getStatusCode());
            
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            
            $this->assertFalse($data['success']);
            $this->assertStringContainsString('points', strtolower($data['message'] ?? $data['error'] ?? ''));
        }
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->pdo) {
            $this->pdo->exec("DELETE FROM users WHERE email LIKE '%@testdomain.com'");
            $this->pdo->exec("DELETE FROM products WHERE name LIKE 'Test Product%'");
            $this->pdo->exec("DELETE FROM point_exchanges WHERE id LIKE 'test-%'");
            $this->pdo->exec("DELETE FROM carbon_tracking WHERE id LIKE 'test-%'");
        }
        
        parent::tearDown();
    }
}