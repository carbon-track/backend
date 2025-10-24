<?php

declare(strict_types=1);

namespace CarbonTrack\Services\Ai;

/**
 * Minimal abstraction over LLM providers using an OpenAI-compatible schema.
 */
interface LlmClientInterface
{
    /**
     * Creates a chat completion using the provider's OpenAI-compatible API.
     *
     * Implementations should return the raw decoded array representation of the provider response.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createChatCompletion(array $payload): array;
}

