<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\UserGroup;
use CarbonTrack\Support\InputValueNormalizer;

class UserGroupService
{
    private const SUPPORT_ROUTING_FIELDS = [
        [
            'key' => 'first_response_minutes',
            'type' => 'number',
            'min' => 1,
            'step' => 1,
            'default' => 240,
            'label_key' => 'admin.groups.supportFirstResponseMinutes',
        ],
        [
            'key' => 'resolution_minutes',
            'type' => 'number',
            'min' => 1,
            'step' => 1,
            'default' => 1440,
            'label_key' => 'admin.groups.supportResolutionMinutes',
        ],
        [
            'key' => 'routing_weight',
            'type' => 'number',
            'min' => 0.1,
            'step' => 0.1,
            'default' => 1,
            'label_key' => 'admin.groups.supportRoutingWeight',
        ],
        [
            'key' => 'min_agent_level',
            'type' => 'number',
            'min' => 1,
            'max' => 5,
            'step' => 1,
            'default' => 1,
            'label_key' => 'admin.groups.supportMinAgentLevel',
        ],
        [
            'key' => 'overdue_boost',
            'type' => 'number',
            'min' => 0,
            'step' => 0.1,
            'default' => 1,
            'label_key' => 'admin.groups.supportOverdueBoost',
        ],
        [
            'key' => 'tier_label',
            'type' => 'text',
            'default' => 'standard',
            'label_key' => 'admin.groups.supportTierLabel',
        ],
    ];

    public function __construct(
        private QuotaConfigService $quotaConfigService
    ) {}

    public function getAllGroups()
    {
        return UserGroup::orderBy('id', 'asc')
            ->get()
            ->map(fn (UserGroup $group) => $this->formatGroup($group))
            ->values()
            ->all();
    }

    public function getGroupById(int $id)
    {
        $group = UserGroup::find($id);
        return $group ? $this->formatGroup($group) : null;
    }

    public function createGroup(array $data)
    {
        $payload = $this->preparePayload($data, null);
        $group = UserGroup::create($payload);
        return $this->formatGroup($group);
    }

    public function updateGroup(int $id, array $data)
    {
        $group = UserGroup::findOrFail($id);
        $payload = $this->preparePayload($data, $group->config);
        $group->update($payload);
        return $this->formatGroup($group->fresh());
    }

    public function deleteGroup(int $id)
    {
        $group = UserGroup::findOrFail($id);
        $group->delete();
    }

    public function getQuotaDefinitions(): array
    {
        return $this->quotaConfigService->getQuotaDefinitions();
    }

    public function getSupportRoutingFieldDefinitions(): array
    {
        return self::SUPPORT_ROUTING_FIELDS;
    }

    public function getSupportRoutingDefaults(): array
    {
        $defaults = [];
        foreach (self::SUPPORT_ROUTING_FIELDS as $field) {
            $defaults[$field['key']] = $field['default'] ?? null;
        }
        return $defaults;
    }

    private function formatGroup(UserGroup $group): array
    {
        $data = $group->toArray();
        $config = $this->quotaConfigService->decodeJsonToArray($data['config'] ?? null);
        $normalized = $config === null ? null : $this->quotaConfigService->normalizeQuotaConfig($config);
        $data['config'] = $normalized;
        $quotaConfig = is_array($normalized) ? $normalized : [];
        unset($quotaConfig['support_routing']);
        $data['quota_flat'] = $this->quotaConfigService->flattenQuotas($quotaConfig);
        $data['support_routing'] = $this->normalizeSupportRouting($normalized['support_routing'] ?? null);
        return $data;
    }

    private function preparePayload(array $data, $currentConfig): array
    {
        $payload = $data;
        unset($payload['quota_flat']);
        unset($payload['support_routing']);

        if (array_key_exists('is_default', $payload)) {
            $payload['is_default'] = $this->normalizeBooleanValue($payload['is_default']);
        }

        $config = $this->quotaConfigService->decodeJsonToArray($data['config'] ?? null);
        $current = $this->quotaConfigService->decodeJsonToArray($currentConfig);

        if (isset($data['quota_flat']) && is_array($data['quota_flat'])) {
            $base = $config ?? $current ?? [];
            $config = $this->quotaConfigService->unflattenQuotas($data['quota_flat'], $base);
        }

        if (array_key_exists('support_routing', $data)) {
            $base = $config ?? $current ?? [];
            $base['support_routing'] = $this->normalizeSupportRouting($data['support_routing']);
            $config = $base;
        }

        if ($config !== null) {
            $payload['config'] = $this->quotaConfigService->normalizeQuotaConfig($config);
        }

        return $payload;
    }

    private function normalizeBooleanValue(mixed $value, bool $default = false): bool
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || strtolower($trimmed) === 'indeterminate') {
                return $default;
            }
        }

        return InputValueNormalizer::boolean($value, 'is_default', $default);
    }

    private function normalizeSupportRouting(mixed $value): array
    {
        $routing = is_array($value) ? $value : [];

        return [
            'first_response_minutes' => max(1, (int) ($routing['first_response_minutes'] ?? 240)),
            'resolution_minutes' => max(1, (int) ($routing['resolution_minutes'] ?? 1440)),
            'routing_weight' => max(0.1, (float) ($routing['routing_weight'] ?? 1)),
            'min_agent_level' => max(1, min(5, (int) ($routing['min_agent_level'] ?? 1))),
            'overdue_boost' => max(0.0, (float) ($routing['overdue_boost'] ?? 1)),
            'tier_label' => trim((string) ($routing['tier_label'] ?? 'standard')) ?: 'standard',
        ];
    }
}
