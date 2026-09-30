SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS ffx_schema_versions (
  version INT UNSIGNED PRIMARY KEY,
  description VARCHAR(255) NOT NULL,
  applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS ffx_visit_logs;

CREATE TABLE IF NOT EXISTS ffx_site_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value JSON NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  content_type VARCHAR(32) NOT NULL,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  seo_title VARCHAR(255) NOT NULL DEFAULT '',
  seo_keywords VARCHAR(500) NOT NULL DEFAULT '',
  filter_options JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uk_ffx_categories_slug (content_type, slug),
  KEY idx_ffx_categories_tree (parent_id, sort_order, status),
  CONSTRAINT fk_ffx_categories_parent FOREIGN KEY (parent_id) REFERENCES ffx_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  original_title VARCHAR(255) NOT NULL DEFAULT '',
  subtitle VARCHAR(255) NOT NULL DEFAULT '',
  douban_id VARCHAR(32) NOT NULL DEFAULT '',
  imdb_id VARCHAR(32) NOT NULL DEFAULT '',
  summary TEXT NULL,
  content LONGTEXT NULL,
  poster_url VARCHAR(1000) NOT NULL DEFAULT '',
  backdrop_url VARCHAR(1000) NOT NULL DEFAULT '',
  media_type VARCHAR(32) NOT NULL DEFAULT 'video',
  area VARCHAR(80) NOT NULL DEFAULT '',
  language VARCHAR(80) NOT NULL DEFAULT '',
  release_year SMALLINT UNSIGNED NULL,
  release_date DATE NULL,
  episode_total INT UNSIGNED NULL,
  episode_label VARCHAR(50) NOT NULL DEFAULT '',
  is_completed TINYINT(1) NOT NULL DEFAULT 0,
  copyright_mode VARCHAR(30) NOT NULL DEFAULT 'normal',
  access_mode VARCHAR(30) NOT NULL DEFAULT 'free',
  price_points INT UNSIGNED NOT NULL DEFAULT 0,
  rating DECIMAL(4,2) NULL,
  rating_count INT UNSIGNED NOT NULL DEFAULT 0,
  view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dislike_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  weight INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  metadata JSON NULL,
  admin_weekday VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.weekday')), '')) STORED,
  admin_state VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state')), '')) STORED,
  admin_series VARCHAR(255) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.series')), '')) STORED,
  admin_inputer VARCHAR(80) GENERATED ALWAYS AS (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.inputer')), '')) STORED,
  UNIQUE KEY uk_ffx_media_slug (slug),
  KEY idx_ffx_media_feed (status, published_at, id),
  KEY idx_ffx_media_category (category_id, status, updated_at),
  KEY idx_ffx_media_year (release_year, status),
  KEY idx_ffx_media_rank (status, weight, view_count),
  KEY idx_ffx_media_douban (douban_id),
  KEY idx_ffx_media_imdb (imdb_id),
  KEY idx_ffx_media_admin_weekday (admin_weekday, status, updated_at),
  KEY idx_ffx_media_admin_state (admin_state, status, updated_at),
  KEY idx_ffx_media_admin_inputer (admin_inputer, status, updated_at),
  KEY idx_ffx_media_updated (status, updated_at, id),
  CONSTRAINT fk_ffx_media_category FOREIGN KEY (category_id) REFERENCES ffx_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media_categories (
  media_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (media_id, category_id),
  KEY idx_ffx_media_categories_category (category_id, is_primary, sort_order),
  CONSTRAINT fk_ffx_media_categories_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_categories_category FOREIGN KEY (category_id) REFERENCES ffx_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_seasons (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id BIGINT UNSIGNED NOT NULL,
  season_no INT UNSIGNED NOT NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  summary TEXT NULL,
  poster_url VARCHAR(1000) NOT NULL DEFAULT '',
  episode_total INT UNSIGNED NULL,
  release_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_seasons_media_no (media_id, season_no),
  KEY idx_ffx_seasons_feed (media_id, status, season_no),
  CONSTRAINT fk_ffx_seasons_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_play_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id BIGINT UNSIGNED NOT NULL,
  source_key VARCHAR(80) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  parser_key VARCHAR(80) NOT NULL DEFAULT '',
  collection_source_id BIGINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_play_source (media_id, source_key),
  KEY idx_ffx_play_source_order (media_id, status, sort_order),
  KEY idx_ffx_play_source_collection (collection_source_id, media_id),
  CONSTRAINT fk_ffx_play_source_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_episodes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id BIGINT UNSIGNED NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  season_id BIGINT UNSIGNED NULL,
  episode_no INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  media_url TEXT NOT NULL,
  duration_seconds INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  metadata JSON NULL,
  UNIQUE KEY uk_ffx_episode_no (source_id, episode_no),
  KEY idx_ffx_episode_media (media_id, status, sort_order),
  CONSTRAINT fk_ffx_episode_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_episode_source FOREIGN KEY (source_id) REFERENCES ffx_play_sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_episode_season FOREIGN KEY (season_id) REFERENCES ffx_seasons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media_assets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id BIGINT UNSIGNED NOT NULL,
  episode_id BIGINT UNSIGNED NULL,
  asset_type VARCHAR(30) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  language VARCHAR(30) NOT NULL DEFAULT '',
  label VARCHAR(120) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  metadata JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_ffx_media_assets_media (media_id, asset_type, sort_order),
  KEY idx_ffx_media_assets_episode (episode_id, asset_type),
  CONSTRAINT fk_ffx_media_assets_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_assets_episode FOREIGN KEY (episode_id) REFERENCES ffx_episodes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_people (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(30) NOT NULL DEFAULT 'person',
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  aliases VARCHAR(500) NOT NULL DEFAULT '',
  gender VARCHAR(30) NOT NULL DEFAULT '',
  nationality VARCHAR(80) NOT NULL DEFAULT '',
  birthday DATE NULL,
  profession VARCHAR(255) NOT NULL DEFAULT '',
  avatar_url VARCHAR(1000) NOT NULL DEFAULT '',
  backdrop_url VARCHAR(1000) NOT NULL DEFAULT '',
  summary VARCHAR(1000) NOT NULL DEFAULT '',
  biography LONGTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  metadata JSON NULL,
  UNIQUE KEY uk_ffx_people_slug (slug),
  KEY idx_ffx_people_name (name),
  KEY idx_ffx_people_status (kind, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media_people (
  media_id BIGINT UNSIGNED NOT NULL,
  person_id BIGINT UNSIGNED NOT NULL,
  credit_type VARCHAR(30) NOT NULL,
  character_name VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (media_id, person_id, credit_type, character_name),
  KEY idx_ffx_media_people_person (person_id, credit_type),
  CONSTRAINT fk_ffx_media_people_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_people_person FOREIGN KEY (person_id) REFERENCES ffx_people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_articles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  content LONGTEXT NULL,
  cover_url VARCHAR(1000) NOT NULL DEFAULT '',
  author VARCHAR(120) NOT NULL DEFAULT '',
  source_url VARCHAR(1000) NOT NULL DEFAULT '',
  view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  weight INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  metadata JSON NULL,
  UNIQUE KEY uk_ffx_articles_slug (slug),
  KEY idx_ffx_articles_feed (status, published_at, id),
  KEY idx_ffx_articles_category (category_id, status, published_at),
  CONSTRAINT fk_ffx_articles_category FOREIGN KEY (category_id) REFERENCES ffx_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_topics (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  summary TEXT NULL,
  content LONGTEXT NULL,
  logo_url VARCHAR(1000) NOT NULL DEFAULT '',
  banner_url VARCHAR(1000) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'draft',
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  metadata JSON NULL,
  UNIQUE KEY uk_ffx_topics_slug (slug),
  KEY idx_ffx_topics_feed (status, published_at),
  CONSTRAINT fk_ffx_topics_category FOREIGN KEY (category_id) REFERENCES ffx_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_topic_media (
  topic_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (topic_id, media_id),
  CONSTRAINT fk_ffx_topic_media_topic FOREIGN KEY (topic_id) REFERENCES ffx_topics(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_topic_media_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_tags (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(30) NOT NULL,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_tags_scope_slug (scope, slug),
  KEY idx_ffx_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media_tags (
  media_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (media_id, tag_id),
  CONSTRAINT fk_ffx_media_tags_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_tags_tag FOREIGN KEY (tag_id) REFERENCES ffx_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_article_tags (
  article_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (article_id, tag_id),
  CONSTRAINT fk_ffx_article_tags_article FOREIGN KEY (article_id) REFERENCES ffx_articles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_article_tags_tag FOREIGN KEY (tag_id) REFERENCES ffx_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL,
  avatar_url VARCHAR(1000) NOT NULL DEFAULT '',
  points BIGINT NOT NULL DEFAULT 0,
  group_key VARCHAR(50) NOT NULL DEFAULT 'member',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_ip VARCHAR(45) NULL,
  last_login_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  UNIQUE KEY uk_ffx_users_username (username),
  UNIQUE KEY uk_ffx_users_email (email),
  KEY idx_ffx_users_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  parent_id BIGINT UNSIGNED NULL,
  target_type VARCHAR(30) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  episode_id BIGINT UNSIGNED NULL,
  title VARCHAR(255) NOT NULL DEFAULT '',
  content TEXT NOT NULL,
  author_name VARCHAR(100) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  dislike_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at DATETIME(6) NULL,
  KEY idx_ffx_comments_target (target_type, target_id, status, created_at),
  KEY idx_ffx_comments_user (user_id, created_at),
  CONSTRAINT fk_ffx_comments_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ffx_comments_parent FOREIGN KEY (parent_id) REFERENCES ffx_comments(id) ON DELETE SET NULL,
  CONSTRAINT fk_ffx_comments_episode FOREIGN KEY (episode_id) REFERENCES ffx_episodes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_watch_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  episode_id BIGINT UNSIGNED NULL,
  progress_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  watched_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_watch_history_user_media (user_id, media_id),
  KEY idx_ffx_watch_history_recent (user_id, watched_at),
  CONSTRAINT fk_ffx_watch_history_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_watch_history_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_watch_history_episode FOREIGN KEY (episode_id) REFERENCES ffx_episodes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_favorites (
  user_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_id, media_id),
  CONSTRAINT fk_ffx_favorites_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_favorites_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_media_entitlements (
  user_id BIGINT UNSIGNED NOT NULL,
  media_id BIGINT UNSIGNED NOT NULL,
  points_spent INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (user_id, media_id),
  KEY idx_ffx_media_entitlements_media (media_id, created_at),
  CONSTRAINT fk_ffx_media_entitlements_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_media_entitlements_media FOREIGN KEY (media_id) REFERENCES ffx_media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_ratings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  target_type VARCHAR(30) NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  score DECIMAL(4,2) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_ratings_user_target (user_id, target_type, target_id),
  KEY idx_ffx_ratings_target (target_type, target_id),
  CONSTRAINT fk_ffx_ratings_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  login_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_login_ip VARCHAR(45) NULL,
  last_login_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_key VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_roles_key (role_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(120) NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  UNIQUE KEY uk_ffx_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_admin_roles (
  admin_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (admin_id, role_id),
  CONSTRAINT fk_ffx_admin_roles_admin FOREIGN KEY (admin_id) REFERENCES ffx_admins(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_admin_roles_role FOREIGN KEY (role_id) REFERENCES ffx_roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_ffx_role_permissions_role FOREIGN KEY (role_id) REFERENCES ffx_roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_ffx_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES ffx_permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  product_type VARCHAR(40) NOT NULL,
  product_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 1,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'CNY',
  payment_method VARCHAR(50) NOT NULL DEFAULT '',
  payment_reference VARCHAR(255) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  paid_at DATETIME(6) NULL,
  confirmed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  metadata JSON NULL,
  UNIQUE KEY uk_ffx_orders_no (order_no),
  KEY idx_ffx_orders_user (user_id, created_at),
  KEY idx_ffx_orders_status (status, created_at),
  CONSTRAINT fk_ffx_orders_user FOREIGN KEY (user_id) REFERENCES ffx_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_cards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_hash CHAR(64) NOT NULL,
  face_value INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'unused',
  used_by BIGINT UNSIGNED NULL,
  used_at DATETIME(6) NULL,
  expires_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_cards_hash (card_hash),
  KEY idx_ffx_cards_status (status, created_at),
  CONSTRAINT fk_ffx_cards_user FOREIGN KEY (used_by) REFERENCES ffx_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_collection_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  endpoint VARCHAR(1000) NOT NULL,
  source_type VARCHAR(40) NOT NULL,
  credential_ref VARCHAR(255) NULL,
  category_mapping JSON NULL,
  new_data_policy VARCHAR(20) NOT NULL DEFAULT 'published',
  status VARCHAR(20) NOT NULL DEFAULT 'disabled',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_collection_sources_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_external_refs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(80) NOT NULL,
  external_id VARCHAR(255) NOT NULL,
  source_url VARCHAR(1000) NOT NULL DEFAULT '',
  checksum CHAR(64) NOT NULL DEFAULT '',
  payload JSON NULL,
  last_synced_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_external_refs_provider (provider, entity_type, external_id),
  KEY idx_ffx_external_refs_entity (entity_type, entity_id),
  KEY idx_ffx_external_refs_source (source_id, updated_at),
  CONSTRAINT fk_ffx_external_refs_source FOREIGN KEY (source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_collection_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_id BIGINT UNSIGNED NOT NULL,
  idempotency_key VARCHAR(120) NOT NULL,
  mode VARCHAR(30) NOT NULL,
  state VARCHAR(30) NOT NULL DEFAULT 'queued',
  cursor_value VARCHAR(500) NOT NULL DEFAULT '',
  processed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at DATETIME(6) NULL,
  finished_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_collection_jobs_idem (idempotency_key),
  KEY idx_ffx_collection_jobs_state (state, created_at),
  CONSTRAINT fk_ffx_collection_jobs_source FOREIGN KEY (source_id) REFERENCES ffx_collection_sources(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_cron_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  task_type VARCHAR(50) NOT NULL DEFAULT 'collection',
  source_id BIGINT UNSIGNED NULL,
  schedule_type VARCHAR(20) NOT NULL DEFAULT 'daily',
  schedule_time CHAR(5) NOT NULL DEFAULT '02:00',
  interval_minutes SMALLINT UNSIGNED NULL,
  payload JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  last_run_at DATETIME(6) NULL,
  next_run_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  KEY idx_ffx_cron_due (status, next_run_at),
  CONSTRAINT fk_ffx_cron_source FOREIGN KEY (source_id) REFERENCES ffx_collection_sources(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_players (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  player_key VARCHAR(80) NOT NULL,
  name VARCHAR(120) NOT NULL,
  parser_url VARCHAR(1000) NOT NULL DEFAULT '',
  config JSON NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_players_key (player_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_navigation (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  title VARCHAR(100) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  target VARCHAR(20) NOT NULL DEFAULT '_self',
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  KEY idx_ffx_navigation_order (parent_id, status, sort_order),
  CONSTRAINT fk_ffx_navigation_parent FOREIGN KEY (parent_id) REFERENCES ffx_navigation(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_slides (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  image_url VARCHAR(1000) NOT NULL,
  mobile_image_url VARCHAR(1000) NOT NULL DEFAULT '',
  target_url VARCHAR(1000) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  starts_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  KEY idx_ffx_slides_active (status, sort_order, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  logo_url VARCHAR(1000) NOT NULL DEFAULT '',
  link_type VARCHAR(30) NOT NULL DEFAULT 'text',
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'enabled',
  KEY idx_ffx_links_active (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_ads (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slot_key VARCHAR(100) NOT NULL,
  name VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'disabled',
  starts_at DATETIME(6) NULL,
  ends_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_ads_slot (slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_type VARCHAR(80) NOT NULL,
  idempotency_key VARCHAR(120) NOT NULL,
  payload JSON NULL,
  state VARCHAR(30) NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  available_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  reserved_at DATETIME(6) NULL,
  finished_at DATETIME(6) NULL,
  error_message TEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uk_ffx_jobs_idem (idempotency_key),
  KEY idx_ffx_jobs_queue (state, available_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  target_type VARCHAR(80) NOT NULL,
  target_id VARCHAR(120) NOT NULL DEFAULT '',
  request_id VARCHAR(64) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NULL,
  before_data JSON NULL,
  after_data JSON NULL,
  result VARCHAR(20) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_ffx_audit_target (target_type, target_id, created_at),
  KEY idx_ffx_audit_admin (admin_id, created_at),
  CONSTRAINT fk_ffx_audit_admin FOREIGN KEY (admin_id) REFERENCES ffx_admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_search_state (
  index_name VARCHAR(100) PRIMARY KEY,
  last_indexed_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  indexed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  pending_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_success_at DATETIME(6) NULL,
  last_error_at DATETIME(6) NULL,
  last_error TEXT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS ffx_legacy_map (
  entity_type VARCHAR(50) NOT NULL,
  legacy_id BIGINT UNSIGNED NOT NULL,
  new_id BIGINT UNSIGNED NOT NULL,
  checksum CHAR(64) NOT NULL DEFAULT '',
  migrated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (entity_type, legacy_id),
  UNIQUE KEY uk_ffx_legacy_map_new (entity_type, new_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO ffx_schema_versions (version, description)
VALUES (12, 'indexed media administration filters')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO ffx_roles (role_key, name, description)
VALUES ('super_admin', '超级管理员', '拥有所有管理权限')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO ffx_permissions (permission_key, name, description) VALUES
  ('dashboard.view', '查看仪表盘', ''),
  ('media.manage', '管理影视内容', ''),
  ('category.manage', '管理分类', ''),
  ('content.manage', '管理资讯与专题', ''),
  ('user.manage', '管理用户与互动', ''),
  ('collection.manage', '管理采集与任务', ''),
  ('system.manage', '管理系统设置', '')
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);

INSERT INTO ffx_categories (content_type, name, slug, sort_order, status) VALUES
  ('media', '电影', 'movies', 10, 'published'),
  ('media', '电视剧', 'series', 20, 'published'),
  ('media', '动漫', 'animation', 30, 'published'),
  ('media', '综艺', 'variety', 40, 'published'),
  ('article', '资讯', 'news', 50, 'published'),
  ('topic', '专题', 'topics', 60, 'published'),
  ('person', '人物', 'people', 70, 'published')
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order), status = VALUES(status);

INSERT INTO ffx_site_settings (setting_key, setting_value, is_public) VALUES
  ('site.name', JSON_QUOTE('飞飞影视'), 1),
  ('site.description', JSON_QUOTE('发现值得观看的影视内容'), 1),
  ('content.default_status', JSON_QUOTE('draft'), 0)
ON DUPLICATE KEY UPDATE is_public = VALUES(is_public);
