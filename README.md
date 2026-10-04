# FeiFeiCMS 8.5

#### 系统介绍

飞飞影视导航系统（FeiFeiCMS）是一款开源的 PHP 影视内容管理程序。8.5 版使用 ThinkPHP 8，提供视频点播、多线路与分集、资讯、人物、专题、分集剧情、评论和采集管理，并兼容常用飞飞模板标签。自带 MXOne 前台模板，可作为制作新模板的起点。

#### 环境要求

- PHP 8.2～8.5，安装 `curl`、`dom`、`fileinfo`、`gd`、`intl`、`mbstring`、`openssl`、`pdo_mysql`、`xml`、`xmlwriter`、`zip` 扩展。
- MySQL 5.7.13+ 或 8.x；不支持 MySQL 5.6、5.5 和 MariaDB。
- Nginx 或 Apache、Composer 2。Redis 和 Meilisearch 为可选服务；未启用时可分别使用文件缓存和 MySQL 搜索。
- 数据库账号在安装和升级时需要创建触发器的权限。如果 MySQL 开启二进制日志后报告 `1419`，请让数据库管理员临时处理 `log_bin_trust_function_creators` 或代为执行迁移。

#### 主要功能

- 免费、VIP 和积分点播；多线路选集、播放记录、收藏与评分。
- 视频、资讯、人物、专题、标签、评论、独立分集剧情与运营组件。
- 飞飞 JSON、MacCMS JSON 采集，分类绑定、跨来源影片合并与定时采集。
- 自定义 URL、伪静态、旧地址兼容、缓存和搜索索引。
- 电脑、手机和平板自适应的 MXOne 示例模板。

#### 安装说明

1. 下载本仓库代码，上传到网站目录；在项目目录执行 `composer install --no-dev --classmap-authoritative`。
2. 将网站运行目录设为项目的 `public/`，不能指向项目根目录。让 PHP-FPM 用户能够写入 `runtime/` 和 `public/uploads/`。
3. Nginx 使用 [`deploy/baota/thinkphp.conf`](deploy/baota/thinkphp.conf) 的伪静态规则；Apache 已提供 `public/.htaccess`，需开启 `mod_rewrite` 和 `AllowOverride All`。
4. 浏览器访问 `https://您的域名/install.php`，按向导填写数据库、管理员账号和站点信息。安装程序只接受没有 `ffx_*` 表的空数据库，不会覆盖已有数据。
5. 安装后访问 `https://您的域名/admin.php` 登录。管理员密码由安装时自行设置，没有通用默认密码。

宝塔用户还可以参考 [`deploy/baota/`](deploy/baota/) 中的站点与计划任务示例。Docker 部署可复制 `.example.env` 为 `.env`，填写密钥和数据库信息后运行 `docker compose up -d --build`，再按安装向导完成初始化。

#### 模板使用

前台自带 `view/mxone/` 示例模板和 `/features` 功能展示页。新手请从 [模板制作与标签调用指南](docs/模板标签.md) 开始：里面有复制模板、切换模板、首页卡片、分类与视频查询、详情/播放页、剧情、资讯、广告、常用字段和排错示例。制作模板时请用 `ff_url()`、`ff_play_url()` 生成链接，不要写死 URL 规则。

#### 更新与旧站迁移

升级前请备份数据库、`.env` 和上传文件。超级管理员可以在“工具 → 版本升级”检查 [GitHub 正式版发布页](https://github.com/yunzhanlin/feifeicms8.5/releases/latest) 并确认在线更新；自动更新要求站点 PHP 用户能执行 `proc_open`、使用同系列 PHP CLI，并能写入程序目录。不满足时请手动覆盖程序文件、安装依赖并执行：

```bash
php think feifei:schema:upgrade
php think feifei:doctor
php think feifei:search:sync
```

FeiFeiCMS 4.3 数据不能直接覆盖 8.5 数据表。新站后台“工具 → 4.3数据升级”可以预检并分批迁移；也可以按[旧站单文件升级插件说明](plugins/legacy43/README.md)从 4.3 网站启动迁移。迁移前请备份新旧数据库，并在迁移期间暂停旧站写入；插件不覆盖旧程序，也不自动切换域名。

#### 版本记录

每次正式版的新增、更新和删除内容见 [`docs/releases/`](docs/releases/)；下载安装包请使用 [最新正式版](https://github.com/yunzhanlin/feifeicms8.5/releases/latest)。

#### 项目地址

- 本项目：[yunzhanlin/feifeicms8.5](https://github.com/yunzhanlin/feifeicms8.5)
- 原始项目：[daicuo/feifeicms](https://github.com/daicuo/feifeicms)

#### 免责声明

请确保采集、存储、传播的内容和播放来源拥有合法授权，并遵守适用法律法规。
