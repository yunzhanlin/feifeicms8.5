-- Transactional outbox: raw SQL, ORM, collection and migration writes share
-- the same durable queue. No remote search call is made inside a DB write.
CREATE TABLE IF NOT EXISTS ffx_search_outbox (
  media_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TRIGGER IF EXISTS ffx_media_search_insert;
CREATE TRIGGER ffx_media_search_insert AFTER INSERT ON ffx_media FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id) VALUES (NEW.id)
ON DUPLICATE KEY UPDATE revision = revision + 1;

DROP TRIGGER IF EXISTS ffx_media_search_update;
CREATE TRIGGER ffx_media_search_update AFTER UPDATE ON ffx_media FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id)
SELECT NEW.id WHERE NOT (
  OLD.title <=> NEW.title AND OLD.original_title <=> NEW.original_title AND
  OLD.subtitle <=> NEW.subtitle AND OLD.summary <=> NEW.summary AND
  OLD.content <=> NEW.content AND OLD.area <=> NEW.area AND
  OLD.language <=> NEW.language AND OLD.category_id <=> NEW.category_id AND
  OLD.release_year <=> NEW.release_year AND OLD.media_type <=> NEW.media_type AND
  OLD.status <=> NEW.status AND OLD.deleted_at <=> NEW.deleted_at AND
  OLD.published_at <=> NEW.published_at AND OLD.rating <=> NEW.rating AND OLD.weight <=> NEW.weight
) ON DUPLICATE KEY UPDATE revision = revision + 1;

DROP TRIGGER IF EXISTS ffx_media_search_delete;
CREATE TRIGGER ffx_media_search_delete AFTER DELETE ON ffx_media FOR EACH ROW
INSERT INTO ffx_search_outbox (media_id) VALUES (OLD.id)
ON DUPLICATE KEY UPDATE revision = revision + 1;

INSERT INTO ffx_roles (role_key, name, description) VALUES
('editor', '内容编辑', '管理影片、剧情、分类、资讯和采集，不允许系统或文件管理')
ON DUPLICATE KEY UPDATE name = VALUES(name);
INSERT IGNORE INTO ffx_role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM ffx_roles r CROSS JOIN ffx_permissions p
WHERE r.role_key = 'editor' AND p.permission_key IN
('dashboard.view','media.manage','category.manage','content.manage','collection.manage');
-- Existing unassigned accounts become restricted editors, never super admins.
INSERT IGNORE INTO ffx_admin_roles (admin_id, role_id)
SELECT a.id, r.id FROM ffx_admins a JOIN ffx_roles r ON r.role_key='editor'
WHERE NOT EXISTS (SELECT 1 FROM ffx_admin_roles ar WHERE ar.admin_id=a.id);

INSERT INTO ffx_schema_versions (version, description)
VALUES (15, 'enforced admin roles and transactional search outbox')
ON DUPLICATE KEY UPDATE description = VALUES(description);
