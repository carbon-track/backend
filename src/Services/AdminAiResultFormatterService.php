<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class AdminAiResultFormatterService
{
    /**
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $payload
     */
    public function buildProposalSummary(array $definition, array $payload): string
    {
        $label = (string) ($definition['label'] ?? $definition['name'] ?? '后台操作');
        $recordIds = isset($payload['record_ids']) && is_array($payload['record_ids']) ? array_values($payload['record_ids']) : [];
        $segments = [$label];

        if ($recordIds !== []) {
            $segments[] = sprintf('记录 %s', implode(', ', array_map(static fn ($item) => (string) $item, $recordIds)));
        }
        if (!empty($payload['user_id'])) {
            $segments[] = sprintf('用户 #%s', (string) $payload['user_id']);
        } elseif (!empty($payload['user_uuid'])) {
            $segments[] = sprintf('用户 UUID %s', (string) $payload['user_uuid']);
        }
        if (!empty($payload['exchange_id'])) {
            $segments[] = sprintf('兑换单 %s', (string) $payload['exchange_id']);
        }
        if (!empty($payload['badge_id'])) {
            $segments[] = sprintf('徽章 #%s', (string) $payload['badge_id']);
        }
        if (!empty($payload['product_id'])) {
            $segments[] = sprintf('商品 #%s', (string) $payload['product_id']);
        }
        if (!empty($payload['username'])) {
            $segments[] = sprintf('用户名 %s', (string) $payload['username']);
        }
        if (!empty($payload['email'])) {
            $segments[] = sprintf('邮箱 %s', (string) $payload['email']);
        }
        if (!empty($payload['region_code'])) {
            $segments[] = sprintf('地区 %s', (string) $payload['region_code']);
        }
        if (isset($payload['delta']) && is_numeric((string) $payload['delta'])) {
            $segments[] = sprintf('积分变动 %s', (string) $payload['delta']);
        }
        if (isset($payload['stock_delta']) && is_numeric((string) $payload['stock_delta'])) {
            $segments[] = sprintf('库存增量 %s', (string) $payload['stock_delta']);
        }
        if (isset($payload['target_stock']) && is_numeric((string) $payload['target_stock'])) {
            $segments[] = sprintf('目标库存 %s', (string) $payload['target_stock']);
        }
        if (!empty($payload['status'])) {
            $segments[] = sprintf('状态 %s', (string) $payload['status']);
        }
        if (!empty($payload['review_note'])) {
            $segments[] = sprintf('备注：%s', trim((string) $payload['review_note']));
        }
        if (!empty($payload['notes'])) {
            $segments[] = sprintf('备注：%s', trim((string) $payload['notes']));
        }
        if (!empty($payload['reason'])) {
            $segments[] = sprintf('原因：%s', trim((string) $payload['reason']));
        }
        if (!empty($payload['admin_notes'])) {
            $segments[] = sprintf('管理员备注：%s', trim((string) $payload['admin_notes']));
        }
        if (!empty($payload['tracking_number'])) {
            $segments[] = sprintf('物流单号：%s', trim((string) $payload['tracking_number']));
        }
        if (!empty($payload['days'])) {
            $segments[] = sprintf('范围 %d 天', (int) $payload['days']);
        }

        return implode('；', $segments);
    }

    /**
     * @param array<string,mixed> $result
     */
    public function formatReadActionResult(string $actionName, array $result): string
    {
        return match ($actionName) {
            'get_admin_stats' => sprintf(
                '后台总览：用户 %s，待审核记录 %s，累计减排 %s kg。',
                $this->safeReadValue($result, ['data', 'user_count'], '0'),
                $this->safeReadValue($result, ['data', 'pending_records'], '0'),
                $this->safeReadValue($result, ['data', 'total_carbon_saved'], '0')
            ),
            'get_pending_carbon_records' => sprintf(
                '当前共有 %d 条待处理记录。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeRecordList((array) ($result['items'] ?? []))
            ),
            'get_llm_usage_analytics' => sprintf(
                '近 %d 天 LLM 调用 %d 次，共 %d tokens，主要模型为 %s。',
                (int) ($result['days'] ?? 0),
                (int) ($result['total_calls'] ?? 0),
                (int) ($result['total_tokens'] ?? 0),
                (string) ($result['top_model'] ?? '未知')
            ),
            'get_activity_statistics' => $this->summarizeActivityStats((array) ($result['items'] ?? [])),
            'generate_admin_report' => sprintf(
                '已生成 %d 天管理摘要：待处理记录 %d 条，LLM 调用 %d 次。',
                (int) ($result['days'] ?? 0),
                (int) ($result['pending']['total'] ?? 0),
                (int) ($result['llm']['total_calls'] ?? 0)
            ),
            'search_users' => sprintf(
                '匹配到 %d 位用户。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeUserList((array) ($result['items'] ?? []))
            ),
            'get_user_overview' => sprintf(
                '用户 %s：状态 %s，积分 %d，累计减排 %.2f kg，Passkey %d 个。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['user']['status'] ?? 'unknown'),
                (int) ($result['user']['points'] ?? 0),
                (float) ($result['metrics']['total_carbon_saved'] ?? 0),
                (int) ($result['metrics']['passkey_count'] ?? 0)
            ),
            'get_exchange_orders' => sprintf(
                '匹配到 %d 条兑换单。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeExchangeList((array) ($result['items'] ?? []))
            ),
            'get_exchange_order_detail' => sprintf(
                '兑换单 %s：用户 %s，商品 %s，状态 %s，积分 %s。',
                (string) ($result['exchange']['id'] ?? '-'),
                (string) ($result['exchange']['username'] ?? '未知用户'),
                (string) ($result['exchange']['product_name'] ?? '未知商品'),
                (string) ($result['exchange']['status'] ?? 'unknown'),
                (string) ($result['exchange']['points_used'] ?? '0')
            ),
            'get_product_catalog' => sprintf(
                '商品列表共匹配 %d 项。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeProductList((array) ($result['items'] ?? []))
            ),
            'get_passkey_admin_stats' => sprintf(
                '当前共有 %d 个 Passkey，覆盖 %d 位用户，近 30 天活跃 %d 个。',
                (int) ($result['total_passkeys'] ?? 0),
                (int) ($result['users_with_passkeys'] ?? 0),
                (int) ($result['used_recently_30d'] ?? 0)
            ),
            'get_passkey_admin_list' => sprintf(
                '匹配到 %d 个 Passkey。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizePasskeyList((array) ($result['items'] ?? []))
            ),
            'search_system_logs' => sprintf(
                '日志检索返回 %d 条结果。%s',
                (int) ($result['returned_count'] ?? 0),
                $this->summarizeLogList((array) ($result['items'] ?? []))
            ),
            'get_broadcast_history' => sprintf(
                '广播历史共 %d 条。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeBroadcastList((array) ($result['items'] ?? []))
            ),
            'search_broadcast_recipients' => sprintf(
                '匹配到 %d 位候选接收人。%s',
                (int) ($result['total'] ?? 0),
                $this->summarizeUserList((array) ($result['items'] ?? []))
            ),
            default => '已完成查询。'
        };
    }

    /**
     * @param array<string,mixed> $result
     */
    public function formatWriteActionResult(string $actionName, array $result): string
    {
        return match ($actionName) {
            'approve_carbon_records' => sprintf(
                '已批准 %d 条记录。%s',
                (int) ($result['processed_count'] ?? 0),
                $this->formatSkippedSummary((array) ($result['skipped'] ?? []))
            ),
            'reject_carbon_records' => sprintf(
                '已驳回 %d 条记录。%s',
                (int) ($result['processed_count'] ?? 0),
                $this->formatSkippedSummary((array) ($result['skipped'] ?? []))
            ),
            'adjust_user_points' => sprintf(
                '已为用户 %s 调整积分 %s，当前积分 %d。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['delta'] ?? '0'),
                (int) ($result['new_points'] ?? 0)
            ),
            'create_user' => sprintf(
                '已创建用户 %s（%s），状态 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['user']['email'] ?? '-'),
                (string) ($result['user']['status'] ?? 'unknown')
            ),
            'update_user_status' => sprintf(
                '已将用户 %s 的状态更新为 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['new_status'] ?? 'unknown')
            ),
            'award_badge_to_user' => sprintf(
                '已向用户 %s 发放徽章 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['badge']['name'] ?? '未命名徽章')
            ),
            'revoke_badge_from_user' => sprintf(
                '已撤销用户 %s 的徽章 %s。',
                (string) ($result['user']['username'] ?? '未知用户'),
                (string) ($result['badge']['name'] ?? '未命名徽章')
            ),
            'update_exchange_status' => sprintf(
                '兑换单 %s 已更新为 %s。',
                (string) ($result['exchange']['id'] ?? '-'),
                (string) ($result['exchange']['status'] ?? 'unknown')
            ),
            'update_product_status' => sprintf(
                '商品 %s 已更新为 %s。',
                (string) ($result['product']['name'] ?? '未命名商品'),
                (string) ($result['product']['status'] ?? 'unknown')
            ),
            'adjust_product_inventory' => sprintf(
                '商品 %s 库存已从 %d 调整到 %d。',
                (string) ($result['product']['name'] ?? '未命名商品'),
                (int) ($result['old_stock'] ?? 0),
                (int) ($result['new_stock'] ?? 0)
            ),
            default => '操作已执行。'
        };
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeRecordList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配记录。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s %skg',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['carbon_saved'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . (count($items) > 3 ? '。' : '。');
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeActivityStats(array $items): string
    {
        if ($items === []) {
            return '当前没有可汇总的活动统计数据。';
        }

        $top = $items[0];
        return sprintf(
            '活动统计已整理，当前领先项为“%s”，通过 %d 条，待处理 %d 条。',
            (string) ($top['activity_name'] ?? '未命名活动'),
            (int) ($top['approved_count'] ?? 0),
            (int) ($top['pending_count'] ?? 0)
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeUserList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配用户。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（%s，积分 %s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['status'] ?? 'unknown'),
                (string) ($item['points'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeExchangeList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配兑换单。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s / %s / %s',
                (string) ($item['id'] ?? '-'),
                (string) ($item['username'] ?? '未知用户'),
                (string) ($item['product_name'] ?? '未知商品'),
                (string) ($item['status'] ?? 'unknown')
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeProductList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配商品。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（%s 积分，库存 %s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['name'] ?? '未命名商品'),
                (string) ($item['points_required'] ?? 0),
                (string) ($item['stock'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizePasskeyList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配 Passkey。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s / sign_count=%s',
                (string) ($item['id'] ?? '-'),
                (string) (($item['username'] ?? null) ?: ($item['user_uuid'] ?? '未知用户')),
                (string) ($item['sign_count'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeLogList(array $items): string
    {
        if ($items === []) {
            return '当前没有匹配日志。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '[%s] %s',
                (string) ($item['type'] ?? 'log'),
                (string) ($item['summary'] ?? '无摘要')
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function summarizeBroadcastList(array $items): string
    {
        if ($items === []) {
            return '当前没有广播历史。';
        }

        $parts = [];
        foreach (array_slice($items, 0, 3) as $item) {
            $parts[] = sprintf(
                '#%s %s（发送 %s/%s）',
                (string) ($item['id'] ?? '-'),
                (string) ($item['title'] ?? '未命名广播'),
                (string) ($item['sent_count'] ?? 0),
                (string) ($item['target_count'] ?? 0)
            );
        }

        return '示例：' . implode('；', $parts) . '。';
    }

    /**
     * @param array<string,mixed> $result
     * @param array<int,string> $path
     */
    private function safeReadValue(array $result, array $path, string $fallback): string
    {
        $cursor = $result;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $fallback;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor === null || $cursor === '' ? $fallback : (string) $cursor;
    }

    /**
     * @param array<int,array<string,mixed>> $skipped
     */
    private function formatSkippedSummary(array $skipped): string
    {
        if ($skipped === []) {
            return '没有跳过项。';
        }

        $parts = [];
        foreach (array_slice($skipped, 0, 3) as $item) {
            $parts[] = sprintf('#%s %s', (string) ($item['id'] ?? '-'), (string) ($item['reason'] ?? 'skipped'));
        }

        return '跳过：' . implode('；', $parts) . (count($skipped) > 3 ? ' 等。' : '。');
    }
}
