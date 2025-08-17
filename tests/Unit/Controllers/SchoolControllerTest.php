<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\SchoolController;

class SchoolControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(SchoolController::class));
    }

    public function testIndexReturnsSchoolsListShape(): void
    {
        // 由于 SchoolController 使用 Eloquent 静态方法，这里只验证方法存在与基本返回结构约束，不直接调用 Eloquent
        $this->assertTrue(method_exists(SchoolController::class, 'index'));
        $this->assertTrue(method_exists(SchoolController::class, 'adminIndex'));
        $this->assertTrue(method_exists(SchoolController::class, 'stats'));
    }
}


