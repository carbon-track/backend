<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\UserAiService;
use CarbonTrack\Services\Ai\LlmClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class UserAiServiceTest extends TestCase
{
    private $llmClient;
    private $logger;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LlmClientInterface::class);
        $this->logger = new NullLogger();
    }

    private function createService(array $config = [], bool $withClient = true): UserAiService
    {
        return new UserAiService(
            $withClient ? $this->llmClient : null,
            $this->logger,
            $config
        );
    }

    public function testIsEnabled(): void
    {
        $serviceWithClient = $this->createService();
        $this->assertTrue($serviceWithClient->isEnabled());

        $serviceWithoutClient = $this->createService([], false);
        $this->assertFalse($serviceWithoutClient->isEnabled());
    }

    public function testSuggestActivityThrowsWhenDisabled(): void
    {
        $service = $this->createService([], false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI service is disabled');

        $service->suggestActivity('some query');
    }

    public function testSuggestActivitySuccess(): void
    {
        $expectedResponse = [
            'activity_name' => 'Bus',
            'amount' => 10,
            'unit' => 'km',
            'activity_uuid' => null
        ];

        $rawResponse = [
            'choices' => [
                [
                    'message' => [
                        'content' => json_encode($expectedResponse)
                    ]
                ]
            ],
            'model' => 'test-model',
            'usage' => ['total_tokens' => 100]
        ];

        $this->llmClient->expects($this->once())
            ->method('createChatCompletion')
            ->with($this->callback(function ($payload) {
                return $payload['model'] === 'google/gemini-2.5-flash-lite'
                    && isset($payload['messages'][1]['content'])
                    && $payload['messages'][1]['content'] === 'test query';
            }))
            ->willReturn($rawResponse);

        $service = $this->createService();
        $result = $service->suggestActivity('test query');

        $this->assertTrue($result['success']);
        $this->assertEquals($expectedResponse, $result['prediction']);
        $this->assertEquals('test-model', $result['metadata']['model']);
    }

    public function testSuggestActivityHandlesMarkdownJsonBlock(): void
    {
        $expectedResponse = ['activity' => 'Test', 'activity_uuid' => null];
        $jsonString = json_encode($expectedResponse);
        $content = "Here is the result:\n```json\n$jsonString\n```";

        $rawResponse = [
            'choices' => [
                [
                    'message' => ['content' => $content]
                ]
            ]
        ];

        $this->llmClient->method('createChatCompletion')->willReturn($rawResponse);

        $service = $this->createService();
        $result = $service->suggestActivity('test');

        $this->assertTrue($result['success']);
        $this->assertEquals($expectedResponse, $result['prediction']);
    }

    public function testSuggestActivityHandlesFallbackParsing(): void
    {
        $expectedResponse = ['activity' => 'Test', 'activity_uuid' => null];
        $jsonString = json_encode($expectedResponse);
        $content = "Sure! $jsonString is your result.";

        $rawResponse = [
            'choices' => [
                [
                    'message' => ['content' => $content]
                ]
            ]
        ];

        $this->llmClient->method('createChatCompletion')->willReturn($rawResponse);

        $service = $this->createService();
        $result = $service->suggestActivity('test');

        $this->assertTrue($result['success']);
        $this->assertEquals($expectedResponse, $result['prediction']);
    }

    public function testSuggestActivityHandlesInvalidJson(): void
    {
        $rawResponse = [
            'choices' => [
                [
                    'message' => ['content' => 'Not JSON at all']
                ]
            ]
        ];

        $this->llmClient->method('createChatCompletion')->willReturn($rawResponse);

        $service = $this->createService();
        $result = $service->suggestActivity('test');

        $this->assertFalse($result['success']);
        $this->assertEquals('Failed to parse AI response', $result['error']);
    }

    public function testSuggestActivityHandlesClientException(): void
    {
        $this->llmClient->method('createChatCompletion')
            ->willThrowException(new \Exception('API Error'));

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LLM_UNAVAILABLE');

        $service->suggestActivity('test');
    }
    
    public function testConfigOverrides(): void
    {
        $config = [
            'model' => 'custom-model',
            'temperature' => 0.5,
            'max_tokens' => 1000
        ];
        
        $service = $this->createService($config);
        
        // We can verify this by checking what createChatCompletion receives
        $this->llmClient->expects($this->once())
            ->method('createChatCompletion')
            ->with($this->callback(function ($payload) {
                return $payload['model'] === 'custom-model'
                    && $payload['temperature'] === 0.5
                    && $payload['max_tokens'] === 1000;
            }))
            ->willReturn(['choices' => []]); // will fail at parsing but that's fine for this test check
            
        $service->suggestActivity('test');
    }
}
