SET NAMES utf8mb4;
SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_users' AND COLUMN_NAME='session_version')=0,
  'ALTER TABLE ffx_users ADD COLUMN session_version BIGINT UNSIGNED NOT NULL DEFAULT 1',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;
SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_legacy_map' AND COLUMN_NAME='parent_legacy_id')=0,
  'ALTER TABLE ffx_legacy_map ADD COLUMN parent_legacy_id BIGINT UNSIGNED NULL',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;
SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_comments' AND INDEX_NAME='idx_ffx_comments_replies')=0,
  'ALTER TABLE ffx_comments ADD INDEX idx_ffx_comments_replies (parent_id,target_type,status,id)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;
INSERT INTO ffx_schema_versions (version,description) VALUES (16,'session revocation, deferred legacy category links and bounded replies')
ON DUPLICATE KEY UPDATE description=VALUES(description);
