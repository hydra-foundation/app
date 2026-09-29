CREATE TABLE IF NOT EXISTS sign_ins (
    id           CHAR(32)        NOT NULL,
    user_id      BIGINT UNSIGNED NOT NULL,
    created_at   INT UNSIGNED    NOT NULL,
    last_seen_at INT UNSIGNED    NOT NULL,
    ip           VARCHAR(45)     NULL,
    user_agent   VARCHAR(255)    NULL,
    PRIMARY KEY (id),
    KEY idx_sign_ins_user (user_id),
    KEY idx_sign_ins_last_seen (last_seen_at),
    CONSTRAINT fk_sign_ins_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
