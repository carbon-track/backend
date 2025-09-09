<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;

class BaseController
{
    protected function response(Response $response, array $data, int $status = 200): Response
    {
        // 自动附加 request_id 到 4xx/5xx 错误响应，便于前端提示用户反馈
        if ($status >= 400) {
            if (!isset($data['request_id'])) {
                $data['request_id'] = $_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['REQUEST_ID'] ?? null);
            }
        }
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    protected function validate(array $data, array $rules): void
    {
        // Minimal no-op validator for tests; extend as needed
    }
}


