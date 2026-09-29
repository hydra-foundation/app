CREATE TABLE IF NOT EXISTS rate_limit_lockouts (
    policy      VARCHAR(64)  NOT NULL,
    identity    VARCHAR(255) NOT NULL,
    locked_at   INT UNSIGNED NOT NULL,
    until       INT UNSIGNED NOT NULL,
    lock_limit  INT UNSIGNED NOT NULL,
    lock_window INT UNSIGNED NOT NULL,
    PRIMARY KEY (policy, identity),
    KEY idx_rate_limit_lockouts_until (until)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
