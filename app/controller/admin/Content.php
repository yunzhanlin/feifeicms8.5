<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\Slugger;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Content extends BaseController
{
    private const DEFINITIONS = [
        'articles' => ['table' => 'ffx_articles', 'label' => '资讯', 'title' => 'title', 'category_type' => 'article'],
        'topics' => ['table' => 'ffx_topics', 'label' => '专题', 'title' => 'title', 'category_type' => 'topic'],
        'people' => ['table' => 'ffx_people', 'label' => '人物', 'title' => 'name', 'category_type' => null],
    ];

    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly Slugger $slugger, private readonly SiteSettings $settings)
    {
        parent::__construct($app);
    }

    public function index(string $type): Response
    {
        $def = $this->definition($type);
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $status = (string) $this->request->param('status', '');
        $kind = (string) $this->request->param('kind', '');
        $missing = (string) $this->request->param('missing', '');
        $query = Db::table($def['table'])->whereNull('deleted_at')->order('id', 'desc');
        if ($keyword !== '') {
            $query->whereLike($def['title'], '%' . $keyword . '%');
        }
        if (in_array($status, ['draft', 'published'], true)) {
            $query->where('status', $status);
        } else {
            $status = '';
        }
        if ($type === 'people' && in_array($kind, ['person', 'character', 'role'], true)) {
            $kind === 'role' ? $query->whereIn('kind', ['role', 'character']) : $query->where('kind', $kind);
        } else {
            $kind = '';
        }
        if ($type === 'people' && $missing === 'avatar') {
            $query->where('avatar_url', '');
        } else {
            $missing = '';
        }
        return view('/admin/content/index', [
            'type' => $type, 'label' => $def['label'], 'titleField' => $def['title'], 'keyword' => $keyword,
            'items' => $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => array_filter(['wd' => $keyword, 'status' => $status, 'kind' => $kind, 'missing' => $missing])]),
            'status' => $status, 'kind' => $kind, 'missing' => $missing,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function create(string $type): Response
    {
        $def = $this->definition($type);
        return $this->form($type, $def, null);
    }

    public function edit(string $type, int $id): Response
    {
        $def = $this->definition($type);
        return $this->form($type, $def, $this->requireItem($def['table'], $id));
    }

    public function store(string $type): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $data = $this->payload($type);
        if (trim((string) $data[$def['title']]) === '') {
            return response($def['label'] . '名称不能为空', 422);
        }
        $data['slug'] = $this->slugger->make((string) $this->request->post('slug', ''), rtrim($type, 's'));
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        if ($data['status'] === 'published' && $type !== 'people') {
            $data['published_at'] = $data['created_at'];
        }
        $id = Db::transaction(function () use ($def, $data, $type): int {
            $id = (int) Db::table($def['table'])->insertGetId($data);
            $this->syncRelations($type, $id);
            return $id;
        });
        $this->audit->record(rtrim($type, 's') . '.create', rtrim($type, 's'), $id, null, $data);
        return redirect('/admin/content/' . $type . '/' . $id . '/edit');
    }

    public function update(string $type, int $id): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $before = $this->requireItem($def['table'], $id);
        $data = $this->payload($type);
        if (trim((string) $data[$def['title']]) === '') {
            return response($def['label'] . '名称不能为空', 422);
        }
        if ($data['status'] === 'published' && $type !== 'people' && empty($before['published_at'])) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($type === 'articles' && empty($data['published_at']) && !empty($before['published_at'])) {
            $data['published_at'] = $before['published_at'];
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::transaction(function () use ($def, $data, $type, $id): void {
            Db::table($def['table'])->where('id', $id)->update($data);
            $this->syncRelations($type, $id);
        });
        $this->audit->record(rtrim($type, 's') . '.update', rtrim($type, 's'), $id, $before, $data);
        return redirect('/admin/content/' . $type . '/' . $id . '/edit');
    }

    public function delete(string $type, int $id): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $before = $this->requireItem($def['table'], $id);
        $data = ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table($def['table'])->where('id', $id)->update($data);
        $this->audit->record(rtrim($type, 's') . '.archive', rtrim($type, 's'), $id, $before, $data);
        return redirect('/admin/content/' . $type);
    }

    public function batch(string $type): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])), static fn (int $id): bool => $id > 0)));
        $action = (string) $this->request->post('action', '');
        if ($ids === [] || !in_array($action, ['publish', 'draft', 'archive'], true)) return response('请选择内容和有效操作', 422);
        $ids = array_slice($ids, 0, 500);
        $before = Db::table($def['table'])->whereIn('id', $ids)->whereNull('deleted_at')->column('status', 'id');
        $data = ['updated_at' => gmdate('Y-m-d H:i:s')];
        if ($action === 'archive') {
            $data += ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s')];
        } else {
            $data['status'] = $action === 'publish' ? 'published' : 'draft';
            if ($action === 'publish' && $type !== 'people') $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        Db::table($def['table'])->whereIn('id', $ids)->whereNull('deleted_at')->update($data);
        $this->audit->record('content.batch.' . $action, $type, implode(',', $ids), $before, $data);
        return redirect('/admin/content/' . $type);
    }

    private function form(string $type, array $def, ?array $item): Response
    {
        $isNew = $item === null;
        $defaultStatus = $this->settings->string('admin.content.default_status', 'draft') === 'published' ? 'published' : 'draft';
        $defaults = $type === 'people'
            ? ['id' => 0, 'name' => '', 'slug' => '', 'kind' => in_array((string) $this->request->param('kind', ''), ['person', 'character', 'role'], true) ? (string) $this->request->param('kind') : 'person', 'aliases' => '', 'gender' => '', 'nationality' => '', 'birthday' => '', 'profession' => '', 'avatar_url' => '', 'backdrop_url' => '', 'summary' => '', 'biography' => '', 'status' => $defaultStatus, 'metadata' => null]
            : ['id' => 0, 'title' => '', 'slug' => '', 'category_id' => null, 'summary' => '', 'content' => '', 'cover_url' => '', 'logo_url' => '', 'banner_url' => '', 'author' => '', 'source_url' => '', 'weight' => 0, 'published_at' => '', 'status' => $defaultStatus, 'metadata' => null];
        $item = $item ?? $defaults;
        $metadata = $item['metadata'] ?? [];
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        $item['metadata_fields'] = (is_array($metadata) ? $metadata : []) + [
            'seo_title' => '', 'seo_keywords' => '', 'seo_description' => '', 'english_name' => '',
            'astrology' => '', 'blood_type' => '', 'height' => '', 'weight' => '', 'region' => '',
            'view_count' => 0, 'sort_order' => 0,
        ];
        $categories = $def['category_type'] === null ? [] : Db::table('ffx_categories')->where('content_type', $def['category_type'])->where('status', 'published')->whereNull('deleted_at')->order('sort_order')->select()->toArray();
        return view('/admin/content/edit', [
            'type' => $type, 'label' => $def['label'], 'item' => $item,
            'categories' => $categories, 'csrf' => $this->csrf->get(), 'isNew' => $isNew,
            'tags' => $type === 'articles' && !$isNew ? implode(', ', Db::table('ffx_article_tags')->alias('at')->join(['ffx_tags' => 't'], 't.id = at.tag_id')->where('at.article_id', (int) $item['id'])->order('t.name')->column('t.name')) : '',
            'mediaIds' => $type === 'topics' && !$isNew ? implode(', ', Db::table('ffx_topic_media')->where('topic_id', (int) $item['id'])->order('sort_order')->column('media_id')) : '',
            'credits' => $type === 'people' && !$isNew ? $this->personCredits((int) $item['id']) : '',
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $type): array
    {
        $status = $this->request->post('status', '') === 'published' ? 'published' : 'draft';
        $rawContent = trim((string) $this->request->post('content', ''));
        $content = $this->settings->bool('admin.content.allow_html', true) ? $rawContent : strip_tags($rawContent);
        $summary = trim((string) $this->request->post('summary', ''));
        if ($summary === '' && $content !== '') {
            $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($content)));
            $summary = mb_substr($plain, 0, $this->settings->int('admin.content.summary_length', 180, 20, 1000));
        }
        if ($type === 'people') {
            return [
                'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 255),
                'kind' => in_array((string) $this->request->post('kind', 'person'), ['person', 'character', 'role'], true) ? (string) $this->request->post('kind') : 'person',
                'aliases' => mb_substr(trim((string) $this->request->post('aliases', '')), 0, 500),
                'gender' => mb_substr(trim((string) $this->request->post('gender', '')), 0, 30),
                'nationality' => mb_substr(trim((string) $this->request->post('nationality', '')), 0, 80),
                'birthday' => (($birthday = trim((string) $this->request->post('birthday', ''))) !== '') ? $birthday : null,
                'profession' => mb_substr(trim((string) $this->request->post('profession', '')), 0, 255),
                'avatar_url' => mb_substr(trim((string) $this->request->post('avatar_url', '')), 0, 1000),
                'backdrop_url' => mb_substr(trim((string) $this->request->post('backdrop_url', '')), 0, 1000),
                'summary' => mb_substr($summary, 0, 1000),
                'biography' => $content,
                'status' => $status,
                'metadata' => $this->metadata(['english_name', 'astrology', 'blood_type', 'height', 'weight', 'region', 'seo_title', 'seo_keywords', 'seo_description', 'view_count', 'sort_order']),
            ];
        }
        $base = [
            'category_id' => (($category = (int) $this->request->post('category_id', 0)) > 0) ? $category : null,
            'title' => mb_substr(trim((string) $this->request->post('title', '')), 0, 255),
            'summary' => $summary,
            'content' => $content,
            'status' => $status,
        ];
        if ($type === 'articles') {
            $base['cover_url'] = mb_substr(trim((string) $this->request->post('cover_url', '')), 0, 1000);
            $base['author'] = mb_substr(trim((string) $this->request->post('author', '')), 0, 120);
            $base['source_url'] = mb_substr(trim((string) $this->request->post('source_url', '')), 0, 1000);
            $base['weight'] = (int) $this->request->post('weight', 0);
            $base['published_at'] = $this->nullableDateTime('published_at');
            $base['metadata'] = $this->metadata(['seo_title', 'seo_keywords', 'seo_description']);
        } else {
            $base['logo_url'] = mb_substr(trim((string) $this->request->post('logo_url', '')), 0, 1000);
            $base['banner_url'] = mb_substr(trim((string) $this->request->post('banner_url', '')), 0, 1000);
            $base['metadata'] = $this->metadata(['seo_title', 'seo_keywords', 'seo_description', 'view_count', 'sort_order']);
        }
        return $base;
    }

    private function definition(string $type): array
    {
        if (!isset(self::DEFINITIONS[$type])) {
            throw new HttpException(404, '内容类型不存在');
        }
        return self::DEFINITIONS[$type];
    }

    private function requireItem(string $table, int $id): array
    {
        $row = Db::table($table)->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '内容不存在');
        }
        return $row;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }

    private function syncRelations(string $type, int $id): void
    {
        if ($type === 'topics') {
            Db::table('ffx_topic_media')->where('topic_id', $id)->delete();
            $mediaIds = preg_split('/[,\x{FF0C}\s]+/u', (string) $this->request->post('media_ids', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $mediaIds = array_values(array_unique(array_filter(array_map('intval', $mediaIds), static fn (int $mediaId): bool => $mediaId > 0)));
            $validIds = $mediaIds === [] ? [] : Db::table('ffx_media')->whereIn('id', array_slice($mediaIds, 0, 200))->whereNull('deleted_at')->column('id');
            foreach ($validIds as $sort => $mediaId) {
                Db::table('ffx_topic_media')->insert(['topic_id' => $id, 'media_id' => (int) $mediaId, 'sort_order' => $sort]);
            }
        }
        if ($type === 'articles') {
            Db::table('ffx_article_tags')->where('article_id', $id)->delete();
            $tagNames = preg_split('/[,\x{FF0C}\n]+/u', (string) $this->request->post('tags', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice(array_values(array_unique(array_filter(array_map('trim', $tagNames)))), 0, 30) as $tagName) {
                $tagName = mb_substr($tagName, 0, 100);
                $tagSlug = 'tag-' . substr(hash('sha256', mb_strtolower($tagName)), 0, 32);
                $tag = Db::table('ffx_tags')->where('scope', 'article')->where('slug', $tagSlug)->find();
                $tagId = $tag === null
                    ? Db::table('ffx_tags')->insertGetId(['scope' => 'article', 'name' => $tagName, 'slug' => $tagSlug, 'created_at' => gmdate('Y-m-d H:i:s')])
                    : (int) $tag['id'];
                Db::table('ffx_article_tags')->insert(['article_id' => $id, 'tag_id' => $tagId]);
            }
        }
        if ($type === 'people') {
            Db::table('ffx_media_people')->where('person_id', $id)->delete();
            $lines = preg_split('/\R+/', (string) $this->request->post('credits', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($lines, 0, 500) as $sort => $line) {
                $parts = array_map('trim', explode('|', $line, 4));
                $mediaId = (int) ($parts[0] ?? 0);
                if ($mediaId < 1 || Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')->count() < 1) continue;
                $creditType = in_array($parts[1] ?? '', ['actor', 'director', 'writer', 'producer', 'voice'], true) ? $parts[1] : 'actor';
                Db::table('ffx_media_people')->insert([
                    'media_id' => $mediaId, 'person_id' => $id, 'credit_type' => $creditType,
                    'character_name' => mb_substr($parts[2] ?? '', 0, 255),
                    'sort_order' => isset($parts[3]) ? (int) $parts[3] : $sort,
                ]);
            }
        }
    }

    /** @param array<int, string> $keys */
    private function metadata(array $keys): string
    {
        $data = [];
        foreach ($keys as $key) {
            $value = trim((string) $this->request->post($key, ''));
            if ($value !== '') $data[$key] = in_array($key, ['view_count', 'sort_order'], true) ? (int) $value : mb_substr($value, 0, 2000);
        }
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullableDateTime(string $key): ?string
    {
        $value = trim((string) $this->request->post($key, ''));
        return $value === '' ? null : str_replace('T', ' ', $value);
    }

    private function personCredits(int $personId): string
    {
        $lines = [];
        foreach (Db::table('ffx_media_people')->where('person_id', $personId)->order('sort_order')->select()->toArray() as $row) {
            $lines[] = implode('|', [$row['media_id'], $row['credit_type'], $row['character_name'], $row['sort_order']]);
        }
        return implode("\n", $lines);
    }
}
