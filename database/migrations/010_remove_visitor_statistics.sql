SET NAMES utf8mb4;

DROP TABLE IF EXISTS ffx_visit_logs;

INSERT INTO ffx_schema_versions (version, description)
VALUES (10, 'remove visitor statistics and request logging')
ON DUPLICATE KEY UPDATE description = VALUES(description);
