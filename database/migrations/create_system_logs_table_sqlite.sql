-- SQLite migration: create system_logs table
-- Use for local sqlite test database (test.db / test_inline.db)

CREATE TABLE IF NOT EXISTS system_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id TEXT,
  method TEXT,
  path TEXT,
  status_code INTEGER,
  user_id INTEGER,
  ip_address TEXT,
  user_agent TEXT,
  duration_ms REAL,
  request_body TEXT,
  response_body TEXT,
  created_at DATETIME DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON system_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_system_logs_status_code ON system_logs (status_code);
CREATE INDEX IF NOT EXISTS idx_system_logs_method ON system_logs (method);
CREATE INDEX IF NOT EXISTS idx_system_logs_user_id ON system_logs (user_id);
CREATE INDEX IF NOT EXISTS idx_system_logs_path ON system_logs (path);
