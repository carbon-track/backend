-- Backfill support_routing into legacy user_groups configs when missing.
UPDATE `user_groups`
SET `config` = CASE
  WHEN `config` IS NULL OR TRIM(`config`) = '' THEN
    CASE
      WHEN `code` = 'premium' THEN '{"support_routing":{"first_response_minutes":60,"resolution_minutes":720,"routing_weight":1.5,"min_agent_level":2,"overdue_boost":1.5,"tier_label":"premium"}}'
      WHEN `code` = 'free' THEN '{"support_routing":{"first_response_minutes":240,"resolution_minutes":1440,"routing_weight":1,"min_agent_level":1,"overdue_boost":1,"tier_label":"standard"}}'
      ELSE '{"support_routing":{"first_response_minutes":240,"resolution_minutes":1440,"routing_weight":1,"min_agent_level":1,"overdue_boost":1,"tier_label":"standard"}}'
    END
  WHEN `config` LIKE '%"support_routing"%' THEN `config`
  WHEN RIGHT(TRIM(`config`), 1) = '}' THEN
    CONCAT(
      SUBSTRING(TRIM(`config`), 1, CHAR_LENGTH(TRIM(`config`)) - 1),
      CASE
        WHEN `code` = 'premium' THEN ',"support_routing":{"first_response_minutes":60,"resolution_minutes":720,"routing_weight":1.5,"min_agent_level":2,"overdue_boost":1.5,"tier_label":"premium"}}'
        WHEN `code` = 'free' THEN ',"support_routing":{"first_response_minutes":240,"resolution_minutes":1440,"routing_weight":1,"min_agent_level":1,"overdue_boost":1,"tier_label":"standard"}}'
        ELSE ',"support_routing":{"first_response_minutes":240,"resolution_minutes":1440,"routing_weight":1,"min_agent_level":1,"overdue_boost":1,"tier_label":"standard"}}'
      END
    )
  ELSE `config`
END
WHERE `config` IS NULL
   OR TRIM(`config`) = ''
   OR `config` NOT LIKE '%"support_routing"%';

-- Backfill first support response time from the earliest support/admin message.
UPDATE `support_tickets` AS `t`
INNER JOIN (
  SELECT
    `ticket_id`,
    MIN(`created_at`) AS `first_support_response_at`
  FROM `support_ticket_messages`
  WHERE `sender_role` IN ('support', 'admin')
  GROUP BY `ticket_id`
) AS `m` ON `m`.`ticket_id` = `t`.`id`
SET `t`.`first_support_response_at` = `m`.`first_support_response_at`
WHERE `t`.`first_support_response_at` IS NULL;

-- Backfill deadline fields using user override support_routing first, then group support_routing, then global defaults.
UPDATE `support_tickets` AS `t`
INNER JOIN `users` AS `u` ON `u`.`id` = `t`.`user_id`
LEFT JOIN `user_groups` AS `g` ON `g`.`id` = `u`.`group_id`
SET
  `t`.`first_response_due_at` = COALESCE(
    `t`.`first_response_due_at`,
    DATE_ADD(
      COALESCE(`t`.`created_at`, CURRENT_TIMESTAMP),
      INTERVAL COALESCE(
        CASE
          WHEN `u`.`quota_override` IS NOT NULL AND LOCATE('"first_response_minutes":', `u`.`quota_override`) > 0 THEN
            NULLIF(CAST(TRIM(
              SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                  SUBSTRING(
                    `u`.`quota_override`,
                    LOCATE('"first_response_minutes":', `u`.`quota_override`) + CHAR_LENGTH('"first_response_minutes":')
                  ),
                  ',',
                  1
                ),
                '}',
                1
              )
            ) AS UNSIGNED), 0)
          ELSE NULL
        END,
        CASE
          WHEN `g`.`config` IS NOT NULL AND LOCATE('"first_response_minutes":', `g`.`config`) > 0 THEN
            NULLIF(CAST(TRIM(
              SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                  SUBSTRING(
                    `g`.`config`,
                    LOCATE('"first_response_minutes":', `g`.`config`) + CHAR_LENGTH('"first_response_minutes":')
                  ),
                  ',',
                  1
                ),
                '}',
                1
              )
            ) AS UNSIGNED), 0)
          ELSE NULL
        END,
        240
      ) MINUTE
    )
  ),
  `t`.`resolution_due_at` = COALESCE(
    `t`.`resolution_due_at`,
    DATE_ADD(
      COALESCE(`t`.`created_at`, CURRENT_TIMESTAMP),
      INTERVAL COALESCE(
        CASE
          WHEN `u`.`quota_override` IS NOT NULL AND LOCATE('"resolution_minutes":', `u`.`quota_override`) > 0 THEN
            NULLIF(CAST(TRIM(
              SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                  SUBSTRING(
                    `u`.`quota_override`,
                    LOCATE('"resolution_minutes":', `u`.`quota_override`) + CHAR_LENGTH('"resolution_minutes":')
                  ),
                  ',',
                  1
                ),
                '}',
                1
              )
            ) AS UNSIGNED), 0)
          ELSE NULL
        END,
        CASE
          WHEN `g`.`config` IS NOT NULL AND LOCATE('"resolution_minutes":', `g`.`config`) > 0 THEN
            NULLIF(CAST(TRIM(
              SUBSTRING_INDEX(
                SUBSTRING_INDEX(
                  SUBSTRING(
                    `g`.`config`,
                    LOCATE('"resolution_minutes":', `g`.`config`) + CHAR_LENGTH('"resolution_minutes":')
                  ),
                  ',',
                  1
                ),
                '}',
                1
              )
            ) AS UNSIGNED), 0)
          ELSE NULL
        END,
        1440
      ) MINUTE
    )
  ),
  `t`.`sla_status` = CASE
    WHEN `t`.`sla_status` IS NOT NULL AND TRIM(`t`.`sla_status`) <> '' THEN `t`.`sla_status`
    WHEN `t`.`status` IN ('resolved', 'closed') THEN 'resolved'
    ELSE 'pending'
  END
WHERE `t`.`first_response_due_at` IS NULL
   OR `t`.`resolution_due_at` IS NULL
   OR `t`.`sla_status` IS NULL
   OR TRIM(`t`.`sla_status`) = '';
