<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Tests\Support\DatabaseConnectionStub;
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
        $connection->setQueryGrammar(new MySqlGrammar($connection));
        $connection->unprepared('CREATE TABLE schools (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, location TEXT, is_active INTEGER,
            created_at TEXT, updated_at TEXT, deleted_at TEXT
        )');
        $connection->unprepared("INSERT INTO schools (name, is_active) VALUES ('Zeta School', 1), ('Alpha School', 1)");

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
        $controller = new SchoolController($audit, $errors, $connection);

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
            new DatabaseConnectionStub()
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
            new DatabaseConnectionStub()
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
            new DatabaseConnectionStub()
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
            new DatabaseConnectionStub()
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
            new DatabaseConnectionStub()
        );

        $method = new \ReflectionMethod($controller, 'shouldRetryWithoutSortOrder');
        $method->setAccessible(true);

        $exception = new QueryException(
            'mysql',
            'insert into schools',
            [],
            new \RuntimeException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'sort_order' in 'field list'")
        );

        $shouldRetry = $method->invoke($controller, ['sort_order' => 0], $exception);

        $this->assertTrue($shouldRetry);
    }

    public function testShouldNotRetryWithoutSortOrderForUnrelatedQueryError(): void
    {
        $controller = new SchoolController(
            $this->createMock(AuditLogService::class),
            $this->createMock(ErrorLogService::class),
            new DatabaseConnectionStub()
        );

        $method = new \ReflectionMethod($controller, 'shouldRetryWithoutSortOrder');
        $method->setAccessible(true);

        $exception = new QueryException(
            'mysql',
            'insert into schools',
            [],
            new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry')
        );

        $shouldRetry = $method->invoke($controller, ['sort_order' => 0], $exception);

        $this->assertFalse($shouldRetry);
    }

    public function testClassCreationAndListingUseTheSchoolModelConnection(): void
    {
        $manager = new Capsule();
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $manager->getConnection();
        $connection->statement('CREATE TABLE schools (id INTEGER PRIMARY KEY, deleted_at DATETIME)');
        $connection->statement('CREATE TABLE school_classes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            school_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            is_active INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            deleted_at DATETIME
        )');
        $connection->table('schools')->insert(['id' => 12]);
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->once())->method('log')->with($this->callback(
            static fn (array $entry): bool =>
                $entry['user_id'] === 7
                && $entry['action'] === 'school_class_created'
                && $entry['affected_id'] === 1
        ));
        $controller = new SchoolController($audit, $this->createMock(ErrorLogService::class), $connection);
        $resolver = School::getConnectionResolver();
        School::setConnectionResolver($manager->getDatabaseManager());
        try {
            $request = makeRequest('POST', '/api/v1/schools/12/classes', ['name' => 'Biology'])
                ->withAttribute('user_id', 7);
            $created = $controller->createClass($request, new \Slim\Psr7\Response(), ['id' => 12]);
            $duplicate = $controller->createClass(
                $request->withParsedBody(['name' => 'BIOLOGY']),
                new \Slim\Psr7\Response(),
                ['id' => 12]
            );
            $list = $controller->listClasses(
                makeRequest('GET', '/api/v1/schools/12/classes', null, ['search' => 'Bio', 'limit' => 1]),
                new \Slim\Psr7\Response(),
                ['id' => 12]
            );
        } finally {
            if ($resolver === null) {
                School::unsetConnectionResolver();
            } else {
                School::setConnectionResolver($resolver);
            }
        }

        $this->assertSame(201, $created->getStatusCode());
        $this->assertSame(200, $duplicate->getStatusCode());
        $response = json_decode((string) $list->getBody(), true)['data'];
        $this->assertSame(['Biology'], array_column($response['classes'], 'name'));
        $this->assertSame(1, $response['pagination']['total_items']);
    }
}


