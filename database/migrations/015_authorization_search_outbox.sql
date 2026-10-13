-- The application writes this journal in the same transaction as media changes.
-- New installations do not require TRIGGER/SUPER or binary-log setting changes.
CREATE TABLE IF NOT EXISTS ffx_search_outbox (
  media_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
