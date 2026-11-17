<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Models\School;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\SchoolController;

class SchoolControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(SchoolController::class));
    }

    public function testListsAndCreatesSchoolsOnLegacySchemaWhileLoggingMissingColumn(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $connection = $capsule->getConnection();
        // Backticks enforce identifier resolution in SQLite for the missing sort_order column.
        $connection->setQueryGrammar(new MySqlGrammar());
        $pdo = $connection->getPdo();
        $pdo->exec('CREATE TABLE schools (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, location TEXT, is_active INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $pdo->exec("INSERT INTO schools (name, is_active) VALUES ('Zeta School', 1), ('Alpha School', 1)");

        $list = makeRequest('GET', '/api/v1/schools');
        $create = makeRequest('POST', '/api/v1/schools', ['name' => 'Legacy School']);
        $errors = $this->createMock(ErrorLogService::class);
        $errors->expects($this->exactly(2))->method('logException')->withConsecutive(
            [
                $this->isInstanceOf(QueryException::class),
                $list,
                ['context_message' => 'Retrying school ordering by name after query failure'],
            ],
            [
                $this->isInstanceOf(QueryException::class),
                $create,
                ['context_message' => 'Retrying school insert without sort_order'],
            ]
        );
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log');
        $controller = new SchoolController($audit, $errors, $pdo);

        $previousResolver = School::getConnectionResolver();
        School::setConnectionResolver($capsule->getDatabaseManager());
        try {
            $listed = $controller->index($list, new \Slim\Psr7\Response(), []);
            $created = $controller->createOrFetch($create, new \Slim\Psr7\Response(), []);
        } finally {
            if ($previousResolver === null) {
                School::unsetConnectionResolver();
            } else {
                School::setConnectionResolver($previousResolver);
            }
        }

        $this->assertSame(200, $listed->getStatusCode());
        $schools = json_decode((string)$listed->getBody(), true)['data']['schools'];
        $this->assertSame(['Alpha School', 'Zeta School'], array_column($schools, 'name'));
        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame('Legacy School', json_decode((string)$created->getBody(), true)['data']['school']['name']);
    }

    public function testSanitizeSchoolPayloadNormalizesEmptyStringNumericFields(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $method = new \ReflectionMethod($controller, 'sanitizeSchoolPayload');
        $method->setAccessible(true);

        $payload = $method->invoke($controller, [
            'name' => 'Test School',
            'is_active' => '',
            'sort_order' => '',
        ]);

        $this->assertSame('Test School', $payload['name']);
        $this->assertFalse($payload['is_active']);
        $this->assertSame(0, $payload['sort_order']);
    }

    public function testSanitizeSchoolPayloadRejectsInvalidStringValues(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $method = new \ReflectionMethod($controller, 'sanitizeSchoolPayload');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sort_order must be an integer');

        $method->invoke($controller, [
            'name' => 'Test School',
            'sort_order' => 'abc',
        ]);
    }

    public function testSanitizeSchoolPayloadRejectsNonObjectPayload(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $method = new \ReflectionMethod($controller, 'sanitizeSchoolPayload');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Request body must be a JSON object');

        $method->invoke($controller, null);
    }

    public function testStoreRejectsNonObjectRequestBody(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $response = $controller->store(
            makeRequest('POST', '/api/v1/admin/schools', null),
            new \Slim\Psr7\Response(),
            []
        );

        $this->assertSame(400, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertFalse($payload['success']);
        $this->assertSame('INVALID_REQUEST_BODY', $payload['code']);
    }

    public function testShouldRetryWithoutSortOrderForLegacySchemaError(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $method = new \ReflectionMethod($controller, 'shouldRetryWithoutSortOrder');
        $method->setAccessible(true);

        $exception = new QueryException(
            'insert into schools',
            [],
            new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'sort_order' in 'field list'")
        );

        $shouldRetry = $method->invoke($controller, ['sort_order' => 0], $exception);

        $this->assertTrue($shouldRetry);
    }

    public function testShouldNotRetryWithoutSortOrderForUnrelatedQueryError(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            $this->createMock(\PDO::class)
        );

        $method = new \ReflectionMethod($controller, 'shouldRetryWithoutSortOrder');
        $method->setAccessible(true);

        $exception = new QueryException(
            'insert into schools',
            [],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
        );

        $shouldRetry = $method->invoke($controller, ['sort_order' => 0], $exception);

        $this->assertFalse($shouldRetry);
    }
}


