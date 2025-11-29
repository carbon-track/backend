<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\LeaderboardService;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LeaderboardController
{
    public function __construct(
        private LeaderboardService $leaderboardService,
        private Logger $logger
    ) {}

    public function triggerRefresh(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $providedKey = (string) ($query['key'] ?? $query['trigger_key'] ?? '');
        $expectedKey = trim((string) ($_ENV['LEADERBOARD_TRIGGER_KEY'] ?? ''));

        if ($expectedKey === '') {
            return $this->json($response, [
                'success' => false,
                'message' => 'Trigger key is not configured on the server',
            ], 503);
        }

        if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
            return $this->json($response, [
                'success' => false,
                'message' => 'Invalid trigger key',
            ], 403);
        }

        $snapshot = $this->leaderboardService->rebuildCache('manual-trigger');
        $meta = [
            'generated_at' => $snapshot['generated_at'] ?? null,
            'expires_at' => $snapshot['expires_at'] ?? null,
            'global_count' => isset($snapshot['global']) ? count($snapshot['global']) : 0,
            'regions_count' => isset($snapshot['regions']) ? count($snapshot['regions']) : 0,
            'schools_count' => isset($snapshot['schools']) ? count($snapshot['schools']) : 0,
        ];

        $this->logger->info('Leaderboard cache refreshed via trigger', $meta);

        return $this->json($response, [
            'success' => true,
            'message' => 'Leaderboard cache refreshed',
            'data' => $meta,
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload === false ? '{}' : $payload);
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
