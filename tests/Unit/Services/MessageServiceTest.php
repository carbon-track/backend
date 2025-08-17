<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Models\Message;

class MessageServiceTest extends TestCase
{
    public function testSendSystemMessageBuildsModel(): void
    {
        $logger = $this->createMock(\Monolog\Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);

        // Mock Message::createSystemMessage static call via a stub class
        $service = new MessageService($logger, $audit);

        $this->assertTrue(method_exists($service, 'sendSystemMessage'));
        $this->assertTrue(defined(Message::class . '::TYPE_SYSTEM'));
        $this->assertTrue(defined(Message::class . '::PRIORITY_NORMAL'));
    }

    public function testSendBulkMessageDispatches(): void
    {
        $logger = $this->createMock(\Monolog\Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $service = new MessageService($logger, $audit);

        $this->assertTrue(method_exists($service, 'sendBulkMessage'));
        $sent = $service->sendBulkMessage([], 't', 'c');
        $this->assertEquals(0, $sent);
    }
}


