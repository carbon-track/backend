<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\AdminAnnouncementAiException;
use CarbonTrack\Services\AdminAnnouncementAiService;
use CarbonTrack\Services\AdminAnnouncementAiUnavailableException;
use CarbonTrack\Services\Ai\LlmClientInterface;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AdminAnnouncementAiServiceTest extends TestCase
{
    public function testUnavailableExceptionIsAutoloadable(): void
    {
        $this->assertTrue(class_exists(AdminAnnouncementAiUnavailableException::class));
    }

    public function testServiceReportsDisabledWithoutClient(): void
    {
        $service = new AdminAnnouncementAiService(null, new NullLogger());

        $this->assertFalse($service->isEnabled());
        $this->expectException(AdminAnnouncementAiException::class);
        $service->generateDraft(['title' => 'Hello']);
    }

    public function testGenerateDraftParsesJsonPayload(): void
    {
        $response = [
            'id' => 'chatcmpl-test',
            'model' => 'test-model',
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'title' => 'System Maintenance Notice',
                        'content' => '<h2>Maintenance</h2><p>Services will be briefly unavailable tonight.</p>',
                    ], JSON_UNESCAPED_UNICODE),
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['total_tokens' => 42],
        ];

        $client = new AdminAnnouncementAiFakeLlmClient($response);
        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);
        $service = new AdminAnnouncementAiService($client, new NullLogger(), ['model' => 'test-model'], null, $auditLogService, $this->createMock(ErrorLogService::class));

        $result = $service->generateDraft([
            'action' => 'generate',
            'title' => 'Maintenance',
            'content' => 'Need a brief announcement',
            'instruction' => 'Keep it concise',
            'priority' => 'high',
            'content_format' => 'html',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('System Maintenance Notice', $result['result']['title']);
        $this->assertStringContainsString('<h2>Maintenance</h2>', $result['result']['content']);
        $this->assertSame('html', $result['result']['content_format']);
        $this->assertSame('test-model', $result['metadata']['model']);
        $this->assertNotNull($client->lastPayload);
        $this->assertSame('json_object', $client->lastPayload['response_format']['type']);
    }

    public function testGenerateDraftHandlesMarkdownWrappedJson(): void
    {
        $response = [
            'choices' => [[
                'message' => [
                    'content' => "```json\n" . json_encode([
                        'title' => 'FAQ Update',
                        'content' => '<p>Updated frequently asked questions are now available.</p>',
                    ], JSON_UNESCAPED_UNICODE) . "\n```",
                ],
                'finish_reason' => 'stop',
            ]],
        ];

        $service = new AdminAnnouncementAiService(new AdminAnnouncementAiFakeLlmClient($response), new NullLogger());
        $result = $service->generateDraft([
            'title' => 'FAQ',
            'content' => 'Add update',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('FAQ Update', $result['result']['title']);
    }

    public function testGenerateDraftFallsBackToHtmlContent(): void
    {
        $response = [
            'choices' => [[
                'message' => [
                    'content' => '<h3>Reminder</h3><p>Please complete your profile.</p>',
                ],
                'finish_reason' => 'stop',
            ]],
        ];

        $service = new AdminAnnouncementAiService(new AdminAnnouncementAiFakeLlmClient($response), new NullLogger());
        $result = $service->generateDraft([
            'title' => 'Profile reminder',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('Profile reminder', $result['result']['title']);
        $this->assertStringContainsString('<h3>Reminder</h3>', $result['result']['content']);
    }

    public function testGenerateDraftWrapsClientFailureAsUnavailableException(): void
    {
        $client = new class implements LlmClientInterface {
            public function createChatCompletion(array $payload): array
            {
                throw new AdminAnnouncementAiTestProviderException('provider down');
            }
        };

        $auditLogService = $this->createMock(AuditLogService::class);
        $auditLogService->expects($this->once())->method('logAdminOperation')->willReturn(true);
        $errorLogService = $this->createMock(ErrorLogService::class);
        $errorLogService->expects($this->once())->method('logException');
        $service = new AdminAnnouncementAiService($client, new NullLogger(), [], null, $auditLogService, $errorLogService);

        $this->expectException(AdminAnnouncementAiUnavailableException::class);
        $service->generateDraft([
            'title' => 'Maintenance',
            'content' => 'Need a draft',
        ]);
    }
}

class AdminAnnouncementAiFakeLlmClient implements LlmClientInterface
{
    /** @var array<string,mixed>|null */
    public ?array $lastPayload = null;

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
}

class AdminAnnouncementAiTestProviderException extends \RuntimeException
{
}
