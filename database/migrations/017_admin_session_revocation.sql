SET NAMES utf8mb4;
SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_admins' AND COLUMN_NAME='session_version')=0,
  'ALTER TABLE ffx_admins ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;
INSERT INTO ffx_schema_versions (version,description) VALUES (17,'administrator session revocation')
ON DUPLICATE KEY UPDATE description=VALUES(description);
