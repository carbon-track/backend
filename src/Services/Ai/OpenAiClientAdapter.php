<?php

declare(strict_types=1);

namespace CarbonTrack\Services\Ai;

use OpenAI\Client;
use OpenAI\Exceptions\ErrorException;

/**
 * Adapter that wraps the official openai-php client and exposes a minimal interface.
 */
class OpenAiClientAdapter implements LlmClientInterface
{
    public function __construct(private Client $client)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createChatCompletion(array $payload): array
    {
        $response = $this->client->chat()->create($payload);

        return $response->toArray();
    }
}

