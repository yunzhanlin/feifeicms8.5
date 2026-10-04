<?php
declare(strict_types=1);

namespace app\plugin\Legacy43;

use app\service\EpisodeParser;
use app\service\FrontendCache;
use app\service\CollectionPayloadNormalizer;
use think\facade\Db;
use Throwable;

final class Legacy43Migrator
{
    /** @var array<string,array{label:string,table:string,id:string}> */
    private const MODULES = [
        'categories' => ['label' => '分类', 'table' => 'list', 'id' => 'list_id'],
        'users' => ['label' => '会员', 'table' => 'user', 'id' => 'user_id'],
        'videos' => ['label' => '视频及播放线路', 'table' => 'vod', 'id' => 'vod_id'],
        'sources' => ['label' => '采集源', 'table' => 'cj', 'id' => 'cj_id'],
        'articles' => ['label' => '文章', 'table' => 'news', 'id' => 'news_id'],
        'people' => ['label' => '人物/角色', 'table' => 'person', 'id' => 'person_id'],
        'topics' => ['label' => '专题及影片关联', 'table' => 'special', 'id' => 'special_id'],
        'tags' => ['label' => '标签及内容关联', 'table' => 'tag', 'id' => 'tag_id'],
        'comments' => ['label' => '评论/留言', 'table' => 'forum', 'id' => 'forum_id'],
        'navigation' => ['label' => '导航', 'table' => 'nav', 'id' => 'nav_id'],
        'slides' => ['label' => '轮播', 'table' => 'slide', 'id' => 'slide_id'],
        'links' => ['label' => '友情链接', 'table' => 'link', 'id' => 'link_id'],
        'ads' => ['label' => '广告', 'table' => 'ads', 'id' => 'ads_id'],
        'players' => ['label' => '播放器', 'table' => 'player', 'id' => 'player_id'],
        'orders' => ['label' => '订单', 'table' => 'orders', 'id' => 'order_id'],
        'cards' => ['label' => '充值卡', 'table' => 'card', 'id' => 'card_id'],
        'records' => ['label' => '观看/收藏记录', 'table' => 'record', 'id' => 'record_id'],
        'ratings' => ['label' => '评分记录', 'table' => 'score', 'id' => 'score_id'],
    ];

    public function __construct(private readonly EpisodeParser $episodes, private readonly FrontendCache $cache, private readonly CollectionPayloadNormalizer $normalizer) {}

    /** @return array<string,array{label:string,table:string,id:string}> */
    public function modules(): array
    {
        return self::MODULES;
    }

    /** @return array{compatible:bool,version:string,prefix:string,modules:array<string,array<string,mixed>>,warnings:list<string>} */
    public function preflight(Legacy43Source $source): array
    {
        $result = [];
        $warnings = [];
        foreach (self::MODULES as $key => $module) {
            $exists = $source->exists($module['table']);
            $count = $exists ? $source->count($module['table']) : 0;
            $migrated = $key === 'tags' ? (int) Db::table('ffx_tags')->count() : (int) Db::table('ffx_legacy_map')->where('entity_type', $this->entityType($key))->count();
            $result[$key] = ['label' => $module['label'], 'table' => $source->prefix() . $module['table'], 'exists' => $exists, 'count' => $count, 'migrated' => $migrated];
            if (!$exists && in_array($key, ['categories', 'videos'], true)) $warnings[] = '缺少核心旧表 ' . $source->prefix() . $module['table'];
        }
        $compatible = ($result['categories']['exists'] ?? false) && ($result['videos']['exists'] ?? false);
        return ['compatible' => $compatible, 'version' => 'FeiFeiCMS 4.3', 'prefix' => $source->prefix(), 'modules' => $result, 'warnings' => $warnings];
    }

    /** @return array{module:string,label:string,cursor:int,processed:int,created:int,updated:int,skipped:int,errors:int,done:bool,messages:list<string>} */
    public function migrateBatch(Legacy43Source $source, string $module, int $cursor, int $limit, bool $dryRun = false): array
    {
        if (!isset(self::MODULES[$module])) throw new \InvalidArgumentException('未知迁移模块。');
        $definition = self::MODULES[$module];
        $offsetCursor = $module === 'tags';
        $rows = $offsetCursor
            ? $source->offsetBatch($definition['table'], max(0, $cursor), $limit)
            : $source->batch($definition['table'], $definition['id'], max(0, $cursor), $limit);
        $stats = ['module' => $module, 'label' => $definition['label'], 'cursor' => max(0, $cursor), 'processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'done' => count($rows) < max(1, min(500, $limit)), 'messages' => []];
        foreach ($rows as $rowIndex => $row) {
            $legacyId = (int) ($row[$definition['id']] ?? 0);
            if ($legacyId < 1) continue;
            $stats['cursor'] = $offsetCursor ? $cursor + $rowIndex + 1 : max($stats['cursor'], $legacyId);
            $stats['processed']++;
            if ($dryRun) { $stats['skipped']++; continue; }
            try {
                $outcome = Db::transaction(fn (): string => $this->{'migrate' . ucfirst($module)}($row));
                $stats[$outcome]++;
            } catch (Throwable $exception) {
                $stats['errors']++;
                if (count($stats['messages']) < 20) $stats['messages'][] = '#' . $legacyId . '：' . mb_substr($exception->getMessage(), 0, 180);
            }
        }
        if (!$dryRun && $stats['processed'] > 0) {
            $this->cache->invalidateCategories();
            $this->cache->invalidateShared();
            $this->cache->invalidateHome();
        }
        return $stats;
    }

    /** @param array<string,mixed> $row */
    private function migrateCategories(array $row): string
    {
        $id = (int) $row['list_id'];
        $type = match ((int) ($row['list_sid'] ?? 1)) { 1, 13 => 'media', 2 => 'article', 3 => 'topic', 8, 9 => 'person', 6 => 'comment', default => 'page' };
        $parent = $this->mappedId('category', (int) ($row['list_pid'] ?? 0));
        $filters = $this->jsonArray($row['list_extend'] ?? null);
        $data = [
            'parent_id' => $parent ?: null, 'content_type' => $type,
            'name' => $this->text($row['list_name'] ?? '', 100),
            'slug' => $this->uniqueSlug('ffx_categories', 'slug', $this->slug((string) ($row['list_dir'] ?? ''), 'category-' . $id), $id, ['content_type' => $type], 'category'),
            'description' => $this->text($row['list_description'] ?? '', 500),
            'seo_title' => $this->text($row['list_title'] ?? '', 255),
            'seo_keywords' => $this->text($row['list_keywords'] ?? '', 500),
            'filter_options' => $filters === [] ? null : $this->json($filters),
            'sort_order' => (int) ($row['list_oid'] ?? 0),
            'status' => (int) ($row['list_status'] ?? 1) === 1 ? 'published' : 'draft',
            'updated_at' => $this->now(), 'deleted_at' => null,
        ];
        return $this->saveMapped('category', $id, 'ffx_categories', $data, $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateUsers(array $row): string
    {
        $id = (int) $row['user_id'];
        $email = filter_var((string) ($row['user_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? $this->text($row['user_email'], 255) : null;
        if ($email !== null && Db::table('ffx_users')->where('email', $email)->where('id', '<>', $this->mappedId('user', $id))->count() > 0) $email = null;
        $username = $this->text($row['user_name'] ?? '', 80);
        if ($username === '') $username = 'legacy-user-' . $id;
        if (Db::table('ffx_users')->where('username', $username)->where('id', '<>', $this->mappedId('user', $id))->count() > 0) $username .= '-43-' . $id;
        $data = [
            'username' => $username, 'password_hash' => (string) ($row['user_pwd'] ?? ''), 'email' => $email,
            'avatar_url' => $this->text($row['user_face'] ?? '', 1000), 'points' => (int) ($row['user_score'] ?? 0),
            'group_key' => 'legacy-' . max(0, (int) ($row['user_group'] ?? 0)),
            'status' => (int) ($row['user_status'] ?? 1) === 1 ? 'active' : 'disabled',
            'last_login_ip' => $this->ip($row['user_logip'] ?? null),
            'last_login_at' => $this->dateTime($row['user_logtime'] ?? 0),
            'expires_at' => $this->dateTime($row['user_deadtime'] ?? 0),
            'updated_at' => $this->now(), 'deleted_at' => null,
        ];
        return $this->saveMapped('user', $id, 'ffx_users', $data, $row, $this->dateTime($row['user_jointime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migrateVideos(array $row): string
    {
        $id = (int) $row['vod_id'];
        $mapped = $this->mappedId('video', $id);
        $metadata = [
            'actor' => (string) ($row['vod_actor'] ?? ''), 'director' => (string) ($row['vod_director'] ?? ''),
            'keywords' => (string) ($row['vod_keywords'] ?? ''), 'type' => (string) ($row['vod_type'] ?? ''),
            'weekday' => (string) ($row['vod_weekday'] ?? ''), 'state' => (string) ($row['vod_state'] ?? ''),
            'version' => (string) ($row['vod_version'] ?? ''), 'tv' => (string) ($row['vod_tv'] ?? ''),
            'duration' => (int) ($row['vod_length'] ?? 0), 'series' => (string) ($row['vod_series'] ?? ''),
            'inputer' => (string) ($row['vod_inputer'] ?? ''), 'source_ref' => (string) ($row['vod_reurl'] ?? ''),
            'trysee' => (int) ($row['vod_trysee'] ?? 0), 'copyright' => (int) ($row['vod_copyright'] ?? 0),
            'lines' => (string) ($row['vod_lines'] ?? ''), 'watch' => (string) ($row['vod_watch'] ?? ''),
            'ending' => (string) ($row['vod_ending'] ?? ''), 'writer' => (string) ($row['vod_writer'] ?? ''),
            'producer' => (string) ($row['vod_producer'] ?? ''), 'camera' => (string) ($row['vod_camera'] ?? ''),
            'editor' => (string) ($row['vod_editor'] ?? ''), 'music' => (string) ($row['vod_music'] ?? ''),
            'art' => (string) ($row['vod_art'] ?? ''), 'legacy_extend' => $this->jsonArray($row['vod_extend'] ?? null),
        ];
        $data = [
            'category_id' => $this->mappedId('category', (int) ($row['vod_cid'] ?? 0)) ?: null,
            'title' => $this->text($row['vod_name'] ?? '', 255),
            'slug' => $this->uniqueSlug('ffx_media', 'slug', $this->slug((string) ($row['vod_ename'] ?? ''), 'vod-' . $id), $id, [], 'video'),
            'original_title' => $this->text($row['vod_ename'] ?? '', 255), 'subtitle' => $this->text($row['vod_title'] ?? '', 255),
            'douban_id' => (string) max(0, (int) ($row['vod_douban_id'] ?? 0)), 'imdb_id' => '',
            'summary' => null, 'content' => (string) ($row['vod_content'] ?? ''),
            'poster_url' => $this->text($row['vod_pic'] ?? '', 1000), 'backdrop_url' => $this->text($row['vod_pic_bg'] ?? '', 1000),
            'media_type' => 'video', 'area' => $this->text($this->normalizer->normalizeArea((string) ($row['vod_area'] ?? '')), 80),
            'language' => $this->text($this->normalizer->normalizeLanguage((string) ($row['vod_language'] ?? '')), 80),
            'release_year' => ($year = (int) ($row['vod_year'] ?? 0)) >= 1000 && $year <= 9999 ? $year : null,
            'release_date' => $this->date($row['vod_filmtime'] ?? 0), 'episode_total' => max(0, (int) ($row['vod_total'] ?? 0)) ?: null,
            'episode_label' => $this->text($row['vod_continu'] ?? '', 50), 'is_completed' => (int) ($row['vod_isend'] ?? 0) === 1 ? 1 : 0,
            'copyright_mode' => (int) ($row['vod_copyright'] ?? 0) > 0 ? 'copyright' : 'normal',
            'access_mode' => (int) ($row['vod_ispay'] ?? 0) > 0 ? 'points' : 'free', 'price_points' => max(0, (int) ($row['vod_price'] ?? 0)),
            'rating' => (float) ($row['vod_douban_score'] ?? $row['vod_gold'] ?? 0) ?: null, 'rating_count' => max(0, (int) ($row['vod_golder'] ?? 0)),
            'view_count' => max(0, (int) ($row['vod_hits'] ?? 0)), 'like_count' => max(0, (int) ($row['vod_up'] ?? 0)),
            'dislike_count' => max(0, (int) ($row['vod_down'] ?? 0)), 'weight' => max(0, (int) ($row['vod_stars'] ?? 0)),
            'status' => (int) ($row['vod_status'] ?? 1) === 1 ? 'published' : 'draft',
            'published_at' => $this->dateTime($row['vod_addtime'] ?? 0), 'updated_at' => $this->dateTime($row['vod_addtime'] ?? 0) ?: $this->now(),
            'deleted_at' => null, 'metadata' => $this->json($metadata),
        ];
        $outcome = $this->saveMapped('video', $id, 'ffx_media', $data, $row, $this->dateTime($row['vod_addtime'] ?? 0));
        $mediaId = $this->mappedId('video', $id);
        if ($mediaId > 0) {
            if ($outcome === 'skipped') {
                // Existing installations may already have imported the raw
                // 4.3 area/language values before normalization was added.
                $current = Db::table('ffx_media')->where('id', $mediaId)->field('area,language')->find();
                if ($current !== null) {
                    $area = $this->normalizer->normalizeArea((string) ($current['area'] ?? ''));
                    $language = $this->normalizer->normalizeLanguage((string) ($current['language'] ?? ''));
                    if ($area !== (string) $current['area'] || $language !== (string) $current['language']) {
                        Db::table('ffx_media')->where('id', $mediaId)->update(['area' => $area, 'language' => $language]);
                    }
                }
            }
            if ($outcome !== 'skipped') {
                Db::table('ffx_media_categories')->where('media_id', $mediaId)->delete();
                if ($data['category_id']) Db::table('ffx_media_categories')->insert(['media_id' => $mediaId, 'category_id' => $data['category_id'], 'is_primary' => 1, 'sort_order' => 0]);
                $this->savePlayback($mediaId, (string) ($row['vod_play'] ?? ''), (string) ($row['vod_url'] ?? ''));
            }
            // Earlier 8.5 migrations did not extract vod_scenario. Run this
            // even for an unchanged mapped video so a retry can backfill it.
            $this->saveScenarios($mediaId, $id, $row['vod_scenario'] ?? null, $data['status']);
        }
        return $outcome;
    }

    /**
     * Import the old 4.3 collection-source directory without enabling it.
     * 4.3 stores several resource types in one table, while 8.5 currently
     * exposes video and independent scenario collectors. Unsupported legacy
     * resource types are counted as skipped so they can be reviewed and
     * configured manually instead of being accidentally executed as video
     * jobs.
     *
     * @param array<string,mixed> $row
     */
    private function migrateSources(array $row): string
    {
        $id = (int) ($row['cj_id'] ?? 0);
        $resourceType = match ((int) ($row['cj_type'] ?? 1)) {
            1 => 'video',
            6 => 'scenario',
            default => '',
        };
        if ($id < 1 || $resourceType === '') return $this->markSkipped('collection_source', $id, $row);

        $endpoint = $this->text($row['cj_url'] ?? '', 1000);
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) return $this->markSkipped('collection_source', $id, $row);

        $credential = trim((string) ($row['cj_appkey'] ?? ''));
        if ($credential === '') $credential = trim((string) ($row['cj_appid'] ?? ''));
        $data = [
            'name' => $this->text($row['cj_name'] ?? '', 255) ?: '4.3采集源-' . $id,
            'endpoint' => $endpoint,
            'source_type' => 'feifei_json',
            'resource_type' => $resourceType,
            'media_source_id' => $resourceType === 'scenario'
                ? (int) (Db::table('ffx_collection_sources')->where('resource_type', 'video')->where('endpoint', $endpoint)->value('id') ?: 0) ?: null
                : null,
            'credential_ref' => $credential !== '' ? $this->text($credential, 255) : null,
            'category_mapping' => '{}',
            'new_data_policy' => 'published',
            // A migrated source must be reviewed before it can make network
            // requests; the old table has no equivalent safety flag.
            'status' => 'disabled',
            'updated_at' => $this->now(),
        ];
        return $this->saveMapped('collection_source', $id, 'ffx_collection_sources', $data, $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateArticles(array $row): string
    {
        $id = (int) $row['news_id'];
        $metadata = ['keywords' => (string) ($row['news_keywords'] ?? ''), 'type' => (string) ($row['news_type'] ?? ''), 'letter' => (string) ($row['news_letter'] ?? ''), 'series' => (string) ($row['news_series'] ?? '')];
        $data = [
            'category_id' => $this->mappedId('category', (int) ($row['news_cid'] ?? 0)) ?: null,
            'title' => $this->text($row['news_name'] ?? '', 255),
            'slug' => $this->uniqueSlug('ffx_articles', 'slug', $this->slug((string) ($row['news_ename'] ?? ''), 'news-' . $id), $id, [], 'article'),
            'summary' => (string) ($row['news_remark'] ?? ''), 'content' => (string) ($row['news_content'] ?? ''),
            'cover_url' => $this->text($row['news_pic'] ?? '', 1000), 'author' => $this->text($row['news_inputer'] ?? '', 120),
            'source_url' => $this->text($row['news_reurl'] ?? '', 1000), 'view_count' => max(0, (int) ($row['news_hits'] ?? 0)),
            'weight' => max(0, (int) ($row['news_stars'] ?? 0)), 'status' => (int) ($row['news_status'] ?? 1) === 1 ? 'published' : 'draft',
            'published_at' => $this->dateTime($row['news_addtime'] ?? 0), 'updated_at' => $this->dateTime($row['news_addtime'] ?? 0) ?: $this->now(),
            'deleted_at' => null, 'metadata' => $this->json($metadata),
        ];
        return $this->saveMapped('article', $id, 'ffx_articles', $data, $row, $this->dateTime($row['news_addtime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migratePeople(array $row): string
    {
        $id = (int) $row['person_id'];
        $kind = (int) ($row['person_sid'] ?? 8) === 9 ? 'character' : 'person';
        $metadata = ['blood' => (string) ($row['person_blood'] ?? ''), 'height' => (int) ($row['person_height'] ?? 0), 'weight' => (int) ($row['person_weight'] ?? 0), 'astrology' => (string) ($row['person_astrology'] ?? ''), 'school' => (string) ($row['person_school'] ?? ''), 'broker' => (string) ($row['person_broker'] ?? ''), 'achievement' => (string) ($row['person_achievement'] ?? ''), 'douban_id' => (int) ($row['person_douban_id'] ?? 0)];
        $data = [
            'kind' => $kind, 'name' => $this->text($row['person_name'] ?? '', 255),
            'slug' => $this->uniqueSlug('ffx_people', 'slug', $this->slug((string) ($row['person_ename'] ?? ''), $kind . '-' . $id), $id, [], 'person'),
            'aliases' => $this->text($row['person_alias'] ?? '', 500), 'gender' => $this->text($row['person_gender'] ?? '', 30),
            'nationality' => $this->text($row['person_nationality'] ?? '', 80), 'birthday' => $this->date($row['person_birthday'] ?? ''),
            'profession' => $this->text($row['person_profession'] ?? '', 255), 'avatar_url' => $this->text($row['person_pic'] ?? '', 1000),
            'backdrop_url' => $this->text($row['person_pic_bg'] ?? '', 1000), 'summary' => $this->text($row['person_intro'] ?? '', 1000),
            'biography' => (string) ($row['person_content'] ?? ''), 'status' => (int) ($row['person_status'] ?? 1) === 1 ? 'published' : 'draft',
            'updated_at' => $this->dateTime($row['person_addtime'] ?? 0) ?: $this->now(), 'deleted_at' => null, 'metadata' => $this->json($metadata),
        ];
        return $this->saveMapped('person', $id, 'ffx_people', $data, $row, $this->dateTime($row['person_addtime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migrateTopics(array $row): string
    {
        $id = (int) $row['special_id'];
        $data = [
            'category_id' => $this->mappedId('category', (int) ($row['special_cid'] ?? 0)) ?: null,
            'title' => $this->text($row['special_name'] ?? '', 255),
            'slug' => $this->uniqueSlug('ffx_topics', 'slug', $this->slug((string) ($row['special_ename'] ?? ''), 'special-' . $id), $id, [], 'topic'),
            'summary' => (string) ($row['special_description'] ?? ''), 'content' => (string) ($row['special_content'] ?? ''),
            'logo_url' => $this->text($row['special_logo'] ?? '', 1000), 'banner_url' => $this->text($row['special_banner'] ?? '', 1000),
            'status' => (int) ($row['special_status'] ?? 1) === 1 ? 'published' : 'draft',
            'published_at' => $this->dateTime($row['special_addtime'] ?? 0), 'updated_at' => $this->dateTime($row['special_addtime'] ?? 0) ?: $this->now(),
            'deleted_at' => null, 'metadata' => $this->json(['type' => (string) ($row['special_type'] ?? ''), 'tags' => (string) ($row['special_tag_name'] ?? ''), 'keywords' => (string) ($row['special_keywords'] ?? '')]),
        ];
        $outcome = $this->saveMapped('topic', $id, 'ffx_topics', $data, $row, $this->dateTime($row['special_addtime'] ?? 0));
        $topicId = $this->mappedId('topic', $id);
        if ($topicId > 0 && $outcome !== 'skipped') {
            Db::table('ffx_topic_media')->where('topic_id', $topicId)->delete();
            $order = 0;
            foreach ($this->ids($row['special_ids_vod'] ?? '') as $legacyVideoId) {
                $mediaId = $this->mappedId('video', $legacyVideoId);
                if ($mediaId > 0) Db::table('ffx_topic_media')->insert(['topic_id' => $topicId, 'media_id' => $mediaId, 'sort_order' => $order++]);
            }
        }
        return $outcome;
    }

    /** @param array<string,mixed> $row */
    private function migrateTags(array $row): string
    {
        $name = $this->text($row['tag_name'] ?? '', 100);
        if ($name === '') return 'skipped';
        $list = strtolower((string) ($row['tag_list'] ?? 'vod_tag'));
        $scope = str_starts_with($list, 'news_') ? 'article' : 'media';
        $slug = $this->slug((string) ($row['tag_ename'] ?? ''), 'tag-' . substr(hash('sha256', $scope . ':' . $name), 0, 16));
        $tag = Db::table('ffx_tags')->where('scope', $scope)->where('slug', $slug)->find();
        $created = false;
        if (!$tag) {
            $tagId = (int) Db::table('ffx_tags')->insertGetId(['scope' => $scope, 'name' => $name, 'slug' => $slug, 'created_at' => $this->now()]);
            $created = true;
        } else {
            $tagId = (int) $tag['id'];
            if ((string) $tag['name'] !== $name) Db::table('ffx_tags')->where('id', $tagId)->update(['name' => $name]);
        }
        $legacyContentId = (int) ($row['tag_id'] ?? 0);
        $targetId = $this->mappedId($scope === 'media' ? 'video' : 'article', $legacyContentId);
        if ($targetId < 1) return $created ? 'created' : 'skipped';
        $join = $scope === 'media' ? 'ffx_media_tags' : 'ffx_article_tags';
        $foreign = $scope === 'media' ? 'media_id' : 'article_id';
        if (Db::table($join)->where($foreign, $targetId)->where('tag_id', $tagId)->count() > 0) return $created ? 'created' : 'skipped';
        Db::table($join)->insert([$foreign => $targetId, 'tag_id' => $tagId]);
        return 'created';
    }

    /** @param array<string,mixed> $row */
    private function migrateComments(array $row): string
    {
        $id = (int) $row['forum_id'];
        $sid = (int) ($row['forum_sid'] ?? 1);
        $targetType = match ($sid) { 1 => 'media', 2 => 'article', 3 => 'topic', 8, 9 => 'person', default => 'site' };
        $mapType = match ($targetType) { 'media' => 'video', 'article' => 'article', 'topic' => 'topic', 'person' => 'person', default => '' };
        $data = [
            'user_id' => $this->mappedId('user', (int) ($row['forum_uid'] ?? 0)) ?: null,
            'parent_id' => $this->mappedId('comment', (int) ($row['forum_pid'] ?? 0)) ?: null,
            'target_type' => $targetType, 'target_id' => $mapType !== '' ? ($this->mappedId($mapType, (int) ($row['forum_cid'] ?? 0)) ?: null) : null,
            'episode_id' => null, 'title' => $this->text($row['forum_title'] ?? '', 255), 'content' => (string) ($row['forum_content'] ?? ''),
            'author_name' => '', 'ip_address' => $this->ip($row['forum_ip'] ?? null),
            'status' => (int) ($row['forum_status'] ?? 0) === 1 ? 'approved' : 'pending', 'is_pinned' => (int) ($row['forum_istop'] ?? 0) === 1 ? 1 : 0,
            'like_count' => max(0, (int) ($row['forum_up'] ?? 0)), 'dislike_count' => max(0, (int) ($row['forum_down'] ?? 0)),
            'updated_at' => $this->dateTime($row['forum_addtime'] ?? 0) ?: $this->now(), 'deleted_at' => null,
        ];
        return $this->saveMapped('comment', $id, 'ffx_comments', $data, $row, $this->dateTime($row['forum_addtime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migrateNavigation(array $row): string
    {
        $id = (int) $row['nav_id'];
        return $this->saveMapped('navigation', $id, 'ffx_navigation', [
            'parent_id' => $this->mappedId('navigation', (int) ($row['nav_pid'] ?? 0)) ?: null,
            'title' => $this->text($row['nav_title'] ?? '', 100), 'url' => $this->legacyUrl((string) ($row['nav_link'] ?? '')),
            'target' => (int) ($row['nav_target'] ?? 0) === 1 ? '_blank' : '_self', 'sort_order' => (int) ($row['nav_oid'] ?? 0),
            'status' => (int) ($row['nav_status'] ?? 1) === 1 ? 'enabled' : 'disabled',
        ], $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateSlides(array $row): string
    {
        $id = (int) $row['slide_id'];
        return $this->saveMapped('slide', $id, 'ffx_slides', [
            'name' => $this->text($row['slide_name'] ?? '', 255), 'image_url' => $this->text($row['slide_pic'] ?? '', 1000),
            'mobile_image_url' => $this->text($row['slide_logo'] ?? '', 1000), 'target_url' => $this->text($row['slide_url'] ?? '', 1000),
            'description' => $this->text($row['slide_content'] ?? '', 500), 'sort_order' => (int) ($row['slide_oid'] ?? 0),
            'status' => (int) ($row['slide_status'] ?? 1) === 1 ? 'enabled' : 'disabled', 'starts_at' => null, 'ends_at' => null,
        ], $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateLinks(array $row): string
    {
        $id = (int) $row['link_id'];
        return $this->saveMapped('link', $id, 'ffx_links', [
            'name' => $this->text($row['link_name'] ?? '', 255), 'url' => $this->text($row['link_url'] ?? '', 1000),
            'logo_url' => $this->text($row['link_logo'] ?? '', 1000), 'link_type' => (int) ($row['link_type'] ?? 1) === 2 ? 'image' : 'text',
            'sort_order' => (int) ($row['link_order'] ?? 0), 'status' => 'enabled',
        ], $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateAds(array $row): string
    {
        $id = (int) $row['ads_id'];
        return $this->saveMapped('ad', $id, 'ffx_ads', [
            'slot_key' => 'legacy-' . $id, 'name' => $this->text($row['ads_name'] ?? '', 255), 'content' => (string) ($row['ads_content'] ?? ''),
            'status' => 'enabled', 'starts_at' => null, 'ends_at' => null, 'updated_at' => $this->now(),
        ], $row);
    }

    /** @param array<string,mixed> $row */
    private function migratePlayers(array $row): string
    {
        $id = (int) $row['player_id'];
        return $this->saveMapped('player', $id, 'ffx_players', [
            'player_key' => $this->uniqueSlug('ffx_players', 'player_key', $this->slug((string) ($row['player_name_en'] ?? ''), 'player-' . $id), $id, [], 'player'),
            'name' => $this->text($row['player_name_zh'] ?? '', 120), 'parser_url' => $this->text($row['player_jiexi'] ?? '', 1000),
            'config' => $this->json(['description' => (string) ($row['player_info'] ?? ''), 'copyright' => (int) ($row['player_copyright'] ?? 0)]),
            'sort_order' => (int) ($row['player_order'] ?? 0), 'status' => (int) ($row['player_status'] ?? 1) === 1 ? 'enabled' : 'disabled', 'updated_at' => $this->now(),
        ], $row);
    }

    /** @param array<string,mixed> $row */
    private function migrateOrders(array $row): string
    {
        $id = (int) $row['order_id'];
        return $this->saveMapped('order', $id, 'ffx_orders', [
            'order_no' => $this->uniqueSlug('ffx_orders', 'order_no', $this->text($row['order_sign'] ?? ('legacy-' . $id), 50), $id, [], 'order'), 'user_id' => $this->mappedId('user', (int) ($row['order_uid'] ?? 0)) ?: null,
            'product_type' => 'legacy', 'product_id' => (int) ($row['order_gid'] ?? 0) ?: null, 'quantity' => max(1, (int) ($row['order_total'] ?? 1)),
            'amount' => (float) ($row['order_money'] ?? 0), 'currency' => 'CNY', 'payment_method' => $this->text($row['order_paytype'] ?? '', 50),
            'payment_reference' => '', 'status' => (int) ($row['order_ispay'] ?? 0) === 1 ? 'paid' : ((int) ($row['order_status'] ?? 0) === 1 ? 'confirmed' : 'pending'),
            'paid_at' => $this->dateTime($row['order_paytime'] ?? 0), 'confirmed_at' => $this->dateTime($row['order_confirmtime'] ?? 0),
            'updated_at' => $this->now(), 'metadata' => $this->json(['info' => (string) ($row['order_info'] ?? ''), 'shipping' => (int) ($row['order_shipping'] ?? 0)]),
        ], $row, $this->dateTime($row['order_addtime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migrateCards(array $row): string
    {
        $id = (int) $row['card_id'];
        return $this->saveMapped('card', $id, 'ffx_cards', [
            'card_hash' => hash('sha256', strtoupper(trim((string) ($row['card_number'] ?? ''))) ?: ('legacy-card-' . $id)), 'face_value' => max(0, (int) ($row['card_face'] ?? 0)),
            'status' => (int) ($row['card_status'] ?? 0) === 1 ? 'used' : 'unused', 'used_by' => $this->mappedId('user', (int) ($row['card_uid'] ?? 0)) ?: null,
            'used_at' => $this->dateTime($row['card_usetime'] ?? 0), 'expires_at' => null,
        ], $row, $this->dateTime($row['card_addtime'] ?? 0));
    }

    /** @param array<string,mixed> $row */
    private function migrateRecords(array $row): string
    {
        $id = (int) $row['record_id'];
        $userId = $this->mappedId('user', (int) ($row['record_uid'] ?? 0));
        $mediaId = $this->mappedId('video', (int) ($row['record_did'] ?? 0));
        if ($userId < 1 || $mediaId < 1) return $this->markSkipped('record', $id, $row);
        if ((int) ($row['record_type'] ?? 0) === 2) {
            // Favorites are naturally keyed by (user_id, media_id), not by the
            // legacy record id. Do not put them in ffx_legacy_map: two users can
            // favorite the same video and the mapped target id would collide.
            $favorite = ['created_at' => $this->dateTime($row['record_time'] ?? 0) ?: $this->now()];
            $exists = Db::table('ffx_favorites')->where('user_id', $userId)->where('media_id', $mediaId)->find();
            if ($exists) {
                Db::table('ffx_favorites')->where('user_id', $userId)->where('media_id', $mediaId)->update($favorite);
                return 'updated';
            }
            Db::table('ffx_favorites')->insert(['user_id' => $userId, 'media_id' => $mediaId] + $favorite);
            return 'created';
        }
        $data = ['user_id' => $userId, 'media_id' => $mediaId, 'episode_id' => null, 'progress_seconds' => 0, 'watched_at' => $this->dateTime($row['record_time'] ?? 0) ?: $this->now()];
        $existing = Db::table('ffx_watch_history')->where('user_id', $userId)->where('media_id', $mediaId)->find();
        if ($existing) { Db::table('ffx_watch_history')->where('id', $existing['id'])->update($data); $newId = (int) $existing['id']; $outcome = 'updated'; }
        else { $newId = (int) Db::table('ffx_watch_history')->insertGetId($data); $outcome = 'created'; }
        $this->writeMap('record', $id, $newId, $this->checksum($row));
        return $outcome;
    }

    /** @param array<string,mixed> $row */
    private function migrateRatings(array $row): string
    {
        $id = (int) $row['score_id'];
        $targetType = (int) ($row['score_sid'] ?? 1) === 1 ? 'media' : 'article';
        $targetId = $this->mappedId($targetType === 'media' ? 'video' : 'article', (int) ($row['score_did'] ?? 0));
        if ($targetId < 1) return $this->markSkipped('rating', $id, $row);
        $data = ['user_id' => $this->mappedId('user', (int) ($row['score_uid'] ?? 0)) ?: null, 'target_type' => $targetType, 'target_id' => $targetId, 'score' => max(0, min(10, (float) ($row['score_ext'] ?? 0))), 'updated_at' => $this->now()];
        return $this->saveMapped('rating', $id, 'ffx_ratings', $data, $row, $this->dateTime($row['score_addtime'] ?? 0));
    }

    private function savePlayback(int $mediaId, string $play, string $urls): void
    {
        $parsed = $this->episodes->parse($play, $urls);
        $keep = [];
        foreach ($parsed as $source) {
            $key = mb_substr($this->slug((string) $source['key'], 'source-' . ((int) $source['index'] + 1)), 0, 80);
            $existing = Db::table('ffx_play_sources')->where('media_id', $mediaId)->where('source_key', $key)->find();
            $data = ['media_id' => $mediaId, 'source_key' => $key, 'display_name' => $this->text($source['name'], 120), 'parser_key' => $key, 'sort_order' => (int) $source['index'], 'status' => 'enabled', 'updated_at' => $this->now()];
            if ($existing) { Db::table('ffx_play_sources')->where('id', $existing['id'])->update($data); $sourceId = (int) $existing['id']; }
            else { $data['created_at'] = $this->now(); $sourceId = (int) Db::table('ffx_play_sources')->insertGetId($data); }
            $keep[] = $sourceId;
            Db::table('ffx_episodes')->where('source_id', $sourceId)->delete();
            foreach ($source['episodes'] as $episode) {
                Db::table('ffx_episodes')->insert([
                    'media_id' => $mediaId, 'source_id' => $sourceId, 'season_id' => null, 'episode_no' => (int) $episode['index'] + 1,
                    'label' => $this->text($episode['label'], 120), 'media_url' => (string) $episode['url'], 'duration_seconds' => null,
                    'sort_order' => (int) $episode['index'], 'status' => 'enabled', 'published_at' => null,
                    'created_at' => $this->now(), 'updated_at' => $this->now(), 'metadata' => null,
                ]);
            }
        }
        $query = Db::table('ffx_play_sources')->where('media_id', $mediaId);
        if ($keep !== []) $query->whereNotIn('id', $keep);
        $query->delete();
    }

    private function saveScenarios(int $mediaId, int $legacyId, mixed $payload, string $status): void
    {
        $rows = $this->normalizer->scenarios($payload);
        if ($rows === []) return;
        $sourceRef = 'legacy43:vod:' . $legacyId;
        $now = $this->now();
        foreach ($rows as $row) {
            $episode = (int) $row['episode_no'];
            $existing = Db::table('ffx_scenarios')->where('media_id', $mediaId)->where('episode_no', $episode)->find();
            $data = [
                'title' => $row['title'], 'content' => $row['content'], 'source_ref' => $sourceRef,
                'sort_order' => $episode, 'status' => $status, 'updated_at' => $now, 'deleted_at' => null,
            ];
            if ($existing === null) {
                Db::table('ffx_scenarios')->insert($data + ['media_id' => $mediaId, 'episode_no' => $episode, 'created_at' => $now]);
            } elseif ((string) ($existing['source_ref'] ?? '') === $sourceRef) {
                Db::table('ffx_scenarios')->where('id', (int) $existing['id'])->update($data);
            }
        }
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $legacy */
    private function saveMapped(string $entity, int $legacyId, string $table, array $data, array $legacy, ?string $createdAt = null): string
    {
        $checksum = $this->checksum($legacy);
        $map = Db::table('ffx_legacy_map')->where('entity_type', $entity)->where('legacy_id', $legacyId)->find();
        if ($map && hash_equals((string) $map['checksum'], $checksum) && Db::table($table)->where('id', (int) $map['new_id'])->count() > 0) return 'skipped';
        if ($map && Db::table($table)->where('id', (int) $map['new_id'])->count() > 0) {
            Db::table($table)->where('id', (int) $map['new_id'])->update($data);
            $newId = (int) $map['new_id']; $outcome = 'updated';
        } else {
            if ($createdAt !== null || $this->hasCreatedAt($table)) $data['created_at'] = $createdAt ?: $this->now();
            $newId = (int) Db::table($table)->insertGetId($data); $outcome = 'created';
        }
        $this->writeMap($entity, $legacyId, $newId, $checksum);
        return $outcome;
    }

    private function hasCreatedAt(string $table): bool
    {
        return !in_array($table, ['ffx_navigation', 'ffx_slides', 'ffx_links'], true);
    }

    /** @param array<string,mixed> $legacy */
    private function saveRawMap(string $entity, int $legacyId, int $newId, array $legacy): string
    {
        $existing = $this->mappedId($entity, $legacyId);
        $this->writeMap($entity, $legacyId, $newId, $this->checksum($legacy));
        return $existing > 0 ? 'updated' : 'created';
    }

    /** @param array<string,mixed> $legacy */
    private function markSkipped(string $entity, int $legacyId, array $legacy): string
    {
        $this->writeMap($entity, $legacyId, 0, $this->checksum($legacy));
        return 'skipped';
    }

    private function writeMap(string $entity, int $legacyId, int $newId, string $checksum): void
    {
        Db::table('ffx_legacy_map')->where('entity_type', $entity)->where('legacy_id', $legacyId)->delete();
        if ($newId > 0) Db::table('ffx_legacy_map')->insert(['entity_type' => $entity, 'legacy_id' => $legacyId, 'new_id' => $newId, 'checksum' => $checksum, 'migrated_at' => $this->now()]);
    }

    private function mappedId(string $entity, int $legacyId): int
    {
        if ($legacyId < 1) return 0;
        return (int) (Db::table('ffx_legacy_map')->where('entity_type', $entity)->where('legacy_id', $legacyId)->value('new_id') ?: 0);
    }

    private function entityType(string $module): string
    {
        return match ($module) { 'categories' => 'category', 'users' => 'user', 'videos' => 'video', 'sources' => 'collection_source', 'articles' => 'article', 'people' => 'person', 'topics' => 'topic', 'comments' => 'comment', 'navigation' => 'navigation', 'slides' => 'slide', 'links' => 'link', 'ads' => 'ad', 'players' => 'player', 'orders' => 'order', 'cards' => 'card', 'records' => 'record', 'ratings' => 'rating', default => $module };
    }

    /** @param array<string,mixed> $row */
    private function checksum(array $row): string { ksort($row); return hash('sha256', serialize($row)); }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    private function text(mixed $value, int $length): string { return mb_substr(trim((string) $value), 0, $length); }
    private function ip(mixed $value): ?string { $ip = trim((string) $value); return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null; }
    private function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR); }
    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array { if (!is_string($value) || trim($value) === '') return []; $decoded = json_decode($value, true); return is_array($decoded) ? $decoded : ['legacy_value' => $value]; }
    private function slug(string $value, string $fallback): string { $value = strtolower(trim($value)); $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? ''; return trim($value, '-') ?: $fallback; }

    /** @param array<string,mixed> $scope */
    private function uniqueSlug(string $table, string $field, string $slug, int $legacyId, array $scope = [], string $entity = ''): string
    {
        $slug = mb_substr($slug, 0, 220);
        $query = Db::table($table)->where($field, $slug);
        foreach ($scope as $key => $value) $query->where($key, $value);
        $currentId = $entity !== '' ? $this->mappedId($entity, $legacyId) : 0;
        if ($currentId > 0) $query->where('id', '<>', $currentId);
        if ($query->count() === 0) return $slug;
        return mb_substr($slug, 0, 205) . '-43-' . $legacyId;
    }

    private function dateTime(mixed $value): ?string
    {
        if (is_numeric($value) && (int) $value > 0) return gmdate('Y-m-d H:i:s', (int) $value);
        $time = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
        return $time && $time > 0 ? gmdate('Y-m-d H:i:s', $time) : null;
    }

    private function date(mixed $value): ?string
    {
        $date = $this->dateTime($value);
        return $date ? substr($date, 0, 10) : null;
    }

    /** @return list<int> */
    private function ids(mixed $value): array
    {
        preg_match_all('/\d+/', (string) $value, $matches);
        return array_values(array_unique(array_filter(array_map('intval', $matches[0] ?? []))));
    }

    private function legacyUrl(string $url): string
    {
        if (preg_match('/list-read-id-(\d+)\.html/i', $url, $match) === 1) {
            $mapped = $this->mappedId('category', (int) $match[1]);
            if ($mapped > 0) return '/list/' . $mapped . '.html';
        }
        return $this->text($url, 1000);
    }
}
