CREATE TABLE IF NOT EXISTS audit (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module     VARCHAR(255)    NOT NULL,
    table_id   VARCHAR(255)    NOT NULL,
    old_value  TEXT            NULL,
    new_value  TEXT            NULL,
    user_id    BIGINT UNSIGNED NULL,
    username   VARCHAR(64)     NULL,
    message    VARCHAR(255)    NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_created_at (created_at),
    KEY idx_audit_user_id (user_id),
    KEY idx_audit_row (module, table_id),
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
