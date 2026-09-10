CREATE TABLE IF NOT EXISTS user_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    name    VARCHAR(64)     NOT NULL,
    value   VARCHAR(255)    NOT NULL,
    PRIMARY KEY (user_id, name),
    CONSTRAINT fk_user_preferences_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
