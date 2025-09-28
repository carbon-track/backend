-- Migration: add priority column to messages
-- Adds a varchar(20) priority column with default 'normal'
ALTER TABLE `messages`
  ADD COLUMN `priority` VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER `content`;

-- Optional: add index for ordering queries by priority
CREATE INDEX `idx_messages_priority` ON `messages` (`priority`);
