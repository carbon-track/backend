<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use CarbonTrack\Services\DatabaseService;
use CarbonTrack\Support\DatabaseRows;
use Illuminate\Database\Connection;


class UserPasskey
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveByUserUuid(string $userUuid): array
    {

        $rows = DatabaseRows::all($this->db->select('SELECT id, user_uuid, credential_id, label, rp_id, user_handle, transports, aaguid, sign_count,
                    last_used_at, attested_at, credential_type, attestation_format, backup_eligible, backup_state,
                    created_at, updated_at
             FROM user_passkeys
             WHERE user_uuid = :user_uuid AND disabled_at IS NULL
             ORDER BY created_at ASC, id ASC', ['user_uuid' => strtolower($userUuid)])) ?: [];
        return array_map([$this, 'hydratePasskeyRow'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCredentialId(string $credentialId): ?array
    {

        $row = DatabaseRows::one($this->db->selectOne('SELECT * FROM user_passkeys
             WHERE credential_id_hash = :credential_id_hash AND disabled_at IS NULL
             LIMIT 1', [
            'credential_id_hash' => hash('sha256', $credentialId),
        ]));
        if (!$row) {
            return null;
        }

        return $this->hydratePasskeyRow($row);
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function create(array $record): array
    {
        $now = gmdate('Y-m-d H:i:s');


        $id = DatabaseService::insertGetId($this->db, 'INSERT INTO user_passkeys (
                user_uuid, credential_id, credential_id_hash, credential_type, label, public_key, rp_id, user_handle,
                transports, aaguid, sign_count, attestation_format, backup_eligible, backup_state, meta_json,
                last_used_at, attested_at, created_at, updated_at
            ) VALUES (
                :user_uuid, :credential_id, :credential_id_hash, :credential_type, :label, :public_key, :rp_id, :user_handle,
                :transports, :aaguid, :sign_count, :attestation_format, :backup_eligible, :backup_state, :meta_json,
                :last_used_at, :attested_at, :created_at, :updated_at
            )', [
            'user_uuid' => strtolower((string) $record['user_uuid']),
            'credential_id' => (string) $record['credential_id'],
            'credential_id_hash' => hash('sha256', (string) $record['credential_id']),
            'credential_type' => (string) ($record['credential_type'] ?? 'public-key'),
            'label' => $record['label'] ?? null,
            'public_key' => (string) $record['public_key'],
            'rp_id' => (string) $record['rp_id'],
            'user_handle' => (string) $record['user_handle'],
            'transports' => $this->encodeJson($record['transports'] ?? []),
            'aaguid' => $record['aaguid'] ?? null,
            'sign_count' => (int) ($record['sign_count'] ?? 0),
            'attestation_format' => $record['attestation_format'] ?? null,
            'backup_eligible' => !empty($record['backup_eligible']) ? 1 : 0,
            'backup_state' => !empty($record['backup_state']) ? 1 : 0,
            'meta_json' => $this->encodeJson($record['meta'] ?? null),
            'last_used_at' => $record['last_used_at'] ?? null,
            'attested_at' => $record['attested_at'] ?? $now,
            'created_at' => $record['created_at'] ?? $now,
            'updated_at' => $record['updated_at'] ?? $now,
        ]);
        $created = $this->findById($id);
        return $created ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByIdForUserUuid(int $passkeyId, string $userUuid): ?array
    {

        $row = DatabaseRows::one($this->db->selectOne('SELECT * FROM user_passkeys
             WHERE id = :id AND user_uuid = :user_uuid AND disabled_at IS NULL
             LIMIT 1', [
            'id' => $passkeyId,
            'user_uuid' => strtolower($userUuid),
        ]));
        if (!$row) {
            return null;
        }

        return $this->hydratePasskeyRow($row);
    }

    public function touchAuthentication(int $passkeyId, int $signCount, bool $backupState, ?string $lastUsedAt = null): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->db->statement('UPDATE user_passkeys
             SET sign_count = :sign_count,
                 backup_state = :backup_state,
                 last_used_at = :last_used_at,
                 updated_at = :updated_at
             WHERE id = :id AND disabled_at IS NULL', [
            'sign_count' => $signCount,
            'backup_state' => $backupState ? 1 : 0,
            'last_used_at' => $lastUsedAt ?? $now,
            'updated_at' => $now,
            'id' => $passkeyId,
        ]);
    }

    public function disable(int $passkeyId, string $userUuid): bool
    {

        $now = gmdate('Y-m-d H:i:s');

        return $this->db->affectingStatement('UPDATE user_passkeys
             SET disabled_at = :disabled_at, updated_at = :updated_at
             WHERE id = :id AND user_uuid = :user_uuid AND disabled_at IS NULL', [
            'disabled_at' => $now,
            'updated_at' => $now,
            'id' => $passkeyId,
            'user_uuid' => strtolower($userUuid),
        ]) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateLabel(int $passkeyId, string $userUuid, ?string $label): ?array
    {

        $now = gmdate('Y-m-d H:i:s');
        $queryAffected = $this->db->affectingStatement('UPDATE user_passkeys
             SET label = :label, updated_at = :updated_at
             WHERE id = :id AND user_uuid = :user_uuid AND disabled_at IS NULL', [
            'label' => $label,
            'updated_at' => $now,
            'id' => $passkeyId,
            'user_uuid' => strtolower($userUuid),
        ]);

        if ($queryAffected === 0) {
            $existing = $this->findActiveByIdForUserUuid($passkeyId, $userUuid);
            if ($existing === null) {
                return null;
            }
        }

        return $this->findActiveByIdForUserUuid($passkeyId, $userUuid);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function listAdminPasskeys(
        string $search = '',
        int $limit = 20,
        int $offset = 0,
        string $sort = 'created_at_desc'
    ): array {
        $sortMap = [
            'created_at_desc' => 'up.created_at DESC, up.id DESC',
            'last_used_at_desc' => 'CASE WHEN up.last_used_at IS NULL THEN 1 ELSE 0 END ASC, up.last_used_at DESC, up.id DESC',
            'sign_count_desc' => 'up.sign_count DESC, up.id DESC',
        ];
        $orderBy = $sortMap[$sort] ?? $sortMap['created_at_desc'];

        $where = ['up.disabled_at IS NULL', 'u.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE :search_username OR u.email LIKE :search_email OR up.label LIKE :search_label OR u.uuid LIKE :search_uuid)';
            $params['search_username'] = '%' . $search . '%';
            $params['search_email'] = '%' . $search . '%';
            $params['search_label'] = '%' . $search . '%';
            $params['search_uuid'] = '%' . $search . '%';
        }
        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                up.id,
                up.user_uuid,
                up.label,
                up.sign_count,
                up.last_used_at,
                up.attested_at,
                up.backup_eligible,
                up.backup_state,
                up.created_at,
                up.updated_at,
                u.id AS user_id,
                u.username,
                u.email,
                s.name AS school_name
            FROM user_passkeys up
            INNER JOIN users u ON u.uuid = up.user_uuid
            LEFT JOIN schools s ON s.id = u.school_id
            WHERE {$whereSql}
            ORDER BY {$orderBy}
            LIMIT :limit OFFSET :offset
        ";
        $queryBindings = [];
        foreach ($params as $key => $value) {
            $queryBindings[':' . $key] = $value;
        }
        $queryBindings[':limit'] = (int) $limit;
        $queryBindings[':offset'] = (int) $offset;
        $items = DatabaseRows::all($this->db->select($sql, $queryBindings)) ?: [];

        $countSql = "
            SELECT COUNT(*)
            FROM user_passkeys up
            INNER JOIN users u ON u.uuid = up.user_uuid
            WHERE {$whereSql}
        ";
        $countBindings = [];
        foreach ($params as $key => $value) {
            $countBindings[':' . $key] = $value;
        }
        $total = (int) DatabaseRows::scalar($this->db->selectOne($countSql, $countBindings));

        return [
            'items' => array_map(function (array $row): array {
                $row['id'] = (int) ($row['id'] ?? 0);
                $row['user_id'] = (int) ($row['user_id'] ?? 0);
                $row['user_uuid'] = isset($row['user_uuid']) ? strtolower((string) $row['user_uuid']) : null;
                $row['sign_count'] = (int) ($row['sign_count'] ?? 0);
                $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
                $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
                return $row;
            }, $items),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdminPasskeyStats(?string $since30Days = null): array
    {
        $stats = [
            'users_with_passkeys' => 0,
            'total_active_passkeys' => 0,
            'new_passkeys_30d' => 0,
        ];

        $baseSql = '
            SELECT
                COUNT(*) AS total_active_passkeys,
                COUNT(DISTINCT up.user_uuid) AS users_with_passkeys
            FROM user_passkeys up
            INNER JOIN users u ON u.uuid = up.user_uuid
            WHERE up.disabled_at IS NULL
              AND u.deleted_at IS NULL
        ';
        $baseRow = DatabaseRows::one($this->db->selectOne($baseSql)) ?: [];
        $stats['users_with_passkeys'] = (int) ($baseRow['users_with_passkeys'] ?? 0);
        $stats['total_active_passkeys'] = (int) ($baseRow['total_active_passkeys'] ?? 0);

        if ($since30Days !== null) {
            $stats['new_passkeys_30d'] = (int) DatabaseRows::scalar($this->db->selectOne('SELECT COUNT(*)
                 FROM user_passkeys up
                 INNER JOIN users u ON u.uuid = up.user_uuid
                 WHERE up.disabled_at IS NULL
                   AND u.deleted_at IS NULL
                   AND up.created_at >= :since_30_days', ['since_30_days' => $since30Days]));
        }

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserPasskeySummary(string $userUuid): array
    {
        $row = DatabaseRows::one($this->db->selectOne('SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN backup_state = 1 THEN 1 ELSE 0 END) AS backup_enabled,
                SUM(CASE WHEN backup_eligible = 1 THEN 1 ELSE 0 END) AS backup_eligible,
                MAX(last_used_at) AS last_used_at,
                MAX(created_at) AS last_registered_at
             FROM user_passkeys
             WHERE user_uuid = :user_uuid AND disabled_at IS NULL', ['user_uuid' => strtolower($userUuid)])) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'backup_enabled' => (int) ($row['backup_enabled'] ?? 0),
            'backup_eligible' => (int) ($row['backup_eligible'] ?? 0),
            'last_used_at' => $row['last_used_at'] ?? null,
            'last_registered_at' => $row['last_registered_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(int $id): ?array
    {

        $row = DatabaseRows::one($this->db->selectOne('SELECT id, user_uuid, credential_id, label, rp_id, user_handle, transports, aaguid, sign_count,
                    last_used_at, attested_at, credential_type, attestation_format, backup_eligible, backup_state,
                    created_at, updated_at
             FROM user_passkeys
             WHERE id = :id
             LIMIT 1', ['id' => $id]));
        if (!$row) {
            return null;
        }

        return $this->hydratePasskeyRow($row);
    }

    /**
     * @param mixed $value
     */
    private function encodeJson($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? null : $encoded;
    }

    /**
     * @return string[]
     */
    private function decodeJsonList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydratePasskeyRow(array $row): array
    {
        if (isset($row['user_uuid'])) {
            $row['user_uuid'] = strtolower((string) $row['user_uuid']);
        }
        $row['transports'] = $this->decodeJsonList($row['transports'] ?? null);
        $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
        $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
        $row['sign_count'] = (int) ($row['sign_count'] ?? 0);

        return $row;
    }
}
