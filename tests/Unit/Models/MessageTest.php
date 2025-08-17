<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\Message;

class MessageTest extends TestCase
{
    public function testMessageConstantsExist(): void
    {
        $this->assertTrue(defined(Message::class . '::TYPE_NOTIFICATION'));
        $this->assertTrue(defined(Message::class . '::TYPE_SYSTEM'));
        $this->assertTrue(defined(Message::class . '::PRIORITY_NORMAL'));
        $this->assertTrue(defined(Message::class . '::PRIORITY_HIGH'));
    }
}


