ALTER TABLE `users`
    ADD COLUMN `uuid` CHAR(36) NULL DEFAULT NULL AFTER `id`;

UPDATE `users` AS `target`
JOIN `users` AS `keeper`
    ON `keeper`.`uuid` = `target`.`uuid`
   AND `keeper`.`id` < `target`.`id`
   AND `keeper`.`uuid` IS NOT NULL
   AND TRIM(`keeper`.`uuid`) <> ''
SET `target`.`uuid` = NULL
WHERE `target`.`uuid` IS NOT NULL
  AND TRIM(`target`.`uuid`) <> '';

UPDATE `users`
JOIN (
    SELECT
        `id`,
        LOWER(CONCAT(
            SUBSTR(`uuid_hash`, 1, 8), '-',
            SUBSTR(`uuid_hash`, 9, 4), '-',
            '4', SUBSTR(`uuid_hash`, 14, 3), '-',
            '8', SUBSTR(`uuid_hash`, 18, 3), '-',
            SUBSTR(`uuid_hash`, 21, 12)
        )) AS `generated_uuid`
    FROM (
        SELECT
            `id`,
            MD5(CONCAT('carbontrack-user-', `id`)) AS `uuid_hash`
        FROM `users`
    ) AS `hashed_users`
) AS `generated_users`
    ON `generated_users`.`id` = `users`.`id`
SET `users`.`uuid` = `generated_users`.`generated_uuid`
WHERE `users`.`uuid` IS NULL OR TRIM(`users`.`uuid`) = '';

ALTER TABLE `users`
    ADD UNIQUE KEY `idx_users_uuid_unique` (`uuid`);

ALTER TABLE `users`
    MODIFY `uuid` CHAR(36) NOT NULL;
