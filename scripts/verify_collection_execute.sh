#!/usr/bin/env bash
set -euo pipefail

access_file="${ADMIN_ACCESS_FILE:-/Volumes/MacSSD/MacData/PanelDev/private/feifeicms-modern-access.json}"
source_id="${COLLECTION_SOURCE_ID:-2}"
login_url="$(jq -r .url "$access_file")"
base_url="${login_url%/admin.php}"
username="$(jq -r .username "$access_file")"
password="$(jq -r .password "$access_file")"
cookie_file="$(mktemp)"
body_file="$(mktemp)"
marker="CODEX-COLLECTION-NO-MATCH-$(date +%s)"

curl -fsS -c "$cookie_file" "$login_url" -o "$body_file"
csrf="$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$body_file" | head -1)"
login_code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_file" -c "$cookie_file" \
  -X POST "$base_url/admin.php/login" --data-urlencode "_token=$csrf" \
  --data-urlencode "username=$username" --data-urlencode "password=$password")"

curl -fsS -b "$cookie_file" "$base_url/admin/collections" -o "$body_file"
if rg -F "action=\"/admin/collections/$source_id/queue\"" "$body_file" >/dev/null \
  && rg -F 'name="h" value="24"' "$body_file" >/dev/null; then
  button_is_post=yes
else
  button_is_post=no
fi

queue_code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_file" \
  -X POST "$base_url/admin/collections/$source_id/queue" \
  --data-urlencode "_token=$csrf" --data-urlencode 'scope=all' --data-urlencode 'h=24' \
  --data-urlencode "wd=$marker" --data-urlencode 'execute_now=1')"

job_result="$(env LIMA_HOME=/Volumes/MacSSD/MacData/PanelDev/lima limactl shell panel-dev -- \
  sudo -u ps959582eda0bd3a67e99e9320 env VERIFY_MARKER="$marker" /bin/bash -c \
  'cd /srv/panel/sites/959582eda0bd3a67e99e93204e9fa436/public/app && /opt/panel/runtimes/php/8.5.10/bin/php -r '\''
    $e=parse_ini_file(".env");
    $pdo=new PDO("mysql:host={$e["DB_HOST"]};port={$e["DB_PORT"]};dbname={$e["DB_NAME"]};charset=utf8mb4",$e["DB_USER"],$e["DB_PASS"]);
    $marker=getenv("VERIFY_MARKER");
    $statement=$pdo->prepare("SELECT id,mode,state,processed_count,created_count,updated_count,error_count,error_message FROM ffx_collection_jobs WHERE cursor_value LIKE ? ORDER BY id DESC LIMIT 1");
    $statement->execute(["%".$marker."%"]);
    $row=$statement->fetch(PDO::FETCH_ASSOC);
    if($row){
      $audit=$pdo->prepare("SELECT action,after_data FROM ffx_audit_logs WHERE target_type=? AND target_id=? ORDER BY id");
      $audit->execute(["collection_job",(string)$row["id"]]);
      $row["audits"]=array_map(static function(array $item): array {
        $after=json_decode((string)($item["after_data"]??"{}"),true);
        return ["action"=>$item["action"],"error"=>$after["error"]??null];
      },$audit->fetchAll(PDO::FETCH_ASSOC));
      $pdo->prepare("DELETE FROM ffx_audit_logs WHERE target_type=? AND target_id=?")->execute(["collection_job",(string)$row["id"]]);
      $pdo->prepare("DELETE FROM ffx_collection_jobs WHERE id=?")->execute([$row["id"]]);
    }
    echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  '\''')"

printf 'login=%s button_is_post=%s queue=%s job=%s\n' "$login_code" "$button_is_post" "$queue_code" "$job_result"
[[ "$login_code" == 302 && "$button_is_post" == yes && "$queue_code" == 302 ]]
printf '%s' "$job_result" | jq -e '.mode == "today" and .state == "completed" and .error_count == 0' >/dev/null
