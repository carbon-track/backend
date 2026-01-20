<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\ErrorLogService;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

class ErrorLogServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE error_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            error_type TEXT,
            error_message TEXT,
            error_file TEXT,
            error_line INTEGER,
            error_time TEXT,
            script_name TEXT,
            client_get TEXT,
            client_post TEXT,
            client_files TEXT,
            client_cookie TEXT,
            client_session TEXT,
            client_server TEXT,
            request_id TEXT
        )');
    }

    public function testLogExceptionUsesRequestAttributeRequestId(): void
    {
        $service = new ErrorLogService($this->pdo, new Logger('test'));
        $request = makeRequest('GET', '/test')
            ->withAttribute('request_id', '550e8400-e29b-41d4-a716-446655440001');

        $service->logException(new \RuntimeException('boom'), $request);

        $row = $this->pdo->query('SELECT request_id FROM error_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440001', $row['request_id']);
    }

    public function testLogExceptionFallsBackToExtraRequestId(): void
    {
        $service = new ErrorLogService($this->pdo, new Logger('test'));
        $request = makeRequest('POST', '/test');

        $service->logException(new \RuntimeException('boom'), $request, ['request_id' => 'extra-request-id']);

        $row = $this->pdo->query('SELECT request_id FROM error_logs')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('extra-request-id', $row['request_id']);
    }
}
