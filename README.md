# FeiFeiCMS 8.5

#### 系统介绍

飞飞影视导航系统（FeiFeiCMS）是一款开源的 PHP 影视内容管理程序。8.5 版使用 ThinkPHP 8，提供视频点播、多线路与分集、资讯、人物、专题、分集剧情、评论和采集管理，并兼容常用飞飞模板标签。自带 MXOne 前台模板，可作为制作新模板的起点。

#### 环境要求

- PHP 8.2～8.5，安装 `curl`、`dom`、`fileinfo`、`gd`、`intl`、`mbstring`、`openssl`、`pdo_mysql`、`xml`、`xmlwriter`、`zip` 扩展。
- MySQL 5.7.13+ 或 8.x；不支持 MySQL 5.6、5.5 和 MariaDB。
- Nginx 或 Apache、Composer 2。Redis 和 Meilisearch 为可选服务；未启用时可分别使用文件缓存和 MySQL 搜索。
- 数据库账号需要建表、读写和创建触发器的权限。安装、升级和恢复会先检查权限；报告 `1419` 时，请数据库管理员处理二进制日志的触发器限制后重试。

#### 主要功能

- 免费、VIP 和积分点播；多线路选集、播放记录、收藏与评分。
- 视频、资讯、人物、专题、标签、评论、独立分集剧情与运营组件。
- 飞飞 JSON、MacCMS JSON 采集，分类绑定、跨来源影片合并与定时采集。
- 自定义 URL、伪静态、旧地址兼容、缓存和搜索索引。
- 电脑、手机和平板自适应的 MXOne 示例模板。

采集前请在后台“系统 → 播放来源”添加并启用资源库使用的播放器标识，例如 `bfzym3u8`。未添加、已停用或不在采集白名单中的线路不会入库；影片没有任何可用线路时整条跳过。采集白名单留空表示使用全部已启用播放器，不是允许任意播放器。同一播放器可以有多组线路，API 输出仍使用后台注册的播放器标识。

需要解析的平台地址可在“系统 → 播放来源”配置解析接口，并在“独立播放器”中填写解析域名白名单、选择 JSON 或 iframe 模式。远程弹幕在同一设置页面配置 API；接口格式及模板接入示例见 [播放器与弹幕调用](docs/模板标签.md#播放器与远程弹幕)。

#### 安装说明

1. 从[最新正式版](https://github.com/yunzhanlin/feifeicms8.5/releases/latest)下载 `feifeicms8.5.zip` 完整安装包并解压到网站目录。该包已包含运行依赖；使用仓库源码时，须先执行 `composer install --no-dev --classmap-authoritative`。
2. 将网站运行目录设为项目的 `public/`，不能指向项目根目录。让 PHP-FPM 用户能够写入 `runtime/` 和 `public/uploads/`。
3. Nginx 使用 [`deploy/baota/thinkphp.conf`](deploy/baota/thinkphp.conf) 的伪静态规则；Apache 已提供 `public/.htaccess`，需开启 `mod_rewrite` 和 `AllowOverride All`。
4. 浏览器访问 `https://您的域名/install.php`，按向导填写数据库、管理员账号和站点信息。安装程序只接受没有 `ffx_*` 表的空数据库，不会覆盖已有数据。
5. 安装后访问 `https://您的域名/admin.php` 登录。管理员密码由安装时自行设置，没有通用默认密码。

宝塔用户还可以参考 [`deploy/baota/`](deploy/baota/) 中的站点与计划任务示例。

Docker 部署先复制 `.example.env` 为 `.env`，替换其中的密码和密钥，并保留数据库、Redis、搜索主机名为相应容器名称。配置文件只读挂载且随容器重建保留，使用以下命令初始化，不使用网页安装向导：

```bash
docker compose up -d --build
docker compose exec app php think feifei:schema:install
read -rs -p '管理员密码（至少 12 位）：' CMS_ADMIN_PASSWORD
export CMS_ADMIN_PASSWORD
docker compose exec -e CMS_ADMIN_PASSWORD app php think feifei:admin:create admin
unset CMS_ADMIN_PASSWORD
```

初始化后访问 `http://您的主机:8080/admin.php`。Docker 升级请更新源码并重新构建容器，再执行 `docker compose exec app php think feifei:schema:upgrade`；不要在只读配置的容器中使用在线覆盖更新。

Docker 使用独立 MySQL 容器并允许站点创建触发器。不要把这里的配置直接用于共享数据库；共享数据库请由管理员按服务器策略授权。

#### 模板使用

前台自带 `view/mxone/` 示例模板和 `/features` 功能展示页。新手请从 [模板制作与标签调用指南](docs/模板标签.md) 开始：里面有复制模板、切换模板、首页卡片、分类与视频查询、详情/播放页、剧情、资讯、广告、常用字段和排错示例。制作模板时请用 `ff_url()`、`ff_play_url()` 生成链接，不要写死 URL 规则。

`view/` 只存放 HTML 模板：`mxone/` 为前台，`admin/` 为后台，`install/` 为安装页面。CSS、JS、图片等资源放在 `public/` 下；编译缓存、备份和更新文件保存在 `runtime/`。模板源文件不提供公开网址或下载入口；只有超级管理员可在后台模板管理中编辑前台模板。请勿把模板复制到网站运行目录，也不要用软链接对外开放模板目录。

Nginx／宝塔使用 [站点伪静态配置](deploy/baota/thinkphp.conf)，其中的私有目录规则须放在 `server` 中、所有 `location` 之外。Apache 推荐使用 [安全站点配置](deploy/apache/feifeicms.conf)，修改程序路径后加载；该配置将伪静态放在 `VirtualHost` 中并禁止跟随软链接。仅使用 `.htaccess` 的共享主机还需由服务商禁止公开目录中的软链接；程序无法替代 Web 服务器对静态文件的权限控制。

#### 字段内容替换

超级管理员在“数据库 → 字段内容替换”中选择数据表、点击字段名，填写原文字和新文字，先预览再确认执行。新文字留空可删除匹配文字；条件留空会处理该字段的全部匹配记录，包括隐藏及归档内容。例如在 `ffx_episodes` 的 `media_url` 中替换播放域名，或在 `ffx_media` 的 `area` 中将“内地”替换为“中国大陆”。

可用 `id>=100 AND id<200` 限定范围，或用 `status='published'` 仅处理已发布记录。支持普通文本及合法 JSON，不支持任意 SQL、主键、数字、日期、密码及系统控制表。每次最多处理 50000 条，建议先备份；替换成功后前台数据缓存会失效，视频搜索由增量索引任务同步，静态页面须重新生成。

#### 更新与旧站迁移

升级前请备份数据库、`.env` 和上传文件。超级管理员可以在“工具 → 版本升级”检查 [GitHub 正式版发布页](https://github.com/yunzhanlin/feifeicms8.5/releases/latest) 并确认在线更新；自动更新要求站点 PHP 用户能执行 `proc_open`、使用同系列 PHP CLI，并能写入程序目录。不满足时请手动覆盖程序文件、安装依赖并执行：

```bash
php think feifei:schema:upgrade
php think feifei:doctor
php think feifei:search:sync
```

升级后管理员和会员旧会话需要重新登录。管理员密码、账号状态或角色变更会撤销旧会话。新密码支持最多 200 字节；旧 bcrypt 密码超过 72 字节的账号须重置密码，旧格式无法确认被截断的尾部。

在线更新会等待已有 PHP 请求结束，再临时返回 503 维护提示，完成后恢复访问并刷新 PHP-FPM 的程序缓存。数据库迁移或文件回滚失败时保持维护，不继续提供可能不一致的服务。管理员检查更新备份、修复文件并执行 `php think feifei:schema:upgrade` 后，可执行 `php think feifei:update:resume` 解除维护。首次手动部署这项保护时请重载 PHP-FPM，确保旧入口缓存被刷新。

多个站点共用 Redis 时，请给每个站点配置不同的 `REDIS_PREFIX`。恢复数据库前请暂停网站写入；恢复后搜索暂时使用 MySQL，执行全量索引同步后再启用外部索引。

豆瓣资料获取会优先读取作品页面，并按该条目官方接口补充资料及 IMDb。豆瓣未提供编号时不覆盖表单原值；遇到验证或访问限制，请更新后台豆瓣 Cookie 后重试，程序不会绕过验证。

旧版审计日志可能含历史配置密钥。可执行 `php think feifei:audit:redact` 预览需要脱敏的记录，再加 `--apply` 处理。该命令不处理既有备份文件；曾进入日志的密钥建议更换。

FeiFeiCMS 4.3 数据不能直接覆盖 8.5 数据表。新站后台“工具 → 4.3数据升级”可以预检并分批迁移；也可以按[旧站单文件升级插件说明](plugins/legacy43/README.md)从 4.3 网站启动迁移。迁移前请备份新旧数据库，并在迁移期间暂停旧站写入；插件不覆盖旧程序，也不自动切换域名。

#### 版本记录

每次正式版的新增、更新和删除内容见 [`docs/releases/`](docs/releases/)；下载安装包请使用 [最新正式版](https://github.com/yunzhanlin/feifeicms8.5/releases/latest)。

#### 项目地址

- 本项目：[yunzhanlin/feifeicms8.5](https://github.com/yunzhanlin/feifeicms8.5)
- 原始项目：[daicuo/feifeicms](https://github.com/daicuo/feifeicms)

#### 免责声明

请确保采集、存储、传播的内容和播放来源拥有合法授权，并遵守适用法律法规。
