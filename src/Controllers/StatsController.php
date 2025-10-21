<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\StatisticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class StatsController
{
    public function __construct(
        private StatisticsService $statisticsService
    ) {}

    public function getPublicSummary(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $forceParam = $params['force'] ?? $params['refresh'] ?? null;
            $forceRefresh = false;
            if ($forceParam !== null) {
                $parsed = filter_var($forceParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($parsed !== null) {
                    $forceRefresh = $parsed;
                }
            }

            $data = $this->statisticsService->getPublicStats($forceRefresh);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            if (($_ENV['APP_ENV'] ?? '') === 'testing') {
                throw $e;
            }
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Unable to load statistics',
            ], 500);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($encoded === false ? '{}' : $encoded);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

