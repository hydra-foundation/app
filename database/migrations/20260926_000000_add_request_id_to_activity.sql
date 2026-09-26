ALTER TABLE activity
    ADD COLUMN request_id VARCHAR(64) NULL,
    ADD KEY idx_activity_request_id (request_id);
