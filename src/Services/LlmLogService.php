<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use PDO;
use Monolog\Logger;

/**
 * LlmLogService
 * 记录 LLM 调用日志（含提示词与原始响应），用于审计与溯源。
 */
class LlmLogService
{
    private PDO $db;
    private Logger $logger;

    private int $maxPromptLength = 8000;
    private int $maxResponseLength = 120000;
    private int $maxErrorLength = 4000;
    private int $maxContextLength = 8000;

    public function __construct(PDO $db, Logger $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * @param array<string,mixed> $data
     */
    public function log(array $data): ?int
    {
        try {
            $requestId = $this->trimString($data['request_id'] ?? null, 64);
            $actorType = $this->trimString($data['actor_type'] ?? null, 20) ?? 'user';
            $actorId = isset($data['actor_id']) ? (int) $data['actor_id'] : null;
            $source = $this->trimString($data['source'] ?? null, 120);
            $model = $this->trimString($data['model'] ?? null, 120);
            $prompt = $this->normalizeText($data['prompt'] ?? null, $this->maxPromptLength);
            $responseRaw = $this->normalizeText($data['response_raw'] ?? null, $this->maxResponseLength);
            $responseId = $this->trimString($data['response_id'] ?? null, 64);
            $status = $this->trimString($data['status'] ?? null, 20);
            $errorMessage = $this->normalizeText($data['error_message'] ?? null, $this->maxErrorLength);

            $usage = $data['usage'] ?? null;
            $usageJson = $this->encodeJson($usage);
            $usageTokens = $this->extractUsageTokens($usage);
            $contextJson = $this->normalizeText($data['context'] ?? ($data['context_json'] ?? null), $this->maxContextLength);

            $latencyMs = isset($data['latency_ms']) ? (float) $data['latency_ms'] : null;

            $stmt = $this->db->prepare("INSERT INTO llm_logs (
                request_id, actor_type, actor_id, source, model, prompt, response_raw, response_id,
                status, error_message, prompt_tokens, completion_tokens, total_tokens, latency_ms, usage_json, context_json
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            $stmt->execute([
                $requestId,
                $actorType,
                $actorId,
                $source,
                $model,
                $prompt,
                $responseRaw,
                $responseId,
                $status,
                $errorMessage,
                $usageTokens['prompt_tokens'],
                $usageTokens['completion_tokens'],
                $usageTokens['total_tokens'],
                $latencyMs,
                $usageJson,
                $contextJson,
            ]);

            $id = (int) $this->db->lastInsertId();
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            try {
                $this->logger->warning('LLM log insert failed', [
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $ignore) {
                // swallow secondary logging failure
            }
        }

        return null;
    }

    private function trimString($value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return $value;
    }

    private function normalizeText($value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            $value = $this->encodeJson($value);
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            return mb_substr($value, 0, $maxLength, 'UTF-8') . '...[TRUNCATED]';
        }
        return $value;
    }

    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    /**
     * @param mixed $usage
     * @return array{prompt_tokens:int|null,completion_tokens:int|null,total_tokens:int|null}
     */
    private function extractUsageTokens($usage): array
    {
        if (!is_array($usage)) {
            return [
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'total_tokens' => null,
            ];
        }

        $promptTokens = $this->toInt($usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? ($usage['promptTokens'] ?? null)));
        $completionTokens = $this->toInt($usage['completion_tokens'] ?? ($usage['output_tokens'] ?? ($usage['completionTokens'] ?? null)));
        $totalTokens = $this->toInt($usage['total_tokens'] ?? ($usage['totalTokens'] ?? null));

        if ($totalTokens === null && ($promptTokens !== null || $completionTokens !== null)) {
            $totalTokens = (int) ($promptTokens ?? 0) + (int) ($completionTokens ?? 0);
        }

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
        ];
    }

    private function toInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return null;
    }
}
