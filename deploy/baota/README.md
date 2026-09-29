# 宝塔 Linux 部署说明

这套程序可以直接由面板的 Nginx + PHP-FPM + MySQL + Redis 运行，Docker 不是强制依赖。当前已在本机“云栈面板”的 Debian 13 ARM 虚拟机完成实际部署和验收；本文同时保留官方宝塔 Linux 的通用步骤。

## 0. 本机云栈面板实例

- 前台：`http://feifeicms-modern.localhost:19101/`
- 后台：`http://feifeicms-modern.localhost:19101/admin.php`
- 站点运行目录：`app/public`，ThinkPHP 伪静态已启用。
- PHP 8.5.10，MySQL 8.4.11，Redis 8.2.10 + PHP redis 6.3.0。
- Meilisearch 1.53.2 以 systemd 服务只监听 `127.0.0.1:7700`；ARM 实例的单元模板见 [`feifeicms-meilisearch.service.example`](feifeicms-meilisearch.service.example)。
- Redis 主机已应用 [`99-feifeicms-redis.conf`](99-feifeicms-redis.conf) 中的内存 overcommit 参数，避免 AOF/RDB 后台保存在内存压力下失败。
- 本机管理员凭据保存在面板的 `private/` 目录，权限为 `0600`，未写入仓库。

`feifeicms-meilisearch.service.example` 使用当前 ARM64 虚拟机的 musl 加载方式；官方宝塔 x86_64 主机应换成对应架构的官方二进制，不要直接复制该执行行。

## 1. 运行环境

- Nginx 1.24 或更新稳定版。
- PHP 8.2–8.5；宝塔环境优先用 PHP 8.4。
- PHP 扩展：`curl`、`dom`、`fileinfo`、`gd`、`intl`、`mbstring`、`openssl`、`pdo_mysql`、`redis`、`xml`、`xmlwriter`、`zip`。
- MySQL 8.4 LTS，开启严格模式，服务端字符集 `utf8mb4`。
- Redis 7/8。
- Meilisearch 1.53.x；可使用宝塔 Docker 插件或 systemd 服务单独运行。不部署时可把 `SEARCH_DRIVER` 设为 `mysql`。

## 2. 站点目录

以 `/www/wwwroot/feifeicms-modern` 为例：

- 将完整项目放到 `/www/wwwroot/feifeicms-modern`。
- 宝塔站点“运行目录”设为 `/public`，绝不要把项目根目录当 Web 根目录。
- 站点用户只需写 `runtime/` 和 `public/uploads/`。
- `.env`、`composer.json`、`legacy/`、`database/` 均不应被 HTTP 访问。

```bash
cd /www/wwwroot/feifeicms-modern
cp .example.env .env
composer install --no-dev --classmap-authoritative
chown -R www:www runtime public/uploads
chmod -R u=rwX,g=rwX,o= runtime public/uploads
```

`www:www` 是宝塔 Linux 的常见站点用户；以实际 PHP-FPM pool 用户为准。

## 3. Nginx 伪静态

把 [`thinkphp.conf`](thinkphp.conf) 内容放入站点“伪静态”。不要覆盖宝塔自动生成的 PHP-FPM socket/include 配置。

配置后执行宝塔 Nginx 配置检查，只有通过才重载：

```bash
/www/server/nginx/sbin/nginx -t -c /www/server/nginx/conf/nginx.conf
/www/server/nginx/sbin/nginx -s reload -c /www/server/nginx/conf/nginx.conf
```

## 4. 数据库

新站导入 `database/schema.sql`。旧站迁移必须先在隔离库恢复备份，再执行 `database/migrations/001_modernize_legacy.sql`：

```bash
mysql --default-character-set=utf8mb4 -u root -p feifeicms < database/schema.sql

# 旧库迁移示例，目标库必须是隔离库
mysql --default-character-set=utf8mb4 -u root -p feifeicms_migration < old-feifeicms.sql
mysql --default-character-set=utf8mb4 -u root -p feifeicms_migration < database/migrations/001_modernize_legacy.sql
```

`--default-character-set=utf8mb4` 不能省略，否则旧中文数据可能在导入时二次转码。

## 5. `.env`

至少替换 `APP_KEY`、数据库密码和 `MEILISEARCH_KEY`。本机服务通常设为：

```dotenv
DB_HOST = 127.0.0.1
DB_PORT = 3306
CACHE_DRIVER = redis
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
SESSION_DRIVER = cache
SESSION_STORE = redis
SEARCH_DRIVER = meilisearch
MEILISEARCH_HOST = http://127.0.0.1:7700
APP_DEBUG = false
```

禁止把 `.env` 提交到 Git，也不要在宝塔站点日志或命令记录中输出密钥。

## 6. 定时任务

在宝塔计划任务中使用与站点一致的 PHP CLI，例如：

```bash
cd /www/wwwroot/feifeicms-modern && /www/server/php/84/bin/php think feifei:search:sync
```

大数据索引不应每分钟全量执行；正式阶段应改为增量队列和低频全量校验。

## 7. 上线前检查

```bash
/www/server/php/84/bin/php -v
/www/server/php/84/bin/php -m
/www/server/php/84/bin/php think feifei:doctor
/www/server/php/84/bin/php think feifei:search:sync
```

确认 PHP CLI 和站点 PHP-FPM 是同一大小版，而不是系统自带的另一个 PHP。

## 8. 真正的宝塔验收门槛

- `nginx -t` 通过，PHP-FPM 配置检查通过，重载后无新增 5xx。
- `feifei:doctor` 的 19 表、Redis 和搜索检查通过。
- 用服务器本地 `curl --resolve` 验证首页、搜索、详情、播放、后台和兼容 API。
- 后台使用 CSRF 登录，旧 MD5 密码自动升级，日志中无密码/密钥。
- 抽样对照旧站 ID、分类、播放来源、集数和顺序；真实播放地址由浏览器实测。
- 备份恢复和回滚演练完成后再切换正式域名。
