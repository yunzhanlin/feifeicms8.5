# FeiFeiCMS 程序使用统计接收端

独立的 PHP / SQLite / Docker 服务。只提供安装实例数、近 7 / 30 天活跃实例、近 7 天新增、每日活跃趋势和程序 / PHP 版本分布，不展示站点清单，不接收域名、站名、账号或访客数据。

## 部署

服务器需已安装 Docker 和 Docker Compose v2。将本目录复制到 `/opt/feifeicms-usage`，运行：

```bash
cd /opt/feifeicms-usage
sudo bash install.sh
```

默认监听 `127.0.0.1:18185`，公网通过 `https://tj.shankan.cc` 的 Nginx 反向代理访问。安装脚本不会安装 Docker、调整防火墙或修改其他站点。重新运行保留数据和登录凭据。

首次启动自动生成随机管理密码。登录信息保存在 `data/admin-login.txt`（0600），私有配置为 `data/config.json`，数据库为 `data/statistics.sqlite`。不要把 `data/` 提交到仓库或放进网站公开目录。备份时先停止本服务，整体备份 `data/`，完成后再启动。

`.env` 只控制监听端口和公网地址，不包含管理密码：

```dotenv
STATS_PORT=18185
STATS_PUBLIC_URL=https://tj.shankan.cc
```

修改已有实例的公网地址时，先备份，再同时修改 `data/config.json` 的 `public_url` 和 `.env`。应用校验二者一致，避免带着错误的 Cookie 安全设置启动。

## Nginx

先为域名申请可信证书，再添加独立虚拟主机。以下证书路径仅为示例，使用实际部署路径：

```nginx
server {
    listen 80;
    server_name tj.shankan.cc;
    location /.well-known/acme-challenge/ { root /var/lib/letsencrypt; }
    location / { return 301 https://tj.shankan.cc$request_uri; }
    access_log off;
}
server {
    listen 443 ssl;
    server_name tj.shankan.cc;
    ssl_certificate /etc/letsencrypt/live/tj.shankan.cc/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tj.shankan.cc/privkey.pem;
    client_max_body_size 16k;
    access_log off;
    location / {
        proxy_pass http://127.0.0.1:18185;
        proxy_set_header Host tj.shankan.cc;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header Connection "";
        proxy_http_version 1.1;
    }
}
```

只在专用、可信的反向代理后启用 `STATS_TRUST_PROXY=1`。代理必须覆盖 `X-Real-IP`；不要将 Docker 端口改成公网监听。Apache 访问日志已关闭；专用 Nginx 同样关闭访问日志，不会因此关闭其他站点日志。

## 接口及统计口径

- `POST /v1/heartbeat`：最多 2048 字节 JSON，字段为 `schema=1`、随机 UUID v4 `installation_id`、程序 `version`、触发来源 `source=web|cron`，以及可选的 PHP 主次版本 `php`。
- `GET /health`：只返回服务健康状态，不返回统计数。
- `/`：管理者登录及汇总面板。`GET /api/summary` 同样需要登录。

未知字段（包括 `domain`）一律拒绝。网络连接不可避免地到达服务器，但应用不保存来源 IP；仅以带私密密钥的小时 HMAC 桶短暂限流，最长 24 小时后清理。每日活跃记录保留 90 天，累计实例的首次 / 最近出现时间保留用于汇总。

首次收到某个 ID 时计为新增，不等同于真实安装日期。相同 ID 升级后更新版本，累计数不增加。完整复制数据库会沿用原安装 ID；清空数据库重新安装会生成新 ID。独立人数无法准确推断；无法访问接收端、关闭上报或没有升级的站点不会纳入。

接口面向公开程序，内置密钥不能证明“真实站点”，因此此数据是去重后的使用量估计，不作为付费授权或财务依据。有限流与严格字段校验，但不能承诺抵御伪造安装 ID。

## 验证与回滚

```bash
docker compose -p feifeicms-usage ps
curl --fail http://127.0.0.1:18185/health
curl --fail https://tj.shankan.cc/health
```

在仓库目录中，PHP 8.2+、PDO SQLite、cURL 环境可运行接收端 HTTP、去重、版本分布、字段边界与登录验证：

```bash
php scripts/verify-usage-statistics.php
```

需要验证 CMS 上报时，使用脚本支持的隔离目录及 `audit_usage_*` 测试数据库环境变量，切勿传入生产库。

停止服务使用 `docker compose -p feifeicms-usage down`，不会删除 `data/`。撤销该域名的独立 Nginx 配置并通过 `nginx -t` 后再重载。程序端关闭上报不会影响网站功能。
