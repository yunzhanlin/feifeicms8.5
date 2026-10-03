SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ffx_media_entitlements (
  user_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  points_spent INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_id, media_id),
  KEY idx_ffx_media_entitlements_media (media_id, created_at),
  CONSTRAINT fk_ffx_media_entitlements_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_entitlements_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ffx_schema_versions (version, description)
VALUES (8, 'media entitlement and paid playback fulfillment')
ON DUPLICATE KEY UPDATE description = VALUES(description);
