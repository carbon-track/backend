ALTER TABLE `users`
    ADD COLUMN `notification_email_mask` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `verification_last_sent_at`;

UPDATE `users` AS u
LEFT JOIN (
    SELECT
        user_id,
        MAX(CASE WHEN category = 'system' AND email_enabled = 0 THEN 1 ELSE 0 END) AS system_disabled,
        MAX(CASE WHEN category = 'transaction' AND email_enabled = 0 THEN 1 ELSE 0 END) AS transaction_disabled,
        MAX(CASE WHEN category = 'activity' AND email_enabled = 0 THEN 1 ELSE 0 END) AS activity_disabled,
        MAX(CASE WHEN category = 'announcement' AND email_enabled = 0 THEN 1 ELSE 0 END) AS announcement_disabled
    FROM `user_notification_preferences`
    GROUP BY user_id
) AS prefs ON prefs.user_id = u.id
SET u.notification_email_mask =
      (IFNULL(prefs.system_disabled, 0) << 0)
    | (IFNULL(prefs.transaction_disabled, 0) << 1)
    | (IFNULL(prefs.activity_disabled, 0) << 2)
    | (IFNULL(prefs.announcement_disabled, 0) << 3);

DROP TABLE IF EXISTS `user_notification_preferences`;
