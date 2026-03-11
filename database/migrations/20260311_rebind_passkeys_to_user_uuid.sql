ALTER TABLE `user_passkeys`
    ADD COLUMN `user_uuid` CHAR(36) NULL AFTER `user_id`;

UPDATE `user_passkeys` AS `up`
JOIN `users` AS `u` ON `u`.`id` = `up`.`user_id`
SET `up`.`user_uuid` = LOWER(`u`.`uuid`)
WHERE `up`.`user_uuid` IS NULL OR TRIM(`up`.`user_uuid`) = '';

ALTER TABLE `user_passkeys`
    MODIFY `user_uuid` CHAR(36) NOT NULL,
    ADD KEY `idx_user_passkeys_user_uuid` (`user_uuid`);

ALTER TABLE `user_passkeys`
    DROP KEY `idx_user_passkeys_user_id`,
    DROP COLUMN `user_id`;

ALTER TABLE `webauthn_challenges`
    ADD COLUMN `user_uuid` CHAR(36) NULL AFTER `user_id`;

UPDATE `webauthn_challenges` AS `wc`
JOIN `users` AS `u` ON `u`.`id` = `wc`.`user_id`
SET `wc`.`user_uuid` = LOWER(`u`.`uuid`)
WHERE (`wc`.`user_uuid` IS NULL OR TRIM(`wc`.`user_uuid`) = '')
  AND `wc`.`user_id` IS NOT NULL;

ALTER TABLE `webauthn_challenges`
    ADD KEY `idx_webauthn_challenges_user_uuid` (`user_uuid`);

ALTER TABLE `webauthn_challenges`
    DROP KEY `idx_webauthn_challenges_user_id`,
    DROP COLUMN `user_id`;
