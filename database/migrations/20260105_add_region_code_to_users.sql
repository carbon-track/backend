ALTER TABLE `users`
    ADD COLUMN `region_code` VARCHAR(16) NULL DEFAULT NULL AFTER `location`;

ALTER TABLE `users`
    ADD KEY `idx_users_region_code` (`region_code`);
