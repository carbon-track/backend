<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use CarbonTrack\Models\Message;
use PHPUnit\Framework\TestCase;

class MessageTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        MessagePriorityStub::$lastPayload = [];
        MessagePriorityStub::$priorityColumnPresent = false;
    }

    public function testCreateSystemMessagePersistsPriorityWhenColumnAvailable(): void
    {
        MessagePriorityStub::$priorityColumnPresent = true;
        MessagePriorityStub::createSystemMessage(5, 'Broadcast', 'Body', Message::TYPE_SYSTEM, Message::PRIORITY_URGENT);

        $this->assertArrayHasKey('priority', MessagePriorityStub::$lastPayload);
        $this->assertSame(Message::PRIORITY_URGENT, MessagePriorityStub::$lastPayload['priority']);
    }

    public function testCreateSystemMessageNormalizesInvalidPriority(): void
    {
        MessagePriorityStub::$priorityColumnPresent = true;
        MessagePriorityStub::createSystemMessage(7, 'Notice', 'Content', Message::TYPE_SYSTEM, 'super-high');

        $this->assertArrayHasKey('priority', MessagePriorityStub::$lastPayload);
        $this->assertSame(Message::PRIORITY_NORMAL, MessagePriorityStub::$lastPayload['priority']);
    }

    public function testCreateSystemMessageSkipsPriorityWhenColumnMissing(): void
    {
        MessagePriorityStub::$priorityColumnPresent = false;
        MessagePriorityStub::createSystemMessage(9, 'Info', 'Body');

        $this->assertArrayNotHasKey('priority', MessagePriorityStub::$lastPayload);
    }
}

class MessagePriorityStub extends Message
{
    public static array $lastPayload = [];
    public static bool $priorityColumnPresent = false;

    protected static function priorityColumnExistsStatic(): bool
    {
        return self::$priorityColumnPresent;
    }

    public static function create(array $attributes = [])
    {
        self::$lastPayload = $attributes;
        $message = new self();
        foreach ($attributes as $key => $value) {
            $message->setAttribute($key, $value);
        }
        return $message;
    }
}
