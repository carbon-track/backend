ALTER TABLE `audit_logs`
  ADD COLUMN `user_uuid` char(36) DEFAULT NULL AFTER `user_id`,
  ADD KEY `idx_audit_logs_user_uuid` (`user_uuid`);

UPDATE `audit_logs` al
LEFT JOIN `users` u ON u.id = al.user_id
SET al.user_uuid = LOWER(u.uuid)
WHERE al.user_uuid IS NULL
  AND u.uuid IS NOT NULL
  AND TRIM(u.uuid) <> '';

ALTER TABLE `system_logs`
  ADD COLUMN `user_uuid` char(36) DEFAULT NULL AFTER `user_id`,
  ADD KEY `idx_system_logs_user_uuid` (`user_uuid`);

UPDATE `system_logs` sl
LEFT JOIN `users` u ON u.id = sl.user_id
SET sl.user_uuid = LOWER(u.uuid)
WHERE sl.user_uuid IS NULL
  AND u.uuid IS NOT NULL
  AND TRIM(u.uuid) <> '';

DROP VIEW IF EXISTS `admin_operations`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `admin_operations` AS
SELECT
  al.id AS id,
  al.user_id AS user_id,
  al.user_uuid AS user_uuid,
  al.actor_type AS actor_type,
  al.action AS action,
  al.data AS data,
  al.old_data AS old_data,
  al.new_data AS new_data,
  al.affected_table AS affected_table,
  al.affected_id AS affected_id,
  al.status AS status,
  al.response_code AS response_code,
  al.session_id AS session_id,
  al.referrer AS referrer,
  al.operation_category AS operation_category,
  al.operation_subtype AS operation_subtype,
  al.change_type AS change_type,
  al.ip_address AS ip_address,
  al.user_agent AS user_agent,
  al.request_method AS request_method,
  al.endpoint AS endpoint,
  al.created_at AS created_at,
  u.username AS admin_username,
  u.email AS admin_email
FROM audit_logs al
LEFT JOIN users u ON al.user_id = u.id
WHERE al.actor_type = 'admin'
ORDER BY al.created_at DESC;

DROP VIEW IF EXISTS `recent_audit_activities`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `recent_audit_activities` AS
SELECT
  al.id AS id,
  al.actor_type AS actor_type,
  al.user_id AS user_id,
  al.user_uuid AS user_uuid,
  al.action AS action,
  al.operation_category AS operation_category,
  al.operation_subtype AS operation_subtype,
  al.change_type AS change_type,
  al.status AS status,
  al.ip_address AS ip_address,
  al.created_at AS created_at
FROM audit_logs al
WHERE al.created_at >= (NOW() - INTERVAL 7 DAY)
ORDER BY al.created_at DESC;
