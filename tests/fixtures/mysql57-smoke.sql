INSERT INTO ffx_categories (content_type, name, slug)
VALUES ('media', '测试分类', 'mysql57-smoke');

INSERT INTO ffx_media (category_id, title, slug, status, metadata)
SELECT id, 'MySQL 5.7 测试影片', 'mysql57-smoke', 'published', '{"weekday":"周六","state":"更新中","inputer":"compat"}'
FROM ffx_categories WHERE slug = 'mysql57-smoke';

SELECT admin_weekday, admin_state, admin_inputer FROM ffx_media WHERE slug = 'mysql57-smoke';
SELECT revision FROM ffx_search_outbox WHERE media_id = (SELECT id FROM ffx_media WHERE slug = 'mysql57-smoke');

UPDATE ffx_media SET title = 'MySQL 5.7 测试影片已更新' WHERE slug = 'mysql57-smoke';
SELECT revision FROM ffx_search_outbox WHERE media_id = (SELECT id FROM ffx_media WHERE slug = 'mysql57-smoke');

DELETE FROM ffx_media WHERE slug = 'mysql57-smoke';
DELETE FROM ffx_categories WHERE slug = 'mysql57-smoke';
