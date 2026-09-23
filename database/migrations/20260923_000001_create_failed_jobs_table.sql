CREATE TABLE IF NOT EXISTS failed_jobs (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job       VARCHAR(255)    NOT NULL,
    payload   LONGTEXT        NOT NULL,
    exception LONGTEXT        NOT NULL,
    failed_at INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_failed_jobs_failed_at (failed_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
