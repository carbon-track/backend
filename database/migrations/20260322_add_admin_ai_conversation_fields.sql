ALTER TABLE `audit_logs`
  ADD COLUMN `conversation_id` varchar(64) DEFAULT NULL AFTER `user_uuid`,
  ADD KEY `idx_audit_logs_conversation_id` (`conversation_id`),
  ADD KEY `idx_audit_logs_actor_conversation_created` (`actor_type`,`user_id`,`conversation_id`,`created_at`);

ALTER TABLE `llm_logs`
  ADD COLUMN `conversation_id` varchar(64) DEFAULT NULL AFTER `actor_id`,
  ADD COLUMN `turn_no` int(11) DEFAULT NULL AFTER `conversation_id`,
  ADD KEY `idx_llm_logs_conversation_id` (`conversation_id`),
  ADD KEY `idx_llm_logs_turn_no` (`turn_no`),
  ADD KEY `idx_llm_logs_actor_conversation_created` (`actor_type`,`actor_id`,`conversation_id`,`created_at`);
