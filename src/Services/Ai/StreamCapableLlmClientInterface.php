<?php

declare(strict_types=1);

namespace CarbonTrack\Services\Ai;

interface StreamCapableLlmClientInterface extends LlmClientInterface
{
    /**
     * Streams a chat completion and returns the aggregated provider response.
     *
     * @param array<string,mixed> $payload
     * @param callable(array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamChatCompletion(array $payload, callable $onEvent): array;
}
