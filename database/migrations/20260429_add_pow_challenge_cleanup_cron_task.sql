INSERT INTO `cron_tasks` (
  `task_key`,
  `task_name`,
  `description`,
  `interval_minutes`,
  `enabled`,
  `next_run_at`,
  `last_status`,
  `consecutive_failures`,
  `settings_json`
)
SELECT
  'pow_challenge_cleanup',
  'Proof-of-Work Challenge Cleanup',
  'Delete expired or already consumed proof-of-work challenges outside anonymous request handling.',
  10,
  1,
  CURRENT_TIMESTAMP,
  'idle',
  0,
  '{}'
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1
  FROM `cron_tasks`
  WHERE `task_key` = 'pow_challenge_cleanup'
);
