<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\LogSearchController;
use CarbonTrack\Services\AuthService;
use PDO;
use PHPUnit\Framework\TestCase;

class LogSearchControllerTest extends TestCase
{
    public function testNormalizeRequestIdLowercasesUuid(): void
    {
        $controller = $this->makeController();
        $normalized = $this->invokeNormalizeRequestId($controller, '550E8400-E29B-41D4-A716-446655440001');

        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $normalized);
    }

    public function testNormalizeRequestIdPreservesNonUuid(): void
    {
        $controller = $this->makeController();
        $normalized = $this->invokeNormalizeRequestId($controller, 'Req-ABC-123');

        $this->assertSame('Req-ABC-123', $normalized);
    }

    private function makeController(): LogSearchController
    {
        $pdo = new PDO('sqlite::memory:');
        $authService = $this->createMock(AuthService::class);
        return new LogSearchController($pdo, $authService);
    }

    private function invokeNormalizeRequestId(LogSearchController $controller, string $value): ?string
    {
        $ref = new \ReflectionClass(LogSearchController::class);
        $method = $ref->getMethod('normalizeRequestId');
        $method->setAccessible(true);

        /** @var ?string $normalized */
        $normalized = $method->invoke($controller, $value);
        return $normalized;
    }
}
