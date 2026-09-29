SET NAMES utf8mb4;

SET @ffx_has_new_data_policy = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ffx_collection_sources'
    AND COLUMN_NAME = 'new_data_policy'
);
SET @ffx_new_data_policy_sql = IF(
  @ffx_has_new_data_policy = 0,
  'ALTER TABLE ffx_collection_sources ADD COLUMN new_data_policy VARCHAR(20) NOT NULL DEFAULT ''published'' AFTER category_mapping',
  'SELECT 1'
);
PREPARE ffx_new_data_policy_statement FROM @ffx_new_data_policy_sql;
EXECUTE ffx_new_data_policy_statement;
DEALLOCATE PREPARE ffx_new_data_policy_statement;

INSERT INTO ffx_schema_versions (version, description)
VALUES (11, 'collection source new data policy')
ON DUPLICATE KEY UPDATE description = VALUES(description);
