<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\SupportTicketController;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\SupportRoutingEngineService;
use CarbonTrack\Services\SupportTicketService;
use CarbonTrack\Services\TurnstileService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportTicketControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['SUPPORT_SLA_SWEEP_KEY']);
        unset($_GET['key']);
    }

    private function makeController(
        ?SupportTicketService $supportTicketService = null,
        ?AuthService $authService = null,
        ?TurnstileService $turnstileService = null,
        ?SupportRoutingEngineService $supportRoutingEngineService = null,
        ?AuditLogService $auditLogService = null
    ): SupportTicketController {
        return new SupportTicketController(
            $supportTicketService ?? $this->createMock(SupportTicketService::class),
            $authService ?? $this->createMock(AuthService::class),
            $turnstileService ?? $this->createMock(TurnstileService::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ErrorLogService::class),
            $supportRoutingEngineService,
            $auditLogService
        );
    }

    public function testCreateTicketRequiresAuthentication(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);

        $controller = $this->makeController(authService: $auth);
        $response = $controller->createTicket(
            makeRequest('POST', '/api/v1/tickets', ['subject' => 'Need help']),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCreateTicketRejectsFailedTurnstile(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'username' => 'user', 'email' => 'user@example.com']);

        $turnstile = $this->createMock(TurnstileService::class);
        $turnstile->expects($this->once())
            ->method('verify')
            ->with('bad-token', null)
            ->willReturn(['success' => false]);

        $controller = $this->makeController(authService: $auth, turnstileService: $turnstile);
        $response = $controller->createTicket(
            makeRequest('POST', '/api/v1/tickets', [
                'subject' => 'Broken page',
                'content' => 'Details',
                'category' => 'website_bug',
                'cf_turnstile_response' => 'bad-token',
            ]),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testListSupportTicketsRequiresAuthentication(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(null);

        $controller = $this->makeController(authService: $auth);
        $response = $controller->listSupportTickets(
            makeRequest('GET', '/api/v1/support/tickets'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testGetSupportTicketReturnsNotFound(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'support', 'is_support' => true]);

        $service = $this->createMock(SupportTicketService::class);
        $service->expects($this->once())
            ->method('getTicketDetailForSupport')
            ->with(['id' => 1, 'role' => 'support', 'is_support' => true], 42)
            ->willThrowException(new \RuntimeException('Ticket not found'));

        $controller = $this->makeController($service, $auth);
        $response = $controller->getSupportTicket(
            makeRequest('GET', '/api/v1/support/tickets/42'),
            new \Slim\Psr7\Response(),
            ['ticketId' => '42']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateSupportTicketReturnsValidationError(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 2, 'role' => 'support', 'is_support' => true]);

        $service = $this->createMock(SupportTicketService::class);
        $service->expects($this->once())
            ->method('updateTicketFromSupport')
            ->willThrowException(new \InvalidArgumentException('Invalid status'));

        $controller = $this->makeController($service, $auth);
        $response = $controller->updateSupportTicket(
            makeRequest('PATCH', '/api/v1/support/tickets/12', ['status' => 'bad_status']),
            new \Slim\Psr7\Response(),
            ['ticketId' => '12']
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testListSupportAssigneesReturnsSuccess(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 2, 'role' => 'support', 'is_support' => true]);

        $service = $this->createMock(SupportTicketService::class);
        $service->expects($this->once())
            ->method('listSupportAssignees')
            ->with(['id' => 2, 'role' => 'support', 'is_support' => true])
            ->willReturn([
                ['id' => 5, 'username' => 'support-a', 'assigned_total_count' => 6, 'open_count' => 2, 'in_progress_count' => 3],
            ]);

        $controller = $this->makeController($service, $auth);
        $response = $controller->listSupportAssignees(
            makeRequest('GET', '/api/v1/support/assignees'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCreateTransferRequestReturnsForbidden(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 2, 'role' => 'support', 'is_support' => true]);

        $service = $this->createMock(SupportTicketService::class);
        $service->expects($this->once())
            ->method('createTransferRequest')
            ->willThrowException(new \DomainException('Only the current assignee can request a transfer'));

        $controller = $this->makeController($service, $auth);
        $response = $controller->createTransferRequest(
            makeRequest('POST', '/api/v1/support/tickets/12/transfer-requests', ['to_assignee' => 5]),
            new \Slim\Psr7\Response(),
            ['ticketId' => '12']
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testSubmitMyTicketFeedbackReturnsValidationError(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 9, 'role' => 'user']);

        $service = $this->createMock(SupportTicketService::class);
        $service->expects($this->once())
            ->method('submitTicketFeedback')
            ->with(['id' => 9, 'role' => 'user'], 12, ['rated_user_id' => 5, 'rating' => 9])
            ->willThrowException(new \InvalidArgumentException('rating must be between 1 and 5'));

        $controller = $this->makeController($service, $auth);
        $response = $controller->submitMyTicketFeedback(
            makeRequest('POST', '/api/v1/tickets/12/feedback', ['rated_user_id' => 5, 'rating' => 9]),
            new \Slim\Psr7\Response(),
            ['ticketId' => '12']
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testReviewTransferRequestReturnsValidationErrorForInvalidId(): void
    {
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'role' => 'admin', 'is_admin' => true]);

        $controller = $this->makeController(authService: $auth);
        $response = $controller->reviewTransferRequest(
            makeRequest('PATCH', '/api/v1/support/transfer-requests/bad', ['status' => 'approved']),
            new \Slim\Psr7\Response(),
            ['requestId' => 'bad']
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testRunSlaSweepReturnsForbiddenForInvalidKey(): void
    {
        $_ENV['SUPPORT_SLA_SWEEP_KEY'] = 'expected-secret';

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent');

        $controller = $this->makeController(
            supportRoutingEngineService: $this->createMock(SupportRoutingEngineService::class),
            auditLogService: $audit
        );

        $response = $controller->runSlaSweep(
            makeRequest('GET', '/api/v1/support/sla-sweep?key=bad'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRunSlaSweepReturnsSummaryForValidKey(): void
    {
        $_ENV['SUPPORT_SLA_SWEEP_KEY'] = 'expected-secret';
        $_GET['key'] = 'expected-secret';

        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent');

        $engine = $this->createMock(SupportRoutingEngineService::class);
        $engine->expects($this->once())
            ->method('runSlaSweep')
            ->willReturn(['processed' => 4, 'breached' => 2, 'rerouted' => 1]);

        $controller = $this->makeController(
            supportRoutingEngineService: $engine,
            auditLogService: $audit
        );

        $response = $controller->runSlaSweep(
            makeRequest('GET', '/api/v1/support/sla-sweep?key=expected-secret'),
            new \Slim\Psr7\Response()
        );

        $this->assertSame(200, $response->getStatusCode());
        unset($_GET['key']);
    }
}
