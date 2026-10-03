ALTER TABLE ffx_media
  ADD COLUMN douban_id VARCHAR(32) NOT NULL DEFAULT '' AFTER subtitle,
  ADD COLUMN imdb_id VARCHAR(32) NOT NULL DEFAULT '' AFTER douban_id,
  ADD KEY idx_ffx_media_douban (douban_id),
  ADD KEY idx_ffx_media_imdb (imdb_id);

CREATE TABLE IF NOT EXISTS ffx_scenarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id BIGINT UNSIGNED NOT NULL,
  episode_no INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  content LONGTEXT NOT NULL,
  source_ref VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uk_ffx_scenarios_media_episode (media_id, episode_no),
  KEY idx_ffx_scenarios_feed (status, updated_at, id),
  CONSTRAINT fk_ffx_scenarios_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ffx_scenarios (media_id, episode_no, title, content, source_ref, sort_order, status, created_at, updated_at)
SELECT id, 1, '第1集', JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scenario')), '', 0,
       CASE WHEN status = 'published' THEN 'published' ELSE 'draft' END, created_at, updated_at
FROM ffx_media
WHERE JSON_VALID(metadata)
  AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.scenario')), '') <> ''
ON DUPLICATE KEY UPDATE content = VALUES(content), updated_at = VALUES(updated_at);

UPDATE ffx_media
SET metadata = JSON_REMOVE(metadata, '$.scenario')
WHERE JSON_VALID(metadata) AND JSON_CONTAINS_PATH(metadata, 'one', '$.scenario');

INSERT INTO ffx_schema_versions (version, description)
VALUES (5, 'standalone scenarios and media external ids')
ON DUPLICATE KEY UPDATE description = VALUES(description);
