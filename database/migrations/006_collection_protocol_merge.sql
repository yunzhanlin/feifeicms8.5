ALTER TABLE ffx_play_sources
  ADD COLUMN collection_source_id BIGINT UNSIGNED NULL AFTER parser_key,
  ADD KEY idx_ffx_play_source_collection (collection_source_id, media_id),
  ADD CONSTRAINT fk_ffx_play_source_collection FOREIGN KEY (collection_source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL;

INSERT INTO ffx_schema_versions (version, description)
VALUES (6, 'FeiFei and MacCMS collection mapping, scenario import and source merge')
ON DUPLICATE KEY UPDATE description = VALUES(description);
