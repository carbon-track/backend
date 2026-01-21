<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use CarbonTrack\Controllers\AdminUserGroupController;
use CarbonTrack\Services\UserGroupService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class AdminUserGroupControllerTest extends TestCase
{
    public function testMetaReturnsQuotaDefinitions(): void
    {
        $service = $this->createMock(UserGroupService::class);
        $service->method('getQuotaDefinitions')->willReturn(['llm.daily_limit', 'llm.rate_limit']);

        $controller = new AdminUserGroupController($service);
        $request = makeRequest('GET', '/admin/users/groups/meta');
        $response = new Response();

        $result = $controller->meta($request, $response);

        $this->assertSame(200, $result->getStatusCode());
        $payload = json_decode((string) $result->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertSame(['llm.daily_limit', 'llm.rate_limit'], $payload['data']['quota_definitions']);
    }
}
