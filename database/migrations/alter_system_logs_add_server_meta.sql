-- Migration: add server_meta column to system_logs (MySQL)
ALTER TABLE `system_logs`
  ADD COLUMN `server_meta` MEDIUMTEXT NULL AFTER `response_body`;

-- Optional index for full-text like searches (comment out if not needed or MySQL <5.6 engine constraints):
-- ALTER TABLE `system_logs` ADD FULLTEXT KEY `ft_system_logs_server_meta` (`server_meta`);
