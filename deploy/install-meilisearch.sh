#!/usr/bin/env bash
# FeiFeiCMS Meilisearch installer. Run on the Linux server, never in a browser.
set -Eeuo pipefail
umask 077

VERSION=1.53.2
NAME=feifeicms-meilisearch
PORT=7700
PORT_SET=0
SITE=''
SITE_USER=''
PHP_BIN=''
INDEX=''
WORK=''
MARKER='# Managed by FeiFeiCMS install-meilisearch.sh'

usage() {
    cat <<'HELP'
用法：sudo bash install-meilisearch.sh [选项]

  --site /www/wwwroot/example.com  同时接入已安装的 FeiFeiCMS，备份搜索设置、
                                  全量同步，并创建每分钟增量同步 timer
  --php /www/server/php/85/bin/php 指定网站使用的 PHP CLI（推荐明确指定）
  --site-user www                 网站运行用户；默认取 runtime/ 的所有者
  --index feifeicms_example       索引名；默认按网站绝对路径生成，避免多站混用
  --port 7700                    只监听 127.0.0.1；已有部署默认沿用原端口
  --name feifeicms-meilisearch    独立服务名；同机多实例使用不同名称和端口
  -h, --help                     显示帮助，不修改系统

支持 Debian 12+、Ubuntu 22.04+，systemd，x86_64 / aarch64。
无 --site 时只部署搜索服务，后台接入参数保存在 /etc/feifeicms/meilisearch/。
重复运行复用原密钥、数据和版本；不会自动升级或接管其他搜索服务。
HELP
}
die() { printf '[失败] %s\n' "$*" >&2; exit 1; }
log() { printf '[部署] %s\n' "$*"; }
cleanup() { if [[ -n "$WORK" && -d "$WORK" ]]; then rm -rf -- "$WORK"; fi; }
trap cleanup EXIT
trap 'printf "[失败] 第 %s 行执行失败；已有索引和密钥保留，请检查上方错误。\n" "$LINENO" >&2' ERR

while (($#)); do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --site|--php|--site-user|--index|--port|--name)
            if (($# < 2)) || [[ -z "$2" || "$2" == --* ]]; then die "$1 缺少参数"; fi
            case "$1" in
                --site) SITE=$2 ;; --php) PHP_BIN=$2 ;; --site-user) SITE_USER=$2 ;;
                --index) INDEX=$2 ;; --port) PORT=$2; PORT_SET=1 ;; --name) NAME=$2 ;;
            esac
            shift 2 ;;
        *) die "未知参数：$1（使用 --help 查看用法）" ;;
    esac
done
[[ "$NAME" =~ ^[a-z][a-z0-9-]{0,49}$ ]] || die '服务名只能包含小写字母、数字和连字符，最长 50 字符'
if [[ ! "$PORT" =~ ^[1-9][0-9]{0,4}$ ]] || ((PORT < 1024 || PORT > 65535)); then die '端口必须是 1024～65535 的整数'; fi
[[ -z "$INDEX" || "$INDEX" =~ ^[A-Za-z0-9_-]{1,100}$ ]] || die '索引名称只能包含字母、数字、下划线和连字符'
[[ -n "$SITE" || ( -z "$PHP_BIN" && -z "$SITE_USER" && -z "$INDEX" ) ]] || die '--php、--site-user、--index 必须配合 --site 使用'
[[ $(uname -s) == Linux ]] || die '请在 Linux 服务器运行本脚本'
((EUID == 0)) || die '请使用 root 或 sudo 运行'
[[ -d /run/systemd/system && -f /etc/os-release ]] || die '需要使用 systemd 的 Debian / Ubuntu 服务器'
# Keep os-release's NAME and VERSION variables out of the installer namespace.
# shellcheck source=/dev/null
read -r CMS_OS_ID CMS_OS_MAJOR < <(. /etc/os-release; printf '%s %s\n' "$ID" "${VERSION_ID%%.*}")
case "$CMS_OS_ID" in
    debian) [[ $CMS_OS_MAJOR -ge 12 ]] || die '需要 Debian 12 或更高版本' ;;
    ubuntu) [[ $CMS_OS_MAJOR -ge 22 ]] || die '需要 Ubuntu 22.04 或更高版本' ;;
    *) die '当前只支持 Debian 12+ 和 Ubuntu 22.04+' ;;
esac
case "$(uname -m)" in
    x86_64) ARCH=amd64; SHA256=6c00019a887813ccbce54eec52718c9343bf4234383871146c67c76b1d2646cd ;;
    aarch64|arm64) ARCH=aarch64; SHA256=12a38c387953e61807a5a0deb64e4bf52def38e49872c7963949a7d4d41a2117 ;;
    *) die '当前只支持 x86_64 和 aarch64 / arm64' ;;
esac

missing=0
for command in curl openssl flock runuser ss; do command -v "$command" >/dev/null || missing=1; done
if ((missing)); then
    log '安装缺少的 curl / OpenSSL / util-linux / iproute2 依赖'
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends ca-certificates curl openssl util-linux iproute2
fi
exec 9>"/run/lock/$NAME-install.lock"
flock -n 9 || die '同名部署正在执行，请稍后重试'

CONFIG_DIR=/etc/feifeicms/meilisearch
CONFIG="$CONFIG_DIR/$NAME.env"
CLIENT="$CONFIG_DIR/$NAME-client.txt"
DATA="/var/lib/feifeicms-meilisearch/$NAME"
UNIT="/etc/systemd/system/$NAME.service"
BINARY_DIR="/opt/feifeicms/meilisearch/$VERSION-$ARCH"
BINARY="$BINARY_DIR/meilisearch"
for path in /etc/feifeicms "$CONFIG_DIR" "$CONFIG" "$CLIENT" /var/lib/feifeicms-meilisearch "$DATA" /opt/feifeicms /opt/feifeicms/meilisearch "$BINARY_DIR" "$BINARY" "$UNIT"; do
    [[ ! -L "$path" ]] || die "部署路径不能是软链接：$path"
done
if [[ -e "$UNIT" ]]; then
    grep -Fxq "$MARKER" "$UNIT" || die "同名服务由其他程序管理，拒绝覆盖：$UNIT"
    [[ -f "$CONFIG" ]] || die '已有服务缺少配置文件，拒绝重新生成密钥'
elif systemctl cat "$NAME.service" >/dev/null 2>&1; then
    die '发现其他位置的同名 systemd 服务，请改用 --name 和 --port'
elif [[ -e "$DATA" || -e "$CONFIG" ]]; then
    die '发现未关联到本脚本服务的数据或配置，请先检查原部署'
fi

if [[ -f "$CONFIG" ]]; then
    [[ $(stat -c %u "$CONFIG") == 0 ]] || die '已有搜索配置必须归 root 所有'
    [[ $(grep -c '^MEILI_MASTER_KEY=' "$CONFIG") == 1 ]] || die '已有配置的密钥项异常'
    MASTER_KEY=$(sed -n 's/^MEILI_MASTER_KEY=//p' "$CONFIG")
    [[ "$MASTER_KEY" =~ ^[a-f0-9]{64}$ ]] || die '已有密钥格式异常；不会重新生成覆盖'
    SAVED_ADDR=$(sed -n 's/^MEILI_HTTP_ADDR=//p' "$CONFIG")
    [[ "$SAVED_ADDR" =~ ^127\.0\.0\.1:([1-9][0-9]{0,4})$ ]] || die '已有监听地址异常'
    SAVED_PORT=${BASH_REMATCH[1]}
    ((SAVED_PORT >= 1024 && SAVED_PORT <= 65535)) || die '已有监听端口异常'
    (( ! PORT_SET || PORT == SAVED_PORT )) || die '已有部署端口不同，请保留原端口或创建新实例'
    PORT=$SAVED_PORT
fi
if ss -H -ltn "sport = :$PORT" | grep -q .; then
    if [[ ! -f "$UNIT" ]] || ! systemctl is-active --quiet "$NAME.service"; then die "端口 $PORT 已被其他服务占用，请改用 --port"; fi
fi
WORK=$(mktemp -d /tmp/feifei-meili-install.XXXXXXXX)
chmod 755 "$WORK"

cat > "$WORK/configure.php" <<'PHP'
<?php
declare(strict_types=1);
umask(0077);
[$script, $site, $host, $index, $mode] = $argv;
$key = $mode === 'enable' ? trim(stream_get_contents(STDIN)) : '';
$before = null;
$backup = '';
$settings = null;
$lock = 'ff_search_setup_' . substr(hash('sha256', $site), 0, 40);
$locked = false;
$keys = array_map(static fn(string $name): string => 'admin.cache.' . $name,
    ['search_driver', 'search_fallback', 'search_host', 'search_index', 'search_key']);
try {
    chdir($site);
    require $site . '/vendor/autoload.php';
    $app = new think\App($site . '/');
    $app->initialize();
    $settings = $app->make(app\service\SiteSettings::class);
    think\facade\Db::table('ffx_search_outbox')->limit(1)->select();
    $rows = think\facade\Db::table('ffx_site_settings')->whereIn('setting_key', $keys)->select()->toArray();
    if ($mode === 'check') { echo "[检查] PHP、数据库和搜索数据表正常\n"; exit(0); }
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new RuntimeException('搜索密钥格式异常');
    $locked = (int)(think\facade\Db::query('SELECT GET_LOCK(?,0) AS acquired', [$lock])[0]['acquired'] ?? 0) === 1;
    if (!$locked) throw new RuntimeException('该网站正在执行搜索接入，请稍后重试');
    $rows = think\facade\Db::table('ffx_site_settings')->whereIn('setting_key', $keys)->select()->toArray();
    $directory = $site . '/runtime/backups';
    if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700, true))) throw new RuntimeException('无法创建私有备份目录');
    $backup = $directory . '/meilisearch-settings-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.json';
    $handle = fopen($backup, 'x');
    if ($handle === false) throw new RuntimeException('无法创建搜索设置备份');
    try {
        $json = json_encode(['format'=>1,'settings'=>$rows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($handle, $json) !== strlen($json)) throw new RuntimeException('搜索设置备份写入不完整');
    } finally { fclose($handle); }
    $before = $rows;
    $values = ['search_driver'=>'mysql', 'search_fallback'=>'mysql', 'search_host'=>$host, 'search_index'=>$index, 'search_key'=>$key];
    think\facade\Db::transaction(static function() use($values): void {
        foreach ($values as $name=>$value) {
            $settingKey = 'admin.cache.' . $name;
            $payload = ['setting_value'=>json_encode($value, JSON_THROW_ON_ERROR), 'is_public'=>0, 'updated_at'=>gmdate('Y-m-d H:i:s')];
            if (think\facade\Db::table('ffx_site_settings')->where('setting_key',$settingKey)->find() === null) {
                think\facade\Db::table('ffx_site_settings')->insert(['setting_key'=>$settingKey] + $payload);
            } else { think\facade\Db::table('ffx_site_settings')->where('setting_key',$settingKey)->update($payload); }
        }
    });
    $settings->forget();
    $count = $app->make(app\service\SearchIndexer::class)->sync();
    $client = $app->make(app\service\MeilisearchClientFactory::class)->client();
    if ((int)($client->index($index)->stats()['numberOfDocuments'] ?? -1) !== $count) throw new RuntimeException('搜索索引数量校验失败');
    think\facade\Db::table('ffx_site_settings')->where('setting_key','admin.cache.search_driver')->update([
        'setting_value'=>json_encode('meilisearch'), 'updated_at'=>gmdate('Y-m-d H:i:s')]);
    $settings->forget();
    echo "[完成] 已启用 Meilisearch，全量同步 {$count} 部已审核视频\n[备份] {$backup}\n";
} catch (Throwable $error) {
    if ($before !== null) {
        try {
            think\facade\Db::transaction(static function() use($before,$keys): void {
                think\facade\Db::table('ffx_site_settings')->whereIn('setting_key',$keys)->delete();
                if ($before !== []) think\facade\Db::table('ffx_site_settings')->insertAll($before);
            });
            $settings?->forget();
            fwrite(STDERR, "[恢复] 已恢复接入前的搜索设置；备份：{$backup}\n");
        } catch (Throwable $restoreError) { fwrite(STDERR, "[失败] 自动恢复未完成，请从 {$backup} 恢复搜索设置\n"); }
    }
    $message = $error->getMessage();
    if ($key !== '') $message = str_replace($key, '[redacted]', $message);
    fwrite(STDERR, "[失败] 网站接入失败：{$message}\n");
    exit(1);
} finally {
    if ($locked) think\facade\Db::query('SELECT RELEASE_LOCK(?)', [$lock]);
}
PHP
chmod 644 "$WORK/configure.php"

if [[ -n "$SITE" ]]; then
    SITE=$(realpath -e -- "$SITE")
    [[ "$SITE" =~ ^/[A-Za-z0-9_./-]+$ ]] || die '网站路径仅支持字母、数字、下划线、点、斜线和连字符'
    [[ -f "$SITE/think" && -f "$SITE/vendor/autoload.php" && -f "$SITE/runtime/install.lock" ]] || die '--site 必须指向已完成安装的 FeiFeiCMS 程序根目录'
    [[ -n "$SITE_USER" ]] || SITE_USER=$(stat -c %U "$SITE/runtime")
    [[ "$SITE_USER" =~ ^[a-z_][a-z0-9_-]*$ && "$SITE_USER" != root ]] || die '请用 --site-user 指定非 root 的网站运行用户'
    id "$SITE_USER" >/dev/null || die '网站运行用户不存在'
    if [[ -z "$PHP_BIN" ]]; then
        for candidate in /www/server/php/85/bin/php /www/server/php/84/bin/php /www/server/php/83/bin/php /www/server/php/82/bin/php /usr/bin/php; do
            if [[ -x "$candidate" ]]; then PHP_BIN=$candidate; break; fi
        done
    fi
    [[ "$PHP_BIN" =~ ^/[A-Za-z0-9_./-]+$ && -x "$PHP_BIN" ]] || die '请通过 --php 指定有效的 PHP CLI 绝对路径'
    # PHP, rather than the shell, must receive the literal $argv expression.
    # shellcheck disable=SC2016
    runuser -u "$SITE_USER" -- "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_mysql") && is_writable($argv[1]) ? 0 : 1);' "$SITE/runtime" || die 'PHP 需要 8.2+、pdo_mysql，且网站运行用户必须能写入 runtime/'
    [[ -n "$INDEX" ]] || INDEX="feifeicms_$(printf '%s' "$SITE" | sha256sum | cut -c1-16)"
    runuser -u "$SITE_USER" -- "$PHP_BIN" "$WORK/configure.php" "$SITE" "http://127.0.0.1:$PORT" "$INDEX" check
    SITE_ID=$(printf '%s' "$SITE" | sha256sum | cut -c1-12)
    WORKER="$NAME-search-$SITE_ID"
    for path in "/etc/systemd/system/$WORKER.service" "/etc/systemd/system/$WORKER.timer"; do
        [[ ! -L "$path" ]] || die "计划任务路径不能是软链接：$path"
        [[ ! -e "$path" ]] || grep -Fxq "$MARKER" "$path" || die "已有同名计划任务，拒绝覆盖：$path"
    done
fi

if [[ -e "$BINARY" ]]; then
    printf '%s  %s\n' "$SHA256" "$BINARY" | sha256sum --check --status || die '已有程序校验值不符，拒绝覆盖；请检查原部署'
else
    log "下载 Meilisearch v$VERSION（$ARCH），并核对官方 SHA-256"
    downloaded=0
    for ((attempt=0; attempt<5; attempt++)); do
        if curl --proto '=https' --tlsv1.2 --fail --location --silent --show-error --continue-at - \
            --connect-timeout 15 --max-time 600 \
            "https://github.com/meilisearch/meilisearch/releases/download/v$VERSION/meilisearch-linux-$ARCH" -o "$WORK/meilisearch"; then
            downloaded=1; break
        fi
    done
    ((downloaded)) || die '下载未完成；未安装服务，请检查到 GitHub 的网络后重试'
    printf '%s  %s\n' "$SHA256" "$WORK/meilisearch" | sha256sum --check --status || die '下载校验失败，未安装程序'
    chmod 755 "$WORK/meilisearch"
    "$WORK/meilisearch" --version || die '官方二进制无法在当前系统运行，未安装服务'
    install -d -m 755 "$BINARY_DIR"
    install -m 755 "$WORK/meilisearch" "$BINARY"
fi
if ! id feifei-meili >/dev/null 2>&1; then
    useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin feifei-meili
fi
[[ $(id -u feifei-meili) != 0 ]] || die '服务账号不能是 root'
if [[ ! -d "$DATA" ]]; then install -d -m 750 -o feifei-meili -g feifei-meili "$DATA"; fi
[[ $(stat -c %U "$DATA") == feifei-meili ]] || die '数据目录所有者异常，拒绝修改已有数据'
install -d -m 700 "$CONFIG_DIR"
if [[ ! -f "$CONFIG" ]]; then
    MASTER_KEY=$(openssl rand -hex 32)
    cat > "$WORK/service.env" <<EOF
MEILI_ENV=production
MEILI_NO_ANALYTICS=true
MEILI_MASTER_KEY=$MASTER_KEY
MEILI_HTTP_ADDR=127.0.0.1:$PORT
MEILI_DB_PATH=$DATA/data.ms
MEILI_DUMP_DIR=$DATA/dumps
MEILI_SNAPSHOT_DIR=$DATA/snapshots
EOF
    install -m 600 "$WORK/service.env" "$CONFIG"
fi
cat > "$WORK/service" <<EOF
$MARKER
[Unit]
Description=FeiFeiCMS Meilisearch $VERSION ($NAME)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=feifei-meili
Group=feifei-meili
WorkingDirectory=$DATA
EnvironmentFile=$CONFIG
ExecStart=$BINARY
Restart=on-failure
RestartSec=3
LimitNOFILE=65535
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=$DATA
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6

[Install]
WantedBy=multi-user.target
EOF
if [[ ! -f "$UNIT" ]]; then install -m 644 "$WORK/service" "$UNIT";
else cmp -s "$WORK/service" "$UNIT" || die '已有服务定义与当前脚本不一致，请检查，脚本不会自动覆盖升级'; fi
systemctl daemon-reload
systemctl enable --now "$NAME.service"
log '等待服务健康检查'
ready=0
for ((attempt=0; attempt<60; attempt++)); do
    if curl --fail --silent --max-time 2 "http://127.0.0.1:$PORT/health" | grep -Eq '"status"[[:space:]]*:[[:space:]]*"available"'; then ready=1; break; fi
    systemctl is-active --quiet "$NAME.service" || break
    sleep 1
done
((ready)) || die "服务未就绪，请运行 journalctl -u $NAME.service -n 80 --no-pager 检查；密钥与数据保留"
printf 'header = "Authorization: Bearer %s"\n' "$MASTER_KEY" > "$WORK/auth.curl"
curl --config "$WORK/auth.curl" --fail --silent --show-error --max-time 5 "http://127.0.0.1:$PORT/stats" > /dev/null || die '服务认证失败，网站配置尚未修改'
cat > "$WORK/client.txt" <<EOF
搜索驱动：Meilisearch
搜索故障回退：MySQL
搜索服务地址：http://127.0.0.1:$PORT
索引名称：${INDEX:-feifeicms_media}
搜索服务密钥：$MASTER_KEY
EOF
install -m 600 "$WORK/client.txt" "$CLIENT"

if [[ -n "$SITE" ]]; then
    log '备份网站搜索设置并执行全量同步；完成后启用搜索驱动'
    printf '%s' "$MASTER_KEY" | runuser -u "$SITE_USER" -- "$PHP_BIN" "$WORK/configure.php" "$SITE" "http://127.0.0.1:$PORT" "$INDEX" enable
    cat > "$WORK/worker.service" <<EOF
$MARKER
[Unit]
Description=FeiFeiCMS search updates for $SITE
After=$NAME.service network-online.target

[Service]
Type=oneshot
User=$SITE_USER
WorkingDirectory=$SITE
ExecStart=$PHP_BIN $SITE/think feifei:search:work
UMask=0077
NoNewPrivileges=true
PrivateTmp=true
EOF
    cat > "$WORK/worker.timer" <<EOF
$MARKER
[Unit]
Description=FeiFeiCMS search updates every minute for $SITE

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
RandomizedDelaySec=5
Unit=$WORKER.service

[Install]
WantedBy=timers.target
EOF
    install -m 644 "$WORK/worker.service" "/etc/systemd/system/$WORKER.service"
    install -m 644 "$WORK/worker.timer" "/etc/systemd/system/$WORKER.timer"
    systemctl daemon-reload
    systemctl enable --now "$WORKER.timer"
    log "已设置每分钟增量同步：$WORKER.timer"
fi
log "部署完成：Meilisearch v$VERSION，地址 http://127.0.0.1:$PORT"
log "服务：$NAME.service；数据：$DATA；接入参数：$CLIENT（仅 root 可读）"
if [[ -z "$SITE" ]]; then
    log '下一步：用接入参数填写后台“系统 → 缓存设置”，再到“工具 → 缓存管理”全量同步；或加 --site 参数再次运行自动接入'
else
    log '网站已接入。请在后台“工具 → 缓存管理”核对搜索状态、索引数量，并验证前台搜索'
fi
