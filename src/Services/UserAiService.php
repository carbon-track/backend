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

        return $this->processResponse($rawResponse, $availableActivities);
    }

    private function buildMessages(string $query, array $activities): array
    {
        $activityLines = [];
        foreach (array_slice($activities, 0, 500) as $item) {
            if (is_array($item)) {
                $id = (string)($item['id'] ?? '');
                $label = $item['label'] ?? ($item['name'] ?? '');
                $category = $item['category'] ?? ($item['cat'] ?? 'General');
                $unit = $item['unit'] ?? null;
                $unitPart = $unit ? " | Unit: {$unit}" : '';
                $activityLines[] = "UUID: {$id} | Category: {$category} | Name: {$label}{$unitPart}";
            } else {
                $activityLines[] = (string)$item;
            }
        }
        $activityList = implode("\n", $activityLines);
        if (count($activities) > 500) {
            $activityList .= "\n... (and more)";
        }

        $systemPrompt = <<<EOT
You are a CarbonTrack assistant. help extract carbon footprint activity data from user input.
You must return a valid JSON object. Match to the provided activities by UUID.

Available Activity Types (Reference):
{$activityList}

Instructions:
1. Identify the activity type from the user input. Match it to one of the available UUIDs above if possible.
2. Return the matched activity_uuid (required). If no match, set activity_uuid to null and confidence 0.
3. Include activity_name only as a display label (keep the provided name if matched).
4. Extract the numeric amount and unit. If the unit is missing, infer a standard unit for that activity (e.g., km for transport).
5. Return confidence score (0-1).

Output Schema (JSON):
{
    "activity_uuid": "string|null (Use one of the UUIDs provided above; null if none)",
    "activity_name": "string (Best match name, optional)",
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

    private function processResponse(array $rawResponse, array $availableActivities = []): array
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
         
         // Normalize uuid presence and enforce allowed list
         $allowedUuids = [];
         foreach ($availableActivities as $item) {
             if (is_array($item) && isset($item['id'])) {
                 $allowedUuids[] = (string)$item['id'];
             }
         }
         $allowedUuids = array_unique($allowedUuids);

         if (!array_key_exists('activity_uuid', $data)) {
             $data['activity_uuid'] = null;
         }

         if ($data['activity_uuid'] !== null && !is_string($data['activity_uuid'])) {
             $data['activity_uuid'] = (string)$data['activity_uuid'];
         }

         if (!empty($allowedUuids) && $data['activity_uuid'] !== null && !in_array($data['activity_uuid'], $allowedUuids, true)) {
             // If model picked an unknown uuid, drop confidence and clear uuid to signal no match
             $data['activity_uuid'] = null;
             $data['confidence'] = 0;
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
