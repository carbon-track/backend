<?php

declare(strict_types=1);

namespace CarbonTrack\Services\Ai;

use GuzzleHttp\Psr7\Request;
use OpenAI\Client;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapter that wraps the official openai-php client and exposes a minimal interface.
 */
class OpenAiClientAdapter implements LlmClientInterface
{
    public function __construct(
        private Client $client,
        private ?HttpClientInterface $httpClient = null,
        private string $baseUri = 'https://api.openai.com/v1',
        private ?string $apiKey = null,
        private ?string $organization = null
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createChatCompletion(array $payload): array
    {
        try {
            $response = $this->client->chat()->create($payload);

            return $response->toArray();
        } catch (\TypeError $exception) {
            if (!$this->shouldFallbackToRawHttp($exception)) {
                throw $exception;
            }

            return $this->createChatCompletionViaHttp($payload);
        }
    }

    /**
     * Some OpenAI-compatible gateways omit x-request-id, which breaks openai-php response hydration.
     */
    private function shouldFallbackToRawHttp(\TypeError $exception): bool
    {
        return str_contains($exception->getMessage(), 'MetaInformation::__construct()')
            || str_contains($exception->getMessage(), 'MetaInformation::from()');
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function createChatCompletionViaHttp(array $payload): array
    {
        if ($this->httpClient === null || !is_string($this->apiKey) || trim($this->apiKey) === '') {
            throw new \RuntimeException('LLM raw HTTP fallback is not configured.');
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $request = new Request(
            'POST',
            $this->buildChatCompletionUri(),
            $this->buildHeaders(),
            $body
        );

        $response = $this->httpClient->sendRequest($request);
        $contents = (string) $response->getBody();
        $decoded = json_decode($contents, true);

        if ($response->getStatusCode() >= 400) {
            $message = $this->extractErrorMessage($decoded, $response);
            throw new \RuntimeException($message);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('LLM returned an invalid JSON response.');
        }

        return $this->normalizeRawResponseMetadata($decoded, $response);
    }

    private function buildChatCompletionUri(): string
    {
        $baseUri = trim($this->baseUri);
        if ($baseUri === '') {
            $baseUri = 'https://api.openai.com/v1';
        }

        if (!preg_match('#^https?://#i', $baseUri)) {
            $baseUri = 'https://' . ltrim($baseUri, '/');
        }

        return rtrim($baseUri, '/') . '/chat/completions';
    }

    /**
     * @return array<string,string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . trim((string) $this->apiKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $organization = trim((string) $this->organization);
        if ($organization !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }

        return $headers;
    }

    /**
     * @param mixed $decoded
     */
    private function extractErrorMessage(mixed $decoded, ResponseInterface $response): string
    {
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_string($error) && trim($error) !== '') {
                return trim($error);
            }

            if (is_array($error)) {
                $message = $error['message'] ?? null;
                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }
        }

        return sprintf('LLM request failed with status %d.', $response->getStatusCode());
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function normalizeRawResponseMetadata(array $decoded, ResponseInterface $response): array
    {
        $requestId = trim($response->getHeaderLine('x-request-id'));
        if ($requestId === '') {
            $requestId = isset($decoded['metadata']['request_id']) && is_string($decoded['metadata']['request_id'])
                ? trim($decoded['metadata']['request_id'])
                : '';
        }
        if ($requestId === '' && isset($decoded['id']) && is_string($decoded['id'])) {
            $requestId = trim($decoded['id']);
        }
        if ($requestId === '') {
            $requestId = $this->generateRequestId();
        }

        if (!isset($decoded['metadata']) || !is_array($decoded['metadata'])) {
            $decoded['metadata'] = [];
        }

        $decoded['metadata']['request_id'] = $requestId;
        if (!isset($decoded['id']) || !is_string($decoded['id']) || trim($decoded['id']) === '') {
            $decoded['id'] = $requestId;
        }

        return $decoded;
    }

    private function generateRequestId(): string
    {
        try {
            return 'llm-' . bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return 'llm-' . str_replace('.', '', uniqid('', true));
        }
    }
}

