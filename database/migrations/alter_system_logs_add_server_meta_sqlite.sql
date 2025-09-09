-- SQLite migration: add server_meta column to system_logs
ALTER TABLE system_logs ADD COLUMN server_meta TEXT;
