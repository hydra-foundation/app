CREATE TABLE IF NOT EXISTS sent_mail (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sent_at       INT UNSIGNED    NOT NULL,
    transport     VARCHAR(16)     NOT NULL,
    from_address  VARCHAR(255)    NOT NULL,
    to_addresses  TEXT            NOT NULL,
    cc_addresses  TEXT            NOT NULL,
    bcc_addresses TEXT            NOT NULL,
    subject       TEXT            NOT NULL,
    text_body     MEDIUMTEXT      NULL,
    html_body     MEDIUMTEXT      NULL,
    PRIMARY KEY (id),
    KEY idx_sent_mail_sent_at (sent_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
