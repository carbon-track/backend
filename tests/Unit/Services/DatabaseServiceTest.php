<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\DatabaseService;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use PHPUnit\Framework\TestCase;

final class DatabaseServiceTest extends TestCase
{
    public function testHealthProbeUsesApplicationConnection(): void
    {
        $database = $this->database();
        $this->assertTrue($database->isConnected());

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('selectOne')
            ->with('SELECT 1')->willThrowException(new \RuntimeException('Database unavailable'));
        $manager = $this->createMock(Manager::class);
        $manager->method('getConnection')->willReturn($connection);
        $this->assertFalse((new DatabaseService($manager))->isConnected());
    }

    public function testTransactionRollsBackBoundWrite(): void
    {
        $database = $this->database();
        $connection = $database->getConnection();
        $connection->statement('CREATE TABLE sample (id INTEGER PRIMARY KEY, label TEXT)');
        $database->beginTransaction();
        $id = DatabaseService::insertGetId($connection, 'INSERT INTO sample (label) VALUES (?)', ['bound']);
        $this->assertSame(1, $id);
        $this->assertSame(1, $connection->transactionLevel());
        $database->rollback();
        $this->assertSame(0, $connection->table('sample')->count());
        $this->assertSame(0, $connection->transactionLevel());

        $database->beginTransaction();
        $connection->table('sample')->insert(['label' => 'committed']);
        $database->commit();
        $this->assertSame('committed', $connection->table('sample')->value('label'));
    }

    private function database(): DatabaseService
    {
        $manager = new Manager();
        $manager->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);

        return new DatabaseService($manager);
    }
}
