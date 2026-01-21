<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\UserGroup;

class UserGroupService
{
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

    private function formatGroup(UserGroup $group): array
    {
        $data = $group->toArray();
        $config = $this->quotaConfigService->decodeJsonToArray($data['config'] ?? null);
        $normalized = $config === null ? null : $this->quotaConfigService->normalizeQuotaConfig($config);
        $data['config'] = $normalized;
        $data['quota_flat'] = $this->quotaConfigService->flattenQuotas($normalized);
        return $data;
    }

    private function preparePayload(array $data, $currentConfig): array
    {
        $payload = $data;
        unset($payload['quota_flat']);

        $config = $this->quotaConfigService->decodeJsonToArray($data['config'] ?? null);
        $current = $this->quotaConfigService->decodeJsonToArray($currentConfig);

        if (isset($data['quota_flat']) && is_array($data['quota_flat'])) {
            $base = $config ?? $current ?? [];
            $config = $this->quotaConfigService->unflattenQuotas($data['quota_flat'], $base);
        }

        if ($config !== null) {
            $payload['config'] = $this->quotaConfigService->normalizeQuotaConfig($config);
        }

        return $payload;
    }
}
