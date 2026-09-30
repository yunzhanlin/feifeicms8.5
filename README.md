# FeiFeiCMS 8.5 Beta 1

这是 FeiFeiCMS `4.3.201206` 向 ThinkPHP 8 的完整框架升级，不是另做一套 CMS。原始源码完整保存在 [`legacy/`](legacy/) 并作为功能、界面、模板标签和兼容路由的事实基线；新内核基于 ThinkPHP 8.1、PHP 8.2–8.5、MySQL 8.4 LTS、Redis 和 Meilisearch。当前发布版为 `8.5.0-beta1`，发布说明见 [`CHANGELOG.md`](CHANGELOG.md)。

当前版本已经建立完整的新运行时、规范化 `ffx_*` 数据模型、FeiFei 模板标签兼容层、MXOne 前台、Redis/搜索抽象、原版结构管理后台和容器化运行环境。数据模型见 [`docs/DATA_MODEL_V2.md`](docs/DATA_MODEL_V2.md)，功能范围与迁移边界见 [`docs/FEIFEICMS-TP8-MIGRATION-BLUEPRINT.md`](docs/FEIFEICMS-TP8-MIGRATION-BLUEPRINT.md)。

不使用 Docker 也可部署；宝塔 Linux 的站点目录、PHP 扩展、伪静态、数据迁移和验收步骤见 [`deploy/baota/README.md`](deploy/baota/README.md)。

本机宝塔兼容面板已有 PHP 8.5.10、MySQL 8.4.11、Redis 8.2.10 和 Meilisearch 1.53.2 的运行环境。空库安装后执行下述命令；最终验收使用的就是这套 Nginx + PHP-FPM 环境。

## 浏览器安装

将站点运行目录指向 `public/`，配置好伪静态后访问：

```text
https://你的域名/install.php
```

安装器会检查 PHP 扩展与目录权限，连接 MySQL 8，创建新的 `ffx_*` 数据表、超级管理员和 `.env`，最后生成 `runtime/install.lock`锁定安装入口。为防止数据覆盖，目标库已存在任何 `ffx_` 表时会拒绝安装。

## 命令行安装

```bash
cp .example.env .env
# 修改 .env 中的数据库、Meilisearch 和 APP_KEY 密钥，不要使用示例值
docker compose up -d --build
docker compose exec app php think feifei:schema:install
# 密码不会写入命令参数或日志
docker compose exec -e CMS_ADMIN_PASSWORD='替换为至少12位强密码' app php think feifei:admin:create admin
docker compose exec app php think feifei:doctor
docker compose exec app php think feifei:search:sync
```

已安装站点升级代码后不要重新导入整库，执行带数据库互斥锁的增量升级：

```bash
php think feifei:schema:upgrade
php think feifei:doctor
```

默认访问：

- 前台：`http://localhost:8080/`
- 健康检查：`http://localhost:8080/health`
- 兼容采集接口：`http://localhost:8080/api.php/provide/vod/?ac=list`

## 数据结构

`database/schema.sql` 是 Docker 首次启动入口，和 `database/schema-v2.sql` 保持完全一致。当前 V4 基线共 45 张表，主要变化：

- 影片、季度、播放线路、分集、海报/字幕/预告资源拆表；
- 分类、标签、人物、专题使用关系表，不保存逗号 ID；
- 用户、评论、收藏、评分、观看记录各自建模；
- 后台使用角色/权限关系和审计日志；
- 采集源、外部 ID、采集任务和通用任务分离，支持幂等与诊断；
- 固定可查询字段使用普通列，JSON 只保存非固定扩展属性。

旧库不是启动依赖。将来确需导入历史站点时，先在隔离库导入旧 SQL，再由专用导入器写入 `ffx_*` 和 `ffx_legacy_map`；`database/migrations/001_modernize_legacy.sql` 仅属于旧库预处理工具。

## 接口边界

- 正式业务只读写 `ffx_*` 表；播放数据绝不以 `$$$ / # / $` 字符串入库。
- `/api.php/provide/vod/` 可以输出常用采集字段，但在响应边界从线路/分集表组装。
- 旧 URL 可保留为跳转或适配入口，不反向决定内部模型。
- `legacy/Tpl` 不在 PHP 8 进程中直接执行；MXOne 已迁移到 PHP 8 视图层，并通过 `ff_mysql_*`、`ff_url_*` 与旧字段适配保持 FeiFei 模板合同。
- Meilisearch 作为 Xunsearch 的现代替代方案；服务不可用时按设置安全降级到 MySQL 搜索，不与业务代码耦合。

## 当前完成度

已完成 V2 结构、幂等安装、管理员与权限、影片主链路、搜索索引、采集输出适配，以及下列可实际操作的后台模块：

- 分类管理；
- 视频资料、播放线路与分集编辑；
- 文章、专题、人物；
- 采集源、采集任务、执行与失败重试；
- FeiFeiCMS 7.3 JSON 与 MacCMS JSON 双协议入库、独立剧情采集、跨来源视频合并及地区/语言标准化（详见 [`docs/COLLECTION-COMPATIBILITY.md`](docs/COLLECTION-COMPATIBILITY.md)）；
- 用户与评论审核；
- 导航、播放器、轮播、广告、友情链接；
- 订单与卡密；
- Redis 缓存、Meilisearch 索引、后台任务和审计日志。

管理界面参考 FeiFeiCMS 4.3/7.4 原始后台的信息结构重新实现：保留双层蓝色顶栏、按模块切换的 140px 左侧菜单、密集列表和分区表单；底层不执行旧 ThinkPHP 模板，桌面与移动端使用同一套响应式模板。

本地云栈面板验收入口为 `http://feifeicms-modern.localhost:19101/`，后台入口为 `http://feifeicms-modern.localhost:19101/admin.php`。账号信息保存在面板私有凭据文件中，不写入仓库。

## 验证

```bash
docker compose run --rm app composer validate --strict
docker compose run --rm app composer test
docker compose exec mysql mysqladmin ping -h 127.0.0.1 -uroot -p
docker compose exec redis redis-cli ping
curl -fsS http://localhost:8080/health
```

本地宝塔的最终功能、采集、播放和视觉结果见 [`docs/ACCEPTANCE-2026-09-29.md`](docs/ACCEPTANCE-2026-09-29.md)，第二阶段性能与稳定性改造见 [`docs/SECOND-PHASE-OPTIMIZATION-2026-09-30.md`](docs/SECOND-PHASE-OPTIMIZATION-2026-09-30.md)，底层验证记录见 [`docs/VERIFICATION.md`](docs/VERIFICATION.md)。
