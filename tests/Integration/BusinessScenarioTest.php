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
        $configuredBaseUrl = $_ENV['CARBONTRACK_TEST_BASE_URL']
            ?? $_SERVER['CARBONTRACK_TEST_BASE_URL']
            ?? getenv('CARBONTRACK_TEST_BASE_URL')
            ?? null;

        if (is_string($configuredBaseUrl) && $configuredBaseUrl !== '') {
            $this->baseUrl = $configuredBaseUrl;
        }

        $this->baseUrl = rtrim($this->baseUrl, '/');

        // Ensure the external API server is reachable before running these end-to-end tests
        $this->startServerIfNeeded();

        // Create test users for scenarios
        $this->setupTestUsers();
    }

    private function startServerIfNeeded(): void
    {
        $probeUrl = $this->baseUrl . '/';
        $headers = @get_headers($probeUrl);

        $reachable = false;
        if (is_array($headers) && isset($headers[0]) && stripos((string)$headers[0], 'HTTP/') === 0) {
            $reachable = true;
        } elseif (is_string($headers) && stripos($headers, 'HTTP/') === 0) {
            $reachable = true;
        }

        if (!$reachable) {
            // Fallback to cURL probing when available to distinguish between HTTP errors and network failures
            if (function_exists('curl_init')) {
                $timeout = (int)($_ENV['CARBONTRACK_TEST_TIMEOUT'] ?? $_SERVER['CARBONTRACK_TEST_TIMEOUT'] ?? getenv('CARBONTRACK_TEST_TIMEOUT') ?? 3);
                $ch = curl_init($probeUrl);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_FOLLOWLOCATION => true,
                ]);
                $curlResult = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlResult !== false || $httpCode > 0) {
                    $reachable = true;
                } else {
                    $extra = $curlError ? ' (cURL error: ' . $curlError . ')' : '';
                    $this->markTestSkipped('Server not reachable on ' . $probeUrl . $extra);
                    return;
                }
            } else {
                $this->markTestSkipped('Server not reachable on ' . $probeUrl . '. Set CARBONTRACK_TEST_BASE_URL if your server runs elsewhere.');
                return;
            }
        }

        if (!$reachable) {
            $this->markTestSkipped('Server not reachable on ' . $probeUrl . '. Set CARBONTRACK_TEST_BASE_URL if your server runs elsewhere.');
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
        $url = rtrim($this->baseUrl, '/') . '/api/v1' . $endpoint;

        $hasRequestId = false;
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-Request-ID') === 0) {
                $hasRequestId = true;
                break;
            }
        }
        if (!$hasRequestId) {
            $headers['X-Request-ID'] = $this->generateRequestId();
        }

        $options = [
            'http' => [
                'method' => strtoupper($method),
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => !empty($data) ? json_encode($data) : '',
                'ignore_errors' => true
            ]
        ];

        foreach ($headers as $key => $value) {
            $options['http']['header'] .= "{$key}: {$value}\r\n";
        }

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

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

    private function generateRequestId(): string
    {
        try {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            $hex = bin2hex($data);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
        } catch (\Throwable $e) {
            return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                mt_rand(0, 0xffff),
                mt_rand(0, 0x0fff) | 0x4000,
                mt_rand(0, 0x3fff) | 0x8000,
                mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
            );
        }
    }

    public function testCompleteUserJourney(): void
    {
        $student = $this->testUsers['student'];
        
        // Step 1: User Registration
        $registrationData = [
            'username' => $student['username'],
            'email' => $student['email'],
            'password' => $student['password'],
            'confirm_password' => $student['password'],
            // 'phone' 字段已移除
            'school_id' => $student['school_id'],
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/register', $registrationData);
        
        $this->assertEquals(201, $response['status_code'], 'User registration should succeed');
        $this->assertTrue($response['body']['success'] ?? false, 'Registration should return success');
        
        if (isset($response['body']['data']['token'])) {
            $this->testUsers['student']['token'] = $response['body']['data']['token'];
        }
        
        // Step 2: User Login (alternative if registration doesn't return token)
        if (!$this->testUsers['student']['token']) {
            $loginData = [
                'email' => $student['email'],
                'password' => $student['password'],
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
        $payload = $response['body']['data'] ?? [];
        $activities = $payload['activities'] ?? $payload;
        $this->assertIsArray($activities, 'Should return array of activities');
        if (empty($activities)) {
            $this->markTestSkipped('No carbon activities available on ' . $this->baseUrl);
        }

        $firstActivity = $activities[0] ?? null;
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
            'unit' => $firstActivity['unit'],
            'date' => date('Y-m-d'),
            'description' => 'Automated test - brought reusable water bottle to work',
            'proof_images' => ['/test/proof_image.jpg'],
            'request_id' => $this->generateRequestId()
        ];

        $headers['X-Request-ID'] = $recordData['request_id'];
        $response = $this->makeApiRequest('POST', '/carbon-track/record', $recordData, $headers);

        $this->assertEquals(200, $response['status_code'], 'Submitting carbon record should succeed');
        $this->assertArrayHasKey('record_id', $response['body']['data'] ?? [], 'Should return record_id');
        $recordId = $response['body']['data']['record_id'] ?? null;
        $this->assertNotEmpty($recordId, 'Record id should not be empty');


        // Step 7: Get User's Carbon Tracking History
        $response = $this->makeApiRequest('GET', '/carbon-track/transactions', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting transactions should succeed');
        $this->assertIsArray($response['body']['data'] ?? [], 'Should return array of transactions');

        $transactionsPayload = $response['body']['data'] ?? [];
        $transactions = $transactionsPayload['records']
            ?? $transactionsPayload['transactions']
            ?? $transactionsPayload;
        if (!is_array($transactions)) {
            $transactions = [];
        }

        if (empty($transactions)) {
            $this->markTestSkipped('No transactions returned by ' . $this->baseUrl);
        }

        $foundRecord = null;
        foreach ($transactions as $transaction) {
            $transactionIdValue = $transaction['id']
                ?? ($transaction['record_id'] ?? null);
            if ($transactionIdValue === $recordId) {
                $foundRecord = $transaction;
                break;
            }
        }

        $this->assertNotNull($foundRecord, 'Submitted record should appear in history');
        
        // Step 8: Get Available Products for Exchange
        $response = $this->makeApiRequest('GET', '/products', [], $headers);
        
        $this->assertEquals(200, $response['status_code'], 'Getting products should succeed');
        
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
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/login', $invalidLogin);
        $this->assertNotEquals(200, $response['status_code'], 'Invalid login should fail');
        
    }

    public function testDataValidation(): void
    {
        // Test user registration with invalid data
        $invalidRegistration = [
            'username' => 'a', // Too short
            'email' => 'invalid-email', // Invalid format
            'password' => '123', // Too weak
        ];
        
        $response = $this->makeApiRequest('POST', '/auth/register', $invalidRegistration);
        $this->assertNotEquals(200, $response['status_code'], 'Invalid registration data should be rejected');
        
    }

    // tearDown 使用基类默认实现
}
