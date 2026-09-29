CREATE TABLE ff_list (
  list_id INTEGER PRIMARY KEY, list_pid INTEGER NOT NULL, list_oid INTEGER NOT NULL, list_sid INTEGER NOT NULL,
  list_name TEXT NOT NULL, list_status INTEGER NOT NULL, list_dir TEXT NOT NULL
);
CREATE TABLE ff_vod (
  vod_id INTEGER PRIMARY KEY, vod_cid INTEGER NOT NULL, vod_name TEXT NOT NULL, vod_ename TEXT, vod_title TEXT,
  vod_keywords TEXT, vod_type TEXT, vod_actor TEXT, vod_director TEXT, vod_content TEXT, vod_pic TEXT, vod_area TEXT,
  vod_language TEXT, vod_year INTEGER, vod_continu TEXT, vod_addtime INTEGER, vod_hits INTEGER, vod_status INTEGER,
  vod_play TEXT, vod_url TEXT, vod_state TEXT, vod_gold REAL, vod_douban_score REAL
);
CREATE TABLE ff_admin (
  admin_id INTEGER PRIMARY KEY, admin_name TEXT NOT NULL, admin_pwd TEXT NOT NULL, admin_count INTEGER NOT NULL,
  admin_ok TEXT NOT NULL, admin_del INTEGER NOT NULL, admin_ip TEXT NOT NULL, admin_email TEXT NOT NULL, admin_logintime INTEGER NOT NULL
);
INSERT INTO ff_list VALUES (1, 0, 1, 1, '电影', 1, 'movie');
INSERT INTO ff_vod VALUES (
  1, 1, '迁移验收影片', 'migration-movie', '验证旧数据契约', '验收,迁移', '剧情', '测试演员', '测试导演',
  '用于端到端验收的本地记录', '', '中国', '国语', 2026, '2集全', 1790553600, 100, 1,
  'm3u8$$$mp4', '第1集$https://media.example.test/1.m3u8#第2集$https://media.example.test/2.m3u8$$$正片$https://media.example.test/movie.mp4',
  '完结', 8.5, 8.1
);
INSERT INTO ff_admin VALUES (1, 'admin', '7fef6171469e80d32c0559f88b377245', 0, 'all', 0, '127.0.0.1', 'admin@example.test', 0);
