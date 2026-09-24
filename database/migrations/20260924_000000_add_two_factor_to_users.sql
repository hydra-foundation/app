ALTER TABLE users
    ADD COLUMN two_factor_secret     TEXT            NULL,
    ADD COLUMN two_factor_enabled_at TIMESTAMP       NULL,
    ADD COLUMN two_factor_step       BIGINT UNSIGNED NULL;

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    user_id BIGINT UNSIGNED NOT NULL,
    hash    VARCHAR(255)    NOT NULL,
    PRIMARY KEY (user_id, hash),
    CONSTRAINT fk_user_recovery_codes_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
