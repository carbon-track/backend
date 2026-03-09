<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use PDO;

class UserPasskey
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, credential_id, label, rp_id, user_handle, transports, aaguid, sign_count,
                    last_used_at, attested_at, credential_type, attestation_format, backup_eligible, backup_state,
                    created_at, updated_at
             FROM user_passkeys
             WHERE user_id = :user_id AND disabled_at IS NULL
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(function (array $row): array {
            $row['transports'] = $this->decodeJsonList($row['transports'] ?? null);
            $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
            $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
            $row['sign_count'] = (int) ($row['sign_count'] ?? 0);
            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_passkeys
             WHERE credential_id_hash = :credential_id_hash AND disabled_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'credential_id_hash' => hash('sha256', $credentialId),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['transports'] = $this->decodeJsonList($row['transports'] ?? null);
        $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
        $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
        $row['sign_count'] = (int) ($row['sign_count'] ?? 0);

        return $row;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function create(array $record): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO user_passkeys (
                user_id, credential_id, credential_id_hash, credential_type, label, public_key, rp_id, user_handle,
                transports, aaguid, sign_count, attestation_format, backup_eligible, backup_state, meta_json,
                last_used_at, attested_at, created_at, updated_at
            ) VALUES (
                :user_id, :credential_id, :credential_id_hash, :credential_type, :label, :public_key, :rp_id, :user_handle,
                :transports, :aaguid, :sign_count, :attestation_format, :backup_eligible, :backup_state, :meta_json,
                :last_used_at, :attested_at, :created_at, :updated_at
            )'
        );

        $stmt->execute([
            'user_id' => (int) $record['user_id'],
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

        $id = (int) $this->db->lastInsertId();
        $created = $this->findById($id);
        return $created ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByIdForUser(int $passkeyId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM user_passkeys
             WHERE id = :id AND user_id = :user_id AND disabled_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'id' => $passkeyId,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['transports'] = $this->decodeJsonList($row['transports'] ?? null);
        $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
        $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
        $row['sign_count'] = (int) ($row['sign_count'] ?? 0);

        return $row;
    }

    public function touchAuthentication(int $passkeyId, int $signCount, bool $backupState, ?string $lastUsedAt = null): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_passkeys
             SET sign_count = :sign_count,
                 backup_state = :backup_state,
                 last_used_at = :last_used_at,
                 updated_at = :updated_at
             WHERE id = :id AND disabled_at IS NULL'
        );

        $now = gmdate('Y-m-d H:i:s');
        return $stmt->execute([
            'sign_count' => $signCount,
            'backup_state' => $backupState ? 1 : 0,
            'last_used_at' => $lastUsedAt ?? $now,
            'updated_at' => $now,
            'id' => $passkeyId,
        ]);
    }

    public function disable(int $passkeyId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE user_passkeys
             SET disabled_at = :disabled_at, updated_at = :updated_at
             WHERE id = :id AND user_id = :user_id AND disabled_at IS NULL'
        );

        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'disabled_at' => $now,
            'updated_at' => $now,
            'id' => $passkeyId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, credential_id, label, rp_id, user_handle, transports, aaguid, sign_count,
                    last_used_at, attested_at, credential_type, attestation_format, backup_eligible, backup_state,
                    created_at, updated_at
             FROM user_passkeys
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['transports'] = $this->decodeJsonList($row['transports'] ?? null);
        $row['backup_eligible'] = (bool) ($row['backup_eligible'] ?? false);
        $row['backup_state'] = (bool) ($row['backup_state'] ?? false);
        $row['sign_count'] = (int) ($row['sign_count'] ?? 0);

        return $row;
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
}
