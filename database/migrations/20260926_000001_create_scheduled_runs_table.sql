CREATE TABLE IF NOT EXISTS scheduled_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    task         VARCHAR(255)    NOT NULL,
    outcome      VARCHAR(16)     NOT NULL,
    items        INT UNSIGNED    NULL,
    held_minutes INT UNSIGNED    NULL,
    error        TEXT            NULL,
    started_at   INT UNSIGNED    NOT NULL,
    duration_ms  INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    KEY idx_scheduled_runs_task (task, id),
    KEY idx_scheduled_runs_started_at (started_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
