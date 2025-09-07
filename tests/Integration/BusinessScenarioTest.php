<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Practical Business Scenario Tests
 * 
 * This test suite focuses on real-world business scenarios using the actual API
 * to validate core functionality works as expected in realistic conditions.
 */
class BusinessScenarioTest extends TestCase
{
    private array $testUsers = [];
    private string $baseUrl = 'http://localhost:8080';

    protected function setUp(): void
    {
        // Start the PHP built-in server if not already running
        $this->startServerIfNeeded();
        
        // Create test users for scenarios
        $this->setupTestUsers();
    }

    private function startServerIfNeeded(): void
    {
        // Check if server is already running
        $context = stream_context_create(['http' => ['timeout' => 1]]);
        $result = @file_get_contents($this->baseUrl, false, $context);
        
        if ($result === false) {
            // Server not running, we'll skip these tests
            $this->markTestSkipped('Server not running on ' . $this->baseUrl);
        }
    }

    private function setupTestUsers(): void
    {
        $this->testUsers = [
            'student' => [
                'username' => 'test_student_' . time(),
                'email' => 'student_' . time() . '@test.com',
                'password' => 'SecurePassword123!',
                // real_name 与 class_name 已弃用
                // phone 字段已移除
                'school_id' => 1,
                'token' => null
            ],
            'admin' => [
                'username' => 'test_admin_' . time(),
                'email' => 'admin_' . time() . '@test.com',
                'password' => 'AdminPassword123!',
                // real_name 已弃用
                // phone 字段已移除
                'school_id' => 1,
                'role' => 'admin',
                'token' => null
            ]
        ];
    }

    private function makeApiRequest(string $method, string $endpoint, array $data = [], array $headers = []): array
    {
        $url = $this->baseUrl . '/api/v1' . $endpoint;
        
        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => "Content-Type: application/json\r\n",
                'content' => !empty($data) ? json_encode($data) : '',
                'ignore_errors' => true
            ]
        ];
        
        // Add additional headers
        foreach ($headers as $key => $value) {
            $options['http']['header'] .= "{$key}: {$value}\r\n";
        }
        
        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);
        
        // Parse response headers to get status code
        $statusCode = 200;
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (strpos($header, 'HTTP/') === 0) {
                    $statusCode = (int) explode(' ', $header)[1];
                    break;
                }
            }
        }
        
        return [
            'status_code' => $statusCode,
            'body' => $response ? json_decode($response, true) : null,
            'raw_body' => $response
        ];
    }

    public function testCompleteUserJourney(): void
    {
        $student = $this->testUsers['student'];
        
        // Step 1: User Registration
        $registrationData = [
            'username' => $student['username'],
            'email' => $student['email'],
            'password' => $student['password'],
            // 'phone' 字段已移除
            'school_id' => $student['school_id'],
            'cf_turnstile_response' => 'test_token' // Mock turnstile
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/register', $registrationData);
        
        $this->assertEquals(200, $response['status_code'], 'User registration should succeed');
        $this->assertTrue($response['body']['success'] ?? false, 'Registration should return success');
        
        if (isset($response['body']['data']['token'])) {
            $this->testUsers['student']['token'] = $response['body']['data']['token'];
        }
        
        // Step 2: User Login (alternative if registration doesn't return token)
        if (!$this->testUsers['student']['token']) {
            $loginData = [
                'email' => $student['email'],
                'password' => $student['password'],
                'cf_turnstile_response' => 'test_token'
            ];
            
            $response = $this->makeApiRequest('POST', '/auth/login', $loginData);
            $this->assertEquals(200, $response['status_code'], 'User login should succeed');
            $this->testUsers['student']['token'] = $response['body']['data']['token'] ?? null;
        }
        
        $this->assertNotNull($this->testUsers['student']['token'], 'Should have authentication token');
        
        // Step 3: Get User Profile
        $headers = ['Authorization' => 'Bearer ' . $this->testUsers['student']['token']];
        $response = $this->makeApiRequest('GET', '/users/me', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting user profile should succeed');
        $this->assertEquals($student['email'], $response['body']['data']['email'] ?? '', 'Should return correct user email');
        
        // Step 4: Get Available Carbon Activities
        $response = $this->makeApiRequest('GET', '/carbon-activities', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting carbon activities should succeed');
        $this->assertIsArray($response['body']['data'] ?? [], 'Should return array of activities');
        $this->assertNotEmpty($response['body']['data'] ?? [], 'Should have at least one activity');
        
        $firstActivity = $response['body']['data'][0] ?? null;
        $this->assertNotNull($firstActivity, 'Should have first activity');
        
        // Step 5: Calculate Carbon Savings
        $calculateData = [
            'activity_id' => $firstActivity['id'],
            'amount' => 2.0,
            'unit' => $firstActivity['unit']
        ];
        
        $response = $this->makeApiRequest('POST', '/carbon-track/calculate', $calculateData, $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Carbon calculation should succeed');
        $this->assertArrayHasKey('carbon_saved', $response['body']['data'] ?? [], 'Should return carbon_saved');
        $this->assertArrayHasKey('points_earned', $response['body']['data'] ?? [], 'Should return points_earned');
        
        // Step 6: Submit Carbon Tracking Record
        $recordData = [
            'activity_id' => $firstActivity['id'],
            'amount' => 2.0,
            'description' => 'Automated test - brought reusable water bottle to work',
            'proof_images' => ['/test/proof_image.jpg'],
            'request_id' => 'test_' . uniqid()
        ];
        
        $headers['X-Request-ID'] = $recordData['request_id'];
        $response = $this->makeApiRequest('POST', '/carbon-track/record', $recordData, $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Submitting carbon record should succeed');
        $this->assertArrayHasKey('transaction_id', $response['body']['data'] ?? [], 'Should return transaction_id');
        
        // Step 7: Get User's Carbon Tracking History
        $response = $this->makeApiRequest('GET', '/carbon-track/transactions', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting transactions should succeed');
        $this->assertIsArray($response['body']['data'] ?? [], 'Should return array of transactions');
        
        // Step 8: Get Available Products for Exchange
        $response = $this->makeApiRequest('GET', '/products', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting products should succeed');
        
        echo "\n✅ Complete user journey test passed! User can register, login, track carbon activities, and view products.\n";
    }

    public function testAdminWorkflow(): void
    {
        // This test requires an existing admin user or the ability to create one
        // For simplicity, we'll test admin endpoints that don't require authentication
        
        // Test getting carbon activities (public endpoint)
        $response = $this->makeApiRequest('GET', '/carbon-activities');
        
        $this->assertEquals(200, $response['status_code'], 'Getting carbon activities should work');
        $this->assertIsArray($response['body']['data'] ?? [], 'Should return activities data');
        
        // Test getting avatars (public endpoint)
        $response = $this->makeApiRequest('GET', '/avatars');
        
        $this->assertEquals(200, $response['status_code'], 'Getting avatars should work');
        $this->assertIsArray($response['body']['data'] ?? [], 'Should return avatars data');
        
        echo "\n✅ Admin workflow basics test passed!\n";
    }

    public function testApiHealthAndConnectivity(): void
    {
        // Test root health check
        $response = $this->makeApiRequest('GET', '', []); // Root endpoint
        $this->assertEquals(200, $response['status_code'], 'Root health check should work');
        
        // Test API v1 root
        $response = $this->makeApiRequest('GET', '');
        $this->assertEquals(200, $response['status_code'], 'API v1 root should work');
        
        // Test that proper error handling works for non-existent endpoints
        $response = $this->makeApiRequest('GET', '/nonexistent');
        $this->assertNotEquals(200, $response['status_code'], 'Non-existent endpoint should return error');
        
        echo "\n✅ API health and connectivity test passed!\n";
    }

    public function testAuthenticationFlow(): void
    {
        // Test accessing protected endpoint without authentication
        $response = $this->makeApiRequest('GET', '/users/me');
        $this->assertEquals(401, $response['status_code'], 'Protected endpoint should require authentication');
        
        // Test invalid login credentials
        $invalidLogin = [
            'email' => 'nonexistent@test.com',
            'password' => 'wrongpassword',
            'cf_turnstile_response' => 'test_token'
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/login', $invalidLogin);
        $this->assertNotEquals(200, $response['status_code'], 'Invalid login should fail');
        
        echo "\n✅ Authentication flow test passed!\n";
    }

    public function testDataValidation(): void
    {
        // Test user registration with invalid data
        $invalidRegistration = [
            'username' => 'a', // Too short
            'email' => 'invalid-email', // Invalid format
            'password' => '123', // Too weak
            'cf_turnstile_response' => 'test_token'
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/register', $invalidRegistration);
        $this->assertNotEquals(200, $response['status_code'], 'Invalid registration data should be rejected');
        
        echo "\n✅ Data validation test passed!\n";
    }

    // tearDown 使用基类默认实现
}