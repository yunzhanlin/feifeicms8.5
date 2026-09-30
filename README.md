# FeiFeiCMS 8.5 Beta 1

#### 系统介绍

飞飞影视导航系统（FeiFeiCMS）是一款免费开源的 PHP 影视内容管理程序。本项目以 FeiFeiCMS 4.3 系列为兼容基线，将原 ThinkPHP 2.1 内核升级为 ThinkPHP 8，保留原版后台使用习惯、模板标签、采集方式和常用 URL，同时支持 PHP 8、MySQL 8、Redis 与 Meilisearch。

原始 FeiFeiCMS 源码保存在 `legacy/`，仅用于功能和兼容性对照，不会在 PHP 8 运行时直接执行。

#### 环境要求

- PHP 8.2～8.5
- MySQL 8.0 或更高版本，推荐 MySQL 8.4 LTS
- Nginx 或 Apache
- Composer 2
- PHP 扩展：`curl`、`dom`、`fileinfo`、`gd`、`intl`、`mbstring`、`openssl`、`pdo_mysql`、`xml`、`xmlwriter`、`zip`
- Redis 7/8（推荐，未安装时可改用文件缓存）
- Meilisearch 1.x（推荐，未安装时可使用 MySQL 搜索）

#### 系统特色

* 开源免费
* ThinkPHP 8 内核
* PHP 8 与 MySQL 8 支持
* Redis 数据缓存
* Meilisearch 全文搜索
* FeiFeiCMS 兼容模板标签
* FeiFeiCMS 与 MacCMS 采集接口兼容
* 自适应电脑、手机和平板

#### 功能列表

* 免费点播、VIP 点播、积分点播与试看
* 多线路、多分集、自动下一集与观看记录
* 影视资讯、人物、专题、标签与导航
* 独立分集剧情、经典台词、看点与结局
* 豆瓣 ID、IMDb ID、评分、评论、收藏与顶踩
* 轮播图、广告位、友情链接与独立播放器
* 视频、文章、人物、评论和分集剧情采集
* FeiFeiCMS JSON 与 MacCMS JSON 字段匹配
* 跨采集源视频合并、地区和语言格式化
* 自定义 URL、伪静态、旧地址兼容与 301 跳转
* 分类、视频、剧情、文章、人物、专题、用户、评论和数据库后台
* 缓存管理、模板管理、批量维护、静态生成与计划任务

#### 基础架构

* ThinkPHP 8.1
* PHP 8.2～8.5
* MySQL 8 / `utf8mb4`
* Redis 缓存与会话
* Meilisearch 搜索，支持自动降级到 MySQL
* `ffx_*` 规范化数据表
* 前后台模板分离
* 浏览器安装与增量数据库升级
* Docker、宝塔 Nginx + PHP-FPM 两种运行方式

#### 安装说明

1. 下载本项目，将全部文件上传到网站目录。
2. 执行 `composer install --no-dev --classmap-authoritative` 安装依赖。
3. 将网站运行目录设置为 `public/`，不要把项目根目录作为 Web 根目录。
4. Linux 服务器需让 PHP-FPM 用户拥有 `runtime/` 和 `public/uploads/` 的写入权限。
5. 配置伪静态后访问 `http://您的域名/install.php`。
6. 按安装向导填写 MySQL、管理员账号和站点信息。安装程序会创建数据表、`.env` 和 `runtime/install.lock`。
7. 安装完成后访问 `http://您的域名/admin.php` 登录后台。

安装程序只允许写入没有 `ffx_*` 表的空数据库，不会覆盖已有站点数据。管理员密码由安装时自行设置，本版本没有通用默认密码。

宝塔 Linux 建议使用 PHP 8.4。站点运行目录设为 `/public`，伪静态复制 `deploy/baota/thinkphp.conf` 的内容；`deploy/baota/` 还提供 Redis 与计划任务配置示例。

Docker 安装：

```bash
cp .example.env .env
# 修改 .env 中的数据库密码、APP_KEY 和搜索密钥
docker compose up -d --build
docker compose exec app php think feifei:schema:install
docker compose exec -e CMS_ADMIN_PASSWORD='替换为至少12位强密码' app php think feifei:admin:create admin
docker compose exec app php think feifei:doctor
```

#### 伪静态说明

1. Apache 已提供 `public/.htaccess`，需要开启 `mod_rewrite` 和 `AllowOverride All`。
2. Nginx 可直接使用 `deploy/baota/thinkphp.conf`，或将其中的 `location` 规则合并到当前站点配置。
3. 在后台“系统 → URL 优化”选择 URL 模式并设置自定义规则。
4. 修改 Nginx 配置后先运行 `nginx -t`，检查通过再重载服务。
5. URL 必须由模板函数生成，不要在模板中写死详情页或播放页路径。

#### 模板说明

自带的 `view/mxone` 是完整示例模板。前台访问 `/features` 可以查看视频、资讯、专题、人物、标签、分集剧情、评论、轮播、广告和友情链接的真实调用结果及代码示例。

常用页面：

```text
/                         首页
/features                 模板功能与调用示例
/list/{id}                分类筛选
/search?wd=关键词          搜索
/vod/{id}                 视频详情
/play/{id}/{线路}/{分集}.html  播放页，公开线路和分集从 1 开始
/vod/{id}/scenarios       分集剧情
/scenario/{id}            剧情详情
/news                     资讯
/special                  专题
/person                   人物
/guestbook                留言板
```

常用模板标签：

```html
{volist name=":ff_mysql_list('limit:20')" id="type"}
  <a href="{:ff_url('type',$type.list_id)}">{$type.list_name}</a>
{/volist}

{volist name=":ff_mysql_vod('cid:1;limit:12;order:vod_hits;sort:desc')" id="vod"}
  <a href="{:ff_url('vod',$vod.vod_id)}">{$vod.vod_name}</a>
{/volist}

{:ff_play_url($vod.vod_id,0,0)}
```

可用查询函数：

```text
ff_mysql_list       分类
ff_mysql_vod        视频
ff_mysql_news       文章
ff_mysql_special    专题
ff_mysql_star       人物
ff_mysql_role       角色
ff_mysql_tags       标签
ff_mysql_scenario   分集剧情
ff_mysql_forum      评论与留言
ff_mysql_slide      轮播图
ff_mysql_nav        导航
ff_mysql_link       友情链接
ff_mysql_ads        广告
```

标签参数使用分号分隔，例如 `cid:1;limit:12;order:vod_hits;sort:desc`。URL 使用 `ff_url()` 或 `ff_play_url()` 生成，以便自动适配后台的动态、Rewrite 和自定义规则。公开播放地址的线路、分集从 `1` 开始；`ff_play_url()` 的线路、分集参数仍使用从 `0` 开始的内部下标。

标准广告位为 `header`、`home_top`、`home_middle`、`vod_detail`、`vod_play` 和 `footer`。创建新模板时复制 `view/mxone`，保留相同的页面目录，在后台“全局配置 → 基本配置”选择模板并清理模板缓存。

兼容采集接口：

```text
GET /api.php/provide/vod?ac=list
GET /api.php/provide/vod?ac=detail&ids=16
GET /api/provide/vod?ac=detail&t=1&pg=1
```

#### 升级说明

升级前必须备份数据库、`.env` 和上传文件。覆盖程序文件并安装依赖后执行：

```bash
php think feifei:schema:upgrade
php think feifei:doctor
php think feifei:search:sync
```

旧版 FeiFeiCMS 数据不能直接覆盖新表。需要迁移旧站时，应先在隔离数据库恢复备份，再通过迁移程序写入新的 `ffx_*` 表。

#### 验证

```bash
composer validate --strict
composer test
php think feifei:doctor
curl -fsS http://您的域名/health
```

#### 项目地址

- 升级版：[github.com/yunzhanlin/feifeicms8.5](https://github.com/yunzhanlin/feifeicms8.5)
- 原始项目：[github.com/daicuo/feifeicms](https://github.com/daicuo/feifeicms)

#### 参与贡献

1. Fork 本仓库。
2. 新建功能分支。
3. 提交代码与测试。
4. 新建 Pull Request。

#### 免责声明

本程序仅供合法的学习、研究和站点建设使用。使用者应确保采集、存储、传播的内容以及接入的播放来源拥有合法授权，并遵守所在地法律法规。因使用者违法使用本程序造成的责任由使用者自行承担。
