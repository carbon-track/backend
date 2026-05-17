-- Migration: 20260504 add token_version to users and per-user composite scope to idempotency_records
-- Purpose: Support JWT version-based revocation (W-201) and per-user idempotency keying (B-106).

-- 1. users.token_version drives JWT "tv" claim verification.
--    Existing rows default to 0 so previously issued tokens continue to validate
--    until the next change-password / reset-password event increments the column.
ALTER TABLE `users`
    ADD COLUMN `token_version` INT NOT NULL DEFAULT 0 AFTER `email_verified_at`;

-- 2. Idempotency must be keyed by (idempotency_key, composite_key, user_id) instead of just key.
--    composite_key = sha256(user_id|method|path|sha256(body)) and is computed by the middleware.
--    Adding it as a nullable column keeps existing rows readable; the new unique
--    index covers (idempotency_key, composite_key, user_id) so different users
--    replaying the same UUID never collide, while distinct UUIDs for the same
--    payload can each cache their own response. Anonymous requests are stored
--    with user_id=0 by the middleware so MySQL NULL uniqueness rules do not
--    weaken the replay guard. The legacy idx_idempotency_key unique index is
--    dropped so the same `idempotency_key` UUID can be reused across users.
ALTER TABLE `idempotency_records`
    ADD COLUMN `composite_key` CHAR(64) NULL AFTER `idempotency_key`;
ALTER TABLE `idempotency_records`
    DROP INDEX `idx_idempotency_key`;
ALTER TABLE `idempotency_records`
    ADD INDEX `idx_idempotency_key_user` (`idempotency_key`, `user_id`),
    ADD UNIQUE KEY `uniq_idempotency_key_composite_user` (`idempotency_key`, `composite_key`, `user_id`);

-- 3. Per-IP rate limiting for proof-of-work challenge issuance (B-105).
--    Stored as a lightweight insert table; consumed by ProofOfWorkService::recordIssuance.
CREATE TABLE IF NOT EXISTS `pow_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `scope` VARCHAR(64) NOT NULL,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pow_attempts_ip_attempted_at` (`ip_address`, `attempted_at`),
    KEY `idx_pow_attempts_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
