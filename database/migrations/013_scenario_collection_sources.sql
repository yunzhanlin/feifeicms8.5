SET NAMES utf8mb4;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND COLUMN_NAME = 'resource_type') = 0,
  'ALTER TABLE ffx_collection_sources ADD COLUMN resource_type VARCHAR(20) NOT NULL DEFAULT ''video'' AFTER source_type',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND COLUMN_NAME = 'media_source_id') = 0,
  'ALTER TABLE ffx_collection_sources ADD COLUMN media_source_id BIGINT UNSIGNED NULL AFTER resource_type',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND INDEX_NAME = 'uk_ffx_collection_sources_endpoint') > 0,
  'ALTER TABLE ffx_collection_sources DROP INDEX uk_ffx_collection_sources_endpoint',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND INDEX_NAME = 'uk_ffx_collection_sources_resource_endpoint') = 0,
  'ALTER TABLE ffx_collection_sources ADD UNIQUE INDEX uk_ffx_collection_sources_resource_endpoint (resource_type, endpoint(255))',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND INDEX_NAME = 'idx_ffx_collection_sources_media_source') = 0,
  'ALTER TABLE ffx_collection_sources ADD INDEX idx_ffx_collection_sources_media_source (media_source_id, resource_type)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_collection_sources' AND CONSTRAINT_NAME = 'fk_ffx_collection_sources_media_source') = 0,
  'ALTER TABLE ffx_collection_sources ADD CONSTRAINT fk_ffx_collection_sources_media_source FOREIGN KEY (media_source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

INSERT INTO ffx_schema_versions (version, description)
VALUES (13, 'independent scenario collection sources')
ON DUPLICATE KEY UPDATE description = VALUES(description);
