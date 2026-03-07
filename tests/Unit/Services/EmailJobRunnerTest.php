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
}