<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\StatsController;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\StatisticsService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class StatsControllerTest extends TestCase
{
    public function testGetPublicSummaryWritesAuditLog(): void
    {
        $statisticsService = $this->createMock(StatisticsService::class);
        $statisticsService->expects($this->once())
            ->method('getPublicStats')
            ->with(false)
            ->willReturn([
                'total_users' => 12,
                'total_checkins' => 34,
            ]);

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logSystemEvent')->willReturn(true);

        $controller = new StatsController(
            $statisticsService,
            $auditLogService,
            $this->createMock(ErrorLogService::class)
        );

        $request = makeRequest('GET', '/stats/summary')->withAttribute('request_id', 'req-stats');
        $response = $controller->getPublicSummary($request, new Response());

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(12, $payload['data']['total_users']);
    }
}