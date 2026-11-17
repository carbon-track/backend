<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminSupportController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportRoutingEngineService;
use CarbonTrack\Services\SupportAutomationService;
use CarbonTrack\Services\SupportOperationException;
use CarbonTrack\Services\SupportTicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AdminSupportControllerTest extends TestCase
{
    private function makeController(
        ?SupportAutomationService $automationService = null,
        ?AuthService $authService = null,
        ?SupportTicketService $ticketService = null,
        ?SupportRoutingEngineService $routingEngineService = null,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null
    ): AdminSupportController {
        return new AdminSupportController(
            $automationService ?? $this->createMock(SupportAutomationService::class),
            $ticketService ?? $this->createMock(SupportTicketService::class),
            $routingEngineService ?? $this->createMock(SupportRoutingEngineService::class),
            $authService ?? $this->createMock(AuthService::class),
            $auditLogService ?? $this->createMock(AuditLogService::class),
            $this->createMock(LoggerInterface::class),
            $errorLogService ?? $this->createMock(ErrorLogService::class)
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

    public function testUpdateTagReturnsValidationErrorForInvalidId(): void
    {
        $controller = $this->makeController();
        $response = $controller->updateTag(
            makeRequest('PUT', '/api/v1/admin/support/tags/0', ['name' => 'Urgent']),
            new \Slim\Psr7\Response(),
            ['id' => '0']
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
    }

    /**
     * @dataProvider supportResourceUpdates
     */
    public function testSupportResourceUpdateDistinguishesDatabaseFailure(
        string $handler,
        string $serviceMethod,
        string $resource,
        string $notFoundCode
    ): void {
        $request = makeRequest('PUT', '/api/v1/admin/support/' . $resource . '/42', ['name' => 'Test']);
        $args = ['id' => '42'];
        $databaseFailure = new \PDOException('database unavailable');
        $missingResource = new SupportOperationException('Resource not found');
        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->exactly(2))->method($serviceMethod)->willReturnOnConsecutiveCalls(
            $this->throwException($missingResource),
            $this->throwException($databaseFailure)
        );
        $loggedExceptions = [];
        $errorLog = $this->createMock(ErrorLogService::class);
        $errorLog->method('logException')->willReturnCallback(
            static function (\Throwable $exception) use (&$loggedExceptions): int {
                $loggedExceptions[] = $exception;
                return 1;
            }
        );
        $controller = $this->makeController($service, errorLogService: $errorLog);

        $missing = $controller->$handler($request, new \Slim\Psr7\Response(), $args);
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame($notFoundCode, json_decode((string) $missing->getBody(), true)['code']);

        $failure = $controller->$handler($request, new \Slim\Psr7\Response(), $args);
        $this->assertSame(500, $failure->getStatusCode());
        $this->assertSame('INTERNAL_ERROR', json_decode((string) $failure->getBody(), true)['code']);
        $this->assertSame([$missingResource, $databaseFailure], $loggedExceptions);
    }

    public static function supportResourceUpdates(): array
    {
        return [
            'tag' => ['updateTag', 'saveTag', 'tags', 'TAG_NOT_FOUND'],
            'rule' => ['updateRule', 'saveRule', 'rules', 'RULE_NOT_FOUND'],
        ];
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

    public function testUpdateRoutingSettingsReturnsPayload(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true, 'role' => 'admin']);

        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->once())
            ->method('saveRoutingSettings')
            ->with(['id' => 1, 'is_admin' => true, 'role' => 'admin'], ['ai_enabled' => true])
            ->willReturn(['id' => 1, 'ai_enabled' => true]);

        $controller = $this->makeController($service, $auth);
        $response = $controller->updateRoutingSettings(
            makeRequest('PUT', '/api/v1/admin/support/routing-settings', ['ai_enabled' => true]),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUpdateAssigneeRoutingProfileReturnsNotFound(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true, 'role' => 'admin']);

        $service = $this->createMock(SupportAutomationService::class);
        $service->expects($this->once())
            ->method('saveAssigneeRoutingProfile')
            ->willThrowException(new SupportOperationException('Support assignee not found'));

        $controller = $this->makeController($service, $auth);
        $response = $controller->updateAssigneeRoutingProfile(
            makeRequest('PUT', '/api/v1/admin/support/assignees/42/routing-profile', ['level' => 3]),
            new \Slim\Psr7\Response(),
            ['id' => '42']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateRuleReturnsValidationErrorForInvalidId(): void
    {
        $controller = $this->makeController();
        $response = $controller->updateRule(
            makeRequest('PUT', '/api/v1/admin/support/rules/0', ['name' => 'Rule']),
            new \Slim\Psr7\Response(),
            ['id' => '0']
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
    }

    public function testListTicketsReturnsQueuePayload(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');
        $ticketService = $this->createMock(SupportTicketService::class);
        $ticketService->expects($this->once())
            ->method('listSupportTickets')
            ->with([], [])
            ->willReturn(['items' => [['id' => 7]], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 1]]);

        $controller = $this->makeController(ticketService: $ticketService, auditLogService: $audit);
        $response = $controller->listTickets(
            makeRequest('GET', '/api/v1/admin/support/tickets'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testListTicketsReturnsValidationErrorForInvalidFilters(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $ticketService = $this->createMock(SupportTicketService::class);
        $ticketService->expects($this->once())
            ->method('listSupportTickets')
            ->with([], ['status' => 'bad'])
            ->willThrowException(new \InvalidArgumentException('Invalid status'));

        $controller = $this->makeController(ticketService: $ticketService, auditLogService: $audit);
        $response = $controller->listTickets(
            makeRequest('GET', '/api/v1/admin/support/tickets?status=bad', [], ['status' => 'bad']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
        $this->assertSame('Invalid status', $payload['message']);
    }

    public function testGetTicketDetailIncludesRoutingRuns(): void
    {
        $_ENV['SUPPORT_ROUTING_AUDIT_LIMIT'] = '4';
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $ticketService = $this->createMock(SupportTicketService::class);
        $ticketService->expects($this->once())
            ->method('getTicketDetailForSupport')
            ->with([], 12)
            ->willReturn(['id' => 12, 'subject' => 'Test']);

        $routingEngine = $this->createMock(SupportRoutingEngineService::class);
        $routingEngine->expects($this->once())
            ->method('getRoutingRunsForTicket')
            ->with(12, 4)
            ->willReturn([['id' => 90, 'trigger' => 'created']]);

        $controller = $this->makeController(
            ticketService: $ticketService,
            routingEngineService: $routingEngine,
            auditLogService: $audit
        );

        $response = $controller->getTicketDetail(
            makeRequest('GET', '/api/v1/admin/support/tickets/12'),
            new \Slim\Psr7\Response(),
            ['id' => '12']
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertSame(90, $payload['data']['routing_runs'][0]['id']);
    }

    public function testUpdateTicketReturnsUpdatedPayload(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logAdminOperation');

        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true, 'role' => 'admin']);

        $ticketService = $this->createMock(SupportTicketService::class);
        $ticketService->expects($this->once())
            ->method('updateTicketFromSupport')
            ->with(['id' => 1, 'is_admin' => true, 'role' => 'admin'], 12, ['status' => 'resolved'])
            ->willReturn(['id' => 12, 'status' => 'resolved']);

        $controller = $this->makeController(
            authService: $auth,
            ticketService: $ticketService,
            auditLogService: $audit
        );

        $response = $controller->updateTicket(
            makeRequest('PATCH', '/api/v1/admin/support/tickets/12', ['status' => 'resolved']),
            new \Slim\Psr7\Response(),
            ['id' => '12']
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('resolved', $payload['data']['status']);
    }

    public function testUpdateTicketValidationErrorLogsAuditFailure(): void
    {
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('logAdminOperation')
            ->with(
                'admin_support_ticket_update_failed',
                1,
                'admin_support',
                $this->callback(static function (array $context): bool {
                    return ($context['table'] ?? null) === 'support_tickets'
                        && ($context['record_id'] ?? null) === 12
                        && ($context['status'] ?? null) === 'failed'
                        && ($context['data']['error'] ?? null) === 'Invalid status';
                })
            );

        $errorLog = $this->createMock(ErrorLogService::class);
        $errorLog->expects($this->once())->method('logException');

        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'is_admin' => true, 'role' => 'admin']);

        $ticketService = $this->createMock(SupportTicketService::class);
        $ticketService->expects($this->once())
            ->method('updateTicketFromSupport')
            ->with(['id' => 1, 'is_admin' => true, 'role' => 'admin'], 12, ['status' => 'bad'])
            ->willThrowException(new \InvalidArgumentException('Invalid status'));

        $controller = $this->makeController(
            authService: $auth,
            ticketService: $ticketService,
            auditLogService: $audit,
            errorLogService: $errorLog
        );

        $response = $controller->updateTicket(
            makeRequest('PATCH', '/api/v1/admin/support/tickets/12', ['status' => 'bad']),
            new \Slim\Psr7\Response(),
            ['id' => '12']
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('VALIDATION_ERROR', $payload['code']);
    }

}
