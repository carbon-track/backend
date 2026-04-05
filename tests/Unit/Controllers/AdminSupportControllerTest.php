<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminSupportController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportAutomationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminSupportControllerTest extends TestCase
{
    private function makeController(
        ?SupportAutomationService $automationService = null,
        ?AuthService $authService = null
    ): AdminSupportController {
        return new AdminSupportController(
            $automationService ?? $this->createMock(SupportAutomationService::class),
            $authService ?? $this->createMock(AuthService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class)
        );
    }

    public function testCreateTagReturnsCreatedPayload(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true, 'role' => 'admin']);

        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->once())
            ->method('saveTag')
            ->with(['id' => 1, 'is_admin' => true, 'role' => 'admin'], ['name' => 'Urgent'], null)
            ->willReturn(['id' => 8, 'name' => 'Urgent', 'slug' => 'urgent']);

        $controller = $this->makeController($service, $auth);
        $response = $controller->createTag(
            makeRequest('POST', '/api/v1/admin/support/tags', ['name' => 'Urgent']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    public function testReportsReturnsValidationError(): void
    {
        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->once())
            ->method('getReports')
            ->willThrowException(new \InvalidArgumentException('Invalid days'));

        $controller = $this->makeController($service);
        $response = $controller->reports(
            makeRequest('GET', '/api/v1/admin/support/reports?days=999'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testGetAssigneeDetailReturnsNotFound(): void
    {
        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->once())
            ->method('getAssignableUserDetail')
            ->with(42)
            ->willReturn(null);

        $controller = $this->makeController($service);
        $response = $controller->getAssigneeDetail(
            makeRequest('GET', '/api/v1/admin/support/assignees/42'),
            new \Slim\Psr7\Response(),
            ['id' => '42']
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
