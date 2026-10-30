SET NAMES utf8mb4;
SET @ffx_sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_comments' AND COLUMN_NAME='author_avatar_url')=0,
  'ALTER TABLE ffx_comments ADD COLUMN author_avatar_url VARCHAR(1000) NOT NULL DEFAULT '''' AFTER author_name',
  'SELECT 1'
);
PREPARE ffx_statement FROM @ffx_sql; EXECUTE ffx_statement; DEALLOCATE PREPARE ffx_statement;
INSERT INTO ffx_schema_versions (version,description) VALUES (18,'original attribution and avatars for imported media comments')
ON DUPLICATE KEY UPDATE description=VALUES(description);
