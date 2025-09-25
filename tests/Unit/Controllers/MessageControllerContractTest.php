<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\MessageController;

class MessageControllerContractTest extends TestCase
{
    public function testRequiredMethodsExist(): void
    {
        $this->assertTrue(method_exists(MessageController::class, 'getUserMessages'));
        $this->assertTrue(method_exists(MessageController::class, 'getMessageDetail'));
        $this->assertTrue(method_exists(MessageController::class, 'markAsRead'));
        $this->assertTrue(method_exists(MessageController::class, 'markAllAsRead'));
        $this->assertTrue(method_exists(MessageController::class, 'deleteMessage'));
        $this->assertTrue(method_exists(MessageController::class, 'getUnreadCount'));
        $this->assertTrue(method_exists(MessageController::class, 'deleteMessages'));
        $this->assertTrue(method_exists(MessageController::class, 'getMessageStats'));
        $this->assertTrue(method_exists(MessageController::class, 'getBroadcastHistory'));
        $this->assertTrue(method_exists(MessageController::class, 'searchBroadcastRecipients'));
    }
}

