ALTER TABLE ffx_cron_tasks
  ADD COLUMN schedule_type VARCHAR(20) NOT NULL DEFAULT 'daily' AFTER source_id,
  ADD COLUMN interval_minutes SMALLINT UNSIGNED NULL AFTER schedule_time;

INSERT INTO ffx_schema_versions (version, description)
VALUES (7, 'cron interval, hourly and daily schedule modes')
ON DUPLICATE KEY UPDATE description = VALUES(description);
