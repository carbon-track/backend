<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\EmailService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\NotificationPreferenceService;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class EmailServiceTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(EmailService::class));
    }

    public function testSendEmailWithoutMailerSimulatesDelivery(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'templates_path' => __DIR__ . '/',
            'force_simulation' => true,
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-test');
        $logger->pushHandler($handler);

        $service = new EmailService($config, $logger, null);

        $result = $service->sendEmail('to@example.com', 'To', 'Subj', '<b>body</b>', 'body');

        $this->assertTrue($result);
        $this->assertTrue(
            $handler->hasInfoThatContains('Simulated email send'),
            'Expected simulated email log when EmailService runs in simulation mode.'
        );

        $simulationRecords = array_values(array_filter(
            $handler->getRecords(),
            static fn(array $record): bool => $record['message'] === 'Simulated email send'
        ));
        $this->assertNotEmpty($simulationRecords, 'Expected simulation log record to be captured.');
        $record = $simulationRecords[0];
        $this->assertSame('force_simulation', $record['context']['reason'] ?? null);
    }

    public function testSendMessageNotificationRespectsPreferences(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'templates_path' => __DIR__ . '/',
            'force_simulation' => true,
            'app_name' => 'CarbonTrack QA',
            'frontend_url' => 'https://app.example.com',
        ];

        $handlerAllow = new TestHandler();
        $loggerAllow = new Logger('email-service-allow');
        $loggerAllow->pushHandler($handlerAllow);

        $preferenceAllow = new class(true, $loggerAllow) extends NotificationPreferenceService {
            private bool $result;

            public function __construct(bool $result, Logger $logger)
            {
                parent::__construct($logger);
                $this->result = $result;
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                return $this->result;
            }
        };

        $serviceAllow = new EmailService($config, $loggerAllow, $preferenceAllow);
        $this->assertTrue($serviceAllow->sendMessageNotification(
            'to@example.com',
            'User',
            'A subject',
            "Line one\n\nLine two",
            'system',
            'high'
        ));
        $this->assertTrue(
            $handlerAllow->hasInfoThatContains('Simulated email send'),
            'Expected simulated send when preferences allow email delivery.'
        );

        $handlerBlock = new TestHandler();
        $loggerBlock = new Logger('email-service-block');
        $loggerBlock->pushHandler($handlerBlock);

        $preferenceBlock = new class(false, $loggerBlock) extends NotificationPreferenceService {
            private bool $result;

            public function __construct(bool $result, Logger $logger)
            {
                parent::__construct($logger);
                $this->result = $result;
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                return $this->result;
            }
        };

        $serviceBlock = new EmailService($config, $loggerBlock, $preferenceBlock);
        $this->assertFalse($serviceBlock->sendMessageNotification(
            'to@example.com',
            'User',
            'Blocked subject',
            'Any content',
            'system',
            'normal'
        ));
        $this->assertFalse(
            $handlerBlock->hasInfoThatContains('Simulated email send'),
            'Expected no send when preferences block email delivery.'
        );
    }

    public function testSendSupportTicketNotificationRendersStructuredHtml(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'force_simulation' => true,
            'app_name' => 'CarbonTrack QA',
            'support_email' => 'help@example.com',
            'frontend_url' => 'https://app.example.com',
        ];

        $logger = new Logger('email-service-support-ticket');
        $logger->pushHandler(new TestHandler());
        $service = new class($config, $logger, null) extends EmailService {
            public ?array $capturedEmail = null;

            public function sendEmail(string $toEmail, string $toName, string $subject, string $bodyHtml, string $bodyText = ''): bool
            {
                $this->capturedEmail = [
                    'to' => $toEmail,
                    'name' => $toName,
                    'subject' => $subject,
                    'bodyHtml' => $bodyHtml,
                    'bodyText' => $bodyText,
                ];

                return true;
            }
        };

        $result = $service->sendSupportTicketNotification(
            'owner@example.com',
            'Owner',
            'Support ticket #42 updated',
            [
                'eyebrow' => 'Workflow update',
                'intro' => 'We updated the workflow details for your support ticket.',
                'summary' => 'Review the latest status below so you know what changed on our side.',
                'ticket' => [
                    'id' => 42,
                    'subject' => 'Billing mismatch on April export',
                ],
                'details' => [
                    ['label' => 'Status', 'value' => 'In progress'],
                    ['label' => 'Priority', 'value' => 'High'],
                ],
                'changes' => [
                    ['label' => 'Status', 'from' => 'Open', 'to' => 'In progress'],
                    ['label' => 'Priority', 'from' => 'Normal', 'to' => 'High'],
                ],
                'message' => [
                    'label' => 'Latest update',
                    'body' => "We reproduced the export issue.\nA fix is being prepared.",
                ],
                'button_label' => 'Review ticket',
                'button_path' => 'tickets/42',
                'closing' => 'Open CarbonTrack to review the full thread.',
            ],
            NotificationPreferenceService::CATEGORY_SUPPORT,
            'high'
        );

        $this->assertTrue($result);
        $this->assertNotNull($service->capturedEmail);
        $this->assertStringContainsString('Billing mismatch on April export', $service->capturedEmail['bodyHtml']);
        $this->assertStringContainsString('What changed', $service->capturedEmail['bodyHtml']);
        $this->assertStringContainsString('Latest update', $service->capturedEmail['bodyHtml']);
        $this->assertStringContainsString('/tickets/42', $service->capturedEmail['bodyHtml']);
        $this->assertStringContainsString('Status:', $service->capturedEmail['bodyText']);
        $this->assertStringContainsString('In progress', $service->capturedEmail['bodyText']);
        $this->assertStringContainsString('Review ticket:', $service->capturedEmail['bodyText']);
        $this->assertStringContainsString('/tickets/42', $service->capturedEmail['bodyText']);
    }

    public function testSendMessageNotificationToManyUsesBroadcastAndPreferences(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'force_simulation' => true,
            'app_name' => 'CarbonTrack QA',
            'frontend_url' => 'https://app.example.com',
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-bulk');
        $logger->pushHandler($handler);

        $preference = new class(['admin1@example.com'], $logger) extends NotificationPreferenceService {
            private array $allowed;

            public function __construct(array $allowed, Logger $logger)
            {
                parent::__construct($logger);
                $this->allowed = array_map('strtolower', $allowed);
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                return in_array(strtolower($email), $this->allowed, true);
            }
        };

        $service = new EmailService($config, $logger, $preference);

        $result = $service->sendMessageNotificationToMany(
            [
                ['email' => 'admin1@example.com', 'name' => 'Admin A'],
                ['email' => 'blocked@example.com', 'name' => 'Admin B'],
            ],
            'Pending review alert',
            "Line 1\n\nLine 2",
            'system',
            'high'
        );

        $this->assertTrue($result, 'Expected broadcast send when at least one recipient allows email.');
        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn(array $record): bool => $record['message'] === 'Simulated broadcast email send'
        ));
        $this->assertNotEmpty($records, 'Simulated broadcast log expected.');
        $context = $records[0]['context'] ?? [];
        $this->assertSame(1, $context['recipient_count'] ?? null, 'Only allowed recipients should be counted.');
        $this->assertSame('system', $context['category'] ?? null);
    }

    public function testSendMessageNotificationToManySkipsWhenNoEligibleRecipients(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'force_simulation' => true,
            'frontend_url' => 'https://app.example.com',
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-bulk-skip');
        $logger->pushHandler($handler);

        $preference = new class($logger) extends NotificationPreferenceService {
            public function __construct(Logger $logger)
            {
                parent::__construct($logger);
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                return false;
            }
        };

        $service = new EmailService($config, $logger, $preference);

        $result = $service->sendMessageNotificationToMany(
            [
                ['email' => 'blocked@example.com', 'name' => 'Admin'],
            ],
            'Ignored',
            'Body',
            'system',
            'normal'
        );

        $this->assertFalse($result, 'Expected failure when no recipients are eligible.');
        $this->assertSame(
            'No deliverable email recipients provided',
            $service->getLastError()
        );
        $this->assertFalse(
            $handler->hasInfoThatContains('Simulated broadcast email send'),
            'No broadcast send should occur when every recipient is filtered out.'
        );
    }

    public function testSendAnnouncementBroadcastRespectsTemplatesAndPreferences(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'CarbonTrack',
            'force_simulation' => true,
            'frontend_url' => 'https://app.example.com',
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-announcement');
        $logger->pushHandler($handler);

        $preference = new class($logger) extends NotificationPreferenceService {
            public function __construct(Logger $logger)
            {
                parent::__construct($logger);
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                return strtolower($email) === 'allowed@example.com';
            }
        };

        $service = new EmailService($config, $logger, $preference);

        $result = $service->sendAnnouncementBroadcast(
            [
                ['email' => 'allowed@example.com', 'name' => 'Allowed User'],
                ['email' => 'blocked@example.com', 'name' => 'Blocked User'],
            ],
            'Planned maintenance',
            "Systems will undergo maintenance tonight.\nPlease review the announcement in the app.",
            'high'
        );

        $this->assertTrue($result, 'Expected queued send when at least one recipient allows announcement emails.');
        $records = array_values(array_filter(
            $handler->getRecords(),
            static fn(array $record): bool => $record['message'] === 'Simulated broadcast email send'
        ));
        $this->assertNotEmpty($records, 'Expected simulation log for announcement broadcast.');
        $context = $records[0]['context'] ?? [];
        $this->assertSame(1, $context['recipient_count'] ?? null, 'Only allowed recipients should be included.');
        $this->assertSame(
            NotificationPreferenceService::CATEGORY_ANNOUNCEMENT,
            $context['category'] ?? null,
            'Announcement emails should be tagged with the announcement category.'
        );
    }

    public function testSendAnnouncementBroadcastDoesNotExposeInternalMetadataInVisibleCopy(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'CarbonTrack',
            'force_simulation' => true,
            'frontend_url' => 'https://app.example.com',
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-announcement-visible-copy');
        $logger->pushHandler($handler);

        $service = new class($config, $logger, null) extends EmailService {
            public ?array $capturedBroadcast = null;

            public function sendBroadcastEmail(array $recipients, string $subject, string $bodyHtml, string $bodyText = '', ?string $category = null): bool
            {
                $this->capturedBroadcast = [
                    'recipients' => $recipients,
                    'subject' => $subject,
                    'bodyHtml' => $bodyHtml,
                    'bodyText' => $bodyText,
                    'category' => $category,
                ];

                return true;
            }
        };

        $result = $service->sendAnnouncementBroadcast(
            [['email' => 'allowed@example.com', 'name' => 'Allowed User']],
            'Planned maintenance',
            '<p>Systems will undergo maintenance tonight.</p>',
            'high',
            'html',
            'announcement_html_v1',
            1,
            'admin_broadcast'
        );

        $this->assertTrue($result);
        $this->assertNotNull($service->capturedBroadcast);
        $this->assertStringContainsString('has published a new announcement', $service->capturedBroadcast['bodyHtml']);
        $this->assertStringNotContainsString('admin_broadcast', $service->capturedBroadcast['bodyHtml']);
        $this->assertStringNotContainsString('render v1', $service->capturedBroadcast['bodyHtml']);
        $this->assertStringNotContainsString('admin_broadcast', $service->capturedBroadcast['bodyText']);
        $this->assertStringNotContainsString('render v1', $service->capturedBroadcast['bodyText']);
    }

    public function testTemplateWrappersReturnSuccess(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_email_tpl_' . uniqid();
        mkdir($dir);
        $make = function (string $name, string $contentHtml) use ($dir): void {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.html', $contentHtml);
        };
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'layout.html',
            '<html><head><title>{{email_title}}</title></head><body><h1>{{email_title}}</h1>{{content}}{{buttons}}<footer>{{app_name}}</footer></body></html>'
        );
        $make('verification_code', 'Code: {{code}}');
        $make('password_reset', 'Link: {{link}}');
        $make('activity_approved', 'Activity: {{activity_name}} {{points_earned}}');
        $make('activity_rejected', 'Activity: {{activity_name}} {{reason}}');
        $make('exchange_confirmation', 'Product: {{product_name}} x{{quantity}} = {{total_points}}');
        $make('exchange_status_update', 'Product: {{product_name}} {{status}} {{admin_notes}}');

        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'templates_path' => $dir . DIRECTORY_SEPARATOR,
            'subjects' => [
                'verification_code' => 'VC',
                'password_reset' => 'PR',
                'activity_approved' => 'AA',
                'activity_rejected' => 'AR',
                'exchange_confirmation' => 'EC',
                'exchange_status_update' => 'ESU',
            ],
            'force_simulation' => true,
            'app_name' => 'CarbonTrack QA',
            'support_email' => 'help@example.com',
            'frontend_url' => 'https://app.example.com',
            'reset_link_base' => 'https://app.example.com',
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-test');
        $logger->pushHandler($handler);

        $svc = new EmailService($config, $logger, null);

        $this->assertTrue($svc->sendVerificationCode('to@example.com', 'User', '123456'));
        $this->assertTrue($svc->sendPasswordResetLink('to@example.com', 'User', 'https://reset'));
        $this->assertTrue($svc->sendActivityApprovedNotification('to@example.com', 'User', 'Act', 10));
        $this->assertTrue($svc->sendActivityRejectedNotification('to@example.com', 'User', 'Act', 'Bad'));
        $this->assertTrue($svc->sendExchangeConfirmation('to@example.com', 'User', 'Prod', 2, 100));
        $this->assertTrue($svc->sendExchangeStatusUpdate('to@example.com', 'User', 'Prod', 'shipped', 'soon'));

        $this->assertTrue($handler->hasInfoThatContains('Simulated email send'), 'Expected info logs for simulated email sends.');

        foreach (array_filter(
            $handler->getRecords(),
            static fn(array $record): bool => $record['message'] === 'Simulated email send'
        ) as $record) {
            $this->assertSame('force_simulation', $record['context']['reason'] ?? null);
        }

        $this->assertNull($svc->getLastError(), 'Expected EmailService not to record any error during helper sends.');

        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }

    public function testSendEmailSimulationWritesAuditLog(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'force_simulation' => true,
        ];

        $logger = new Logger('email-service-audit');
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload): bool {
                return ($payload['action'] ?? null) === 'email_simulated'
                    && ($payload['operation_category'] ?? null) === 'notification'
                    && ($payload['data']['to'] ?? null) === 'audit@example.com';
            }))
            ->willReturn(true);

        $service = new EmailService($config, $logger, null, $audit, null);

        $this->assertTrue($service->sendEmail('audit@example.com', 'Audit', 'Audit Subject', '<p>Body</p>', 'Body'));
    }

    public function testPreferenceLookupFailureWritesAuditAndErrorLog(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'force_simulation' => true,
            'app_name' => 'CarbonTrack QA',
            'frontend_url' => 'https://app.example.com',
        ];

        $logger = new Logger('email-service-pref-failure');
        $preference = new class($logger) extends NotificationPreferenceService {
            public function __construct(Logger $logger)
            {
                parent::__construct($logger);
            }

            public function shouldSendEmailByEmail(string $email, string $category): bool
            {
                throw new \RuntimeException('preference lookup failed');
            }
        };

        $actions = [];
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function (array $payload) use (&$actions): bool {
                $actions[] = $payload['action'] ?? null;
                return true;
            });

        $error = $this->createMock(ErrorLogService::class);
        $error->expects($this->once())
            ->method('logException')
            ->with(
                $this->isInstanceOf(\Throwable::class),
                $this->anything(),
                $this->arrayHasKey('context_message')
            )
            ->willReturn(1);

        $service = new EmailService($config, $logger, $preference, $audit, $error);

        $this->assertTrue($service->sendMessageNotification(
            'fallback@example.com',
            'Fallback',
            'Subject',
            'Body',
            'system'
        ));

        $this->assertContains('email_preference_lookup_failed', $actions);
        $this->assertContains('email_simulated', $actions);
    }
}
