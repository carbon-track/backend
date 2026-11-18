<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Models;

use CarbonTrack\Tests\Support\DatabaseQueryResponse;
use CarbonTrack\Tests\Support\DatabaseConnectionStub;
use PHPUnit\Framework\TestCase;
use CarbonTrack\Models\CarbonActivity;

class CarbonActivityTest extends TestCase
{
    public function testFindByIdUsesPdo(): void
    {
        $connection = $this->getMockBuilder(DatabaseConnectionStub::class)->onlyMethods(['responseFor'])->getMock();
        $stmt = $this->createMock(DatabaseQueryResponse::class);
        $stmt->shape = 'row';
        $stmt->method('bindings')->willReturn(true);
        $stmt->method('row')->willReturn(['id'=>'a1','unit'=>'km']);
        $connection->method('responseFor')->willReturn($stmt);
        $row = CarbonActivity::findById($connection, 'a1');
        $this->assertEquals('a1', $row['id']);
    }
}


