CREATE TABLE `llm_logs` (
    `id` int(11) NOT NULL,
    `request_id` varchar(64) DEFAULT NULL,
    `actor_type` varchar(20) NOT NULL,
    `actor_id` int(11) DEFAULT NULL,
    `source` varchar(120) DEFAULT NULL,
    `model` varchar(120) DEFAULT NULL,
    `prompt` mediumtext,
    `response_raw` mediumtext,
    `response_id` varchar(64) DEFAULT NULL,
    `status` varchar(20) DEFAULT NULL,
    `error_message` text,
    `prompt_tokens` int(11) DEFAULT NULL,
    `completion_tokens` int(11) DEFAULT NULL,
    `total_tokens` int(11) DEFAULT NULL,
    `latency_ms` decimal(10,2) DEFAULT NULL,
    `usage_json` mediumtext,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `llm_logs`
    ADD PRIMARY KEY (`id`),
    ADD KEY `idx_llm_logs_request_id` (`request_id`),
    ADD KEY `idx_llm_logs_actor` (`actor_type`, `actor_id`),
    ADD KEY `idx_llm_logs_created_at` (`created_at`),
    ADD KEY `idx_llm_logs_status` (`status`),
    ADD KEY `idx_llm_logs_model` (`model`(50));

ALTER TABLE `llm_logs`
    MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
