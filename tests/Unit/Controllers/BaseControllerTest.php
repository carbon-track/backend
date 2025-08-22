<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\BaseController;

class BaseControllerTest extends TestCase
{
    public function testResponseWritesJsonAndStatus(): void
    {
        $controller = new class extends BaseController {
            public function out($data, $status = 201) {
                $resp = new \Slim\Psr7\Response();
                return $this->response($resp, $data, $status);
            }
        };
        $resp = $controller->out(['ok' => true], 201);
        $this->assertEquals(201, $resp->getStatusCode());
        $this->assertEquals('application/json', $resp->getHeaderLine('Content-Type'));
        $this->assertSame('{"ok":true}', (string)$resp->getBody());
    }
}
