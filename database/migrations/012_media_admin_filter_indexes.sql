SET NAMES utf8mb4;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND COLUMN_NAME = 'admin_weekday') = 0,
  'ALTER TABLE ffx_media ADD COLUMN admin_weekday VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, ''$.weekday'')), '''')) STORED',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND COLUMN_NAME = 'admin_state') = 0,
  'ALTER TABLE ffx_media ADD COLUMN admin_state VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, ''$.state'')), '''')) STORED',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND COLUMN_NAME = 'admin_series') = 0,
  'ALTER TABLE ffx_media ADD COLUMN admin_series VARCHAR(255) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, ''$.series'')), '''')) STORED',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND COLUMN_NAME = 'admin_inputer') = 0,
  'ALTER TABLE ffx_media ADD COLUMN admin_inputer VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, ''$.inputer'')), '''')) STORED',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_admin_weekday') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_admin_weekday (admin_weekday, status, updated_at)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_admin_state') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_admin_state (admin_state, status, updated_at)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_admin_inputer') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_admin_inputer (admin_inputer, status, updated_at)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_updated') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_updated (status, updated_at, id)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

INSERT INTO ffx_schema_versions (version, description)
VALUES (12, 'indexed media administration filters')
ON DUPLICATE KEY UPDATE description = VALUES(description);
