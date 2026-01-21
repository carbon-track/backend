<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\UserGroupService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AdminUserGroupController
{
    public function __construct(
        private UserGroupService $groupService
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $groups = $this->groupService->getAllGroups();
        return $this->json($response, ['success' => true, 'data' => $groups]);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = $request->getParsedBody();
        $group = $this->groupService->createGroup($body);
        return $this->json($response, ['success' => true, 'data' => $group]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $body = $request->getParsedBody();
        $group = $this->groupService->updateGroup($id, $body);
        return $this->json($response, ['success' => true, 'data' => $group]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $this->groupService->deleteGroup($id);
        return $this->json($response, ['success' => true]);
    }

    public function meta(Request $request, Response $response): Response
    {
        $definitions = $this->groupService->getQuotaDefinitions();
        return $this->json($response, [
            'success' => true,
            'data' => [
                'quota_definitions' => $definitions,
            ],
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
