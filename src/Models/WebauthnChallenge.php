<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use PDO;

class WebauthnChallenge
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $record
     */
    public function create(array $record): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO webauthn_challenges (
                challenge_id, user_id, flow_type, challenge, request_id, context_json, expires_at, consumed_at, created_at, updated_at
            ) VALUES (
                :challenge_id, :user_id, :flow_type, :challenge, :request_id, :context_json, :expires_at, :consumed_at, :created_at, :updated_at
            )'
        );

        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'challenge_id' => (string) $record['challenge_id'],
            'user_id' => $record['user_id'] ?? null,
            'flow_type' => (string) $record['flow_type'],
            'challenge' => (string) $record['challenge'],
            'request_id' => $record['request_id'] ?? null,
            'context_json' => $this->encodeJson($record['context'] ?? null),
            'expires_at' => (string) $record['expires_at'],
            'consumed_at' => $record['consumed_at'] ?? null,
            'created_at' => $record['created_at'] ?? $now,
            'updated_at' => $record['updated_at'] ?? $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActive(string $challengeId, string $flowType, ?int $userId = null): ?array
    {
        $sql = 'SELECT * FROM webauthn_challenges
                WHERE challenge_id = :challenge_id
                  AND flow_type = :flow_type
                  AND consumed_at IS NULL
                  AND expires_at > :current_time';
        $params = [
            'challenge_id' => $challengeId,
            'flow_type' => $flowType,
            'current_time' => $this->utcNow(),
        ];

        if ($userId !== null) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['context'] = $this->decodeJsonObject($row['context_json'] ?? null);
        return $row;
    }

    public function markConsumed(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE webauthn_challenges
             SET consumed_at = :consumed_at, updated_at = :updated_at
             WHERE id = :id AND consumed_at IS NULL'
        );

        $now = gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'consumed_at' => $now,
            'updated_at' => $now,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteExpired(): int
    {
        $stmt = $this->db->prepare('DELETE FROM webauthn_challenges WHERE expires_at <= :current_time');
        $stmt->execute([
            'current_time' => $this->utcNow(),
        ]);
        return $stmt->rowCount();
    }

    private function utcNow(): string
    {
        return gmdate('Y-m-d H:i:s');
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
     * @return array<string, mixed>
     */
    private function decodeJsonObject(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
