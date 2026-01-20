ALTER TABLE `llm_logs`
    ADD COLUMN `context_json` mediumtext AFTER `usage_json`;
