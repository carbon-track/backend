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
    private mixed $previousDisableErrorWrites = null;
    private mixed $previousDisableErrorWritesServer = null;
    private mixed $previousAppEnv = null;
    private mixed $previousAppEnvServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDisableErrorWrites = $_ENV['DISABLE_ERROR_LOG_WRITES'] ?? null;
        $this->previousDisableErrorWritesServer = $_SERVER['DISABLE_ERROR_LOG_WRITES'] ?? null;
        $this->previousAppEnv = $_ENV['APP_ENV'] ?? null;
        $this->previousAppEnvServer = $_SERVER['APP_ENV'] ?? null;
        unset($_ENV['DISABLE_ERROR_LOG_WRITES']);
        unset($_SERVER['DISABLE_ERROR_LOG_WRITES']);
        $_ENV['APP_ENV'] = 'development';
        $_SERVER['APP_ENV'] = 'development';
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

    protected function tearDown(): void
    {
        if ($this->previousDisableErrorWrites === null) {
            unset($_ENV['DISABLE_ERROR_LOG_WRITES']);
        } else {
            $_ENV['DISABLE_ERROR_LOG_WRITES'] = $this->previousDisableErrorWrites;
        }
        if ($this->previousDisableErrorWritesServer === null) {
            unset($_SERVER['DISABLE_ERROR_LOG_WRITES']);
        } else {
            $_SERVER['DISABLE_ERROR_LOG_WRITES'] = $this->previousDisableErrorWritesServer;
        }
        if ($this->previousAppEnv === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->previousAppEnv;
        }
        if ($this->previousAppEnvServer === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $this->previousAppEnvServer;
        }

        parent::tearDown();
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

    public function testLogExceptionReturnsNullWhenWritesDisabled(): void
    {
        $_ENV['DISABLE_ERROR_LOG_WRITES'] = '1';
        $_SERVER['DISABLE_ERROR_LOG_WRITES'] = '1';

        $service = new ErrorLogService($this->pdo, new Logger('test'));
        $request = makeRequest('GET', '/test');

        $result = $service->logException(new \RuntimeException('boom'), $request);

        $this->assertNull($result);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testProductionEnvironmentIgnoresDisableFlag(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $_ENV['DISABLE_ERROR_LOG_WRITES'] = '1';
        $_SERVER['DISABLE_ERROR_LOG_WRITES'] = '1';

        $service = new ErrorLogService($this->pdo, new Logger('test'));
        $request = makeRequest('GET', '/test');

        $result = $service->logException(new \RuntimeException('boom'), $request);

        $this->assertSame(1, $result);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM error_logs')->fetchColumn();
        $this->assertSame(1, $count);
    }
}
