# FeiFeiCMS 8.5

#### 系统介绍

飞飞影视导航系统（FeiFeiCMS）是一款开源的 PHP 影视内容管理程序。8.5 版使用 ThinkPHP 8，提供视频点播、多线路与分集、资讯、人物、专题、分集剧情、评论和采集管理，并兼容常用飞飞模板标签。自带 MXOne 前台模板，可作为制作新模板的起点。

#### 环境要求

- PHP 8.2～8.5，安装 `curl`、`dom`、`fileinfo`、`gd`、`intl`、`mbstring`、`openssl`、`pdo_mysql`、`xml`、`xmlwriter`、`zip` 扩展。
- MySQL 5.7.13+ 或 8.x；不支持 MySQL 5.6、5.5 和 MariaDB。
- Nginx 或 Apache、Composer 2。Redis 和 Meilisearch 为可选服务；未启用时可分别使用文件缓存和 MySQL 搜索。
- 数据库账号需要对本站数据库具备建表、修改表结构和读写权限，不需要全局 `SUPER` 或创建触发器的权限，无需调整二进制日志。程序在影片写入事务中记录搜索变更，支持 MySQL 和 Meilisearch 搜索。

#### 主要功能

- 免费、VIP 和积分点播；多线路选集、播放记录、收藏与评分。
- 视频、资讯、人物、专题、标签、评论、独立分集剧情与运营组件。
- 飞飞 JSON、MacCMS JSON 采集，分类绑定、跨来源影片合并与定时采集。
- 自定义 URL、伪静态、旧地址兼容、缓存和搜索索引。
- 电脑、手机和平板自适应的 MXOne 示例模板。

需要解析的地址或资源标识可在“系统 → 播放来源”为对应播放器填写解析器 URL；填写后该播放器的全部地址交由此接口解析，留空则直接播放，不自动调用全局解析接口。在“独立播放器”中填写解析域名白名单，并选择 JSON 或 iframe 模式。远程弹幕在同一设置页面配置 API；接口格式及模板接入示例见 [播放器与弹幕调用](docs/模板标签.md#播放器与远程弹幕)。

“全局配置 → 基本配置”的“前台 HTML 压缩”可减少页面排版空白，不压缩后台、JSON、脚本、样式及需要保留格式的代码块或表单文本。URL 链接统一遵循“URL优化”的规则；未保存过设置时使用页面显示的默认规则，已保存的自定义规则保持优先。

#### 安装说明

1. 从[最新正式版](https://github.com/yunzhanlin/feifeicms8.5/releases/latest)下载 `feifeicms8.5.zip` 完整安装包并解压到网站目录。该包已包含运行依赖；使用仓库源码时，须先执行 `composer install --no-dev --classmap-authoritative`。
2. 将网站运行目录设为项目的 `public/`，不能指向项目根目录。让 PHP-FPM 用户能够写入 `runtime/` 和 `public/uploads/`。
3. Nginx 使用 [`deploy/baota/thinkphp.conf`](deploy/baota/thinkphp.conf) 的伪静态规则；Apache 已提供 `public/.htaccess`，需开启 `mod_rewrite` 和 `AllowOverride All`。
4. 浏览器访问 `https://您的域名/install.php`，按向导填写数据库、管理员账号和站点信息。安装程序只接受没有 `ffx_*` 表的空数据库，不会覆盖已有数据。安装成功后自动删除 `public/install.php` 并保留安装锁；若文件权限阻止删除，成功页会提示手动删除，不会回滚已安装的数据。
5. 安装后访问 `https://您的域名/admin.php` 登录。管理员密码由安装时自行设置，没有通用默认密码。

宝塔用户还可以参考 [`deploy/baota/`](deploy/baota/) 中的站点与计划任务示例。

Meilisearch 的服务部署、后台配置、首次索引同步和每分钟任务见 [Meilisearch 部署与启用](docs/Meilisearch部署与启用.md)。启用后建议每分钟运行 `php think feifei:search:work`，自动重试失败任务并处理批量变更；直接用其他工具执行数据库 SQL 后请运行 `php think feifei:search:sync` 全量同步。

宝塔／普通 Linux 可使用 [Meilisearch 一键部署脚本](deploy/install-meilisearch.sh)：自动部署原生服务、生成密钥；加 `--site` 和 `--php` 可备份并保存后台搜索设置、全量同步和创建每分钟任务。支持 Debian 12+、Ubuntu 22.04+，x86_64 / ARM64，具体命令见上面的部署指南。

安装包不包含站点数据、实际环境配置或第三方采集源，仅提供默认 MXOne 模板。默认使用文件缓存和 MySQL 搜索，播放器、采集源、支付、SMTP、第三方接口、Redis 和 Meilisearch 均由用户自行配置。

完整安装 ZIP 仅保留运行必需文件、安装说明、模板制作指南及 Web 服务器伪静态规则；Docker 示例、可选服务配置、历史发布记录和独立迁移插件不进入 ZIP，可按需要从本仓库单独获取。包内安装步骤见 [安装说明](docs/安装说明.md)。

Docker 部署先复制 `.example.env` 为 `.env`，替换必需的应用密钥和数据库密码，并保留数据库主机名为 `mysql`。配置文件只读挂载且随容器重建保留，使用以下命令初始化，不使用网页安装向导：

```bash
docker compose up -d --build
docker compose exec app php think feifei:schema:install
read -rs -p '管理员密码（至少 12 位）：' CMS_ADMIN_PASSWORD
export CMS_ADMIN_PASSWORD
docker compose exec -e CMS_ADMIN_PASSWORD app php think feifei:admin:create admin
unset CMS_ADMIN_PASSWORD
```

初始化后访问 `http://您的主机:8080/admin.php`。Redis 与 Meilisearch 默认不启动：需要 Redis 时配置 `CACHE_DRIVER=redis`、`REDIS_HOST=redis`，可另设 `SESSION_DRIVER=cache`、`SESSION_STORE=redis`，再执行 `docker compose --profile redis up -d`；需要搜索服务时填写 `SEARCH_DRIVER=meilisearch`、`MEILISEARCH_HOST=http://meilisearch:7700` 与自行生成的 `MEILISEARCH_KEY`，再执行 `docker compose --profile search up -d`。修改配置后重新创建 app 容器。

Docker 升级请更新源码并重新构建容器，再执行 `docker compose exec app php think feifei:schema:upgrade`；不要在只读配置的容器中使用在线覆盖更新。

Docker 使用独立 MySQL 容器；程序不需要调整 `log_bin_trust_function_creators`，也不要给站点账号授予全局 `SUPER` 权限。

#### 模板使用

前台自带 `view/mxone/` 示例模板和 `/features` 功能展示页。新手请从 [模板制作与标签调用指南](docs/模板标签.md) 开始：按照“标签说明、参数、调用示例、返回字段”讲解 8.5 自有方法，包含首页制作、自适应 CSS、13 类查询标签、页面变量、分类筛选、搜索分页、播放与弹幕、剧情、会员互动及上线检查。制作模板时请用 `ff_url()`、`ff_play_url()` 生成链接，不要写死 URL 规则。

模板与静态资源按同名目录分离：`view/<主题名>/` 只存放 `.tpl` 页面和私有 `extensions/` PHP 展示扩展，`public/theme-assets/<主题名>/` 存放 CSS、JS、图片和字体。`mxone` 为默认前台，`admin`、`install` 为系统页面，`shared` 为共享播放器及公共标签。复制主题时同时复制这两个同名目录；编译缓存、备份和更新文件仍保存在 `runtime/`。

模板使用 `ff_theme_asset()` 引用自身资源，返回 `/theme-assets/<主题名>/...` 及内容指纹。资源是网站运行目录 `public/` 内的真实文件，由 Nginx／Apache 直接读取，不需要主题资源专用 rewrite，也不经过 PHP 或查询数据库。静态目录只能放公开 CSS、JS、图片和字体，不能放 PHP、`.tpl`、配置、密钥或软链接。`view/` 始终保持私有，不能设置公开映射。网站 URL 的 `.html` 伪静态后缀不受影响。

后台模板管理仍在主题下显示“assets”入口，并显示实际的 `public/theme-assets/` 路径；超级管理员可以编辑前台 `.tpl`、CSS、JS，PHP 扩展须通过服务器部署。旧在线更新器仍可接收升级包，新版首次启动会将旧 `view/<主题名>/assets/` 中的允许资源搬迁到公开目录，并把原目录保存在 `runtime/retired-theme-assets/`。全新安装包已是分离结构。已有主题资源 rewrite 可继续兼容使用，无需为了本次调整修改伪静态。

Nginx／宝塔使用 [站点伪静态配置](deploy/baota/thinkphp.conf)，其中的私有目录规则须放在 `server` 中、所有 `location` 之外。Apache 推荐使用 [安全站点配置](deploy/apache/feifeicms.conf)，修改程序路径后加载；该配置将伪静态放在 `VirtualHost` 中并禁止跟随软链接。仅使用 `.htaccess` 的共享主机还需由服务商禁止公开目录中的软链接；程序无法替代 Web 服务器对静态文件的权限控制。

#### 字段内容替换

超级管理员在“数据库 → 字段内容替换”中选择数据表、点击字段名，填写原文字和新文字，先预览再确认执行。新文字留空可删除匹配文字；条件留空会处理该字段的全部匹配记录，包括隐藏及归档内容。例如在 `ffx_episodes` 的 `media_url` 中替换播放域名，或在 `ffx_media` 的 `area` 中将“内地”替换为“中国大陆”。

可用 `id>=100 AND id<200` 限定范围，或用 `status='published'` 仅处理已发布记录。支持普通文本及合法 JSON，不支持任意 SQL、主键、数字、日期、密码及系统控制表。每次最多处理 50000 条，建议先备份；替换成功后前台数据缓存会失效，视频搜索由增量索引任务同步。

程序仅提供动态页面与伪静态 URL，不再提供 HTML 静态生成功能。旧版生成的 `public/generated/` 会在升级收尾或新版首次启动时移至非公开的 `runtime/retired-static/` 保留，不会删除原内容；遗留的生成工具模板在目录可写时一并移走。升级前请先更新 Web 服务器规则，禁止访问 `/generated`，并重载 PHP-FPM，避免旧规则或旧代码缓存继续提供静态副本。只读部署请由管理员先移走 `public/generated/`；外部 CDN 或浏览器缓存需另行清除。动态地图和 RSS 不受影响。

#### 匿名程序使用统计

程序安装后默认每天最多上报一次随机安装 ID、程序版本、触发来源及 PHP 主次版本，用于汇总安装实例数、活跃数和版本分布；不发送域名、站名、账号、内容或访客信息，也不修改前台模板。可在本站 `.env` 设置 `USAGE_REPORTING=false` 关闭，详见 [程序使用统计](docs/程序使用统计.md)。统计接收服务由维护者独立部署，站长无需安装。

#### 更新与旧站迁移

`8.5.261030` 加入匿名程序使用统计，接收端可独立部署到 Docker；程序每天最多成功上报一次，不改模板和后台界面，可通过 `.env` 关闭。本版同时保留上一版 Redis 设置生效修复。

本版提供完整干净安装包、分别从 `8.5.261023.2`、`8.5.261024` 和 `8.5.261029` 升级的严格差量包，以及兼容这三个版本的在线更新包。所有更新包均排除模板、CSS/JS、图片字体、上传文件、`.env`、伪静态规则和网站配置，仅保留程序变化与版本标识；在线兼容包另保留旧更新器必需的核心文件。完整包包含默认 MXOne 轮播样式修复，仅用于新安装。更早版本先升级到 `8.5.261023.2`；支持的版本在暂存校验阶段检查，不符合时停止覆盖。详见 [本版发布说明](docs/releases/8.5.261030.md)。

升级前请备份数据库、`.env` 和上传文件。超级管理员可以在“工具 → 版本升级”检查 [GitHub 正式版发布页](https://github.com/yunzhanlin/feifeicms8.5/releases/latest) 并确认在线更新；程序优先启动同系列 PHP CLI。面板禁用 `proc_open` 或 CLI 无法启动时，PHP-FPM 可在结束响应并释放会话与请求锁后继续执行，不需要修改面板禁用函数配置。程序目录须由本站 PHP 用户可写；不支持这两种方式的环境请手动覆盖程序文件、安装依赖并执行：

```bash
php think feifei:schema:upgrade
php think feifei:doctor
php think feifei:search:sync
```

升级后管理员和会员旧会话需要重新登录。管理员密码、账号状态或角色变更会撤销旧会话。新密码支持最多 200 字节；旧 bcrypt 密码超过 72 字节的账号须重置密码，旧格式无法确认被截断的尾部。

在线更新会等待已有 PHP 请求结束，再临时返回 503 维护提示，完成后恢复访问并刷新 PHP-FPM 的程序缓存。数据库迁移或文件回滚失败时保持维护，不继续提供可能不一致的服务。管理员检查更新备份、修复文件并执行 `php think feifei:schema:upgrade` 后，可执行 `php think feifei:update:resume` 解除维护。首次手动部署这项保护时请重载 PHP-FPM，确保旧入口缓存被刷新。

PHP-FPM 后台更新会占用一个工作进程，仍受面板的进程终止策略影响。若设置了 `request_terminate_timeout_track_finished=yes`，管理员应确认其超时足够完成备份和更新；大站点优先使用 CLI 或服务器计划任务。程序不会自动修改 PHP-FPM 的全局安全配置。

多个站点共用 Redis 时，请给每个站点配置不同的 `REDIS_PREFIX`。恢复数据库前请暂停网站写入；恢复后搜索暂时使用 MySQL，执行全量索引同步后再启用外部索引。

豆瓣资料获取会优先读取作品页面，并按该条目官方接口补充资料及 IMDb。豆瓣未提供编号时不覆盖表单原值；遇到验证或访问限制，请更新后台豆瓣 Cookie 后重试，程序不会绕过验证。

“视频 → 豆瓣资料补全”支持电影、电视剧、综艺和动漫，可选择视频目录（包含子目录）、最近一天／一周更新或不限时间，再选择仅补齐缺失字段或刷新全部资料。各范围分别保存续跑进度；批次以开始时的时间窗口和影片ID上限为准，新入库内容可重置进度后重新处理。没有豆瓣ID的影片会明确计数并跳过，不按片名猜测条目。

资源库分类必须选择本地目录并保存才生效；自动建议不代表已绑定。未绑定、已解绑或本地目录已停用时跳过采集，不会默认入库到电影。修改绑定后重新采集同来源影片会修正其主分类，影片与分集ID保持不变；已锁定影片不会被自动修改，不同根分类的影片不会仅凭相同豆瓣编号合并。

新视频留空链接别名时按片名生成拼音，同名时增加数字后缀。重新采集会修复同来源影片的旧 `source-来源ID-影片ID` 别名；也可在“工具 → 批量维护”分批生成拼音，默认保留自定义别名。修改后旧视频别名保留兼容访问，不需要重新生成播放线路。

旧版审计日志可能含历史配置密钥。可执行 `php think feifei:audit:redact` 预览需要脱敏的记录，再加 `--apply` 处理。该命令不处理既有备份文件；曾进入日志的密钥建议更换。

FeiFeiCMS 4.3 数据不能直接覆盖 8.5 数据表。新站后台“工具 → 4.3数据升级”可以预检并分批迁移；也可以按[旧站单文件升级插件说明](plugins/legacy43/README.md)从 4.3 网站启动迁移。迁移前请备份新旧数据库，并在迁移期间暂停旧站写入；插件不覆盖旧程序，也不自动切换域名。

#### 版本记录

每次正式版的新增、更新和删除内容见 [`docs/releases/`](docs/releases/)；下载安装包请使用 [最新正式版](https://github.com/yunzhanlin/feifeicms8.5/releases/latest)。

#### 项目地址

- 本项目：[yunzhanlin/feifeicms8.5](https://github.com/yunzhanlin/feifeicms8.5)
- 原始项目：[daicuo/feifeicms](https://github.com/daicuo/feifeicms)

#### 免责声明

请确保采集、存储、传播的内容和播放来源拥有合法授权，并遵守适用法律法规。
