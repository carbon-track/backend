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

    public function testSendEmailLogsOnFailure(): void
    {
        $logger = $this->createMock(\Monolog\Logger::class);
        $logger->expects($this->once())->method('error');
        // 配置缺失将导致 PHPMailer 初始化或发送失败
        $svc = new EmailService([
            'host' => 'invalid',
            'port' => 465,
            'username' => 'u',
            'password' => 'p',
            'from_email' => 'no-reply@example.com',
            'from_name' => 'CarbonTrack',
            'debug' => false,
            'templates_path' => __DIR__ . '/'
        ], $logger);

        $ok = $svc->sendEmail('to@example.com','To','Subject','<b>Hi</b>');
        $this->assertIsBool($ok);
    }
}


