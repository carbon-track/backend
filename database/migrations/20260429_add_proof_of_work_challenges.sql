CREATE TABLE IF NOT EXISTS proof_of_work_challenges (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  challenge_id char(32) NOT NULL,
  challenge_hash char(64) NOT NULL,
  scope varchar(80) NOT NULL,
  difficulty tinyint(3) UNSIGNED NOT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_pow_challenges_challenge_id (challenge_id),
  KEY idx_pow_challenges_hash_scope (challenge_hash, scope),
  KEY idx_pow_challenges_expires_at (expires_at),
  KEY idx_pow_challenges_used_at (used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
