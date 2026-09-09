CREATE TABLE IF NOT EXISTS activity (
    id          BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED   NULL,
    username    VARCHAR(64)       NULL,
    method      VARCHAR(10)       NOT NULL,
    path        VARCHAR(512)      NOT NULL,
    query       VARCHAR(1024)     NOT NULL DEFAULT '',
    status      SMALLINT UNSIGNED NOT NULL,
    duration_ms INT UNSIGNED      NOT NULL,
    ip          VARCHAR(45)       NULL,
    user_agent  VARCHAR(512)      NULL,
    referer     VARCHAR(512)      NULL,
    created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_activity_created_at (created_at),
    KEY idx_activity_user_id (user_id),
    KEY idx_activity_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
