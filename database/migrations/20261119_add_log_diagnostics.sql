CREATE TABLE log_diagnostic_sessions (
    id INT NOT NULL AUTO_INCREMENT,
    created_by INT NOT NULL,
    name VARCHAR(160) NOT NULL,
    log_types VARCHAR(60) NOT NULL,
    log_from BIGINT NOT NULL,
    log_to BIGINT NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked_at BIGINT DEFAULT NULL,
    revoked_by INT DEFAULT NULL,
    rate_minute BIGINT NOT NULL DEFAULT 0,
    rate_count INT NOT NULL DEFAULT 0,
    query_count BIGINT NOT NULL DEFAULT 0,
    result_bytes BIGINT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_diag_sessions_creator (created_by, id),
    KEY idx_diag_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE log_diagnostic_credentials (
    id INT NOT NULL AUTO_INCREMENT,
    session_id INT NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    channel VARCHAR(12) NOT NULL,
    audience VARCHAR(512) NOT NULL,
    client_id VARCHAR(512) DEFAULT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked_at BIGINT DEFAULT NULL,
    revoked_by INT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_diag_credentials_hash (token_hash),
    KEY idx_diag_credentials_session (session_id, id),
    CONSTRAINT fk_diag_credentials_session FOREIGN KEY (session_id) REFERENCES log_diagnostic_sessions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE log_diagnostic_oauth_requests (
    id CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    client_id VARCHAR(512) NOT NULL,
    redirect_uri VARCHAR(1024) NOT NULL,
    resource VARCHAR(512) NOT NULL,
    state VARCHAR(1024) DEFAULT NULL,
    code_challenge CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    browser_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ticket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    assertion_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    session_id INT DEFAULT NULL,
    decided_by INT DEFAULT NULL,
    approved TINYINT NOT NULL DEFAULT 0,
    expires_at BIGINT NOT NULL,
    code_expires_at BIGINT DEFAULT NULL,
    completed_at BIGINT DEFAULT NULL,
    consumed_at BIGINT DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_diag_oauth_ticket (ticket_hash),
    UNIQUE KEY uq_diag_oauth_code (code_hash),
    UNIQUE KEY uq_diag_oauth_assertion (assertion_hash),
    KEY idx_diag_oauth_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE log_diagnostic_query_leases (
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    session_id INT NOT NULL,
    expires_at BIGINT NOT NULL,
    PRIMARY KEY (request_id),
    KEY idx_diag_leases_session (session_id, expires_at),
    KEY idx_diag_leases_expires (expires_at),
    CONSTRAINT fk_diag_leases_session FOREIGN KEY (session_id) REFERENCES log_diagnostic_sessions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE error_logs ADD KEY idx_error_logs_time_id (error_time, id);
ALTER TABLE audit_logs ADD KEY idx_audit_logs_diagnostic_session (operation_category, affected_id, id);

INSERT INTO cron_tasks (
    task_key, task_name, description, interval_minutes, enabled, next_run_at,
    last_status, consecutive_failures, settings_json
) VALUES (
    'log_diagnostic_cleanup', 'Log Diagnostic Cleanup',
    'Delete expired diagnostic OAuth transactions and query leases.',
    15, 1, CURRENT_TIMESTAMP, 'idle', 0, '{}'
);
