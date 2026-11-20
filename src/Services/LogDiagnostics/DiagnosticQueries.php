<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

final class DiagnosticQueries
{
    private const MAX_PAGE = 50;
    private const SOURCES = [
        'system' => ['system_logs', 'created_at', ['method', 'path', 'status_code', 'duration_ms'], ['method', 'status_code']],
        'error' => ['error_logs', 'error_time', ['error_type', 'error_message', 'error_file', 'error_line'], ['error_type']],
        'audit' => ['audit_logs', 'created_at', ['action', 'operation_category', 'actor_type', 'status'], ['action', 'status']],
        'llm' => ['llm_logs', 'created_at', ['source', 'model', 'status', 'latency_ms', 'prompt_tokens', 'completion_tokens', 'total_tokens'], ['source', 'model', 'status']],
    ];

    private ?bool $isMaria = null;
    private ?string $serverVersion = null;

    public function __construct(
        private readonly Connection $db,
        private readonly DiagnosticConfig $config,
        private readonly DiagnosticProjection $projection,
        private readonly LegacyMysqlDiagnosticReader $legacyReader
    ) {
    }

    public function find(string $tool, object $grant, array $input, int $deadline): array
    {
        try {
            return $this->findInScope($tool, $grant, $input, $deadline);
        } finally {
            $this->legacyReader->close();
        }
    }

    private function findInScope(string $tool, object $grant, array $input, int $deadline): array
    {
        $permitted = explode(',', $grant->log_types);
        $from = array_key_exists('from', $input) ? DiagnosticConfig::timestamp($input['from'], 'from') : (int) $grant->log_from;
        $to = array_key_exists('to', $input) ? DiagnosticConfig::timestamp($input['to'], 'to') : (int) $grant->log_to;
        if ($from < (int) $grant->log_from || $to > (int) $grant->log_to) {
            throw DiagnosticProblem::scope();
        }
        if ($from >= $to) {
            throw DiagnosticProblem::invalid('The start time must precede the end time.');
        }

        $types = $tool === 'search_logs' ? DiagnosticAccess::types([$input['type'] ?? null])
            : (array_key_exists('types', $input) ? DiagnosticAccess::types($input['types']) : $permitted);
        if (array_diff($types, $permitted) !== []) {
            throw DiagnosticProblem::scope();
        }
        sort($types);
        $base = [
            'classification' => 'untrusted_log_data',
            'from' => DiagnosticConfig::iso($from),
            'to' => DiagnosticConfig::iso($to),
        ];

        if ($tool === 'get_log_summary') {
            self::only($input, ['types', 'from', 'to']);
            return $base + $this->summary($types, $from, $to, $deadline);
        }
        $limit = $input['limit'] ?? self::MAX_PAGE;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_PAGE) {
            throw DiagnosticProblem::invalid('limit must be an integer between 1 and 50.');
        }

        $criteria = [];
        $keyword = null;
        $anchor = null;
        $ascending = $tool === 'get_trace_context';
        if ($ascending) {
            self::only($input, ['types', 'type', 'id', 'from', 'to', 'request_id', 'limit', 'cursor']);
            $hasAnchor = array_key_exists('id', $input) || array_key_exists('type', $input);
            if ($hasAnchor === array_key_exists('request_id', $input)) {
                throw DiagnosticProblem::invalid('Supply either request_id or both type and id.');
            }
            if ($hasAnchor) {
                $anchorType = DiagnosticAccess::types([$input['type'] ?? null])[0];
                if (!in_array($anchorType, $permitted, true)) {
                    throw DiagnosticProblem::scope();
                }
                $id = $input['id'] ?? null;
                if (!is_int($id) || $id < 1) {
                    throw DiagnosticProblem::invalid('The anchor log ID must be a positive integer.');
                }
                $found = $this->read($this->rows($anchorType, $from, $to)->where('id', $id)->limit(1), $deadline)[0] ?? null;
                if ($found === null) {
                    if (array_key_exists('cursor', $input)) {
                        throw DiagnosticProblem::invalid('The anchor no longer supports pagination.');
                    }
                    return $base + $this->emptyPage('not_found');
                }
                $anchor = $this->projection->row($anchorType, $found);
                if ($anchor['request_id'] === null) {
                    if (array_key_exists('cursor', $input)) {
                        throw DiagnosticProblem::invalid('The anchor does not support pagination.');
                    }
                    $page = $this->emptyPage('unavailable');
                    if (in_array($anchorType, $types, true)) {
                        $page['items'] = [$anchor];
                        $page['log_count'] = 1;
                    }
                    return $base + $page;
                }
            }
            $criteria['request_id'] = $anchor['request_id'] ?? $this->requestId($input['request_id']);
        } else {
            $type = $types[0];
            $filters = self::SOURCES[$type][3];
            self::only($input, array_merge(['type', 'from', 'to', 'request_id', 'q', 'limit', 'cursor'], $filters));
            foreach ($filters as $key) {
                if (!array_key_exists($key, $input)) {
                    continue;
                }
                $value = $input[$key];
                if ($key === 'status_code' ? (!is_int($value) || $value < 100 || $value > 599)
                    : DiagnosticProjection::label($value) === null) {
                    throw DiagnosticProblem::invalid('Invalid ' . $key . ' filter.');
                }
                $criteria[$key] = $value;
            }
            if (array_key_exists('request_id', $input)) {
                $criteria['request_id'] = $this->requestId($input['request_id']);
            }
            if (array_key_exists('q', $input)) {
                $value = $input['q'];
                if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 200
                    || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                    throw DiagnosticProblem::invalid('The keyword must be plain text up to 200 characters.');
                }
                $keyword = trim($value);
                if ($keyword === '') {
                    $keyword = null;
                }
            }
        }

        $binding = hash('sha256', json_encode(
            [$tool, (int) $grant->id, $types, $from, $to, $criteria, $keyword, $anchor['id'] ?? null, $anchor['type'] ?? null],
            JSON_THROW_ON_ERROR
        ));
        $cursor = array_key_exists('cursor', $input) ? $this->decodeCursor($input['cursor'], $binding) : [];
        $page = $this->page($types, $from, $to, $criteria, $keyword, $limit, $cursor, $binding, $ascending, $deadline);
        if ($ascending) {
            $page['correlation_status'] = 'request_id';
        }
        return $base + $page;
    }

    private function summary(array $types, int $from, int $to, int $deadline): array
    {
        $result = ['counts_by_type' => [], 'log_count' => 0];
        foreach ($types as $type) {
            $scoped = $this->source($type, $from, $to);
            $count = (int) $this->read((clone $scoped)->selectRaw('COUNT(*) as n'), $deadline)[0]->n;
            $result['counts_by_type'][$type] = $count;
            $result['log_count'] += $count;
            if ($type === 'system') {
                $rows = $this->read((clone $scoped)->select('status_code')->selectRaw('COUNT(*) as n')
                    ->whereBetween('status_code', [100, 599])->groupBy('status_code')->orderBy('status_code'), $deadline);
                $result['http_statuses'] = array_map(
                    static fn (object $row): array => ['status_code' => (int) $row->status_code, 'count' => (int) $row->n],
                    $rows
                );
            }
            if ($type === 'error') {
                $result += $this->errorSummary($scoped, $from, $to, $deadline);
            }
        }

        return $result;
    }

    private function errorSummary(Builder $scoped, int $from, int $to, int $deadline): array
    {
        $groups = $this->read((clone $scoped)->select('error_type')
            ->selectRaw('COUNT(*) as n, MIN(error_time) as first_at, MAX(error_time) as last_at, MAX(id) as sample_id')
            ->groupBy('error_type'), $deadline);
        $errors = [];
        foreach ($groups as $group) {
            $type = DiagnosticProjection::label($group->error_type) ?? 'unknown';
            $first = $this->projection->iso('error', $group->first_at);
            $last = $this->projection->iso('error', $group->last_at);
            $item = $errors[$type] ?? ['count' => 0, 'first_at' => $first, 'last_at' => $last, 'sample_id' => 0];
            $errors[$type] = [
                'count' => $item['count'] + (int) $group->n,
                'first_at' => min($first, $item['first_at']),
                'last_at' => max($last, $item['last_at']),
                'sample_id' => max((int) $group->sample_id, $item['sample_id']),
            ];
        }
        uksort($errors, static fn (string $a, string $b): int => ($errors[$b]['count'] <=> $errors[$a]['count']) ?: strcmp($a, $b));
        $errors = array_slice($errors, 0, 10, true);
        $samples = [];
        if ($errors !== []) {
            $ids = array_column($errors, 'sample_id');
            foreach ($this->read($this->rows('error', $from, $to)->whereIn('id', $ids), $deadline) as $row) {
                $samples[(int) $row->id] = $this->projection->row('error', $row);
            }
        }
        $top = [];
        foreach ($errors as $type => $item) {
            $sample = $samples[$item['sample_id']] ?? null;
            unset($item['sample_id']);
            $top[] = ['error_type' => $type] + $item + ['example' => $sample];
        }

        $bucket = $to - $from <= 21600 ? 'minute' : ($to - $from <= 604800 ? 'hour' : 'day');
        [$sqlite, $mysql] = match ($bucket) {
            'minute' => ['%Y-%m-%d %H:%M:00', '%Y-%m-%d %H:%i:00'],
            'hour' => ['%Y-%m-%d %H:00:00', '%Y-%m-%d %H:00:00'],
            'day' => ['%Y-%m-%d 00:00:00', '%Y-%m-%d 00:00:00'],
        };
        $expression = $this->db->getDriverName() === 'sqlite'
            ? "strftime('$sqlite', error_time)" : "DATE_FORMAT(error_time, '$mysql')";
        $series = $this->read((clone $scoped)->selectRaw($expression . ' as bucket_time, COUNT(*) as n')
            ->groupBy('bucket_time')->orderBy('bucket_time'), $deadline);

        return [
            'top_errors' => $top,
            'error_bucket' => $bucket,
            'error_trend' => array_map(fn (object $row): array => [
                'time' => $this->projection->iso('error', $row->bucket_time),
                'count' => (int) $row->n,
            ], $series),
        ];
    }

    private function page(
        array $types,
        int $from,
        int $to,
        array $criteria,
        ?string $keyword,
        int $limit,
        array $cursor,
        string $binding,
        bool $ascending,
        int $deadline
    ): array {
        $direction = $ascending ? 'asc' : 'desc';
        $relation = $ascending ? '>' : '<';
        $positions = $cursor['positions'] ?? [];
        $ceilings = $cursor['ceilings'] ?? [];
        $items = [];
        $scanning = false;

        foreach ($types as $type) {
            $timeColumn = self::SOURCES[$type][1];
            $source = $this->rows($type, $from, $to)->where($criteria);
            if (!array_key_exists($type, $ceilings)) {
                $ceilings[$type] = (int) ($this->read($this->source($type, $from, $to)
                    ->where($criteria)->selectRaw('MAX(id) as max_id'), $deadline)[0]->max_id ?? 0);
            }
            $source->where('id', '<=', $ceilings[$type])->orderBy($timeColumn, $direction)->orderBy('id', $direction);
            $position = $positions[$type] ?? null;
            $readMore = true;
            $collected = 0;
            while ($readMore && $collected <= $limit) {
                $query = clone $source;
                if ($position !== null) {
                    [$lastTime, $lastId] = $position;
                    $query->where(function (Builder $pivot) use ($timeColumn, $relation, $lastTime, $lastId): void {
                        $pivot->where($timeColumn, $relation, $lastTime)->orWhere(
                            fn (Builder $same): Builder => $same->where($timeColumn, $lastTime)->where('id', $relation, $lastId)
                        );
                    });
                }
                $batchSize = $keyword === null ? $limit + 1 : 64;
                $rows = $this->read($query->limit($batchSize), $deadline);
                foreach ($rows as $row) {
                    $position = [$row->occurred_at, (int) $row->id];
                    $item = $this->projection->row($type, $row);
                    if ($keyword !== null && !$this->matches($item, $keyword)) {
                        $positions[$type] = $position;
                        continue;
                    }
                    $items[] = ['value' => $item, 'position' => $position];
                    $collected++;
                    if ($collected > $limit) {
                        break;
                    }
                }
                $readMore = $keyword !== null && count($rows) === $batchSize;
                if ($readMore && $deadline - hrtime(true) < 100000000) {
                    $scanning = true;
                    break;
                }
            }
        }

        usort($items, static function (array $a, array $b) use ($ascending): int {
            $left = $a['value'];
            $right = $b['value'];
            $comparison = [$left['occurred_at'], $left['type'], $left['id']] <=> [$right['occurred_at'], $right['type'], $right['id']];
            return $ascending ? $comparison : -$comparison;
        });
        $hasMore = $scanning || count($items) > $limit;
        $rows = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $row = $item['value'];
            $positions[$row['type']] = $item['position'];
            $rows[] = $row;
        }
        return [
            'items' => $rows,
            'log_count' => count($rows),
            'has_more' => $hasMore,
            'next_cursor' => $hasMore ? $this->encodeCursor($binding, $ceilings, $positions) : null,
        ];
    }

    private function emptyPage(string $correlation): array
    {
        return ['items' => [], 'log_count' => 0, 'has_more' => false, 'next_cursor' => null, 'correlation_status' => $correlation];
    }

    private function source(string $type, int $from, int $to): Builder
    {
        [$table, $time] = self::SOURCES[$type];
        $query = $this->db->table($table)->useWritePdo()
            ->where($time, '>=', $this->projection->timestamp($type, $from))
            ->where($time, '<', $this->projection->timestamp($type, $to));
        if ($type === 'audit') {
            $query->where(fn (Builder $q): Builder => $q->whereNull('operation_category')
                ->orWhere('operation_category', '<>', DiagnosticConfig::AUDIT_CATEGORY));
        }
        return $query;
    }

    private function rows(string $type, int $from, int $to): Builder
    {
        [, $time, $fields] = self::SOURCES[$type];
        return $this->source($type, $from, $to)->select(array_merge(['id', 'request_id', $time . ' as occurred_at'], $fields));
    }

    private function read(Builder $query, int $deadline): array
    {
        $remaining = (int) floor(($deadline - hrtime(true)) / 1000000);
        if ($remaining < 1) {
            throw DiagnosticProblem::queryTimeout();
        }
        $sql = $query->toSql();
        if ($this->db instanceof MySqlConnection) {
            if ($this->isMaria ??= $this->db->isMaria()) {
                $seconds = number_format($remaining / 1000, 3, '.', '');
                $sql = 'SET STATEMENT max_statement_time=' . $seconds . ' FOR ' . $sql;
            } elseif (version_compare($this->serverVersion ??= $this->db->getServerVersion(), '5.7.8', '>=')) {
                $sql = 'select /*+ MAX_EXECUTION_TIME(' . $remaining . ') */ ' . substr($sql, 7);
            } else {
                // Materialize the rows before sending them so the client can poll the execution.
                return $this->legacyReader->select(
                    'select SQL_BUFFER_RESULT ' . substr($sql, 7),
                    $query->getBindings(),
                    $deadline
                );
            }
        }
        try {
            return $this->db->select($sql, $query->getBindings(), false);
        } catch (QueryException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), [1969, 3024], true)) {
                throw DiagnosticProblem::queryTimeout();
            }
            throw $e;
        }
    }

    private function requestId(mixed $value): string
    {
        return DiagnosticProjection::requestId($value) ?? throw DiagnosticProblem::invalid('request_id must be a UUID.');
    }

    private static function only(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields) !== []) {
            throw DiagnosticProblem::invalid('The request contains unrecognized fields.');
        }
    }

    private function matches(array $item, string $keyword): bool
    {
        foreach ($item as $value) {
            if (is_scalar($value) && mb_stripos((string) $value, $keyword, 0, 'UTF-8') !== false) {
                return true;
            }
        }
        return false;
    }

    private function encodeCursor(string $binding, array $ceilings, array $positions): string
    {
        $data = DiagnosticConfig::base64Url(json_encode(compact('binding', 'ceilings', 'positions'), JSON_THROW_ON_ERROR));
        return $data . '.' . hash_hmac('sha256', $data, $this->config->cursorKey);
    }

    private function decodeCursor(mixed $value, string $binding): array
    {
        if (!is_string($value) || strlen($value) > 4096 || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $value, $parts) !== 1
            || !hash_equals(hash_hmac('sha256', $parts[1], $this->config->cursorKey), $parts[2])) {
            throw DiagnosticProblem::invalid('Invalid diagnostic cursor.');
        }
        $data = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
        if (!is_array($data) || ($data['binding'] ?? null) !== $binding
            || !is_array($data['ceilings'] ?? null) || !is_array($data['positions'] ?? null)) {
            throw DiagnosticProblem::invalid('The cursor belongs to a different diagnostic query.');
        }
        return $data;
    }
}
