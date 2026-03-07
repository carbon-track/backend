<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\LeaderboardController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LeaderboardService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class LeaderboardControllerTest extends TestCase
{
    public function testTriggerRefreshWritesAuditLog(): void
    {
        $_ENV['LEADERBOARD_TRIGGER_KEY'] = 'secret-key';

        $leaderboardService = $this->createMock(LeaderboardService::class);
        $leaderboardService->expects($this->once())
            ->method('rebuildCache')
            ->with('manual-trigger')
            ->willReturn([
                'generated_at' => '2026-03-07T00:00:00Z',
                'expires_at' => '2026-03-07T01:00:00Z',
                'global' => [1, 2],
                'regions' => [1],
                'schools' => [1, 2, 3],
            ]);

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        $controller = new LeaderboardController(
            $leaderboardService,
            $logger,
            $auditLogService,
            $this->createMock(ErrorLogService::class)
        );

        $request = makeRequest('GET', '/leaderboard/trigger', null, ['key' => 'secret-key'])
            ->withAttribute('request_id', 'req-1');
        $response = $controller->triggerRefresh($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['data']['global_count']);
    }
}