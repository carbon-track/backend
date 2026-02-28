-- Data migration from legacy v2 schema (old.sql) to current schema (localhost.sql)
-- Source DB: 3kudvwa29i222
-- Target DB: carbontrack_v3
-- IMPORTANT:
-- 1) This script migrates data only. It does NOT change target schema.
-- 2) Ensure carbontrack_v3 already uses the latest schema from backend/database/localhost.sql.

SET NAMES utf8mb4;

SET @OLD_SQL_MODE := @@SQL_MODE;
SET SQL_MODE = REPLACE(REPLACE(@@SQL_MODE, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '');

SET @OLD_FOREIGN_KEY_CHECKS := @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

USE `carbontrack_v3`;

-- =========================================================
-- 1) Migrate shared lookup/base tables
-- =========================================================

-- user_groups (same shape in old/new)
INSERT INTO `carbontrack_v3`.`user_groups`
(`id`, `name`, `code`, `config`, `is_default`, `notes`, `created_at`, `updated_at`)
SELECT
    `id`,
    `name`,
    `code`,
    `config`,
    `is_default`,
    `notes`,
    COALESCE(`created_at`, CURRENT_TIMESTAMP),
    COALESCE(`updated_at`, CURRENT_TIMESTAMP)
FROM `3kudvwa29i222`.`user_groups`
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `config` = VALUES(`config`),
    `is_default` = VALUES(`is_default`),
    `notes` = VALUES(`notes`),
    `updated_at` = VALUES(`updated_at`);

-- avatars (old schema: filename/mime/active -> new schema fields)
INSERT INTO `carbontrack_v3`.`avatars`
(`id`, `uuid`, `name`, `description`, `file_path`, `thumbnail_path`, `category`, `sort_order`, `is_active`, `is_default`, `created_at`, `updated_at`, `deleted_at`)
SELECT
    oa.`id`,
    LOWER(CONCAT(
        SUBSTRING(MD5(CONCAT('avatar-', oa.`id`)), 1, 8), '-',
        SUBSTRING(MD5(CONCAT('avatar-', oa.`id`)), 9, 4), '-',
        SUBSTRING(MD5(CONCAT('avatar-', oa.`id`)), 13, 4), '-',
        SUBSTRING(MD5(CONCAT('avatar-', oa.`id`)), 17, 4), '-',
        SUBSTRING(MD5(CONCAT('avatar-', oa.`id`)), 21, 12)
    )) AS `uuid`,
    CASE
        WHEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(oa.`filename`, '/', -1), '.', 1)) = '' THEN CONCAT('Avatar ', oa.`id`)
        ELSE LEFT(TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(oa.`filename`, '/', -1), '.', 1)), 100)
    END AS `name`,
    NULL AS `description`,
    LEFT(oa.`filename`, 500) AS `file_path`,
    NULL AS `thumbnail_path`,
    'default' AS `category`,
    0 AS `sort_order`,
    COALESCE(oa.`active`, 1) AS `is_active`,
    CASE WHEN oa.`id` = 1 THEN 1 ELSE 0 END AS `is_default`,
    COALESCE(oa.`created_at`, CURRENT_TIMESTAMP) AS `created_at`,
    COALESCE(oa.`updated_at`, oa.`created_at`, CURRENT_TIMESTAMP) AS `updated_at`,
    NULL AS `deleted_at`
FROM `3kudvwa29i222`.`avatars` oa
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `file_path` = VALUES(`file_path`),
    `is_active` = VALUES(`is_active`),
    `updated_at` = VALUES(`updated_at`);

-- schools
INSERT INTO `carbontrack_v3`.`schools`
(`id`, `name`, `deleted_at`, `location`, `is_active`, `created_at`, `updated_at`)
SELECT
    s.`id`,
    s.`name`,
    NULL AS `deleted_at`,
    NULL AS `location`,
    1 AS `is_active`,
    CURRENT_TIMESTAMP AS `created_at`,
    CURRENT_TIMESTAMP AS `updated_at`
FROM `3kudvwa29i222`.`schools` s
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `updated_at` = VALUES(`updated_at`);

-- products (old product_id -> new id)
INSERT INTO `carbontrack_v3`.`products`
(`name`, `category`, `category_slug`, `id`, `points_required`, `description`, `image_path`, `images`, `stock`, `status`, `sort_order`, `created_at`, `updated_at`, `deleted_at`)
SELECT
    p.`name`,
    NULL AS `category`,
    NULL AS `category_slug`,
    p.`product_id` AS `id`,
    p.`points_required`,
    p.`description`,
    p.`image_path`,
    NULL AS `images`,
    p.`stock`,
    'active' AS `status`,
    0 AS `sort_order`,
    CURRENT_TIMESTAMP AS `created_at`,
    CURRENT_TIMESTAMP AS `updated_at`,
    NULL AS `deleted_at`
FROM `3kudvwa29i222`.`products` p
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `points_required` = VALUES(`points_required`),
    `description` = VALUES(`description`),
    `image_path` = VALUES(`image_path`),
    `stock` = VALUES(`stock`),
    `updated_at` = VALUES(`updated_at`);

-- =========================================================
-- 2) Migrate users with legacy cleanup
-- =========================================================

-- Handle duplicate/empty emails in old data:
-- - keep first appearance as-is (normalized to lowercase)
-- - append +dup{id} for later duplicates
-- - synthesize legacy_user_{id}@placeholder.local when email is empty
DROP TEMPORARY TABLE IF EXISTS `_tmp_old_users`;

CREATE TEMPORARY TABLE `_tmp_old_users` AS
SELECT
    ranked.`id`,
    ranked.`avatar_id`,
    ranked.`username`,
    ranked.`password`,
    ranked.`lastlgn`,
    ranked.`email`,
    ranked.`points`,
    ranked.`school`,
    ranked.`location`,
    ranked.`status`,
    ranked.`group_id`,
    ranked.`quota_override`,
    ranked.`admin_notes`,
    ranked.`email_norm`,
    ranked.`dup_seq`
FROM (
    SELECT
        base.`id`,
        base.`avatar_id`,
        base.`username`,
        base.`password`,
        base.`lastlgn`,
        base.`email`,
        base.`points`,
        base.`school`,
        base.`location`,
        base.`status`,
        base.`group_id`,
        base.`quota_override`,
        base.`admin_notes`,
        base.`email_norm`,
        @dup_seq := IF(@prev_email = base.`email_norm`, @dup_seq + 1, 1) AS `dup_seq`,
        @prev_email := base.`email_norm` AS `_prev_email`
    FROM (
        SELECT
            u.`id`,
            u.`avatar_id`,
            NULLIF(TRIM(u.`username`), '') AS `username`,
            u.`password`,
            u.`lastlgn`,
            u.`email`,
            u.`points`,
            u.`school`,
            u.`location`,
            u.`status`,
            u.`group_id`,
            u.`quota_override`,
            u.`admin_notes`,
            LOWER(TRIM(COALESCE(u.`email`, ''))) AS `email_norm`
        FROM `3kudvwa29i222`.`users` u
        ORDER BY LOWER(TRIM(COALESCE(u.`email`, ''))), u.`id`
    ) base
    CROSS JOIN (SELECT @prev_email := NULL, @dup_seq := 0) vars
) ranked;

INSERT INTO `carbontrack_v3`.`users`
(`id`, `username`, `password`, `lastlgn`, `email`, `points`, `school`, `location`, `region_code`, `created_at`, `updated_at`, `deleted_at`, `status`, `is_admin`, `class_name`, `school_id`, `avatar_id`, `reset_token`, `reset_token_expires_at`, `email_verified_at`, `verification_code`, `verification_token`, `verification_code_expires_at`, `verification_attempts`, `verification_send_count`, `verification_last_sent_at`, `notification_email_mask`, `group_id`, `quota_override`, `admin_notes`)
SELECT
    t.`id`,
    t.`username`,
    LEFT(t.`password`, 255) AS `password`,
    CASE
        WHEN t.`lastlgn` IS NULL OR TRIM(t.`lastlgn`) = '' OR TRIM(t.`lastlgn`) = '0000-00-00 00:00:00' THEN NULL
        ELSE STR_TO_DATE(TRIM(t.`lastlgn`), '%Y-%m-%d %H:%i:%s')
    END AS `lastlgn`,
    CASE
        WHEN t.`email_norm` = '' THEN CONCAT('legacy_user_', t.`id`, '@placeholder.local')
        WHEN t.`dup_seq` = 1 THEN LEFT(t.`email_norm`, 255)
        ELSE LEFT(
            CONCAT(
                CASE
                    WHEN LOCATE('@', t.`email_norm`) > 0 THEN SUBSTRING_INDEX(t.`email_norm`, '@', 1)
                    ELSE CONCAT('legacy_user_', t.`id`)
                END,
                '+dup', t.`id`, '@',
                CASE
                    WHEN LOCATE('@', t.`email_norm`) > 0 THEN SUBSTRING_INDEX(t.`email_norm`, '@', -1)
                    ELSE 'placeholder.local'
                END
            ),
            255
        )
    END AS `email`,
    CAST(ROUND(COALESCE(t.`points`, 0), 2) AS DECIMAL(10,2)) AS `points`,
    NULLIF(LEFT(TRIM(COALESCE(t.`school`, '')), 255), '') AS `school`,
    NULLIF(LEFT(TRIM(COALESCE(t.`location`, '')), 255), '') AS `location`,
    NULLIF(LEFT(TRIM(COALESCE(t.`location`, '')), 16), '') AS `region_code`,
    COALESCE(
        CASE
            WHEN t.`lastlgn` IS NULL OR TRIM(t.`lastlgn`) = '' OR TRIM(t.`lastlgn`) = '0000-00-00 00:00:00' THEN NULL
            ELSE STR_TO_DATE(TRIM(t.`lastlgn`), '%Y-%m-%d %H:%i:%s')
        END,
        CURRENT_TIMESTAMP
    ) AS `created_at`,
    COALESCE(
        CASE
            WHEN t.`lastlgn` IS NULL OR TRIM(t.`lastlgn`) = '' OR TRIM(t.`lastlgn`) = '0000-00-00 00:00:00' THEN NULL
            ELSE STR_TO_DATE(TRIM(t.`lastlgn`), '%Y-%m-%d %H:%i:%s')
        END,
        CURRENT_TIMESTAMP
    ) AS `updated_at`,
    CASE WHEN LOWER(TRIM(COALESCE(t.`status`, 'active'))) = 'deleted' THEN CURRENT_TIMESTAMP ELSE NULL END AS `deleted_at`,
    CASE
        WHEN LOWER(TRIM(COALESCE(t.`status`, 'active'))) = 'active' THEN 'active'
        WHEN LOWER(TRIM(COALESCE(t.`status`, 'active'))) = 'deleted' THEN 'inactive'
        ELSE 'inactive'
    END AS `status`,
    CASE WHEN LOWER(TRIM(COALESCE(t.`username`, ''))) = 'admin' THEN 1 ELSE 0 END AS `is_admin`,
    NULL AS `class_name`,
    (
        SELECT ns.`id`
        FROM `carbontrack_v3`.`schools` ns
        WHERE ns.`name` = NULLIF(TRIM(COALESCE(t.`school`, '')), '')
        LIMIT 1
    ) AS `school_id`,
    NULLIF(t.`avatar_id`, 0) AS `avatar_id`,
    NULL AS `reset_token`,
    NULL AS `reset_token_expires_at`,
    COALESCE(
        CASE
            WHEN t.`lastlgn` IS NULL OR TRIM(t.`lastlgn`) = '' OR TRIM(t.`lastlgn`) = '0000-00-00 00:00:00' THEN NULL
            ELSE STR_TO_DATE(TRIM(t.`lastlgn`), '%Y-%m-%d %H:%i:%s')
        END,
        CURRENT_TIMESTAMP
    ) AS `email_verified_at`,
    NULL AS `verification_code`,
    NULL AS `verification_token`,
    NULL AS `verification_code_expires_at`,
    0 AS `verification_attempts`,
    0 AS `verification_send_count`,
    NULL AS `verification_last_sent_at`,
    0 AS `notification_email_mask`,
    COALESCE(NULLIF(t.`group_id`, 0), 1) AS `group_id`,
    t.`quota_override`,
    t.`admin_notes`
FROM `_tmp_old_users` t
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `password` = VALUES(`password`),
    `lastlgn` = VALUES(`lastlgn`),
    `points` = VALUES(`points`),
    `school` = VALUES(`school`),
    `location` = VALUES(`location`),
    `region_code` = VALUES(`region_code`),
    `updated_at` = VALUES(`updated_at`),
    `deleted_at` = VALUES(`deleted_at`),
    `status` = VALUES(`status`),
    `is_admin` = VALUES(`is_admin`),
    `school_id` = VALUES(`school_id`),
    `avatar_id` = VALUES(`avatar_id`),
    `email_verified_at` = VALUES(`email_verified_at`),
    `group_id` = VALUES(`group_id`),
    `quota_override` = VALUES(`quota_override`),
    `admin_notes` = VALUES(`admin_notes`);

DROP TEMPORARY TABLE IF EXISTS `_tmp_old_users`;

-- user_usage_stats (if any)
INSERT INTO `carbontrack_v3`.`user_usage_stats`
(`user_id`, `resource_key`, `counter`, `last_updated_at`, `reset_at`)
SELECT
    us.`user_id`,
    us.`resource_key`,
    us.`counter`,
    COALESCE(us.`last_updated_at`, CURRENT_TIMESTAMP),
    us.`reset_at`
FROM `3kudvwa29i222`.`user_usage_stats` us
ON DUPLICATE KEY UPDATE
    `counter` = VALUES(`counter`),
    `last_updated_at` = VALUES(`last_updated_at`),
    `reset_at` = VALUES(`reset_at`);

-- =========================================================
-- 3) Migrate operational/history tables
-- =========================================================

-- messages (message_id -> id, send_time -> created_at/updated_at)
INSERT INTO `carbontrack_v3`.`messages`
(`id`, `sender_id`, `receiver_id`, `title`, `content`, `is_read`, `created_at`, `updated_at`, `deleted_at`)
SELECT
    m.`message_id` AS `id`,
    CASE
        WHEN TRIM(COALESCE(m.`sender_id`, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(m.`sender_id`) AS UNSIGNED)
        ELSE NULL
    END AS `sender_id`,
    CASE
        WHEN TRIM(COALESCE(m.`receiver_id`, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(m.`receiver_id`) AS UNSIGNED)
        ELSE 0
    END AS `receiver_id`,
    '' AS `title`,
    m.`content`,
    COALESCE(m.`is_read`, 0) AS `is_read`,
    CASE
        WHEN m.`send_time` IS NULL OR TRIM(m.`send_time`) = '' OR TRIM(m.`send_time`) = '0000-00-00 00:00:00' THEN NULL
        ELSE STR_TO_DATE(TRIM(m.`send_time`), '%Y-%m-%d %H:%i:%s')
    END AS `created_at`,
    CASE
        WHEN m.`send_time` IS NULL OR TRIM(m.`send_time`) = '' OR TRIM(m.`send_time`) = '0000-00-00 00:00:00' THEN NULL
        ELSE STR_TO_DATE(TRIM(m.`send_time`), '%Y-%m-%d %H:%i:%s')
    END AS `updated_at`,
    NULL AS `deleted_at`
FROM `3kudvwa29i222`.`messages` m
ON DUPLICATE KEY UPDATE
    `sender_id` = VALUES(`sender_id`),
    `receiver_id` = VALUES(`receiver_id`),
    `content` = VALUES(`content`),
    `is_read` = VALUES(`is_read`),
    `updated_at` = VALUES(`updated_at`);

-- points_transactions (old auth + text time + huge values -> normalized fields)
INSERT INTO `carbontrack_v3`.`points_transactions`
(`username`, `id`, `email`, `time`, `img`, `points`, `auth`, `raw`, `act`, `uid`, `activity_id`, `type`, `notes`, `activity_date`, `status`, `approved_by`, `approved_at`, `created_at`, `updated_at`, `deleted_at`)
SELECT
    pt.`username`,
    pt.`id`,
    LEFT(COALESCE(NULLIF(TRIM(pt.`email`), ''), CONCAT('legacy_points_', pt.`id`, '@placeholder.local')), 255) AS `email`,
    COALESCE(
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d'),
        CASE WHEN pt.`activity_date` IS NOT NULL THEN CAST(CONCAT(pt.`activity_date`, ' 00:00:00') AS DATETIME) ELSE NULL END,
        CURRENT_TIMESTAMP
    ) AS `time`,
    NULLIF(LEFT(TRIM(COALESCE(pt.`img`, '')), 512), '') AS `img`,
    CAST(ROUND(LEAST(GREATEST(COALESCE(pt.`points`, 0), -99999999.99), 99999999.99), 2) AS DECIMAL(10,2)) AS `points`,
    NULLIF(LEFT(TRIM(COALESCE(pt.`auth`, '')), 50), '') AS `auth`,
    CAST(ROUND(LEAST(GREATEST(COALESCE(pt.`raw`, 0), -99999999.99), 99999999.99), 2) AS DECIMAL(10,2)) AS `raw`,
    NULLIF(LEFT(TRIM(COALESCE(pt.`act`, '')), 255), '') AS `act`,
    COALESCE(NULLIF(pt.`uid`, 0), u.`id`, 0) AS `uid`,
    NULL AS `activity_id`,
    NULLIF(LEFT(TRIM(COALESCE(pt.`type`, '')), 50), '') AS `type`,
    pt.`notes`,
    pt.`activity_date`,
    CASE
        WHEN LOWER(TRIM(COALESCE(pt.`auth`, ''))) IN ('yes', 'approved', 'true', '1') THEN 'approved'
        WHEN LOWER(TRIM(COALESCE(pt.`auth`, ''))) IN ('no', 'non', 'rejected', 'false', '0') THEN 'rejected'
        ELSE 'pending'
    END AS `status`,
    NULL AS `approved_by`,
    CASE
        WHEN LOWER(TRIM(COALESCE(pt.`auth`, ''))) IN ('yes', 'approved', 'true', '1', 'no', 'non', 'rejected', 'false', '0')
            THEN COALESCE(
                STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d %H:%i:%s'),
                STR_TO_DATE(TRIM(pt.`time`), '%Y/%m/%d %H:%i:%s'),
                STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d')
            )
        ELSE NULL
    END AS `approved_at`,
    COALESCE(
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d'),
        CURRENT_TIMESTAMP
    ) AS `created_at`,
    COALESCE(
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y/%m/%d %H:%i:%s'),
        STR_TO_DATE(TRIM(pt.`time`), '%Y-%m-%d'),
        CURRENT_TIMESTAMP
    ) AS `updated_at`,
    NULL AS `deleted_at`
FROM `3kudvwa29i222`.`points_transactions` pt
LEFT JOIN `carbontrack_v3`.`users` u
    ON LOWER(TRIM(u.`email`)) = LOWER(TRIM(pt.`email`))
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `email` = VALUES(`email`),
    `time` = VALUES(`time`),
    `img` = VALUES(`img`),
    `points` = VALUES(`points`),
    `auth` = VALUES(`auth`),
    `raw` = VALUES(`raw`),
    `act` = VALUES(`act`),
    `uid` = VALUES(`uid`),
    `type` = VALUES(`type`),
    `notes` = VALUES(`notes`),
    `activity_date` = VALUES(`activity_date`),
    `status` = VALUES(`status`),
    `approved_at` = VALUES(`approved_at`),
    `updated_at` = VALUES(`updated_at`);

-- spec_points_transactions (same structure)
INSERT INTO `carbontrack_v3`.`spec_points_transactions`
(`username`, `id`, `email`, `time`, `img`, `points`, `auth`, `raw`, `act`, `uid`)
SELECT
    `username`, `id`, `email`, `time`, `img`, `points`, `auth`, `raw`, `act`, `uid`
FROM `3kudvwa29i222`.`spec_points_transactions`
ON DUPLICATE KEY UPDATE
    `username` = VALUES(`username`),
    `email` = VALUES(`email`),
    `time` = VALUES(`time`),
    `img` = VALUES(`img`),
    `points` = VALUES(`points`),
    `auth` = VALUES(`auth`),
    `raw` = VALUES(`raw`),
    `act` = VALUES(`act`),
    `uid` = VALUES(`uid`);

-- transactions (legacy table still exists in new schema)
INSERT INTO `carbontrack_v3`.`transactions`
(`id`, `points_spent`, `transaction_time`, `product_id`, `user_email`, `school`, `location`)
SELECT
    t.`id`,
    t.`points_spent`,
    t.`transaction_time`,
    t.`product_id`,
    t.`user_email`,
    t.`school`,
    t.`location`
FROM `3kudvwa29i222`.`transactions` t
ON DUPLICATE KEY UPDATE
    `points_spent` = VALUES(`points_spent`),
    `transaction_time` = VALUES(`transaction_time`),
    `product_id` = VALUES(`product_id`),
    `user_email` = VALUES(`user_email`),
    `school` = VALUES(`school`),
    `location` = VALUES(`location`);

-- point_exchanges (new table) backfilled from legacy transactions
INSERT INTO `carbontrack_v3`.`point_exchanges`
(`id`, `user_id`, `product_id`, `quantity`, `points_used`, `product_name`, `product_price`, `delivery_address`, `contact_area_code`, `contact_phone`, `notes`, `status`, `tracking_number`, `created_at`, `updated_at`, `deleted_at`)
SELECT
    LOWER(CONCAT(
        SUBSTRING(MD5(CONCAT('legacy-exchange-', t.`id`)), 1, 8), '-',
        SUBSTRING(MD5(CONCAT('legacy-exchange-', t.`id`)), 9, 4), '-',
        SUBSTRING(MD5(CONCAT('legacy-exchange-', t.`id`)), 13, 4), '-',
        SUBSTRING(MD5(CONCAT('legacy-exchange-', t.`id`)), 17, 4), '-',
        SUBSTRING(MD5(CONCAT('legacy-exchange-', t.`id`)), 21, 12)
    )) AS `id`,
    COALESCE(u.`id`, 0) AS `user_id`,
    t.`product_id`,
    1 AS `quantity`,
    CAST(ROUND(COALESCE(t.`points_spent`, 0), 0) AS SIGNED) AS `points_used`,
    COALESCE(NULLIF(TRIM(p.`name`), ''), CONCAT('Legacy Product #', t.`product_id`)) AS `product_name`,
    COALESCE(p.`points_required`, CAST(ROUND(COALESCE(t.`points_spent`, 0), 0) AS SIGNED)) AS `product_price`,
    NULL AS `delivery_address`,
    NULL AS `contact_area_code`,
    NULL AS `contact_phone`,
    CONCAT('Migrated from legacy transactions.id=', t.`id`) AS `notes`,
    'completed' AS `status`,
    NULL AS `tracking_number`,
    COALESCE(
        STR_TO_DATE(TRIM(t.`transaction_time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(TRIM(t.`transaction_time`), '%Y/%m/%d %H:%i:%s'),
        CURRENT_TIMESTAMP
    ) AS `created_at`,
    COALESCE(
        STR_TO_DATE(TRIM(t.`transaction_time`), '%Y-%m-%d %H:%i:%s'),
        STR_TO_DATE(TRIM(t.`transaction_time`), '%Y/%m/%d %H:%i:%s'),
        CURRENT_TIMESTAMP
    ) AS `updated_at`,
    NULL AS `deleted_at`
FROM `3kudvwa29i222`.`transactions` t
LEFT JOIN `carbontrack_v3`.`users` u
    ON LOWER(TRIM(u.`email`)) = LOWER(TRIM(t.`user_email`))
LEFT JOIN `carbontrack_v3`.`products` p
    ON p.`id` = t.`product_id`
ON DUPLICATE KEY UPDATE
    `user_id` = VALUES(`user_id`),
    `product_id` = VALUES(`product_id`),
    `points_used` = VALUES(`points_used`),
    `product_name` = VALUES(`product_name`),
    `product_price` = VALUES(`product_price`),
    `status` = VALUES(`status`),
    `updated_at` = VALUES(`updated_at`);

-- error_logs (new adds request_id; legacy has none)
INSERT INTO `carbontrack_v3`.`error_logs`
(`id`, `error_type`, `error_message`, `error_file`, `error_line`, `error_time`, `script_name`, `request_id`, `client_get`, `client_post`, `client_files`, `client_cookie`, `client_session`, `client_server`)
SELECT
    e.`id`,
    e.`error_type`,
    e.`error_message`,
    e.`error_file`,
    e.`error_line`,
    e.`error_time`,
    e.`script_name`,
    NULL AS `request_id`,
    e.`client_get`,
    e.`client_post`,
    e.`client_files`,
    e.`client_cookie`,
    e.`client_session`,
    e.`client_server`
FROM `3kudvwa29i222`.`error_logs` e
ON DUPLICATE KEY UPDATE
    `error_type` = VALUES(`error_type`),
    `error_message` = VALUES(`error_message`),
    `error_file` = VALUES(`error_file`),
    `error_line` = VALUES(`error_line`),
    `error_time` = VALUES(`error_time`),
    `script_name` = VALUES(`script_name`),
    `client_get` = VALUES(`client_get`),
    `client_post` = VALUES(`client_post`),
    `client_files` = VALUES(`client_files`),
    `client_cookie` = VALUES(`client_cookie`),
    `client_session` = VALUES(`client_session`),
    `client_server` = VALUES(`client_server`);

-- =========================================================
-- 4) Restore session settings
-- =========================================================

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET SQL_MODE = @OLD_SQL_MODE;
