<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\TurnstileService;

class TurnstileServiceTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(TurnstileService::class));
    }

    public function testVerifyWithEmptyToken(): void
    {
        $logger = $this->createMock(\Monolog\Logger::class);
        $svc = new TurnstileService('secret', $logger);
        $res = $svc->verify('');
        $this->assertFalse($res['success']);
        $this->assertEquals('missing-input-response', $res['error']);
    }
}


