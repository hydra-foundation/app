CREATE TABLE IF NOT EXISTS jobs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job          VARCHAR(255)    NOT NULL,
    payload      LONGTEXT        NOT NULL,
    attempts     INT UNSIGNED    NOT NULL DEFAULT 0,
    available_at INT UNSIGNED    NOT NULL,
    reserved_at  INT UNSIGNED    NULL,
    reservation  CHAR(32)        NULL,
    created_at   INT UNSIGNED    NOT NULL,
    PRIMARY KEY (id),
    KEY idx_jobs_available (available_at),
    KEY idx_jobs_reservation (reservation)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
