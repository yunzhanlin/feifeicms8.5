CREATE TABLE IF NOT EXISTS ffx_cron_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  task_type VARCHAR(50) NOT NULL DEFAULT 'collection',
  source_id BIGINT UNSIGNED NULL,
  schedule_time CHAR(5) NOT NULL DEFAULT '02:00',
  payload JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  last_run_at DATETIME(6) NULL,
  next_run_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_ffx_cron_due (status, next_run_at),
  CONSTRAINT fk_ffx_cron_source FOREIGN KEY (source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ffx_schema_versions (version, description)
VALUES (3, 'scheduled collection tasks')
ON DUPLICATE KEY UPDATE description = VALUES(description);
