-- Add request_id columns for correlation (MySQL / MariaDB)
ALTER TABLE audit_logs 
  ADD COLUMN request_id VARCHAR(64) NULL AFTER referrer;

ALTER TABLE error_logs 
  ADD COLUMN request_id VARCHAR(64) NULL AFTER script_name;

-- Optional simple index to accelerate equality lookups
CREATE INDEX IF NOT EXISTS idx_audit_logs_request_id ON audit_logs (request_id);
CREATE INDEX IF NOT EXISTS idx_error_logs_request_id ON error_logs (request_id);
