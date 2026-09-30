CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    kind       VARCHAR(64)     NOT NULL,
    title      VARCHAR(255)    NOT NULL,
    body       TEXT            NULL,
    url        VARCHAR(2048)   NULL,
    created_at INT UNSIGNED    NOT NULL,
    read_at    INT UNSIGNED    NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read (user_id, read_at, id),
    KEY idx_notifications_read_at (read_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
