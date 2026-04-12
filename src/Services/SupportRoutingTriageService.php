<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Services\Ai\LlmClientInterface;
use CarbonTrack\Support\SyntheticRequestFactory;
use Psr\Log\LoggerInterface;

class SupportRoutingTriageService
{
    private string $model;
    private float $temperature;
    private int $maxTokens;

    public function __construct(
        private ?LlmClientInterface $client,
        private LoggerInterface $logger,
        array $config = [],
        private ?LlmLogService $llmLogService = null,
        private ?AuditLogService $auditLogService = null,
        private ?ErrorLogService $errorLogService = null
    ) {
        $this->model = (string) ($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.1;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 500;
    }

    public function triage(array $ticket, array $context = []): array
    {
        $fallback = $this->fallbackTriage($ticket, $context);
        $aiEnabled = (bool) ($context['ai_enabled'] ?? false);
        $logContext = is_array($context['log_context'] ?? null) ? $context['log_context'] : [];

        if (!$aiEnabled || $this->client === null) {
            $reason = !$aiEnabled ? 'ai_disabled' : 'llm_unavailable';
            $this->logAudit('support_ticket_triage_fallback', $logContext, [
                'request_data' => ['reason' => $reason, 'ticket_id' => (int) ($ticket['id'] ?? 0)],
            ]);

            return [
                'used_ai' => false,
                'fallback_reason' => $reason,
                'triage' => $fallback,
            ];
        }

        $body = trim((string) ($context['message_body'] ?? ''));
        $groupRouting = is_array($context['group_routing'] ?? null) ? $context['group_routing'] : [];
        $prompt = $this->buildPrompt($ticket, $body, $groupRouting);
        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $startedAt = microtime(true);

        try {
            $rawResponse = $this->client->createChatCompletion($payload);
            $parsed = $this->parseResponse($rawResponse, $fallback);
            $this->logLlmCall($prompt['user'], $rawResponse, $logContext, [
                'ticket_id' => (int) ($ticket['id'] ?? 0),
                'feature' => 'support_routing_triage',
            ], $startedAt);
            $this->logAudit('support_ticket_triage_completed', $logContext, [
                'request_data' => [
                    'ticket_id' => (int) ($ticket['id'] ?? 0),
                    'model' => $rawResponse['model'] ?? $this->model,
                    'severity' => $parsed['severity'],
                ],
            ]);

            return [
                'used_ai' => true,
                'fallback_reason' => null,
                'triage' => $parsed,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Support triage AI call failed, using fallback', [
                'ticket_id' => (int) ($ticket['id'] ?? 0),
                'error' => $exception->getMessage(),
            ]);
            $this->logLlmFailure($prompt['user'], $logContext, [
                'ticket_id' => (int) ($ticket['id'] ?? 0),
                'feature' => 'support_routing_triage',
            ], $startedAt, $exception);
            $this->logAudit('support_ticket_triage_fallback', $logContext, [
                'request_data' => [
                    'ticket_id' => (int) ($ticket['id'] ?? 0),
                    'reason' => 'llm_error',
                    'error' => $exception->getMessage(),
                ],
                'status' => 'failed',
            ]);
            $this->logError($exception, $logContext, [
                'ticket_id' => (int) ($ticket['id'] ?? 0),
                'feature' => 'support_routing_triage',
            ]);

            return [
                'used_ai' => false,
                'fallback_reason' => 'llm_error',
                'triage' => $fallback,
            ];
        }
    }

    public function fallbackTriage(array $ticket, array $context = []): array
    {
        $priority = strtolower((string) ($ticket['priority'] ?? 'normal'));
        $prioritySeverity = [
            'low' => 'low',
            'normal' => 'medium',
            'high' => 'high',
            'urgent' => 'critical',
        ];
        $priorityAgentLevel = [
            'low' => 1,
            'normal' => 2,
            'high' => 3,
            'urgent' => 4,
        ];

        $groupRouting = is_array($context['group_routing'] ?? null) ? $context['group_routing'] : [];
        $requiredAgentLevel = max(
            1,
            (int) ($groupRouting['min_agent_level'] ?? 1),
            (int) ($priorityAgentLevel[$priority] ?? 2)
        );

        return [
            'severity' => $prioritySeverity[$priority] ?? 'medium',
            'escalation_risk' => in_array($priority, ['high', 'urgent'], true) ? 'high' : 'medium',
            'required_agent_level' => $requiredAgentLevel,
            'suggested_skills' => [],
            'language' => null,
            'confidence' => 0.35,
            'summary' => sprintf('Fallback triage based on priority %s', $priority),
        ];
    }

    private function buildPrompt(array $ticket, string $body, array $groupRouting): array
    {
        $system = <<<PROMPT
You classify support tickets for routing. Return only a JSON object.
Output schema:
{
  "severity": "low|medium|high|critical",
  "escalation_risk": "low|medium|high",
  "required_agent_level": 1-5,
  "suggested_skills": ["string"],
  "language": "string|null",
  "confidence": 0.0-1.0,
  "summary": "short explanation"
}

Rules:
- required_agent_level must be an integer from 1 to 5.
- suggested_skills must be concise support skills.
- Do not include markdown.
- Keep summary under 120 characters.
PROMPT;

        $user = sprintf(
            "Ticket subject: %s\nCategory: %s\nPriority: %s\nUser group routing: %s\nFirst message:\n%s",
            (string) ($ticket['subject'] ?? ''),
            (string) ($ticket['category'] ?? ''),
            (string) ($ticket['priority'] ?? ''),
            json_encode($groupRouting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $body
        );

        return ['system' => $system, 'user' => $user];
    }

    private function parseResponse(array $rawResponse, array $fallback): array
    {
        $content = (string) (($rawResponse['choices'][0]['message']['content'] ?? '{}'));
        if (str_contains($content, '```')) {
            $content = (string) preg_replace('/^```json\s*|\s*```$/', '', trim($content));
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }
        if (!is_array($decoded)) {
            return $fallback;
        }

        $severity = strtolower((string) ($decoded['severity'] ?? $fallback['severity']));
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $severity = $fallback['severity'];
        }

        $risk = strtolower((string) ($decoded['escalation_risk'] ?? $fallback['escalation_risk']));
        if (!in_array($risk, ['low', 'medium', 'high'], true)) {
            $risk = $fallback['escalation_risk'];
        }

        $level = max(1, min(5, (int) ($decoded['required_agent_level'] ?? $fallback['required_agent_level'])));
        $skills = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, is_array($decoded['suggested_skills'] ?? null) ? $decoded['suggested_skills'] : []), static fn (string $value): bool => $value !== '')));

        return [
            'severity' => $severity,
            'escalation_risk' => $risk,
            'required_agent_level' => $level,
            'suggested_skills' => $skills,
            'language' => ($decoded['language'] ?? null) !== null ? trim((string) $decoded['language']) : null,
            'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? $fallback['confidence']))),
            'summary' => trim((string) ($decoded['summary'] ?? $fallback['summary'])),
        ];
    }

    private function logLlmCall(string $prompt, array $rawResponse, array $logContext, array $context, float $startedAt): void
    {
        if ($this->llmLogService === null) {
            return;
        }

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'system',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? '/support/routing/triage',
            'model' => $rawResponse['model'] ?? $this->model,
            'prompt' => $prompt,
            'response_raw' => $rawResponse,
            'response_id' => $rawResponse['id'] ?? ($rawResponse['metadata']['request_id'] ?? null),
            'status' => 'success',
            'usage' => $rawResponse['usage'] ?? null,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000.0, 2),
            'context' => $context,
        ]);
    }

    private function logLlmFailure(string $prompt, array $logContext, array $context, float $startedAt, \Throwable $error): void
    {
        if ($this->llmLogService === null) {
            return;
        }

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'system',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? '/support/routing/triage',
            'model' => $this->model,
            'prompt' => $prompt,
            'response_raw' => null,
            'response_id' => null,
            'status' => 'failed',
            'error_message' => $error->getMessage(),
            'usage' => null,
            'latency_ms' => round((microtime(true) - $startedAt) * 1000.0, 2),
            'context' => $context,
        ]);
    }

    private function logAudit(string $action, array $logContext, array $context = []): void
    {
        if ($this->auditLogService === null) {
            return;
        }

        try {
            $this->auditLogService->logSystemEvent($action, 'support_routing_triage', array_merge([
                'request_method' => 'SYSTEM',
                'endpoint' => $logContext['source'] ?? '/support/routing/triage',
                'request_id' => $logContext['request_id'] ?? null,
                'status' => $context['status'] ?? 'success',
            ], $context));
        } catch (\Throwable) {
            // ignore audit failure
        }
    }

    private function logError(\Throwable $exception, array $logContext, array $context = []): void
    {
        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext(
                $logContext['source'] ?? '/support/routing/triage',
                'SYSTEM',
                $logContext['request_id'] ?? null,
                [],
                $context,
                ['PHP_SAPI' => PHP_SAPI]
            );
            $this->errorLogService->logException($exception, $request, $context);
        } catch (\Throwable) {
            // ignore logging failure
        }
    }
}
