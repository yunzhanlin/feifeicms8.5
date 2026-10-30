#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
command -v docker >/dev/null || { echo '请先安装 Docker。' >&2; exit 1; }
docker compose version >/dev/null
if [[ ! -e .env ]]; then
    printf 'STATS_PORT=18185\nSTATS_PUBLIC_URL=https://tj.shankan.cc\n' >.env
fi
docker compose -p feifeicms-usage build --pull
docker compose -p feifeicms-usage up -d
printf '服务已启动。管理登录信息保存在：%s/data/admin-login.txt（请勿公开）。\n' "$PWD"
printf '默认只监听 127.0.0.1:18185；请按 README 配置 HTTPS 反向代理。\n'
