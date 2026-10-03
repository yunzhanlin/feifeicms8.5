CREATE TABLE IF NOT EXISTS ffx_danmaku (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  video_key VARCHAR(160) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  text VARCHAR(200) NOT NULL,
  time_seconds DECIMAL(10,3) NOT NULL DEFAULT 0,
  mode TINYINT UNSIGNED NOT NULL DEFAULT 0,
  color CHAR(7) NOT NULL DEFAULT '#FFFFFF',
  is_border TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'approved',
  ip_address VARCHAR(45) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_ffx_danmaku_video (video_key, status, time_seconds),
  KEY idx_ffx_danmaku_user (user_id, created_at),
  CONSTRAINT fk_ffx_danmaku_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE SET NULL
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ffx_schema_versions (version, description)
VALUES (4, 'local danmaku storage')
ON DUPLICATE KEY UPDATE description = VALUES(description);
