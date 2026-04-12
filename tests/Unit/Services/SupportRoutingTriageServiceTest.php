<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\Ai\LlmClientInterface;
use CarbonTrack\Services\SupportRoutingTriageService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SupportRoutingTriageServiceTest extends TestCase
{
    public function testFallsBackWhenAiIsDisabled(): void
    {
        $service = new SupportRoutingTriageService(
            null,
            $this->createMock(LoggerInterface::class)
        );

        $result = $service->triage([
            'id' => 11,
            'priority' => 'high',
        ], [
            'ai_enabled' => false,
            'group_routing' => ['min_agent_level' => 2],
        ]);

        $this->assertFalse($result['used_ai']);
        $this->assertSame('ai_disabled', $result['fallback_reason']);
        $this->assertSame('high', $result['triage']['severity']);
        $this->assertSame(3, $result['triage']['required_agent_level']);
    }

    public function testParsesJsonResponseFromLlm(): void
    {
        $client = $this->createMock(LlmClientInterface::class);
        $client->expects($this->once())
            ->method('createChatCompletion')
            ->willReturn([
                'model' => 'test-model',
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'severity' => 'critical',
                            'escalation_risk' => 'high',
                            'required_agent_level' => 5,
                            'suggested_skills' => ['billing', 'vip'],
                            'language' => 'zh-CN',
                            'confidence' => 0.92,
                            'summary' => 'VIP escalation',
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ]],
            ]);

        $service = new SupportRoutingTriageService(
            $client,
            $this->createMock(LoggerInterface::class)
        );

        $result = $service->triage([
            'id' => 22,
            'subject' => 'VIP complaint',
            'priority' => 'urgent',
        ], [
            'ai_enabled' => true,
            'group_routing' => ['min_agent_level' => 2],
            'message_body' => 'I want this escalated now',
        ]);

        $this->assertTrue($result['used_ai']);
        $this->assertNull($result['fallback_reason']);
        $this->assertSame('critical', $result['triage']['severity']);
        $this->assertSame(5, $result['triage']['required_agent_level']);
        $this->assertSame(['billing', 'vip'], $result['triage']['suggested_skills']);
    }
}
