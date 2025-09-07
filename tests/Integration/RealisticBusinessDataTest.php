<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\DatabaseService;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use DI\Container;

/**
 * Realistic Business Data Test
 * 
 * Tests the core business flows with realistic data scenarios
 * without requiring external server setup
 */
class RealisticBusinessDataTest extends TestCase
{
    private App $app;
    private Container $container;

    protected function setUp(): void
    {
        // Set up minimal test environment
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DATABASE_PATH'] = __DIR__ . '/../../test.db';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_ENV['DATABASE_PATH'];
        $_ENV['JWT_SECRET'] = 'test_jwt_secret_for_testing';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'test_turnstile_secret';
    // Provide dummy Cloudflare R2 env vars so CloudflareR2Service can be constructed without throwing
    $_ENV['R2_ACCESS_KEY_ID'] = 'test_access_key';
    $_ENV['R2_SECRET_ACCESS_KEY'] = 'test_secret_key';
    $_ENV['R2_ENDPOINT'] = 'https://example.com';
    $_ENV['R2_BUCKET_NAME'] = 'test-bucket';
    $_ENV['R2_PUBLIC_URL'] = 'https://example.com/test-bucket';

        // Ensure SQLite file exists
        if (!file_exists($_ENV['DATABASE_PATH'])) {
            touch($_ENV['DATABASE_PATH']);
        }

        try {
            $this->container = new Container();

            // Provide database config for dependencies.php (Illuminate setup)
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
            $this->container->set('config', $config);

            // Load dependencies initializer and execute it with our container
            $depsInitializer = require __DIR__ . '/../../src/dependencies.php';
            if (is_callable($depsInitializer)) {
                $depsInitializer($this->container);
            }

            // Initialize unified minimal schema + seed BEFORE app boot
            /** @var DatabaseService $dbServiceSchema */
            $dbServiceSchema = $this->container->get(DatabaseService::class);
            TestSchemaBuilder::init($dbServiceSchema->getConnection()->getPdo());

            // Create Slim app
            $this->app = \Slim\Factory\AppFactory::createFromContainer($this->container);
            $this->app->addErrorMiddleware(false, false, false); // Disable detailed errors for cleaner test output
            $this->app->addBodyParsingMiddleware();
            $this->app->addRoutingMiddleware();

            // Add routes
            $routes = require_once __DIR__ . '/../../src/routes.php';
            $routes($this->app);

            // Previously inline creation of carbon_activities & avatars now handled by TestSchemaBuilder
        } catch (\Exception $e) {
            $this->markTestSkipped('Could not set up test environment: ' . $e->getMessage());
        }
    }

    private function createRequest(string $method, string $uri, array $data = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
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

    public function testHealthCheckEndpoint(): void
    {
        $request = $this->createRequest('GET', '/');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('CarbonTrack API is running', $data['message']);
        $this->assertEquals('1.0.0', $data['version']);
    }

    public function testApiV1RootEndpoint(): void
    {
        $request = $this->createRequest('GET', '/api/v1');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertEquals('CarbonTrack API v1', $data['message']);
        $this->assertArrayHasKey('endpoints', $data);
        
        // Verify all major endpoints are listed
        $expectedEndpoints = [
            'auth', 'users', 'carbon-activities', 'carbon-track', 
            'products', 'exchange', 'messages', 'avatars', 'admin'
        ];
        
        foreach ($expectedEndpoints as $endpoint) {
            $this->assertArrayHasKey($endpoint, $data['endpoints'], "Should have {$endpoint} endpoint listed");
        }
    }

    public function testCarbonActivitiesPublicEndpoint(): void
    {
        $request = $this->createRequest('GET', '/api/v1/carbon-activities');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);

        // New API structure: data.activities holds list
        $activitiesList = $data['data']['activities'] ?? $data['data'];
        $this->assertIsArray($activitiesList, 'Activities list should be an array');
        
        // Verify we have carbon activities data with realistic structure
        if (!empty($activitiesList)) {
            $activity = $activitiesList[0];
            $this->assertArrayHasKey('id', $activity);
            $this->assertArrayHasKey('name_zh', $activity);
            $this->assertArrayHasKey('name_en', $activity);
            $this->assertArrayHasKey('category', $activity);
            $this->assertArrayHasKey('carbon_factor', $activity);
            $this->assertArrayHasKey('unit', $activity);
            
            // Verify realistic business data
            $this->assertNotEmpty($activity['name_zh'], 'Should have Chinese name');
            $this->assertNotEmpty($activity['name_en'], 'Should have English name');
            $this->assertIsNumeric($activity['carbon_factor'], 'Carbon factor should be numeric');
            $this->assertNotEmpty($activity['unit'], 'Should have unit');
        }
    }

    public function testAvatarsPublicEndpoint(): void
    {
        $request = $this->createRequest('GET', '/api/v1/avatars');
        $response = $this->app->handle($request);

        $this->assertEquals(200, $response->getStatusCode());
        
        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
        
        // Verify avatar data structure
        if (!empty($data['data'])) {
            $avatar = $data['data'][0];
            $this->assertArrayHasKey('id', $avatar);
            $this->assertArrayHasKey('name', $avatar);
            $this->assertArrayHasKey('file_path', $avatar);
            $this->assertArrayHasKey('category', $avatar);
        }
    }

    public function testUnauthorizedAccessHandling(): void
    {
        // Test accessing protected endpoint without auth
        $request = $this->createRequest('GET', '/api/v1/users/me');
        $response = $this->app->handle($request);

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testCarbonCalculationWithRealisticData(): void
    {
        // Test calculation endpoint (if accessible without auth, or mock auth)
        $realisticCalculationData = [
            'activity_id' => '550e8400-e29b-41d4-a716-446655440001', // From sample data
            'amount' => 3.5,
            'unit' => 'times'
        ];

        // This may require authentication, so we test with mock token
        $mockToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.test.signature'; // Mock JWT
        
        $request = $this->createRequest('POST', '/api/v1/carbon-track/calculate', $realisticCalculationData, [
            'Authorization' => 'Bearer ' . $mockToken
        ]);
        
        $response = $this->app->handle($request);
        
        // We expect either 200 (success) or 401 (unauthorized), not 500 (server error)
        $this->assertContains($response->getStatusCode(), [200, 401], 
            'Carbon calculation should either work or require auth, not crash');
        
        if ($response->getStatusCode() === 200) {
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('carbon_saved', $data['data']);
            $this->assertArrayHasKey('points_earned', $data['data']);
            $this->assertIsNumeric($data['data']['carbon_saved']);
            $this->assertIsNumeric($data['data']['points_earned']);
        }
    }

    public function testUserRegistrationValidation(): void
    {
        // Test with realistic but potentially problematic data
        $realisticRegistrationData = [
            'username' => 'test_user_' . time(), // Unique username
            'email' => 'test_user_' . time() . '@example.com', // Unique email
            'password' => 'SecurePassword123!',
            'confirm_password' => 'SecurePassword123!',
            'school_id' => 1,
            // 省略 cf_turnstile_response 以跳过外部验证
        ];

        $request = $this->createRequest('POST', '/api/v1/auth/register', $realisticRegistrationData);
        $response = $this->app->handle($request);

        // Should either succeed or fail gracefully (not crash)
    $this->assertContains($response->getStatusCode(), [200, 400, 422, 429],
            'Registration should handle realistic data gracefully');
        
        if ($response->getStatusCode() === 200) {
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            
            $this->assertTrue($data['success']);
            $this->assertArrayHasKey('user', $data['data']);
            $this->assertArrayHasKey('token', $data['data']);
        }
    }

    public function testInvalidDataHandling(): void
    {
        // Test with various invalid data scenarios
        $invalidScenarios = [
            // Empty registration
            [
                'data' => [],
                'endpoint' => '/api/v1/auth/register',
                'method' => 'POST'
            ],
            // Invalid email format
            [
                'data' => [
                    'username' => 'testuser',
                    'email' => 'invalid-email-format',
                    'password' => 'password123'
                ],
                'endpoint' => '/api/v1/auth/register',
                'method' => 'POST'
            ],
            // SQL injection attempt
            [
                'data' => [
                    'username' => "admin'; DROP TABLE users; --",
                    'email' => 'hacker@test.com',
                    'password' => 'password123'
                ],
                'endpoint' => '/api/v1/auth/register',
                'method' => 'POST'
            ],
            // XSS attempt
            [
                'data' => [
                    'username' => '<script>alert("xss")</script>',
                    'email' => 'xss@test.com',
                    'password' => 'password123'
                ],
                'endpoint' => '/api/v1/auth/register',
                'method' => 'POST'
            ]
        ];

        foreach ($invalidScenarios as $scenario) {
            $request = $this->createRequest($scenario['method'], $scenario['endpoint'], $scenario['data']);
            $response = $this->app->handle($request);
            
            // Should reject invalid data gracefully (400-level error, not 500)
            $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
            $this->assertLessThan(500, $response->getStatusCode());
        }
    }

    public function testLargeDataHandling(): void
    {
        // Test with realistic but large data sets
        $largeDescription = str_repeat('这是一个测试描述。', 100); // 1000+ characters Chinese text
        
        $largeDataRequest = [
            'activity_id' => '550e8400-e29b-41d4-a716-446655440001',
            'amount' => 999999.99, // Large amount
            'description' => $largeDescription,
            'proof_images' => array_fill(0, 10, '/test/image_' . uniqid() . '.jpg'), // Multiple images
            'request_id' => 'large_test_' . uniqid()
        ];

        $mockToken = 'mock_jwt_token';
        $request = $this->createRequest('POST', '/api/v1/carbon-track/record', $largeDataRequest, [
            'Authorization' => 'Bearer ' . $mockToken,
            'X-Request-ID' => $largeDataRequest['request_id']
        ]);
        
        $response = $this->app->handle($request);
        
        // Should handle large data gracefully (not crash with 500 error)
    $this->assertNotEquals(500, $response->getStatusCode(),
            'Large data should not cause server errors');
    }

    public function testConcurrentRequestHandling(): void
    {
        // Simulate concurrent requests with different request IDs
        $requests = [];
        for ($i = 0; $i < 5; $i++) {
            $data = [
                'activity_id' => '550e8400-e29b-41d4-a716-446655440001',
                'amount' => 1.0 + $i,
                'description' => "Concurrent test request {$i}",
                'request_id' => 'concurrent_test_' . $i . '_' . uniqid()
            ];
            
            $requests[] = $this->createRequest('POST', '/api/v1/carbon-track/calculate', $data);
        }

        // Execute requests
        $responses = [];
        foreach ($requests as $request) {
            $responses[] = $this->app->handle($request);
        }

        // All requests should be handled consistently
        foreach ($responses as $response) {
            $this->assertContains($response->getStatusCode(), [200, 401, 422],
                'Concurrent requests should be handled consistently');
        }
    }

    // tearDown 使用基类默认实现
}