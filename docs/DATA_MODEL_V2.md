# FeiFeiCMS 新一代数据模型

## 目标

`ff_*` 表是 2012 年单表堆字段的设计：影片人物、播放线路、分集、标签和状态都被压在字符串中，评论、留言和论坛复用一张表，权限也是字符串。当前程序尚未投入生产，因此新版按全新系统建设，不再设置双写期，也不把旧表当作业务真相源。

新表使用 `ffx_` 前缀，老 `ff_*` 表只有一个可选用途：

1. 用户以后主动要求导入历史站点时，作为一次性数据源。

新前台、后台、搜索、缓存和 API 均只读写 `ffx_*`。兼容 API 如果需要旧字段名，也必须由新表即时组装，不能反向依赖旧表。

## 领域划分

| 领域 | 主表 | 设计要点 |
|---|---|---|
| 分类与站点 | `ffx_categories`, `ffx_navigation`, `ffx_site_settings` | 分类树、内容类型、slug 与 JSON 筛选配置分离 |
| 影视 | `ffx_media`, `ffx_media_categories`, `ffx_seasons`, `ffx_play_sources`, `ffx_episodes`, `ffx_media_assets` | 多分类、季度、线路、分集、预告片和字幕均为独立记录，不再解析 `$$$ / # / $` |
| 人物 | `ffx_people`, `ffx_media_people` | 演员、导演、编剧等使用角色关系表 |
| 资讯与专题 | `ffx_articles`, `ffx_topics`, `ffx_topic_media` | 文章和专题不再与影片字段混用 |
| 标签 | `ffx_tags`, `ffx_media_tags`, `ffx_article_tags` | 可索引的多对多关系 |
| 用户互动 | `ffx_users`, `ffx_comments`, `ffx_watch_history`, `ffx_favorites`, `ffx_ratings` | 评论目标类型显式化，播放记录可定位到分集 |
| 权限 | `ffx_admins`, `ffx_roles`, `ffx_permissions` 及两张关联表 | 不再使用 `admin_ok='all'` 字符串判权 |
| 付费 | `ffx_orders`, `ffx_cards` | 金额与状态可审计，不覆盖历史时间 |
| 采集与任务 | `ffx_collection_sources`, `ffx_external_refs`, `ffx_collection_jobs`, `ffx_jobs` | 外部 ID 单独去重，源、执行、进度、错误和幂等键独立 |
| 运营 | `ffx_players`, `ffx_slides`, `ffx_links`, `ffx_ads` | 原有运营数据保留，加入启停、排序与时间字段 |
| 运维 | `ffx_audit_logs`, `ffx_search_state`, `ffx_legacy_map` | 所有后台变更、索引状态和旧 ID 映射可追溯 |

## 核心约束

- 业务主键统一使用 `BIGINT UNSIGNED`，可保留老 ID，也避免中长期溢出。
- 时间使用 `DATETIME(6)` 与 UTC；老 Unix 秒在导入时转换。
- 所有文本表使用 `utf8mb4_0900_ai_ci`，表引擎为 InnoDB。
- 标题、slug、状态、发布时间和外键建组合索引；不为每个字段盲目建索引。
- JSON 只存非固定扩展属性；需要查询、排序、关联或约束的值必须是独立列。
- 密码只保存 `password_hash()` 结果；采集密钥和支付密钥不进业务表，使用加密凭据存储。
- 业务删除默认为软删除；订单、审计和任务记录不允许后台直接物理删除。

## 安装与可选导入

1. 新安装执行 `php think feifei:schema:install`，幂等创建 `ffx_*` 表并登记结构版本。
2. 首个管理员由独立命令创建，密码使用 PHP 当前 `PASSWORD_DEFAULT` 算法保存。
3. 旧库导入不是启动依赖；只有明确提供旧库时才运行导入器，把播放字符串拆成线路和分集。
4. 面向旧采集器的输出字段由 `ffx_play_sources`、`ffx_episodes` 组装，只是接口适配层。
5. 生产环境以迁移文件和结构版本为准，禁止在面板中手工改表。

## 不再保留的旧设计

- `vod_play` + `vod_url` 并行下标协议。
- 逗号拼接的专题影片 ID 和标签。
- `person_father_id/object_id` 多义字段。
- `forum_sid/cid/cid_ep` 推断评论目标。
- `admin_ok` 和其他以字符串表示的权限集。
- 以 `0` 同时表示“未知”、“不限”和“尚未发生”的时间与关联值。
