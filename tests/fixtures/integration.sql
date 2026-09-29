INSERT INTO ff_list
    (list_id, list_pid, list_oid, list_sid, list_name, list_skin, list_dir, list_status, list_keywords, list_title, list_description, list_copyright, list_extend)
VALUES
    (1, 0, 1, 1, '电影', 'list', 'movie', 1, '电影', '电影频道', '电影测试分类', 0, '');

INSERT INTO ff_vod
    (vod_id, vod_cid, vod_name, vod_ename, vod_title, vod_type, vod_actor, vod_director, vod_content, vod_pic, vod_area, vod_language,
     vod_year, vod_continu, vod_total, vod_addtime, vod_hits, vod_status, vod_play, vod_url, vod_lines, vod_watch, vod_ending,
     vod_writer, vod_producer, vod_camera, vod_editor, vod_music, vod_art)
VALUES
    (1, 1, '迁移验收影片', 'migration-movie', '验证旧数据契约', '剧情', '测试演员', '测试导演', '用于端到端验收的本地记录', '', '中国', '国语',
     2026, '2集全', 2, UNIX_TIMESTAMP(), 100, 1, 'm3u8$$$mp4',
     '第1集$https://media.example.test/1.m3u8#第2集$https://media.example.test/2.m3u8$$$正片$https://media.example.test/movie.mp4',
     '', '', '', '', '', '', '', '', ''),
    (2, 1, '未发布影片', 'draft-movie', '', '剧情', '', '', '', '', '中国', '国语',
     2026, '更新中', 0, UNIX_TIMESTAMP(), 0, 0, '', '', '', '', '', '', '', '', '', '', '');

INSERT INTO ff_admin
    (admin_id, admin_name, admin_pwd, admin_count, admin_ok, admin_del, admin_ip, admin_email, admin_logintime)
VALUES
    (1, 'admin', MD5('admin888'), 0, 'all', 0, '127.0.0.1', 'admin@example.test', 0);
