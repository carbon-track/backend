<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\EmailService;

class EmailServiceTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(EmailService::class));
    }

    public function testSendEmailWithoutMailerReturnsFalseAndLogsError(): void
    {
        $config = [
            'debug' => false,
            'host' => 'smtp.example.com',
            'username' => 'user',
            'password' => 'pass',
            'port' => 465,
            'from_email' => 'noreply@example.com',
            'from_name' => 'No Reply',
            'templates_path' => __DIR__ . '/'
        ];
        $logger = $this->createMock(\Monolog\Logger::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $service = new EmailService($config, $logger);
        $ok = $service->sendEmail('to@example.com', 'To', 'Subj', '<b>body</b>', 'body');
        $this->assertFalse($ok);
    }

    public function testTemplateWrappersReturnBoolAndReadTemplates(): void
    {
        // Create temp templates
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ct_email_tpl_' . uniqid();
        mkdir($dir);
        $make = function(string $name, string $contentHtml, string $contentTxt) use ($dir) {
            file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.html', $contentHtml);
            file_put_contents($dir . DIRECTORY_SEPARATOR . $name . '.txt', $contentTxt);
        };
        $make('verification_code', 'Code: {{code}}', 'Code: {{code}}');
        $make('password_reset', 'Link: {{link}}', 'Link: {{link}}');
        $make('activity_approved', 'Activity: {{activity_name}} {{points_earned}}', 'Activity: {{activity_name}} {{points_earned}}');
        $make('activity_rejected', 'Activity: {{activity_name}} {{reason}}', 'Activity: {{activity_name}} {{reason}}');
        $make('exchange_confirmation', 'Product: {{product_name}} x{{quantity}} = {{total_points}}', 'Product: {{product_name}} x{{quantity}} = {{total_points}}');
        $make('exchange_status_update', 'Product: {{product_name}} {{status}} {{admin_notes}}', 'Product: {{product_name}} {{status}} {{admin_notes}}');

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
            ]
        ];
        $logger = $this->createMock(\Monolog\Logger::class);
        $logger->expects($this->atLeastOnce())->method('error');
        $svc = new EmailService($config, $logger);

        $this->assertIsBool($svc->sendVerificationCode('to@example.com','User','123456'));
        $this->assertIsBool($svc->sendPasswordResetLink('to@example.com','User','https://reset'));
        $this->assertIsBool($svc->sendActivityApprovedNotification('to@example.com','User','Act', 10));
        $this->assertIsBool($svc->sendActivityRejectedNotification('to@example.com','User','Act','Bad'));
        $this->assertIsBool($svc->sendExchangeConfirmation('to@example.com','User','Prod', 2, 100));
        $this->assertIsBool($svc->sendExchangeStatusUpdate('to@example.com','User','Prod','shipped','soon'));

        // Cleanup
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) { @unlink($f); }
        @rmdir($dir);
    }
}


