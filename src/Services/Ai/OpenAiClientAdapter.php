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
class OpenAiClientAdapter implements StreamCapableLlmClientInterface
{
    private const STREAM_READ_TIMEOUT_SECONDS = 15;

    public function __construct(
        private Client $client,
        private ?HttpClientInterface $httpClient = null,
        private string $baseUri = 'https://api.openai.com/v1',
        private ?string $apiKey = null,
        private ?string $organization = null,
        private ?HttpClientInterface $streamHttpClient = null
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
     * Streams an OpenAI-compatible chat completion and returns the aggregated response.
     *
     * @param array<string,mixed> $payload
     * @param callable(array<string,mixed>):void $onEvent
     * @return array<string,mixed>
     */
    public function streamChatCompletion(array $payload, callable $onEvent): array
    {
        if (
            ($this->streamHttpClient ?? $this->httpClient) === null
            || !method_exists($this->streamHttpClient ?? $this->httpClient, 'request')
            || !is_string($this->apiKey)
            || trim($this->apiKey) === ''
        ) {
            $response = $this->createChatCompletion($payload);
            $content = (string) ($response['choices'][0]['message']['content'] ?? '');
            if ($content !== '') {
                $onEvent(['type' => 'content.delta', 'content' => $content]);
            }
            return $response;
        }

        $streamPayload = $payload;
        $streamPayload['stream'] = true;
        $streamPayload['stream_options'] = array_merge(
            is_array($streamPayload['stream_options'] ?? null) ? $streamPayload['stream_options'] : [],
            ['include_usage' => true]
        );

        /** @var object{request:callable} $client */
        $client = $this->streamHttpClient ?? $this->httpClient;
        $response = $client->request('POST', $this->buildChatCompletionUri(), [
            'headers' => $this->buildHeaders('text/event-stream'),
            'json' => $streamPayload,
            'stream' => true,
            'read_timeout' => self::STREAM_READ_TIMEOUT_SECONDS,
        ]);

        if ($response->getStatusCode() >= 400) {
            $contents = (string) $response->getBody();
            $decoded = json_decode($contents, true);
            throw new \RuntimeException($this->extractErrorMessage($decoded, $response));
        }

        $requestId = $this->extractStreamRequestId($response);
        $aggregate = [
            'id' => $requestId,
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $payload['model'] ?? null,
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => '',
                ],
                'finish_reason' => null,
            ]],
            'usage' => null,
            'metadata' => [
                'request_id' => $requestId,
                'streamed' => true,
            ],
        ];

        $toolCalls = [];
        $buffer = '';
        $body = $response->getBody();
        $lastChunkAt = microtime(true);
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                if ((microtime(true) - $lastChunkAt) > self::STREAM_READ_TIMEOUT_SECONDS) {
                    throw new \RuntimeException('Timed out while waiting for streamed LLM response data.');
                }
                continue;
            }

            $lastChunkAt = microtime(true);
            $buffer .= $chunk;
            while (preg_match("/\r?\n\r?\n/", $buffer, $separatorMatch, PREG_OFFSET_CAPTURE) === 1) {
                $separator = $separatorMatch[0][1];
                $separatorLength = strlen($separatorMatch[0][0]);
                $frame = substr($buffer, 0, $separator);
                $buffer = substr($buffer, $separator + $separatorLength);
                $this->consumeStreamFrame($frame, $aggregate, $toolCalls, $onEvent);
            }
        }

        if (trim($buffer) !== '') {
            $this->consumeStreamFrame($buffer, $aggregate, $toolCalls, $onEvent);
        }

        if ($toolCalls !== []) {
            ksort($toolCalls);
            $aggregate['choices'][0]['message']['tool_calls'] = array_values($toolCalls);
        }

        return $aggregate;
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
    private function buildHeaders(string $accept = 'application/json'): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . trim((string) $this->apiKey),
            'Content-Type' => 'application/json',
            'Accept' => $accept,
        ];

        $organization = trim((string) $this->organization);
        if ($organization !== '') {
            $headers['OpenAI-Organization'] = $organization;
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $aggregate
     * @param array<int,array<string,mixed>> $toolCalls
     * @param callable(array<string,mixed>):void $onEvent
     */
    private function consumeStreamFrame(string $frame, array &$aggregate, array &$toolCalls, callable $onEvent): void
    {
        $lines = preg_split('/\r?\n/', trim($frame));
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));
            if ($data === '' || $data === '[DONE]') {
                continue;
            }

            $decoded = json_decode($data, true);
            if (!is_array($decoded)) {
                continue;
            }

            if (isset($decoded['id']) && is_string($decoded['id']) && $decoded['id'] !== '') {
                $aggregate['id'] = $decoded['id'];
                $aggregate['metadata']['completion_id'] = $decoded['id'];
            }
            if (isset($decoded['model']) && is_string($decoded['model'])) {
                $aggregate['model'] = $decoded['model'];
            }
            if (isset($decoded['created']) && is_numeric($decoded['created'])) {
                $aggregate['created'] = (int) $decoded['created'];
            }
            if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                $aggregate['usage'] = $decoded['usage'];
            }

            $choice = $decoded['choices'][0] ?? null;
            if (!is_array($choice)) {
                continue;
            }

            if (isset($choice['finish_reason'])) {
                $aggregate['choices'][0]['finish_reason'] = $choice['finish_reason'];
            }

            $delta = isset($choice['delta']) && is_array($choice['delta']) ? $choice['delta'] : [];
            $content = $delta['content'] ?? null;
            if (is_string($content) && $content !== '') {
                $aggregate['choices'][0]['message']['content'] .= $content;
                $onEvent(['type' => 'content.delta', 'content' => $content]);
            }

            $deltaToolCalls = isset($delta['tool_calls']) && is_array($delta['tool_calls']) ? $delta['tool_calls'] : [];
            foreach ($deltaToolCalls as $toolCallDelta) {
                if (!is_array($toolCallDelta)) {
                    continue;
                }

                $index = isset($toolCallDelta['index']) && is_numeric((string) $toolCallDelta['index'])
                    ? (int) $toolCallDelta['index']
                    : count($toolCalls);
                if (!isset($toolCalls[$index])) {
                    $toolCalls[$index] = [
                        'id' => '',
                        'type' => 'function',
                        'function' => [
                            'name' => '',
                            'arguments' => '',
                        ],
                    ];
                }
                if (isset($toolCallDelta['id']) && is_string($toolCallDelta['id'])) {
                    $toolCalls[$index]['id'] = $toolCallDelta['id'];
                }
                if (isset($toolCallDelta['type']) && is_string($toolCallDelta['type'])) {
                    $toolCalls[$index]['type'] = $toolCallDelta['type'];
                }
                $function = isset($toolCallDelta['function']) && is_array($toolCallDelta['function'])
                    ? $toolCallDelta['function']
                    : [];
                if (isset($function['name']) && is_string($function['name'])) {
                    $toolCalls[$index]['function']['name'] .= $function['name'];
                }
                if (isset($function['arguments']) && is_string($function['arguments'])) {
                    $toolCalls[$index]['function']['arguments'] .= $function['arguments'];
                }
            }
        }
    }

    private function extractStreamRequestId(ResponseInterface $response): string
    {
        $requestId = trim($response->getHeaderLine('x-request-id'));
        return $requestId !== '' ? $requestId : $this->generateRequestId();
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

