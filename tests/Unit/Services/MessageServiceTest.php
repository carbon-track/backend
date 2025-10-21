<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\User;
use CarbonTrack\Services\MessageService;
use CarbonTrack\Models\Message;
use CarbonTrack\Services\NotificationPreferenceService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;

class MessageServiceEmailStub extends \CarbonTrack\Services\EmailService
{
    /** @var array<int,array<string,mixed>> */
    public array $messageNotifications = [];
    /** @var array<int,array<string,mixed>> */
    public array $exchangeConfirmations = [];
    /** @var array<int,array<string,mixed>> */
    public array $exchangeStatusUpdates = [];
    public bool $allowSend = true;

    public function __construct()
    {
        $logger = new Logger('message-service-email-stub');
        $logger->pushHandler(new NullHandler());
        parent::__construct([
            'host' => 'smtp.test',
            'port' => 25,
            'from_address' => 'noreply@test',
            'from_name' => 'CarbonTrack',
            'force_simulation' => true,
        ], $logger, null);
    }

    public function sendMessageNotification(
        string $toEmail,
        string $toName,
        string $subject,
        string $messageBody,
        string $category,
        string $priority = Message::PRIORITY_NORMAL
    ): bool {
        $this->messageNotifications[] = [
            'toEmail' => $toEmail,
            'toName' => $toName,
            'subject' => $subject,
            'body' => $messageBody,
            'category' => $category,
            'priority' => $priority,
        ];

        return $this->allowSend;
    }

    public function sendExchangeConfirmation(
        string $toEmail,
        string $toName,
        string $productName,
        int $quantity,
        float $totalPoints
    ): bool {
        $this->exchangeConfirmations[] = [
            'toEmail' => $toEmail,
            'toName' => $toName,
            'productName' => $productName,
            'quantity' => $quantity,
            'totalPoints' => $totalPoints,
        ];

        return $this->allowSend;
    }

    public function sendExchangeStatusUpdate(
        string $toEmail,
        string $toName,
        string $productName,
        string $status,
        string $adminNotes = ''
    ): bool {
        $this->exchangeStatusUpdates[] = [
            'toEmail' => $toEmail,
            'toName' => $toName,
            'productName' => $productName,
            'status' => $status,
            'adminNotes' => $adminNotes,
        ];

        return $this->allowSend;
    }
}

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

    public function testMaybeSendLinkedEmailSendsNotificationWhenUserResolved(): void
    {
        $logger = $this->createMock(Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $emailStub = new MessageServiceEmailStub();
        $service = new MessageService($logger, $audit, $emailStub);

        $user = new User(['id' => 42, 'username' => 'tester', 'email' => 'tester@example.com']);
        $service->setUserResolver(static function (int $userId) use ($user): ?User {
            return $userId === 42 ? $user : null;
        });

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('maybeSendLinkedEmail');
        $method->setAccessible(true);
        $method->invoke($service, 42, 'Important update', 'Body content', Message::TYPE_SYSTEM, Message::PRIORITY_HIGH);

        $this->assertCount(1, $emailStub->messageNotifications);
        $notification = $emailStub->messageNotifications[0];
        $this->assertSame('tester@example.com', $notification['toEmail']);
        $this->assertSame('[HIGH] Important update', $notification['subject']);
        $this->assertSame(NotificationPreferenceService::CATEGORY_SYSTEM, $notification['category']);
    }

    public function testMaybeSendLinkedEmailSkipsWhenResolverReturnsNull(): void
    {
        $logger = $this->createMock(Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $emailStub = new MessageServiceEmailStub();
        $service = new MessageService($logger, $audit, $emailStub);

        $service->setUserResolver(static function (): ?User {
            return null;
        });

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('maybeSendLinkedEmail');
        $method->setAccessible(true);
        $method->invoke($service, 99, 'Notice', 'Body', Message::TYPE_SYSTEM, Message::PRIORITY_NORMAL);

        $this->assertCount(0, $emailStub->messageNotifications);
    }

    public function testSendExchangeConfirmationEmailToUserUsesEmailService(): void
    {
        $logger = $this->createMock(Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $emailStub = new MessageServiceEmailStub();
        $service = new MessageService($logger, $audit, $emailStub);

        $service->sendExchangeConfirmationEmailToUser(
            5,
            'Eco Bottle',
            2,
            120.0,
            'jeffery@example.com',
            'Jeffery'
        );

        $this->assertCount(1, $emailStub->exchangeConfirmations);
        $record = $emailStub->exchangeConfirmations[0];
        $this->assertSame('jeffery@example.com', $record['toEmail']);
        $this->assertSame('Jeffery', $record['toName']);
        $this->assertSame('Eco Bottle', $record['productName']);
        $this->assertSame(2, $record['quantity']);
        $this->assertSame(120.0, $record['totalPoints']);
    }

    public function testSendExchangeStatusUpdateEmailToUserUsesEmailService(): void
    {
        $logger = $this->createMock(Logger::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $emailStub = new MessageServiceEmailStub();
        $service = new MessageService($logger, $audit, $emailStub);

        $service->sendExchangeStatusUpdateEmailToUser(
            7,
            'Eco Bottle',
            'shipped',
            'TRACK-999',
            '发货完成',
            'notify@example.com',
            'Notify User'
        );

        $this->assertCount(1, $emailStub->exchangeStatusUpdates);
        $record = $emailStub->exchangeStatusUpdates[0];
        $this->assertSame('notify@example.com', $record['toEmail']);
        $this->assertSame('Notify User', $record['toName']);
        $this->assertSame('Eco Bottle', $record['productName']);
        $this->assertSame('shipped', $record['status']);
        $this->assertSame("Tracking number: TRACK-999\n发货完成", $record['adminNotes']);
    }
}


