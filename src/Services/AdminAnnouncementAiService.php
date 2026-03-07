<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\SyntheticRequestFactory;
use CarbonTrack\Services\Ai\LlmClientInterface;
use Psr\Log\LoggerInterface;

class AdminAnnouncementAiService
{
    public const ACTION_GENERATE = 'generate';
    public const ACTION_REWRITE = 'rewrite';
    public const ACTION_COMPRESS = 'compress';
    public const ACTION_CONVERT = 'convert';

    /** @var array<int,string> */
    public const SUPPORTED_ACTIONS = [
        self::ACTION_GENERATE,
        self::ACTION_REWRITE,
        self::ACTION_COMPRESS,
        self::ACTION_CONVERT,
    ];

    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $enabled;

    public function __construct(
        private ?LlmClientInterface $client,
        private LoggerInterface $logger,
        array $config = [],
        private ?LlmLogService $llmLogService = null,
        private ?AuditLogService $auditLogService = null,
        private ?ErrorLogService $errorLogService = null
    ) {
        $this->model = (string) ($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float) $config['temperature'] : 0.2;
        $this->maxTokens = isset($config['max_tokens']) ? (int) $config['max_tokens'] : 1800;
        $this->enabled = $client !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public static function normalizeAction(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($normalized, self::SUPPORTED_ACTIONS, true)
            ? $normalized
            : self::ACTION_GENERATE;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $logContext
     * @return array<string,mixed>
     */
    public function generateDraft(array $input, array $logContext = []): array
    {
        if (!$this->enabled) {
            throw new AdminAnnouncementAiException('AI service is disabled');
        }

        $normalized = $this->normalizeInput($input);
        $messages = $this->buildMessages($normalized);
        $promptTranscript = $this->buildPromptTranscript($messages);

        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'],
        ];

        $startedAt = microtime(true);

        try {
            $rawResponse = $this->client->createChatCompletion($payload);
            $this->logLlmCall($promptTranscript, $rawResponse, $logContext, $normalized, $startedAt);
        } catch (\Throwable $e) {
            $this->logger->error('Admin announcement AI call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->logLlmFailure($promptTranscript, $logContext, $normalized, $startedAt, $e);
            $this->logAudit('admin_announcement_ai_service_failed', $logContext, [
                'status' => 'failed',
                'request_data' => [
                    'action' => $normalized['action'],
                    'priority' => $normalized['priority'],
                    'content_format' => $normalized['content_format'],
                    'error' => $e->getMessage(),
                ],
            ]);
            $this->logError($e, $logContext, $normalized);
            throw new AdminAnnouncementAiUnavailableException('LLM_UNAVAILABLE', 0, $e);
        }

        $result = $this->processResponse($rawResponse, $normalized);
        $this->logAudit('admin_announcement_ai_service_succeeded', $logContext, [
            'request_data' => [
                'action' => $normalized['action'],
                'priority' => $normalized['priority'],
                'content_format' => $normalized['content_format'],
                'model' => $rawResponse['model'] ?? $this->model,
                'success' => $result['success'] ?? false,
            ],
        ]);

        return $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalizeInput(array $input): array
    {
        return [
            'action' => self::normalizeAction($input['action'] ?? null),
            'title' => trim((string) ($input['title'] ?? '')),
            'content' => trim((string) ($input['content'] ?? '')),
            'instruction' => trim((string) ($input['instruction'] ?? '')),
            'priority' => $this->normalizePriority($input['priority'] ?? null),
            'content_format' => $this->normalizeContentFormat($input['content_format'] ?? null),
        ];
    }

    private function normalizePriority(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : 'normal';
        return in_array($normalized, ['low', 'normal', 'high', 'urgent'], true) ? $normalized : 'normal';
    }

    private function normalizeContentFormat(mixed $value): string
    {
        $normalized = is_string($value) ? strtolower(trim($value)) : 'html';
        return in_array($normalized, ['text', 'html'], true) ? $normalized : 'html';
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,array<string,string>>
     */
    private function buildMessages(array $input): array
    {
        return [
            ['role' => 'system', 'content' => $this->buildSystemPrompt()],
            ['role' => 'user', 'content' => $this->buildUserPrompt($input)],
        ];
    }

    private function buildSystemPrompt(): string
    {
        return implode("\n", [
            'You are an announcement HTML editor for an admin broadcast system.',
            'You produce SAFE, SANITIZED-FRIENDLY announcement drafts that render well in both a web inbox and an email preview.',
            '',
            'HTML profile constraints:',
            '- Allowed tags: h1, h2, h3, h4, p, br, strong, em, u, ul, ol, li, blockquote, code, pre, table, thead, tbody, tr, th, td, a, hr',
            '- Allowed attributes: href, title, target, rel, scope, colspan, rowspan, align',
            '- No <html>, <head>, <body>, <style>, <script>, <iframe>, <img>, <video>, <audio>, <form>, classes, inline styles, or event handlers.',
            '- Use <pre><code>...</code></pre> for code blocks.',
            '- Links must be descriptive and use absolute https:// URLs when necessary.',
            '- Do not invent facts, dates, discounts, deadlines, promises, or policies that are not present in the input.',
            '- If information is missing, keep the draft generic and honest instead of hallucinating details.',
            '',
            'Output rules:',
            '- Return a valid JSON object only.',
            '- Do not use Markdown fences.',
            '- JSON schema:',
            '{',
            '  "title": "string",',
            '  "content": "string"',
            '}',
            '- "content" must be the final HTML fragment only.',
            '- "title" should be concise, professional, and ready to use as the broadcast title.',
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function buildUserPrompt(array $input): string
    {
        $lines = array_merge(
            $this->buildIntentInstructions((string) $input['action'], ((string) $input['content']) !== ''),
            [
                '',
                'Project constraints:',
                '- The HTML result must survive sanitizer cleanup without losing key meaning.',
                '- Prefer headings, paragraphs, lists, blockquotes, code blocks, simple tables, and safe links.',
                '- Match urgency to the provided priority without exaggeration.',
                '',
                'Context:',
                'Title: ' . (((string) $input['title']) !== '' ? (string) $input['title'] : '(untitled announcement)'),
                'Priority: ' . (string) $input['priority'],
                'Current editor content format: ' . (string) $input['content_format'],
                'Current draft / notes:',
                ((string) $input['content']) !== '' ? (string) $input['content'] : '(no existing content yet)',
            ]
        );

        if (((string) $input['instruction']) !== '') {
            $lines[] = '';
            $lines[] = 'Additional admin request:';
            $lines[] = (string) $input['instruction'];
        }

        $lines[] = '';
        $lines[] = 'Return only the JSON object.';

        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private function buildIntentInstructions(string $action, bool $hasContent): array
    {
        return match ($action) {
            self::ACTION_REWRITE => [
                'Task: polish and rewrite the existing announcement draft.',
                '- Preserve all confirmed facts.',
                '- Improve clarity, structure, scannability, and trustworthiness.',
            ],
            self::ACTION_COMPRESS => [
                'Task: compress the existing announcement draft.',
                '- Keep only essential actionable information.',
                '- Preserve dates, deadlines, and required user actions if present.',
            ],
            self::ACTION_CONVERT => [
                'Task: convert the provided notes or plain text into safe announcement HTML.',
                '- Organize the material into clear semantic sections.',
                '- Preserve meaning and avoid adding new facts.',
            ],
            default => $hasContent
                ? [
                    'Task: generate a refined announcement HTML draft from the provided title, notes, and constraints.',
                    '- Use the supplied draft or notes as the content source of truth.',
                    '- Fill structural gaps only, not factual gaps.',
                ]
                : [
                    'Task: generate a first-draft announcement HTML fragment from the provided title and constraints.',
                    '- If the input lacks details, produce a generic but honest draft.',
                ],
        };
    }

    /**
     * @param array<int,array<string,string>> $messages
     */
    private function buildPromptTranscript(array $messages): string
    {
        $chunks = [];
        foreach ($messages as $message) {
            $role = strtoupper((string) ($message['role'] ?? 'message'));
            $content = (string) ($message['content'] ?? '');
            $chunks[] = "=== {$role} ===\n{$content}";
        }

        return implode("\n\n", $chunks);
    }

    /**
     * @param array<string,mixed> $rawResponse
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function processResponse(array $rawResponse, array $input): array
    {
        $messageContent = $this->extractMessageContent($rawResponse);
        $decoded = $this->decodeJsonContent($messageContent);

        if (!is_array($decoded)) {
            if ($this->looksLikeHtml($messageContent)) {
                $decoded = [
                    'title' => (string) ($input['title'] ?: 'Announcement'),
                    'content' => trim($messageContent),
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to parse AI response',
                    'raw_content' => $messageContent,
                    'metadata' => [
                        'model' => $rawResponse['model'] ?? $this->model,
                        'usage' => $rawResponse['usage'] ?? null,
                        'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? null,
                    ],
                ];
            }
        }

        $title = trim((string) ($decoded['title'] ?? $input['title'] ?? ''));
        $content = trim((string) ($decoded['content'] ?? $decoded['html'] ?? ''));

        if ($title === '') {
            $title = (string) ($input['title'] ?: 'Announcement');
        }

        if ($content === '') {
            return [
                'success' => false,
                'error' => 'AI response did not include announcement content',
                'raw_content' => $messageContent,
                'metadata' => [
                    'model' => $rawResponse['model'] ?? $this->model,
                    'usage' => $rawResponse['usage'] ?? null,
                    'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? null,
                ],
            ];
        }

        return [
            'success' => true,
            'result' => [
                'title' => $title,
                'content' => $content,
                'content_format' => 'html',
                'action' => (string) $input['action'],
            ],
            'metadata' => [
                'model' => $rawResponse['model'] ?? $this->model,
                'usage' => $rawResponse['usage'] ?? null,
                'finish_reason' => $rawResponse['choices'][0]['finish_reason'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $rawResponse
     */
    private function extractMessageContent(array $rawResponse): string
    {
        $choice = $rawResponse['choices'][0] ?? [];
        $content = $choice['message']['content'] ?? '';
        return is_string($content) ? trim($content) : '';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonContent(string $content): ?array
    {
        if ($content === '') {
            return null;
        }

        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $content);
        if (!is_string($cleaned)) {
            $cleaned = $content;
        }

        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
        if (!is_string($cleaned)) {
            $cleaned = $content;
        }

        $decoded = json_decode(trim($cleaned), true);
        if (!is_array($decoded) && preg_match('/\{.*\}/s', $cleaned, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function looksLikeHtml(string $content): bool
    {
        return $content !== '' && preg_match('/<\/?[a-z][^>]*>/i', $content) === 1;
    }

    /**
     * @param array<string,mixed> $rawResponse
     * @param array<string,mixed> $logContext
     * @param array<string,mixed> $context
     */
    private function logLlmCall(string $prompt, array $rawResponse, array $logContext, array $context, float $startedAt): void
    {
        if (!$this->llmLogService) {
            return;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000.0;
        $responseId = $rawResponse['id'] ?? ($rawResponse['metadata']['request_id'] ?? null);

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? null,
            'model' => $rawResponse['model'] ?? $this->model,
            'prompt' => $prompt,
            'response_raw' => $rawResponse,
            'response_id' => $responseId,
            'status' => 'success',
            'error_message' => null,
            'usage' => $rawResponse['usage'] ?? null,
            'latency_ms' => round($durationMs, 2),
            'context' => $context,
        ]);
    }

    /**
     * @param array<string,mixed> $logContext
     * @param array<string,mixed> $context
     */
    private function logLlmFailure(string $prompt, array $logContext, array $context, float $startedAt, \Throwable $error): void
    {
        if (!$this->llmLogService) {
            return;
        }

        $durationMs = (microtime(true) - $startedAt) * 1000.0;

        $this->llmLogService->log([
            'request_id' => $logContext['request_id'] ?? null,
            'actor_type' => $logContext['actor_type'] ?? 'admin',
            'actor_id' => $logContext['actor_id'] ?? null,
            'source' => $logContext['source'] ?? null,
            'model' => $this->model,
            'prompt' => $prompt,
            'response_raw' => null,
            'response_id' => null,
            'status' => 'failed',
            'error_message' => $error->getMessage(),
            'usage' => null,
            'latency_ms' => round($durationMs, 2),
            'context' => $context,
        ]);
    }

    private function logAudit(string $action, array $logContext, array $context = []): void
    {
        if (!$this->auditLogService) {
            return;
        }

        try {
            $actorId = isset($logContext['actor_id']) && is_numeric((string) $logContext['actor_id'])
                ? (int) $logContext['actor_id']
                : null;
            $this->auditLogService->logAdminOperation($action, $actorId, 'admin_announcement_ai_service', array_merge([
                'request_id' => $logContext['request_id'] ?? null,
                'endpoint' => $logContext['source'] ?? '/admin/ai/announcement-drafts',
                'request_method' => 'POST',
                'status' => 'success',
            ], $context));
        } catch (\Throwable $ignore) {
            // 审计日志失败不阻断主流程
        }
    }

    private function logError(\Throwable $exception, array $logContext, array $context = []): void
    {
        if (!$this->errorLogService) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext(
                $logContext['source'] ?? '/admin/ai/announcement-drafts',
                'POST',
                $logContext['request_id'] ?? null,
                [],
                $context
            );
            $this->errorLogService->logException($exception, $request, [
                'request_id' => $logContext['request_id'] ?? null,
                'actor_type' => $logContext['actor_type'] ?? 'admin',
            ]);
        } catch (\Throwable $ignore) {
            // swallow secondary logging failure
        }
    }
}
