<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\UserAiController;
use CarbonTrack\Services\UserAiService;
use CarbonTrack\Services\CarbonCalculatorService;
use CarbonTrack\Services\QuotaService;
use CarbonTrack\Services\AuthService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;

// Ensure makeRequest is available
require_once __DIR__ . '/../../bootstrap.php';

class UserAiControllerTest extends TestCase
{
    private $aiService;
    private $calculatorService;
    private $quotaService;
    private $authService;
    private $logger;

    protected function setUp(): void
    {
        $this->aiService = $this->createMock(UserAiService::class);
        $this->calculatorService = $this->createMock(CarbonCalculatorService::class);
        $this->quotaService = $this->createMock(QuotaService::class);
        $this->authService = $this->createMock(AuthService::class);
        $this->logger = new NullLogger();

        // Default quota check pass
        $this->quotaService->method('checkAndConsume')->willReturn(true);
        
        // Mock getUserIdFromRequest
        $this->authService->method('getUserIdFromRequest')->willReturn(1);
        $this->authService->method('getCurrentUserModel')->willReturn($this->createMock(\CarbonTrack\Models\User::class));
    }

    private function createController(): UserAiController
    {
        return new UserAiController(
            $this->aiService,
            $this->calculatorService,
            $this->quotaService,
            $this->logger,
            $this->authService
        );
    }

    public function testSuggestActivityReturnsPrediction(): void
    {
        $this->aiService->method('suggestActivity')->willReturn([
            'success' => true,
            'prediction' => [
                'activity_name' => 'Bus Ride',
                'amount' => 5,
                'unit' => 'km',
                'confidence' => 0.95
            ]
        ]);

        $this->calculatorService->method('getAvailableActivities')->willReturn([
            [
                'name_en' => 'Bus Ride',
                'name_zh' => '公交',
                'category' => 'transport'
            ]
        ]);

        $controller = $this->createController();

        $request = makeRequest('POST', '/ai/suggest-activity', ['query' => 'I took a 5km bus ride']);
        $response = $controller->suggestActivity($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame('Bus Ride', $payload['prediction']['activity_name']);
        $this->assertSame(5, $payload['prediction']['amount']);
    }

    public function testSuggestActivityValidatesEmptyQuery(): void
    {
        $controller = $this->createController();

        $request = makeRequest('POST', '/ai/suggest-activity', ['query' => '   ']);
        $response = $controller->suggestActivity($request, new Response());

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('Query is required', $payload['error']);
    }

    public function testSuggestActivityHandlesServiceException(): void
    {
        $this->aiService->method('suggestActivity')->willThrowException(new \RuntimeException('Service unavailable'));
        $this->calculatorService->method('getAvailableActivities')->willReturn([]);

        $controller = $this->createController();

        $request = makeRequest('POST', '/ai/suggest-activity', ['query' => 'test']);
        $response = $controller->suggestActivity($request, new Response());

        $this->assertSame(503, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
    }
    
    public function testSuggestActivityEnforcesQuota(): void
    {
        // Re-configure the stub for this specific test
        $this->quotaService = $this->createMock(QuotaService::class);
        $this->quotaService->method('checkAndConsume')->willReturn(false);
        
        $this->authService = $this->createMock(AuthService::class);
        $this->authService->method('getCurrentUserModel')->willReturn($this->createMock(\CarbonTrack\Models\User::class));

        $controller = $this->createController();

        $request = makeRequest('POST', '/ai/suggest-activity', ['query' => 'test']);
        $response = $controller->suggestActivity($request, new Response());

        // Assuming controller returns 429 when checkAndConsume returns false
        $this->assertSame(429, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('Daily limit or rate limit exceeded', $payload['error']);
    }
}
