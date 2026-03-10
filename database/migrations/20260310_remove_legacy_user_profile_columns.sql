SET @has_school := (
    SELECT COUNT(*)
    FROM `INFORMATION_SCHEMA`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'users'
      AND `COLUMN_NAME` = 'school'
);

SET @has_location := (
    SELECT COUNT(*)
    FROM `INFORMATION_SCHEMA`.`COLUMNS`
    WHERE `TABLE_SCHEMA` = DATABASE()
      AND `TABLE_NAME` = 'users'
      AND `COLUMN_NAME` = 'location'
);

SET @sql := IF(
    @has_school > 0,
    "INSERT INTO `schools`
(`name`, `deleted_at`, `location`, `is_active`, `created_at`, `updated_at`)
SELECT
    pending.`school_name`,
    NULL AS `deleted_at`,
    NULL AS `location`,
    1 AS `is_active`,
    CURRENT_TIMESTAMP AS `created_at`,
    CURRENT_TIMESTAMP AS `updated_at`
FROM (
    SELECT DISTINCT
        LEFT(TRIM(u.`school`), 255) AS `school_name`
    FROM `users` u
    WHERE u.`school_id` IS NULL
      AND u.`school` IS NOT NULL
      AND TRIM(u.`school`) <> ''
) pending
LEFT JOIN (
    SELECT
        LOWER(TRIM(s.`name`)) AS `school_name_key`
    FROM `schools` s
    WHERE s.`deleted_at` IS NULL
      AND TRIM(COALESCE(s.`name`, '')) <> ''
    GROUP BY LOWER(TRIM(s.`name`))
) existing
    ON existing.`school_name_key` = LOWER(pending.`school_name`)
WHERE existing.`school_name_key` IS NULL",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_school > 0,
    "UPDATE `users` u
JOIN (
    SELECT
        LOWER(TRIM(s.`name`)) AS `school_name_key`,
        MIN(s.`id`) AS `school_id`
    FROM `schools` s
    WHERE s.`deleted_at` IS NULL
      AND TRIM(COALESCE(s.`name`, '')) <> ''
    GROUP BY LOWER(TRIM(s.`name`))
) matched
    ON matched.`school_name_key` = LOWER(TRIM(u.`school`))
SET u.`school_id` = matched.`school_id`
WHERE u.`school_id` IS NULL
  AND u.`school` IS NOT NULL
  AND TRIM(u.`school`) <> ''",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    @has_location > 0,
    "UPDATE `users`
SET `region_code` = LEFT(TRIM(`location`), 16)
WHERE (`region_code` IS NULL OR TRIM(`region_code`) = '')
  AND `location` IS NOT NULL
  AND TRIM(`location`) <> ''",
    "SELECT 1"
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @drop_sql := CASE
    WHEN @has_school > 0 AND @has_location > 0 THEN
        "ALTER TABLE `users` DROP COLUMN `school`, DROP COLUMN `location`"
    WHEN @has_school > 0 THEN
        "ALTER TABLE `users` DROP COLUMN `school`"
    WHEN @has_location > 0 THEN
        "ALTER TABLE `users` DROP COLUMN `location`"
    ELSE
        "SELECT 1"
END;
PREPARE stmt FROM @drop_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
