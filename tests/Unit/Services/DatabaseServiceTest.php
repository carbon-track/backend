<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Services\DatabaseService;
use Illuminate\Database\Capsule\Manager as Capsule;

class DatabaseServiceTest extends TestCase
{
    public function testBasicHelpersAndIsConnected(): void
    {
    $pdo = $this->createMock(\PDO::class);
    $stmt = $this->createMock(\PDOStatement::class);
    $pdo->method('query')->with('SELECT 1')->willReturn($stmt);

        $connection = $this->getMockBuilder(\Illuminate\Database\Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPdo','getTablePrefix','getDatabaseName','select','beginTransaction','commit','rollback'])
            ->getMock();

        $connection->method('getPdo')->willReturn($pdo);
        $connection->method('getTablePrefix')->willReturn('ct_');
        $connection->method('getDatabaseName')->willReturn('testdb');
        $connection->method('select')->willReturn([["ok" => 1]]);

        $capsule = $this->getMockBuilder(Capsule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection'])
            ->getMock();
        $capsule->method('getConnection')->willReturn($connection);

        $db = new DatabaseService($capsule);
        $this->assertSame($capsule, $db->getCapsule());
        $this->assertTrue($db->isConnected());
        $this->assertEquals('ct_', $db->getTablePrefix());
        $this->assertEquals('testdb', $db->getDatabaseName());
        $this->assertIsArray($db->raw('SELECT 1'));

        // transaction calls should not throw with mocked methods
        $db->beginTransaction();
        $db->commit();
        $db->rollback();
    }

    public function testIsConnectedFalseOnException(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('query')->willThrowException(new \Exception('fail'));

        $connection = $this->getMockBuilder(\Illuminate\Database\Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPdo'])
            ->getMock();
        $connection->method('getPdo')->willReturn($pdo);

        $capsule = $this->getMockBuilder(Capsule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection'])
            ->getMock();
        $capsule->method('getConnection')->willReturn($connection);

        $db = new DatabaseService($capsule);
        $this->assertFalse($db->isConnected());
    }
}
