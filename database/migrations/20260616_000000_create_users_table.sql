CREATE TABLE IF NOT EXISTS users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username          VARCHAR(64)     NOT NULL,
    email             VARCHAR(255)    NOT NULL,
    email_verified_at TIMESTAMP       NULL DEFAULT NULL,
    password_hash     VARCHAR(255)    NOT NULL,
    role              VARCHAR(32)     NOT NULL DEFAULT 'user',
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_users_username (username),
    UNIQUE KEY uniq_users_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
