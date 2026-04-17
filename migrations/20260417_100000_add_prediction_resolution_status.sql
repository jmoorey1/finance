-- BKL-012: Non-destructive missed prediction workflow

ALTER TABLE predicted_instances
    ADD COLUMN resolution_status ENUM('open','skipped') NOT NULL DEFAULT 'open' AFTER confirmed,
    ADD COLUMN resolved_at DATETIME NULL AFTER resolution_status,
    ADD COLUMN resolution_note VARCHAR(255) NULL AFTER resolved_at;

CREATE INDEX idx_predicted_instances_resolution
    ON predicted_instances (resolution_status, fulfilled, scheduled_date);
