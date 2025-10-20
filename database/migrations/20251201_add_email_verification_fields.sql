-- Add email verification metadata columns and password reset helpers to users table
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `reset_token` varchar(255) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `reset_token_expires_at` datetime DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `email_verified_at` datetime DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verification_code` varchar(32) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verification_token` varchar(128) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verification_code_expires_at` datetime DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verification_attempts` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `verification_send_count` int(11) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `verification_last_sent_at` datetime DEFAULT NULL;

ALTER TABLE `users`
    ADD INDEX IF NOT EXISTS `idx_users_verification_token` (`verification_token`),
    ADD INDEX IF NOT EXISTS `idx_users_email_verified_at` (`email_verified_at`);

