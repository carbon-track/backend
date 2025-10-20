<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\EmailService;
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

        $service = new EmailService($config, $logger);

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
        ];

        $handler = new TestHandler();
        $logger = new Logger('email-service-test');
        $logger->pushHandler($handler);

        $svc = new EmailService($config, $logger);

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
}
