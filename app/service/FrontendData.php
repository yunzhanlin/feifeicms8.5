<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class FrontendData
{
    public function __construct(private readonly SiteSettings $settings) {}

    /** @return array<string, mixed> */
    public function shared(string $pageTitle, string $keyword = ''): array
    {
        $categories = Db::table('ffx_categories')->where('content_type', 'media')->where('status', 'published')->whereNull('deleted_at')
            ->whereNull('parent_id')->order('sort_order')->select()->toArray();
        return [
            'siteName' => $this->settings->string('admin.base.site_name', (string) config('feifei.site_name')),
            'siteDescription' => $this->settings->string('admin.base.site_description', (string) config('feifei.site_description')),
            'pageTitle' => $pageTitle,
            'keyword' => $keyword,
            'navCategories' => $categories,
            'types' => array_map(fn (array $category): array => $this->type($category), $categories),
            'seo' => [
                'title' => $pageTitle,
                'keywords' => $keyword !== '' ? $keyword : $this->settings->string('admin.base.site_keywords'),
                'description' => $this->settings->string('admin.base.site_description', (string) config('feifei.site_description')),
            ],
            'pageActive' => '',
            'friendLinks' => Db::table('ffx_links')->where('status', 'enabled')->order('sort_order')->limit(30)->select()->toArray(),
            'siteNavigation' => Db::table('ffx_navigation')->where('status', 'enabled')->order('sort_order')->order('id')->select()->toArray(),
            'siteSlides' => Db::table('ffx_slides')->where('status', 'enabled')->where(function ($query): void {
                $query->whereNull('starts_at')->whereOr('starts_at', '<=', gmdate('Y-m-d H:i:s'));
            })->where(function ($query): void {
                $query->whereNull('ends_at')->whereOr('ends_at', '>=', gmdate('Y-m-d H:i:s'));
            })->order('sort_order')->limit($this->settings->int('admin.base.ui_slide_max', 10, 0, 100) ?: 100)->select()->toArray(),
            'siteCopyright' => $this->settings->string('admin.base.site_copyright'),
            'siteIcp' => $this->settings->string('admin.base.site_icp'),
            'siteStatistics' => $this->settings->string('admin.base.site_tongji'),
            'siteAds' => array_column(Db::table('ffx_ads')->where('status', 'enabled')->where(function ($query): void {
                $query->whereNull('starts_at')->whereOr('starts_at', '<=', gmdate('Y-m-d H:i:s'));
            })->where(function ($query): void {
                $query->whereNull('ends_at')->whereOr('ends_at', '>=', gmdate('Y-m-d H:i:s'));
            })->select()->toArray(), 'content', 'slot_key'),
        ];
    }

    /** @param array<string, mixed> $category
     *  @return array<string, mixed>
     */
    public function type(array $category): array
    {
        return $category + [
            'type_id' => (int) ($category['id'] ?? 0),
            'type_pid' => (int) ($category['parent_id'] ?? 0),
            'type_name' => (string) ($category['name'] ?? ''),
            'list_id' => (int) ($category['id'] ?? 0),
            'list_name' => (string) ($category['name'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $media
     *  @return array<string, mixed>
     */
    public function vod(array $media): array
    {
        if (trim((string) ($media['poster_url'] ?? '')) === '') $media['poster_url'] = $this->settings->string('admin.content.default_poster');
        $metadata = $media['metadata'] ?? [];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $categoryName = (string) ($media['category_name'] ?? '');
        if ($categoryName === '' && !empty($media['category_id'])) {
            $categoryName = (string) Db::table('ffx_categories')->where('id', (int) $media['category_id'])->value('name');
        }
        $created = $this->timestamp($media['created_at'] ?? null);
        $updated = $this->timestamp($media['updated_at'] ?? $media['published_at'] ?? null);
        $release = $this->timestamp($media['release_date'] ?? null);
        $actor = trim((string) ($metadata['actor'] ?? ''));
        $director = trim((string) ($metadata['director'] ?? ''));
        $content = (string) ($media['content'] ?? $media['summary'] ?? '');
        $remark = (string) ($media['episode_label'] ?? '');
        if ($remark === '') {
            $remark = !empty($media['is_completed']) ? '已完结' : '更新中';
        }
        return $media + [
            'vod_id' => (int) ($media['id'] ?? 0),
            'vod_cid' => (int) ($media['category_id'] ?? 0),
            'vod_name' => (string) ($media['title'] ?? ''),
            'vod_ename' => (string) ($media['slug'] ?? ''),
            'vod_title' => (string) ($media['subtitle'] ?? ''),
            'vod_douban_id' => (string) ($media['douban_id'] ?? ''),
            'vod_imdb_id' => (string) ($media['imdb_id'] ?? ''),
            'vod_pic' => (string) ($media['poster_url'] ?? ''),
            'vod_content' => $content,
            'vod_actor' => $actor,
            'vod_director' => $director,
            'vod_type' => $categoryName,
            'vod_keywords' => (string) ($media['tags_text'] ?? ''),
            'vod_year' => (string) ($media['release_year'] ?? ''),
            'vod_area' => (string) ($media['area'] ?? ''),
            'vod_language' => (string) ($media['language'] ?? ''),
            'vod_continu' => (string) ($media['episode_label'] ?? ''),
            'vod_gold' => (float) ($media['rating'] ?? 0),
            'vod_golder' => (int) ($media['rating_count'] ?? 0),
            'vod_hits' => (int) ($media['view_count'] ?? 0),
            'vod_up' => (int) ($media['like_count'] ?? 0),
            'vod_down' => (int) ($media['dislike_count'] ?? 0),
            'vod_addtime' => $updated ?: $created,
            'vod_filmtime' => $release,
            'area_text' => (string) ($media['area'] ?? ''),
            'remark_text' => $remark,
            'summary_text' => $this->summary((string) ($media['summary'] ?? $content), $this->settings->int('admin.content.summary_length', 180, 20, 1000)),
        ];
    }

    /** @param array<int, array<string, mixed>> $media */
    public function vodList(array $media): array
    {
        return array_map(fn (array $item): array => $this->vod($item), $media);
    }

    /** @param array<int, array<string, mixed>> $sources
     *  @return array<int, array<string, mixed>>
     */
    public function sources(array $sources): array
    {
        return array_map(static function (array $source): array {
            $episodes = [];
            foreach ((array) ($source['episodes'] ?? []) as $episode) {
                $episodes[] = $episode + [
                    'title' => (string) ($episode['label'] ?? ''),
                    'url' => (string) ($episode['media_url'] ?? ''),
                ];
            }
            return array_replace($source, [
                'name' => (string) ($source['display_name'] ?? $source['source_key'] ?? ''),
                'code' => (string) ($source['source_key'] ?? ''),
                'episodes' => $episodes,
            ]);
        }, $sources);
    }

    /** @param array<string, mixed> $article */
    public function news(array $article): array
    {
        if (trim((string) ($article['cover_url'] ?? '')) === '') $article['cover_url'] = $this->settings->string('admin.content.default_poster');
        $content = (string) ($article['content'] ?? '');
        return $article + [
            'news_id' => (int) ($article['id'] ?? 0),
            'news_name' => (string) ($article['title'] ?? ''),
            'news_pic' => (string) ($article['cover_url'] ?? ''),
            'news_hits' => (int) ($article['view_count'] ?? 0),
            'news_addtime' => $this->timestamp($article['published_at'] ?? $article['created_at'] ?? null),
            'summary_text' => $this->summary((string) ($article['summary'] ?? $content), $this->settings->int('admin.content.summary_length', 180, 20, 1000)),
            'safe_content' => $this->settings->bool('admin.content.allow_html', true) ? $content : nl2br(htmlspecialchars(strip_tags($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];
    }

    /** @param array<string, mixed> $topic */
    public function special(array $topic): array
    {
        $content = (string) ($topic['content'] ?? '');
        return $topic + [
            'special_id' => (int) ($topic['id'] ?? 0),
            'special_name' => (string) ($topic['title'] ?? ''),
            'special_description' => (string) ($topic['summary'] ?? ''),
            'special_hits' => (int) ($topic['view_count'] ?? 0),
            'special_tag_name' => '',
            'cover' => (string) ($topic['banner_url'] ?? $topic['logo_url'] ?? ''),
            'summary_text' => $this->summary((string) ($topic['summary'] ?? $content), $this->settings->int('admin.content.summary_length', 180, 20, 1000)),
            'safe_content' => $this->settings->bool('admin.content.allow_html', true) ? $content : nl2br(htmlspecialchars(strip_tags($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];
    }

    /** @param array<string, mixed> $person */
    public function person(array $person): array
    {
        if (trim((string) ($person['avatar_url'] ?? '')) === '') $person['avatar_url'] = $this->settings->string('admin.content.default_avatar');
        $metadata = $person['metadata'] ?? [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        $metadata = is_array($metadata) ? $metadata : [];
        return $person + [
            'person_id' => (int) ($person['id'] ?? 0),
            'person_name' => (string) ($person['name'] ?? ''),
            'person_pic' => (string) ($person['avatar_url'] ?? ''),
            'person_sid' => ($person['kind'] ?? '') === 'role' ? 9 : 8,
            'person_alias' => (string) ($person['aliases'] ?? $metadata['alias'] ?? ''),
            'person_profession' => (string) ($person['profession'] ?? $metadata['profession'] ?? ''),
            'person_gender' => (string) ($person['gender'] ?? $metadata['gender'] ?? ''),
            'person_birthday' => (string) ($person['birthday'] ?? $metadata['birthday'] ?? ''),
            'person_nationality' => (string) ($person['nationality'] ?? $metadata['nationality'] ?? ''),
            'person_astrology' => (string) ($metadata['astrology'] ?? ''),
            'person_school' => (string) ($metadata['school'] ?? ''),
            'person_hits' => (int) ($person['view_count'] ?? 0),
            'subtitle' => (string) ($person['profession'] ?? $metadata['profession'] ?? ''),
            'summary_text' => $this->summary((string) ($person['summary'] ?? $person['biography'] ?? ''), $this->settings->int('admin.content.summary_length', 180, 20, 1000)),
            'safe_content' => $this->settings->bool('admin.content.allow_html', true) ? (string) ($person['biography'] ?? '') : nl2br(htmlspecialchars(strip_tags((string) ($person['biography'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ];
    }

    /** @param array<int, array<string, mixed>> $categories
     *  @return array<int, int>
     */
    public function categoryIds(array $categories, int $rootId): array
    {
        $ids = [$rootId];
        do {
            $changed = false;
            foreach ($categories as $category) {
                $id = (int) ($category['id'] ?? 0);
                $parent = (int) ($category['parent_id'] ?? 0);
                if ($id > 0 && in_array($parent, $ids, true) && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $changed = true;
                }
            }
        } while ($changed);

        return $ids;
    }

    /** @return array<string, array<int, string>> */
    public function filters(array $category): array
    {
        $raw = $category['filter_options'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $filters = [];
        foreach (['type', 'area', 'year', 'star', 'state', 'language', 'version', 'weekday'] as $name) {
            $source = $raw[$name] ?? [];
            $values = is_array($source)
                ? array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $source)))
                : array_values(array_filter(array_map('trim', explode(',', (string) $source))));
            if ($values !== []) {
                $filters[$name] = $values;
            }
        }
        return $filters;
    }

    private function timestamp(mixed $value): int
    {
        if (is_int($value)) return $value;
        if (is_numeric($value)) return (int) $value;
        $timestamp = $value ? strtotime((string) $value) : false;
        return $timestamp === false ? 0 : $timestamp;
    }

    private function summary(string $value, int $length = 110): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) . '…' : $value;
    }
}
