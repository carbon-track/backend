<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AdminAiIntentService;
use CarbonTrack\Services\Ai\LlmClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AdminAiIntentServiceTest extends TestCase
{
    public function testServiceReportsDisabledWithoutClient(): void
    {
        $service = new AdminAiIntentService(null, new NullLogger());

        $this->assertFalse($service->isEnabled());
        $this->expectException(\RuntimeException::class);
        $service->analyzeIntent('anything', []);
    }

    public function testAnalyzeIntentParsesActionSuggestion(): void
    {
        $responsePayload = $this->createChatResponse([
            'intent' => [
                'type' => 'action',
                'label' => 'Approve records 10 and 11',
                'confidence' => 0.88,
                'reasoning' => '明确指出要审批两个记录',
                'action' => [
                    'name' => 'approve_carbon_records',
                    'summary' => 'Approve records 10,11',
                    'api' => [
                        'method' => 'PUT',
                        'path' => '/api/v1/admin/activities/review',
                        'payload' => [
                            'action' => 'approve',
                            'record_ids' => [10, 11],
                            'review_note' => null,
                        ],
                    ],
                    'autoExecute' => true,
                ],
                'missing' => [],
            ],
        ]);

        $client = new FakeLlmClient($responsePayload);
        $service = new AdminAiIntentService($client, new NullLogger(), ['model' => 'test-model']);

        $result = $service->analyzeIntent('审批 10 11', []);

        $this->assertSame('action', $result['intent']['type']);
        $this->assertSame('approve_carbon_records', $result['intent']['action']['name']);
        $this->assertSame([10, 11], $result['intent']['action']['api']['payload']['record_ids']);
        $this->assertTrue($result['intent']['action']['autoExecute']);
        $this->assertSame('test-model', $result['metadata']['model']);
    }

    public function testAnalyzeIntentDetectsMissingRequirements(): void
    {
        $responsePayload = $this->createChatResponse([
            'intent' => [
                'type' => 'action',
                'label' => 'Approve records',
                'confidence' => 0.6,
                'reasoning' => '未提供具体记录ID',
                'action' => [
                    'name' => 'approve_carbon_records',
                    'summary' => 'Approve selected records',
                    'api' => [
                        'method' => 'PUT',
                        'path' => '/api/v1/admin/activities/review',
                        'payload' => [
                            'action' => 'approve',
                            'record_ids' => [],
                        ],
                    ],
                ],
                'missing' => [],
            ],
        ]);

        $service = new AdminAiIntentService(new FakeLlmClient($responsePayload), new NullLogger());

        $result = $service->analyzeIntent('帮我审批下刚才的活动', []);

        $this->assertSame('action', $result['intent']['type']);
        $this->assertNotEmpty($result['intent']['missing']);
        $this->assertSame('record_ids', $result['intent']['missing'][0]['field']);
    }

    public function testAnalyzeIntentFallsBackWhenRouteUnknown(): void
    {
        $responsePayload = $this->createChatResponse([
            'intent' => [
                'type' => 'navigate',
                'label' => 'Go somewhere',
                'confidence' => 0.4,
                'target' => [
                    'routeId' => 'non-existent',
                    'route' => '/admin/unknown',
                ],
            ],
        ]);

        $service = new AdminAiIntentService(new FakeLlmClient($responsePayload), new NullLogger());
        $result = $service->analyzeIntent('去未知页面', []);

        $this->assertSame('fallback', $result['intent']['type']);
    }

    public function testCustomConfigurationOverridesDefaults(): void
    {
        $responsePayload = $this->createChatResponse([
            'intent' => [
                'type' => 'navigate',
                'label' => 'Open custom',
                'confidence' => 0.7,
                'target' => [
                    'routeId' => 'custom-dashboard',
                    'route' => '/admin/custom-dashboard',
                ],
            ],
        ]);

        $config = [
            'navigationTargets' => [
                [
                    'id' => 'custom-dashboard',
                    'label' => 'Custom Dashboard',
                    'route' => '/admin/custom-dashboard',
                ],
            ],
            'quickActions' => [],
            'managementActions' => [],
        ];

        $service = new AdminAiIntentService(new FakeLlmClient($responsePayload), new NullLogger(), [], $config);

        $result = $service->analyzeIntent('打开自定义面板', []);

        $this->assertSame('navigate', $result['intent']['type']);
        $this->assertSame('/admin/custom-dashboard', $result['intent']['target']['route']);
    }

    public function testDiagnosticsReportsDisabledWhenClientMissing(): void
    {
        $service = new AdminAiIntentService(null, new NullLogger());

        $diagnostics = $service->getDiagnostics();

        $this->assertFalse($diagnostics['enabled']);
        $this->assertSame('skipped', $diagnostics['connectivity']['status']);
        $this->assertFalse($diagnostics['client']['available']);
    }

    public function testDiagnosticsConnectivityCheckSuccess(): void
    {
        $response = [
            'model' => 'diag-model',
            'choices' => [
                [
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'OK',
                    ],
                ],
            ],
            'usage' => [
                'prompt_tokens' => 3,
                'completion_tokens' => 1,
                'total_tokens' => 4,
            ],
        ];

        $client = new FakeLlmClient($response);
        $service = new AdminAiIntentService($client, new NullLogger(), ['model' => 'diag-model']);

        $diagnostics = $service->getDiagnostics(true);

        $this->assertTrue($diagnostics['enabled']);
        $this->assertSame('ok', $diagnostics['connectivity']['status']);
        $this->assertSame('diag-model', $diagnostics['connectivity']['model']);
        $this->assertNotNull($client->lastPayload);
        $this->assertSame(1, $client->lastPayload['max_tokens']);
        $this->assertSame('Ping', $client->lastPayload['messages'][1]['content']);
    }

    public function testDiagnosticsConnectivityCheckError(): void
    {
        $client = new ThrowingLlmClient(new \RuntimeException('bad gateway'));
        $service = new AdminAiIntentService($client, new NullLogger());

        $diagnostics = $service->getDiagnostics(true);

        $this->assertSame('error', $diagnostics['connectivity']['status']);
        $this->assertSame('bad gateway', $diagnostics['connectivity']['error']);
        $this->assertSame(\RuntimeException::class, $diagnostics['connectivity']['exception']);
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private function createChatResponse(array $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'created' => time(),
            'model' => 'test-model',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($content, JSON_UNESCAPED_UNICODE),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 20,
                'total_tokens' => 30,
            ],
        ];
    }
}

class FakeLlmClient implements LlmClientInterface
{
    /**
     * @param array<string,mixed> $response
     */
    public function __construct(private array $response)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createChatCompletion(array $payload): array
    {
        $this->lastPayload = $payload;
        return $this->response;
    }

    /** @var array<string,mixed>|null */
    public ?array $lastPayload = null;
}

class ThrowingLlmClient implements LlmClientInterface
{
    public function __construct(private \Throwable $throwable)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createChatCompletion(array $payload): array
    {
        throw $this->throwable;
    }
}

