# FeiFeiCMS 8.5：Meilisearch 部署与启用

适用于 FeiFeiCMS 8.5 新安装站点，以及从 MySQL 搜索切换到 Meilisearch 的站点。

程序默认使用 MySQL 搜索。安装包包含 Meilisearch 的 PHP 客户端，搜索服务需要单独安装；只在后台选择 Meilisearch，不会自动安装或启动服务。Meilisearch 与 Redis 相互独立，启用搜索不要求启用 Redis。

启用顺序：启动搜索服务 → 保存后台搜索配置 → 首次全量同步 → 设置每分钟增量同步 → 检查前台搜索。

## 一键部署：宝塔或普通 Linux，PHP 直接运行

提供 [一键部署脚本](../deploy/install-meilisearch.sh)，支持 Debian 12+、Ubuntu 22.04+，以及 x86_64 / aarch64（ARM64）服务器。通过 systemd 原生运行 Meilisearch，不依赖 Docker。脚本固定使用官方社区版 `1.53.2`，下载后校验两种架构各自的官方 SHA-256。

在服务器终端执行下面的命令，将网站目录和 PHP 路径替换成实际值。网站目录必须是包含 `think` 的程序根目录，且网站已经安装完成：

```bash
MEILI_INSTALL_SCRIPT=$(mktemp) && \
curl --proto '=https' --tlsv1.2 -fL https://raw.githubusercontent.com/yunzhanlin/feifeicms8.5/main/deploy/install-meilisearch.sh -o "$MEILI_INSTALL_SCRIPT" && \
sudo bash "$MEILI_INSTALL_SCRIPT" --site /www/wwwroot/example.com --php /www/server/php/85/bin/php
```

脚本会完成以下操作：

1. 检查系统、PHP、数据库、网站运行用户和端口，按需安装基础下载工具。
2. 下载并验证程序，生成随机密钥，以独立的非 root 用户运行，仅监听 `127.0.0.1:7700`，设置开机启动。
3. 将网站后台的五项搜索设置备份到 `runtime/backups/meilisearch-settings-*.json`，保存服务地址、密钥及网站专用索引名。
4. 全量同步已审核视频，核对索引数量，成功后将搜索驱动切换到 Meilisearch，并保留 MySQL 故障回退。
5. 创建每分钟增量同步的 systemd timer，以网站运行用户执行。

网站 `.env`、模板和上传文件保持原样。网站接入或索引同步失败时，脚本尝试自动恢复此前的五项搜索设置，并输出备份位置。备份可能包含原搜索密钥，文件权限为 `600`，不要上传到 GitHub。服务本身安装成功但网站接入失败时，会保留搜索服务及数据，修复原因后可再次运行。

重复执行会复用已有密钥、数据和端口，不会自动升级程序或接管其他程序创建的同名服务。已有端口被占用时，可指定其他端口；同机新建第二个实例时同时使用新的服务名：

```bash
sudo bash "$MEILI_INSTALL_SCRIPT" \
  --name feifeicms-meilisearch-second --port 7701 \
  --site /www/wwwroot/second.example.com --php /www/server/php/85/bin/php
```

不指定 `--site` 时，只部署搜索服务，接入参数保存在 `/etc/feifeicms/meilisearch/feifeicms-meilisearch-client.txt`，仅 root 可读。之后可按第 4～7 节手动接入，或加 `--site` 再次运行：

```bash
sudo bash "$MEILI_INSTALL_SCRIPT"
sudo bash "$MEILI_INSTALL_SCRIPT" --help
```

可选参数 `--site-user www` 指定网站运行用户，默认取 `runtime/` 所有者，拒绝以 root 执行网站任务；`--index feifeicms_example` 指定索引，默认按网站绝对路径生成独立名称。自动接入要求网站和 PHP 路径只包含字母、数字、下划线、点、斜线、连字符。PHP 位于容器内的整站 Docker 部署使用第 3 节。

默认实例的运行检查命令：

```bash
sudo systemctl status feifeicms-meilisearch.service --no-pager
sudo journalctl -u feifeicms-meilisearch.service -n 80 --no-pager
sudo systemctl list-timers 'feifeicms-meilisearch-search-*'
```

脚本会输出实际服务名、数据目录、密钥文件及 timer 名称。搜索数据位于 `/var/lib/feifeicms-meilisearch/<服务名>/`。需要停用时先将后台搜索驱动改为 MySQL，再用 `systemctl disable --now` 停止对应的增量同步 timer 和搜索服务；保留数据目录以便恢复。

## 1. 选择部署方式

| 网站运行方式 | 按本文操作 | 程序使用的搜索地址 |
| --- | --- | --- |
| 宝塔或普通 Linux，PHP 直接运行在服务器上 | 优先使用上面的一键部署；也可按第 2 节手动启动独立 Docker 搜索服务 | `http://127.0.0.1:7700` |
| 使用本仓库的 Docker Compose 运行整站 | 第 3 节，启用已有搜索服务 | `http://meilisearch:7700` |

两种方式选择一种即可。完整安装 ZIP 不包含 Docker Compose 示例；整站容器部署请使用仓库中的 [docker-compose.yml](../docker-compose.yml)。没有 Docker 的服务器也可以单独安装 Meilisearch 原生服务，启动后从第 4 节继续配置；服务地址以实际监听地址为准。

## 2. 宝塔或普通 Linux：启动独立搜索服务

以下命令在运行 PHP 的 Linux 服务器执行，要求已安装 Docker。镜像版本与本仓库的 Compose 示例保持一致。

### 2.1 设置服务密钥

生成随机密钥并妥善保存：

```bash
openssl rand -hex 32
```

用编辑器新建 `/etc/feifeicms-meilisearch.env`，填写下面三项，将占位文字替换成刚生成的密钥。如果该文件已存在，先核对已有配置，不要重新生成密钥覆盖现有服务。

```dotenv
MEILI_ENV=production
MEILI_NO_ANALYTICS=true
MEILI_MASTER_KEY=替换成刚生成的随机密钥
```

限制配置文件权限：

```bash
sudo chmod 600 /etc/feifeicms-meilisearch.env
```

后台“搜索服务密钥”要填写同一个值。不要把真实密钥提交到 GitHub 或放进网站公开目录。

### 2.2 启动服务并检查

```bash
sudo docker run -d \
  --name feifeicms-meilisearch \
  --restart unless-stopped \
  -p 127.0.0.1:7700:7700 \
  -v feifeicms-meili-data:/meili_data \
  --env-file /etc/feifeicms-meilisearch.env \
  getmeili/meilisearch:v1.53.2

curl --fail --silent --show-error http://127.0.0.1:7700/health
```

正常返回：

```json
{"status":"available"}
```

服务启动可能需要几秒，首次连接失败时稍后重试健康检查。这里将端口绑定到本机，并使用命名卷保存索引。容器设置了自动重启策略；已有同名容器时先检查现有部署。

健康检查仅确认服务可访问；密钥和索引是否可用，还需通过后面的全量同步验证。

## 3. Docker Compose 整站部署：启用搜索服务

仅适用于已经使用本仓库 `docker-compose.yml` 部署的网站。该文件已定义 `meilisearch` 服务，默认未启动，无需再创建第 2 节的独立容器。

在项目根目录的现有 `.env` 中设置以下项目，保留其他网站配置。密钥可用 `openssl rand -hex 32` 生成：

```dotenv
SEARCH_DRIVER=meilisearch
SEARCH_FALLBACK=mysql
MEILISEARCH_HOST=http://meilisearch:7700
MEILISEARCH_KEY=替换成自己生成的随机密钥
MEILISEARCH_INDEX=feifeicms_media
```

在同一项目根目录执行：

```bash
docker compose --profile search up -d meilisearch
docker compose up -d --force-recreate app
```

Compose 通过同一个 `MEILISEARCH_KEY` 设置搜索服务的主密钥和程序客户端密钥。修改 `.env` 后重建 `app` 容器，才能刷新容器中的环境变量。

PHP 在容器里运行时，`127.0.0.1` 指向 PHP 容器自身，应使用同网络服务名 `http://meilisearch:7700`。此方式无需向公网映射 7700 端口。

## 4. 保存后台搜索配置

登录后台，进入 **系统 → 缓存设置 → 搜索**，页面路径为 `/admin/settings/cache`。

| 配置项 | 填写内容 |
| --- | --- |
| 搜索驱动 | `Meilisearch` |
| 搜索故障回退 | `MySQL` |
| 搜索服务地址 | PHP 直接运行填 `http://127.0.0.1:7700`；整站 Compose 填 `http://meilisearch:7700` |
| 索引名称 | `feifeicms_media`；多个站点共用搜索服务时，每站使用不同名称 |
| 搜索服务密钥 | 与服务端设置的密钥一致；按本文部署时即 `MEILI_MASTER_KEY` 的值 |

保存设置。后台保存过的搜索驱动等设置优先于 `.env`；曾经在后台选择过 MySQL 的站点，即使修改了 `.env`，也应在这里改选 Meilisearch。

MySQL 回退可以在服务故障或外部索引没有匹配结果时保持搜索可用。因此，前台能搜到影片并不能单独证明 Meilisearch 已经正常工作。

## 5. 第一次全量同步索引

进入 **工具 → 缓存管理 → 前台搜索索引**，页面路径为 `/admin/tools/cache`，点击 **立即全量同步**。

程序会将已审核且未删除的视频写入搜索索引。首次同步前出现“索引不存在”，可以先执行这一步；同步会创建索引。

也可以通过命令行同步。下面以宝塔 PHP 8.5 为例，将网站目录替换成实际程序根目录，也就是包含 `think` 文件的目录，使用本站 PHP 运行用户执行：

```bash
cd /www/wwwroot/example.com && /www/server/php/85/bin/php think feifei:search:sync
```

整站 Compose 部署使用：

```bash
docker compose exec --user www-data app php think feifei:search:sync
```

影片较多时优先使用命令行，避免网页请求执行时间限制。刚安装还没有视频时，同步 0 条是正常情况。

## 6. 设置每分钟增量同步

影片保存和搜索请求会尝试处理增量变更。仍建议设置每分钟任务，用于重试失败任务并处理批量变更。

宝塔中添加 **计划任务 → Shell 脚本**，周期设为每分钟，执行用户选择本站 PHP 运行用户，例如 `www`。脚本如下，网站目录和 PHP 版本按实际情况修改：

```bash
cd /www/wwwroot/example.com && /www/server/php/85/bin/php think feifei:search:work
```

普通 Linux 可在站点用户的 crontab 中添加：

```cron
* * * * * cd /www/wwwroot/example.com && /www/server/php/85/bin/php think feifei:search:work
```

整站 Compose 部署，在拥有 Docker 权限的服务器调度账号中设置以下任务。将项目路径替换成服务器上的实际目录；定时任务使用 `-T` 关闭终端分配：

```cron
* * * * * cd /srv/feifeicms && /usr/bin/docker compose exec -T --user www-data app php think feifei:search:work
```

`/usr/bin/docker` 是示例路径，可用 `command -v docker` 确认。本文的 `www-data` 与仓库 Dockerfile 一致；自定义镜像应使用实际 PHP 运行用户。

直接使用 SQL 或外部工具修改影片数据后，再运行一次 `feifei:search:sync`。数据库恢复后也需要全量同步。

## 7. 确认启用成功

在没有持续采集或编辑影片时，刷新 **工具 → 缓存管理**，确认：

1. “搜索驱动”为 `meilisearch`。
2. “搜索状态”为“正常”。
3. “索引文档”与“已审核视频”的数量一致；新站没有视频时两项都是 0。
4. “待同步变更”经任务处理后归零。
5. 在前台搜索一部已审核的影片，结果可正常打开。

不要只凭 `/health` 返回正常或前台能搜索，就判断配置已经完成；还要确认密钥可用、全量同步成功以及后台索引数量一致。

## 8. 常见问题

| 现象 | 检查与处理 |
| --- | --- |
| 连接被拒绝、连接超时 | 检查服务是否运行、端口及 PHP 所在网络。独立容器可用 `sudo docker logs --tail 100 feifeicms-meilisearch` 查看日志；Compose 使用 `docker compose logs --tail 100 meilisearch`。 |
| PHP 在容器内，地址填写 `127.0.0.1:7700` 后无法连接 | 该地址指向 PHP 容器自身。本仓库整站 Compose 应填写 `http://meilisearch:7700`。 |
| 密钥无效或无权限 | 核对后台密钥和服务端密钥是否一致。仅改后台或仅改 `.env` 不会自动修改已经运行的服务端配置。 |
| 提示索引不存在 | 先检查地址和密钥，再执行首次全量同步；核对索引名称。 |
| 索引数量少于已审核视频 | 执行增量任务；仍不一致时执行全量同步，并检查任务错误及影片审核状态。未审核、已删除的视频不进入索引。 |
| 改了 `.env`，后台仍显示 MySQL | 后台已有设置会覆盖环境默认值，到“系统 → 缓存设置”改选 Meilisearch；Compose 还需重新创建 `app` 容器。 |
| 前台能搜索，但后台搜索状态异常 | 可能正在使用 MySQL 回退。按服务地址、密钥、索引同步的顺序排查。 |
| 定时任务报 PHP 扩展缺失或运行目录无权限 | 使用与网站匹配的 PHP CLI 路径和站点运行用户，确认 `runtime/` 可写。 |

需要暂时停用时，将后台搜索驱动改为 `MySQL` 并保存。MySQL 搜索直接读取数据库，无需同步外部索引。

相关文档：[安装说明](安装说明.md)、[项目 README](../README.md)。
