<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services\Ai;

use CarbonTrack\Services\Ai\OpenAiClientAdapter;
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
}
