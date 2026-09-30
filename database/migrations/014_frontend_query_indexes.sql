SET NAMES utf8mb4;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_categories' AND INDEX_NAME = 'idx_ffx_categories_frontend') = 0,
  'ALTER TABLE ffx_categories ADD INDEX idx_ffx_categories_frontend (content_type, status, deleted_at, sort_order, id)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_frontend_popular') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_frontend_popular (status, deleted_at, view_count, id)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ffx_media' AND INDEX_NAME = 'idx_ffx_media_frontend_category') = 0,
  'ALTER TABLE ffx_media ADD INDEX idx_ffx_media_frontend_category (category_id, status, deleted_at, published_at, id)',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;

INSERT INTO ffx_schema_versions (version, description)
VALUES (14, 'indexed cached frontend queries')
ON DUPLICATE KEY UPDATE description = VALUES(description);
