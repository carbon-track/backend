<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services\Ai;

use CarbonTrack\Services\Ai\OpenAiClientAdapter;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as Psr7Response;
use OpenAI\Client;
use OpenAI\Contracts\TransporterContract;
use OpenAI\ValueObjects\Transporter\Payload;
use OpenAI\ValueObjects\Transporter\Response as TransporterResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OpenAiClientAdapterTest extends TestCase
{
    public function testCreateChatCompletionFallsBackToRawHttpWhenMetaInformationIsMissing(): void
    {
        $transporter = new class implements TransporterContract {
            public function requestObject(Payload $payload): TransporterResponse
            {
                throw new \TypeError('OpenAI\Responses\Meta\MetaInformation::__construct(): Argument #1 ($requestId) must be of type string, null given');
            }

            public function requestContent(Payload $payload): string
            {
                throw new \LogicException('Not used in this test.');
            }

            public function requestStream(Payload $payload): ResponseInterface
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $httpClient = new class implements HttpClientInterface {
            public ?RequestInterface $request = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Psr7Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'model' => 'gemini-3.1-flash-lite-preview',
                        'choices' => [[
                            'message' => [
                                'content' => '{"activity_uuid":"abc","amount":3,"unit":"plate"}',
                            ],
                            'finish_reason' => 'stop',
                        ]],
                        'usage' => [
                            'prompt_tokens' => 10,
                            'completion_tokens' => 5,
                            'total_tokens' => 15,
                        ],
                    ], JSON_THROW_ON_ERROR)
                );
            }
        };

        $payload = [
            'model' => 'gemini-3.1-flash-lite-preview',
            'messages' => [
                ['role' => 'user', 'content' => 'I have finished foods on 3 plates'],
            ],
        ];

        $adapter = new OpenAiClientAdapter(
            new Client($transporter),
            $httpClient,
            'https://example.test/v1',
            'secret-api-key',
            'org-demo'
        );

        $result = $adapter->createChatCompletion($payload);

        $this->assertSame('https://example.test/v1/chat/completions', (string) $httpClient->request?->getUri());
        $this->assertSame('Bearer secret-api-key', $httpClient->request?->getHeaderLine('Authorization'));
        $this->assertSame('org-demo', $httpClient->request?->getHeaderLine('OpenAI-Organization'));
        $this->assertSame($payload, json_decode((string) $httpClient->request?->getBody(), true));
        $this->assertSame('gemini-3.1-flash-lite-preview', $result['model']);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertIsString($result['metadata']['request_id'] ?? null);
        $this->assertNotSame('', $result['metadata']['request_id']);
        $this->assertSame($result['metadata']['request_id'], $result['id']);
    }

    public function testCreateChatCompletionRethrowsUnrelatedTypeErrors(): void
    {
        $transporter = new class implements TransporterContract {
            public function requestObject(Payload $payload): TransporterResponse
            {
                throw new \TypeError('Unrelated type mismatch.');
            }

            public function requestContent(Payload $payload): string
            {
                throw new \LogicException('Not used in this test.');
            }

            public function requestStream(Payload $payload): ResponseInterface
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $adapter = new OpenAiClientAdapter(new Client($transporter));

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Unrelated type mismatch.');

        $adapter->createChatCompletion([
            'model' => 'test-model',
            'messages' => [
                ['role' => 'user', 'content' => 'hello'],
            ],
        ]);
    }

    public function testStreamChatCompletionConsumesCrLfDelimitedFrames(): void
    {
        $transporter = new class implements TransporterContract {
            public function requestObject(Payload $payload): TransporterResponse
            {
                throw new \LogicException('Not used in this test.');
            }

            public function requestContent(Payload $payload): string
            {
                throw new \LogicException('Not used in this test.');
            }

            public function requestStream(Payload $payload): ResponseInterface
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $body = implode("\r\n\r\n", [
            'data: {"id":"chatcmpl-crlf","model":"test-model","choices":[{"index":0,"delta":{"content":"Hello"}}]}',
            'data: {"choices":[{"index":0,"delta":{"content":" world"},"finish_reason":"stop"}],"usage":{"total_tokens":3}}',
            'data: [DONE]',
            '',
        ]);
        $streamClient = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new Psr7Response(200, ['x-request-id' => 'req-crlf'], $body),
            ])),
        ]);
        $adapter = new OpenAiClientAdapter(
            new Client($transporter),
            null,
            'https://example.test/v1',
            'secret-api-key',
            null,
            $streamClient
        );

        $events = [];
        $result = $adapter->streamChatCompletion([
            'model' => 'test-model',
            'messages' => [['role' => 'user', 'content' => 'hello']],
        ], static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame('Hello world', $result['choices'][0]['message']['content']);
        $this->assertSame('stop', $result['choices'][0]['finish_reason']);
        $this->assertSame('chatcmpl-crlf', $result['id']);
        $this->assertSame('req-crlf', $result['metadata']['request_id']);
        $this->assertSame('chatcmpl-crlf', $result['metadata']['completion_id']);
        $this->assertSame(['Hello', ' world'], array_column($events, 'content'));
    }
}
