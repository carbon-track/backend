<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Services\Ai\LlmClientInterface;
use Psr\Log\LoggerInterface;

class UserAiService
{
    private string $model;
    private float $temperature;
    private int $maxTokens;
    private bool $enabled;

    public function __construct(
        private ?LlmClientInterface $client,
        private LoggerInterface $logger,
        array $config = []
    ) {
        $this->model = (string)($config['model'] ?? 'google/gemini-2.5-flash-lite');
        $this->temperature = isset($config['temperature']) ? (float)$config['temperature'] : 0.1;
        $this->maxTokens = isset($config['max_tokens']) ? (int)$config['max_tokens'] : 500;
        $this->enabled = $client !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param string $query
     * @param array<string> $availableActivities List of activity names/descriptions
     * @return array
     */
    public function suggestActivity(string $query, array $availableActivities = []): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('AI service is disabled');
        }

        $messages = $this->buildMessages($query, $availableActivities);

        $payload = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
            'response_format' => ['type' => 'json_object'] // JSON mode if supported
        ];

        try {
            $rawResponse = $this->client->createChatCompletion($payload);
        } catch (\Throwable $e) {
            $this->logger->error('User AI suggest call failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('LLM_UNAVAILABLE', 0, $e);
        }

        return $this->processResponse($rawResponse);
    }

    private function buildMessages(string $query, array $activities): array
    {
        $activityList = implode(", ", array_slice($activities, 0, 50)); // Limit context size
        if (count($activities) > 50) {
            $activityList .= "... (and more)";
        }

        $systemPrompt = <<<EOT
You are a CarbonTrack assistant. help extract carbon footprint activity data from user input.
You must return a valid JSON object.

Available Activity Types (Reference): [{$activityList}]

Instructions:
1. Identify the activity type from the user input. Match it to one of the available types if possible.
2. Extract the numeric amount and unit.
3. If the unit is missing, infer a standard unit for that activity (e.g., km for transport).
4. Return confidence score (0-1).

Output Schema (JSON):
{
    "activity_name": "string (Best match name)",
    "amount": number,
    "unit": "string",
    "notes": "string (Short summary of what was extracted)",
    "confidence": number
}

If no activity is detected, set confidence to 0.
EOT;

        return [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $query]
        ];
    }

    private function processResponse(array $rawResponse): array
    {
         $choice = $rawResponse['choices'][0] ?? [];
         $content = $choice['message']['content'] ?? '{}';
         
         // Basic cleanup for JSON block if model returns markdown
         if (str_contains($content, '```')) {
             $content = preg_replace('/^```json\s*|\s*```$/', '', $content);
         }
         
         $data = json_decode($content, true);
         
         if (!is_array($data)) {
             // Fallback: try to find start and end braces
             if (preg_match('/\{.*\}/s', $content, $matches)) {
                 $data = json_decode($matches[0], true);
             }
         }

         if (!is_array($data)) {
             return [
                 'success' => false,
                 'error' => 'Failed to parse AI response',
                 'raw_content' => $content
             ];
         }
         
         return [
            'success' => true,
            'prediction' => $data,
            'metadata' => [
                'model' => $rawResponse['model'] ?? $this->model,
                'usage' => $rawResponse['usage'] ?? null
            ]
         ];
    }
}
