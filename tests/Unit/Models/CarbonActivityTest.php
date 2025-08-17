<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\CarbonActivity;

class CarbonActivityTest extends TestCase
{
    public function testFindByIdUsesPdo(): void
    {
        $pdo = $this->createMock(\PDO::class);
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(['id'=>'a1','unit'=>'km']);
        $pdo->method('prepare')->willReturn($stmt);
        $row = CarbonActivity::findById($pdo, 'a1');
        $this->assertEquals('a1', $row['id']);
    }
}


