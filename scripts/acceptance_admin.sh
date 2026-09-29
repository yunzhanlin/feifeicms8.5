#!/usr/bin/env bash
set -euo pipefail

access_file="${ADMIN_ACCESS_FILE:-/Volumes/MacSSD/MacData/PanelDev/private/feifeicms-modern-access.json}"
login_url="$(jq -r .url "$access_file")"
base_url="${login_url%/admin.php}"
username="$(jq -r .username "$access_file")"
password="$(jq -r .password "$access_file")"
cookie_file="$(mktemp)"
body_file="$(mktemp)"
header_file="$(mktemp)"

curl -fsS -c "$cookie_file" "$login_url" -o "$body_file"
csrf="$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$body_file" | head -1)"
login_code="$(curl -sS -o /dev/null -w '%{http_code}' -b "$cookie_file" -c "$cookie_file" \
  -X POST "$base_url/admin.php/login" \
  --data-urlencode "_token=$csrf" --data-urlencode "username=$username" --data-urlencode "password=$password")"
test "$login_code" = 302

post_redirect() {
  local route="$1"
  shift
  : > "$header_file"
  local code
  code="$(curl -sS -o "$body_file" -D "$header_file" -w '%{http_code}' -b "$cookie_file" -c "$cookie_file" \
    -X POST "$base_url$route" "$@")"
  if [[ "$code" != 302 ]]; then
    printf 'FAIL POST %s -> %s\n' "$route" "$code" >&2
    sed -n '1,8p' "$body_file" >&2
    exit 1
  fi
  last_location="$(sed -n 's/^[Ll]ocation: \(.*\)\r$/\1/p' "$header_file" | tail -1)"
  printf 'OK POST %s\n' "$route"
}

get_ok() {
  local route="$1"
  local code
  code="$(curl -sS -o "$body_file" -w '%{http_code}' -b "$cookie_file" "$base_url$route")"
  test "$code" = 200 || { printf 'FAIL GET %s -> %s\n' "$route" "$code" >&2; exit 1; }
}

mark="ACCEPTANCE-$(date +%s)"
mark_lc="$(printf '%s' "$mark" | tr '[:upper:]' '[:lower:]')"

# Classic FeiFei category workflow.
post_redirect /admin/categories --data-urlencode "_token=$csrf" --data-urlencode "name=[$mark] 分类" \
  --data-urlencode 'content_type=media' --data-urlencode 'status=published' --data-urlencode 'filter_options={"year":[2026]}'
category_id="$(printf '%s' "$last_location" | sed -n 's#.*/categories/\([0-9][0-9]*\)/edit.*#\1#p')"
test -n "$category_id"
post_redirect "/admin/categories/$category_id" --data-urlencode "_token=$csrf" --data-urlencode "name=[$mark] 分类更新" \
  --data-urlencode 'content_type=media' --data-urlencode 'status=published' --data-urlencode 'sort_order=99'

# Video and the source/episode editor use the real HTTP submit path.
post_redirect /admin/vod --data-urlencode "_token=$csrf" --data-urlencode "title=[$mark] 视频" \
  --data-urlencode "category_id=$category_id" --data-urlencode 'status=draft' --data-urlencode 'release_year=2026' \
  --data-urlencode 'summary=后台验收数据'
media_id="$(printf '%s' "$last_location" | sed -n 's#.*/vod/\([0-9][0-9]*\)/edit.*#\1#p')"
test -n "$media_id"
post_redirect "/admin/vod/$media_id" --data-urlencode "_token=$csrf" --data-urlencode "title=[$mark] 视频更新" \
  --data-urlencode "category_id=$category_id" --data-urlencode 'status=published' --data-urlencode 'release_year=2026'
post_redirect "/admin/vod/$media_id/sources" --data-urlencode "_token=$csrf" --data-urlencode 'source_key=acceptance' \
  --data-urlencode 'display_name=验收线路' --data-urlencode 'status=enabled'
get_ok "/admin/vod/$media_id/playback"
source_id="$(sed -n 's#.*sources/\([0-9][0-9]*\)/delete.*#\1#p' "$body_file" | head -1)"
test -n "$source_id"
post_redirect "/admin/vod/$media_id/sources/$source_id/episodes" --data-urlencode "_token=$csrf" \
  --data-urlencode 'label=第1集' --data-urlencode 'episode_no=1' --data-urlencode 'sort_order=1' \
  --data-urlencode 'media_url=https://example.test/acceptance.m3u8'
get_ok "/admin/vod/$media_id/playback"
episode_id="$(sed -n 's#.*episodes/\([0-9][0-9]*\)/delete.*#\1#p' "$body_file" | head -1)"
test -n "$episode_id"
post_redirect "/admin/vod/$media_id/sources/$source_id/episodes/$episode_id" --data-urlencode "_token=$csrf" \
  --data-urlencode 'label=第1集-已更新' --data-urlencode 'episode_no=1' --data-urlencode 'sort_order=2' \
  --data-urlencode 'media_url=https://example.test/acceptance-updated.m3u8'

# Article, topic and person editors.
for content_type in articles topics people; do
  if [[ "$content_type" = people ]]; then
    post_redirect "/admin/content/$content_type" --data-urlencode "_token=$csrf" --data-urlencode "name=[$mark] 人物" \
      --data-urlencode 'kind=person' --data-urlencode 'status=draft'
  else
    post_redirect "/admin/content/$content_type" --data-urlencode "_token=$csrf" --data-urlencode "title=[$mark] $content_type" \
      --data-urlencode 'status=draft' --data-urlencode "media_ids=$media_id" --data-urlencode "tags=[$mark]"
  fi
  content_id="$(printf '%s' "$last_location" | sed -n "s#.*/content/$content_type/\\([0-9][0-9]*\\)/edit.*#\\1#p")"
  test -n "$content_id"
  post_redirect "/admin/content/$content_type/$content_id/delete" --data-urlencode "_token=$csrf"
done

# User management.
post_redirect /admin/users --data-urlencode "_token=$csrf" --data-urlencode "username=$mark_lc" \
  --data-urlencode "email=$mark_lc@example.test" --data-urlencode 'password=Acceptance-1234' --data-urlencode 'status=active'
user_id="$(printf '%s' "$last_location" | sed -n 's#.*/users/\([0-9][0-9]*\)/edit.*#\1#p')"
test -n "$user_id"
post_redirect "/admin/users/$user_id" --data-urlencode "_token=$csrf" --data-urlencode "username=$mark_lc" \
  --data-urlencode "email=$mark_lc@example.test" --data-urlencode 'points=10' --data-urlencode 'status=active'
post_redirect "/admin/users/$user_id/delete" --data-urlencode "_token=$csrf"

# Player, slide, link, navigation and advertisement CRUD.
for operation in players slides links navigation ads; do
  case "$operation" in
    players) args=(--data-urlencode "player_key=$mark_lc" --data-urlencode "name=[$mark] 播放器" --data-urlencode 'config={"autoplay":false}' --data-urlencode 'status=enabled') ;;
    slides) args=(--data-urlencode "name=[$mark] 轮播" --data-urlencode 'image_url=https://example.test/slide.jpg' --data-urlencode 'status=enabled') ;;
    links) args=(--data-urlencode "name=[$mark] 链接" --data-urlencode 'url=https://example.test/' --data-urlencode 'status=enabled') ;;
    navigation) args=(--data-urlencode "title=[$mark] 导航" --data-urlencode 'url=/acceptance' --data-urlencode 'status=enabled') ;;
    ads) args=(--data-urlencode "slot_key=$mark_lc" --data-urlencode "name=[$mark] 广告" --data-urlencode 'content=acceptance' --data-urlencode 'status=enabled') ;;
  esac
  post_redirect "/admin/operations/$operation" --data-urlencode "_token=$csrf" "${args[@]}"
  operation_id="$(printf '%s' "$last_location" | sed -n "s#.*/operations/$operation/\\([0-9][0-9]*\\)/edit.*#\\1#p")"
  test -n "$operation_id"
  post_redirect "/admin/operations/$operation/$operation_id/delete" --data-urlencode "_token=$csrf"
done

# Tags, scheduled collection, cards and generated public files.
post_redirect /admin/tags --data-urlencode "_token=$csrf" --data-urlencode "name=[$mark]" --data-urlencode 'scope=media'
get_ok "/admin/tags?wd=$mark"
tag_id="$(sed -n 's#.*tags/\([0-9][0-9]*\)/delete.*#\1#p' "$body_file" | head -1)"
test -n "$tag_id"
post_redirect "/admin/tags/$tag_id/delete" --data-urlencode "_token=$csrf"

post_redirect /admin/crontab --data-urlencode "_token=$csrf" --data-urlencode "name=[$mark] 定时采集" \
  --data-urlencode 'source_id=2' --data-urlencode 'schedule_time=23:59' --data-urlencode 'scope=today'
get_ok /admin/crontab
cron_id="$(sed -n 's#.*crontab/\([0-9][0-9]*\)/toggle.*#\1#p' "$body_file" | head -1)"
test -n "$cron_id"
post_redirect "/admin/crontab/$cron_id/toggle" --data-urlencode "_token=$csrf"
post_redirect "/admin/crontab/$cron_id/delete" --data-urlencode "_token=$csrf"

post_redirect /admin/billing/cards --data-urlencode "_token=$csrf" --data-urlencode 'face_value=8'
get_ok /admin/billing
card_id="$(sed -n 's#.*billing/cards/\([0-9][0-9]*\)/disable.*#\1#p' "$body_file" | head -1)"
test -n "$card_id"
post_redirect "/admin/billing/cards/$card_id/disable" --data-urlencode "_token=$csrf"

post_redirect /admin/tools/uploads -F "_token=$csrf" -F "file=@public/static/admin-legacy/arrow.gif;type=image/gif"
get_ok /admin/tools/uploads
upload_url="$(sed -n 's#.*href="\(/uploads/[^"]*\)".*#\1#p' "$body_file" | head -1)"
test -n "$upload_url"
curl -fsS "$base_url$upload_url" -o /dev/null

post_redirect /admin/tools/static/generate --data-urlencode "_token=$csrf"
curl -fsS "$base_url/generated/sitemap.xml" -o /dev/null
curl -fsS "$base_url/generated/rss.xml" -o /dev/null
post_redirect /admin/system/cache/clear --data-urlencode "_token=$csrf"

# Exercise a real database backup of the schema-version table.
post_redirect /admin/database/backup --data-urlencode "_token=$csrf" --data-urlencode 'ids[]=ffx_schema_versions'
get_ok /admin/database
backup_name="$(sed -n 's#.*database/backups/\(feifeicms-v4-[0-9-]*-[a-f0-9]*\.sql\).*#\1#p' "$body_file" | head -1)"
test -n "$backup_name"
post_redirect "/admin/database/backups/$backup_name/restore" --data-urlencode "_token=$csrf" --data-urlencode 'confirm=RESTORE'
post_redirect "/admin/database/backups/$backup_name/delete" --data-urlencode "_token=$csrf"

# Soft-deleted acceptance records are removed by the companion database cleanup
# executed by the calling acceptance workflow.
printf 'admin_acceptance=passed mark=%s media_id=%s category_id=%s user_id=%s card_id=%s\n' \
  "$mark" "$media_id" "$category_id" "$user_id" "$card_id"
