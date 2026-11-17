<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Jobs\EmailJobRunner;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class EmailJobRunnerTest extends TestCase
{
    public function testRunLogsAuditWhenJobTypeMissing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $auditLogService = $this->createMock(AuditLogService::class);
        $errorLogService = $this->createMock(ErrorLogService::class);

        $auditLogService->expects($this->once())
            ->method('logSystemEvent')
            ->with('email_job_missing_type', 'email_job', $this->isType('array'))
            ->willReturn(true);

        $logger = new Logger('test-email-job');
        $logger->pushHandler(new NullHandler());

        EmailJobRunner::run($emailService, $logger, '', [], $auditLogService, $errorLogService);

        $this->assertTrue(true);
    }

    public function testDeliveryExceptionPersistsErrorAndAuditsFailure(): void
    {
        $type = 'activity_approved_notification';
        $failure = new \RuntimeException('Email delivery failed');
        $email = $this->createMock(EmailService::class);
        $email->expects($this->once())->method('sendActivityApprovedNotification')->willThrowException($failure);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('logSystemEvent')->with(
            'email_job_failed',
            'email_job',
            $this->callback(static function (array $entry) use ($type): bool {
                return $entry['status'] === 'failed' && $entry['endpoint'] === '/jobs/email/' . $type;
            })
        )->willReturn(true);
        $errors = $this->createMock(ErrorLogService::class);
        $errors->expects($this->once())->method('logException')->with(
            $failure,
            $this->callback(static function (\Psr\Http\Message\ServerRequestInterface $request) use ($type): bool {
                return $request->getUri()->getPath() === '/jobs/email/' . $type;
            }),
            ['job_type' => $type]
        );
        $logger = new Logger('test-email-job');
        $logger->pushHandler(new NullHandler());

        EmailJobRunner::run($email, $logger, $type, ['email' => 'member@example.test'], $audit, $errors);
    }
}
