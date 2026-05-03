<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class AdminAiRollbackService
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $result
     * @return array<string,mixed>|null
     */
    public function buildDescriptor(int $proposalId, string $actionName, array $payload, array $result): ?array
    {
        $rollbackAction = null;
        $rollbackPayload = null;

        switch ($actionName) {
            case 'adjust_user_points':
                $delta = isset($result['delta']) && is_numeric((string) $result['delta'])
                    ? (float) $result['delta']
                    : (isset($payload['delta']) && is_numeric((string) $payload['delta']) ? (float) $payload['delta'] : null);
                $user = is_array($result['user'] ?? null) ? $result['user'] : [];
                if ($delta !== null && abs($delta) >= 0.00001) {
                    $rollbackAction = 'adjust_user_points';
                    $rollbackPayload = array_filter([
                        'user_id' => $user['id'] ?? ($payload['user_id'] ?? null),
                        'user_uuid' => $user['uuid'] ?? ($payload['user_uuid'] ?? null),
                        'delta' => -1 * $delta,
                        'reason' => $this->buildRollbackReason($proposalId),
                    ], static fn ($value) => $value !== null && $value !== '');
                }
                break;

            case 'update_user_status':
                if (!empty($result['old_status'])) {
                    $user = is_array($result['user'] ?? null) ? $result['user'] : [];
                    $rollbackAction = 'update_user_status';
                    $rollbackPayload = array_filter([
                        'user_id' => $user['id'] ?? ($payload['user_id'] ?? null),
                        'user_uuid' => $user['uuid'] ?? ($payload['user_uuid'] ?? null),
                        'status' => $result['old_status'],
                        'admin_notes' => $this->buildRollbackReason($proposalId),
                    ], static fn ($value) => $value !== null && $value !== '');
                }
                break;

            case 'update_product_status':
                $product = is_array($result['product'] ?? null) ? $result['product'] : [];
                $oldStatus = $result['old_status'] ?? null;
                if ($oldStatus === null && isset($payload['previous_status'])) {
                    $oldStatus = $payload['previous_status'];
                }
                if ($oldStatus !== null && $oldStatus !== '') {
                    $rollbackAction = 'update_product_status';
                    $rollbackPayload = array_filter([
                        'product_id' => $product['id'] ?? ($payload['product_id'] ?? null),
                        'status' => $oldStatus,
                    ], static fn ($value) => $value !== null && $value !== '');
                }
                break;

            case 'adjust_product_inventory':
                $product = is_array($result['product'] ?? null) ? $result['product'] : [];
                if (isset($result['old_stock']) && is_numeric((string) $result['old_stock'])) {
                    $rollbackAction = 'adjust_product_inventory';
                    $rollbackPayload = array_filter([
                        'product_id' => $product['id'] ?? ($payload['product_id'] ?? null),
                        'target_stock' => (int) $result['old_stock'],
                        'reason' => $this->buildRollbackReason($proposalId),
                    ], static fn ($value) => $value !== null && $value !== '');
                }
                break;

            default:
                return null;
        }

        if ($rollbackAction === null || !is_array($rollbackPayload) || $rollbackPayload === []) {
            return null;
        }

        $promptZh = $proposalId > 0
            ? sprintf('回滚刚才的 %s 操作，原提案 #%d。', $actionName, $proposalId)
            : sprintf('回滚刚才自动执行的 %s 操作。', $actionName);
        $promptEn = $proposalId > 0
            ? sprintf('Rollback the previous %s action from proposal #%d.', $actionName, $proposalId)
            : sprintf('Rollback the auto-executed %s action.', $actionName);

        return [
            'source_proposal_id' => $proposalId,
            'source_action' => $actionName,
            'action_name' => $rollbackAction,
            'payload' => $rollbackPayload,
            'requires_confirmation' => true,
            'mode' => 'approval_proposal',
            'prompt' => $promptEn,
            'prompt_i18n' => [
                'zh' => $promptZh,
                'en' => $promptEn,
            ],
        ];
    }

    private function buildRollbackReason(int $proposalId): string
    {
        return $proposalId > 0
            ? sprintf('Rollback admin AI proposal #%d', $proposalId)
            : 'Rollback auto-executed admin AI action';
    }

    /**
     * @param array<string,mixed> $descriptor
     * @return array{action_name:string,payload:array<string,mixed>,source_proposal_id:int|null,source_action:string|null,prompt:string|null,prompt_i18n:array<string,string>}|null
     */
    public function normalizeDescriptor(array $descriptor): ?array
    {
        $actionName = isset($descriptor['action_name']) ? trim((string) $descriptor['action_name']) : '';
        $payload = isset($descriptor['payload']) && is_array($descriptor['payload']) ? $descriptor['payload'] : [];

        if ($actionName === '' || $payload === []) {
            return null;
        }

        return [
            'action_name' => $actionName,
            'payload' => $payload,
            'source_proposal_id' => isset($descriptor['source_proposal_id']) && is_numeric((string) $descriptor['source_proposal_id'])
                ? (int) $descriptor['source_proposal_id']
                : null,
            'source_action' => isset($descriptor['source_action']) && is_string($descriptor['source_action'])
                ? trim($descriptor['source_action'])
                : null,
            'prompt' => isset($descriptor['prompt']) && is_string($descriptor['prompt'])
                ? trim($descriptor['prompt'])
                : null,
            'prompt_i18n' => isset($descriptor['prompt_i18n']) && is_array($descriptor['prompt_i18n'])
                ? array_filter(
                    array_map(static fn ($value): string => is_string($value) ? trim($value) : '', $descriptor['prompt_i18n']),
                    static fn (string $value): bool => $value !== ''
                )
                : [],
        ];
    }
}
