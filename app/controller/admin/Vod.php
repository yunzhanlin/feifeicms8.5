<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\Category;
use app\model\Media;
use app\service\AuditLogger;
use app\service\AdminVodQuery;
use app\service\CollectionRunner;
use app\service\CsrfToken;
use app\service\CollectionSourceIdentity;
use app\service\DoubanMetadata;
use app\service\FrontendCache;
use app\service\MediaMerge;
use app\service\Slugger;
use app\service\SiteSettings;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;
use Throwable;

final class Vod extends BaseController
{
    public function __construct(
        \think\App $app,
        private readonly CsrfToken $csrf,
        private readonly AuditLogger $audit,
        private readonly Slugger $slugger,
        private readonly CollectionSourceIdentity $sourceIdentity,
        private readonly DoubanMetadata $douban,
        private readonly SiteSettings $settings,
        private readonly CollectionRunner $collectionRunner,
        private readonly MediaMerge $mediaMerge,
        private readonly AdminVodQuery $vodQuery,
        private readonly FrontendCache $frontendCache,
    )
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $categoryId = max(0, (int) $this->request->param('category_id', 0));
        $status = (string) $this->request->param('status', '');
        $year = max(0, min(9999, (int) $this->request->param('year', 0)));
        $order = (string) $this->request->param('order', 'id');
        $sort = strtolower((string) $this->request->param('sort', 'desc')) === 'asc' ? 'asc' : 'desc';
        $stars = max(0, min(5, (int) $this->request->param('stars', 0)));
        $isEnd = (string) $this->request->param('isend', '');
        $weekday = mb_substr(trim((string) $this->request->param('weekday', '')), 0, 80);
        $state = mb_substr(trim((string) $this->request->param('state', '')), 0, 80);
        $area = mb_substr(trim((string) $this->request->param('area', '')), 0, 80);
        $play = mb_substr(trim((string) $this->request->param('play', '')), 0, 80);
        $type = mb_substr(trim((string) $this->request->param('type', '')), 0, 80);
        $access = (string) $this->request->param('access', '');
        $missing = (string) $this->request->param('missing', '');
        $meta = (string) $this->request->param('meta', '');
        $duplicate = (string) $this->request->param('duplicate', '');
        $locked = (int) $this->request->param('locked', 0) === 1;
        $paid = (int) $this->request->param('paid', 0) === 1;
        if ($duplicate === '1') $duplicate = 'title';
        if (!in_array($duplicate, ['title', 'douban'], true)) $duplicate = '';
        if (!in_array($status, ['published', 'draft'], true)) {
            $status = '';
        }
        if (!in_array($order, ['id', 'hits', 'year', 'addtime', 'stars', 'updated_at', 'weight', 'rating'], true)) $order = 'id';
        if (!in_array($access, ['member', 'points', 'free'], true)) $access = '';
        if (!in_array($missing, ['poster', 'play', 'douban', 'scenario'], true)) $missing = '';
        if (!in_array($meta, ['lines', 'scenario', 'douban', 'trysee', 'series'], true)) $meta = '';
        if (!in_array($isEnd, ['true', 'false'], true)) $isEnd = '';
        $filters = ['wd' => $keyword, 'category_id' => $categoryId, 'status' => $status, 'year' => $year, 'order' => $order, 'sort' => $sort, 'stars' => $stars, 'isend' => $isEnd, 'weekday' => $weekday, 'state' => $state, 'area' => $area, 'play' => $play, 'type' => $type, 'access' => $access, 'missing' => $missing, 'meta' => $meta, 'duplicate' => $duplicate, 'locked' => $locked ? 1 : 0, 'paid' => $paid ? 1 : 0];
        $queryArgs = array_filter($filters, static fn (mixed $value): bool => $value !== '' && $value !== 0);
        $query = $this->vodQuery->build($filters);
        $items = $query->paginate(['list_rows' => $this->settings->int('admin.content.admin_page_size', 30, 10, 200), 'query' => $queryArgs]);
        $categories = $this->categories();
        $this->decorateListItems($items, $categories);
        $sortLinks = [];
        foreach (['id', 'stars', 'hits', 'year', 'addtime'] as $field) {
            $nextSort = $order === $field && $sort === 'desc' ? 'asc' : 'desc';
            $sortLinks[$field] = '/admin/vod?' . http_build_query(array_merge($queryArgs, ['order' => $field, 'sort' => $nextSort, 'page' => 1]));
        }
        return view('/admin/vod/index', [
            'items' => $items,
            'keyword' => $keyword,
            'categories' => $categories,
            'filters' => $filters,
            'sortLinks' => $sortLinks,
            'pageTotal' => $items->total(),
            'pageCurrent' => $items->currentPage(),
            'pageLast' => $items->lastPage(),
            'notice' => mb_substr(trim((string) $this->request->get('notice', '')), 0, 200),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function create(): Response
    {
        return view('/admin/vod/edit', [
            'media' => [
                'id' => 0, 'title' => '', 'category_id' => null,
                'status' => $this->settings->string('admin.content.default_status', 'draft') === 'published' ? 'published' : 'draft',
                'release_year' => null, 'original_title' => '', 'subtitle' => '', 'douban_id' => '', 'imdb_id' => '',
                'area' => '', 'language' => '', 'poster_url' => '', 'backdrop_url' => '',
                'media_type' => 'video', 'release_date' => '', 'episode_total' => null,
                'episode_label' => '', 'is_completed' => 0, 'copyright_mode' => 'normal',
                'access_mode' => 'free', 'price_points' => 0, 'rating' => null, 'weight' => 0,
                'summary' => '', 'content' => '', 'slug' => '',
            ],
            'legacyMeta' => $this->legacyMeta(),
            'categories' => $this->categories(),
            'selectedCategoryIds' => [],
            'selectedCategoryNames' => '',
            'tags' => '',
            'credits' => '',
            'playSources' => [[
                'id' => 0, 'source_key' => 'default', 'display_name' => '默认线路',
                'parser_key' => '', 'status' => 'enabled', 'legacy_urls' => '',
            ]],
            'players' => $this->players(),
            'editorOptions' => $this->editorOptions(null),
            'csrf' => $this->csrf->get(),
            'isNew' => true,
        ]);
    }

    public function store(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return response('请求已过期', 419);
        }
        $data = $this->payload();
        if ($data['title'] === '') {
            return response('影片名称不能为空', 422);
        }
        $data['slug'] = $this->slugger->make((string) $this->request->post('slug', ''), 'media');
        $data['media_type'] = 'video';
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        if ($data['status'] === 'published') {
            $data['published_at'] = $data['created_at'];
        }
        $media = Db::transaction(function () use ($data): Media {
            $media = Media::create($data);
            $this->syncRelations((int) $media->id, $data['category_id']);
            $this->syncLegacyPlayback((int) $media->id);
            return $media;
        });
        $this->audit->record('media.create', 'media', (int) $media->id, null, $media->toArray());
        $this->frontendCache->invalidateHome();
        return redirect('/admin/vod/' . $media->id . '/edit');
    }

    public function edit(int $id): Response
    {
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在');
        }
        $mediaData = $media->toArray();
        $metadata = is_array($mediaData['metadata'] ?? null) ? $mediaData['metadata'] : [];
        $selectedCategoryIds = Db::table('ffx_media_categories')->where('media_id', $id)->order('sort_order')->column('category_id');
        $selectedCategoryNames = $selectedCategoryIds === [] ? [] : Db::table('ffx_categories')->whereIn('id', $selectedCategoryIds)->column('name');
        $legacyMeta = $this->legacyMeta($metadata);
        $legacyMeta['source_ref'] = $this->sourceIdentity->forMedia($id, (string) $legacyMeta['source_ref']);
        if ($legacyMeta['inputer'] === '') {
            $sourceId = (int) Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', $id)->order('id')->value('source_id');
            if ($sourceId > 0) $legacyMeta['inputer'] = 'json_' . $sourceId;
        }
        return view('/admin/vod/edit', [
            'media' => $mediaData,
            'legacyMeta' => $legacyMeta,
            'categories' => Category::where('content_type', 'media')->where('status', 'published')->order('sort_order')->select()->toArray(),
            'selectedCategoryIds' => $selectedCategoryIds,
            'selectedCategoryNames' => implode(',', $selectedCategoryNames),
            'tags' => implode(', ', Db::table('ffx_media_tags')->alias('mt')->join(['ffx_tags' => 't'], 't.id = mt.tag_id')->where('mt.media_id', $id)->order('t.name')->column('t.name')),
            'credits' => $this->creditText($id),
            'playSources' => $this->legacyPlaySources($id),
            'players' => $this->players(),
            'editorOptions' => $this->editorOptions((int) ($mediaData['category_id'] ?? 0)),
            'csrf' => $this->csrf->get(),
            'isNew' => false,
        ]);
    }

    public function update(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return response('请求已过期', 419);
        }
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在');
        }
        $before = $media->toArray();
        $data = $this->payload();
        if ($data['title'] === '') {
            return response('影片名称不能为空', 422);
        }
        if ($data['status'] === 'published' && empty($media->published_at)) {
            $data['published_at'] = gmdate('Y-m-d H:i:s');
        }
        $existingMetadata = is_array($media->metadata ?? null) ? $media->metadata : [];
        $data['metadata'] = array_merge($existingMetadata, $data['metadata']);
        $data['updated_at'] = (int) $this->request->post('refresh_updated_at', 0) === 1
            ? gmdate('Y-m-d H:i:s')
            : (string) $media->updated_at;
        Db::transaction(function () use ($media, $data, $id): void {
            $media->save($data);
            $this->syncRelations($id, $data['category_id']);
            $this->syncLegacyPlayback($id);
        });
        $this->audit->record('media.update', 'media', $id, $before, $media->toArray());
        $this->frontendCache->invalidateHome();
        return redirect('/admin/vod/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return response('请求已过期', 419);
        }
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在');
        }
        $before = $media->toArray();
        $media->save(['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('media.archive', 'media', $id, $before, $media->toArray());
        $this->frontendCache->invalidateHome();
        return redirect('/admin/vod');
    }

    public function doubanMetadata(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $subjectId = trim((string) $this->request->post('douban_id', ''));
        try {
            $data = $this->douban->fetch($subjectId);
        } catch (Throwable $exception) {
            return json(['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 200)], 422);
        }
        $this->audit->record('media.douban_fetch', 'media', $subjectId, null, ['title' => $data['title'] ?? '']);
        return json(['ok' => true, 'message' => '豆瓣资料已填入表单，请检查后提交保存。', 'data' => $data]);
    }

    public function uploadImage(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $file = $this->request->file('file');
        if ($file === null || !$file->isValid()) return json(['ok' => false, 'message' => '请选择有效图片'], 422);
        $size = $file->getSize();
        $maxMb = $this->settings->int('admin.files.max_size_mb', 20, 1, 200);
        if ($size > $maxMb * 1024 * 1024) return json(['ok' => false, 'message' => '图片不能超过 ' . $maxMb . 'MB'], 422);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!isset($extensions[$mime])) return json(['ok' => false, 'message' => '仅允许 JPG、PNG、WebP 或 GIF 图片'], 422);
        $name = 'vod-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
        $root = $this->uploadRoot();
        if (!is_dir($root)) mkdir($root, 0755, true);
        $file->move($root, $name);
        chmod($root . DIRECTORY_SEPARATOR . $name, 0644);
        $baseUrl = trim($this->settings->string('admin.files.base_url'));
        if ($baseUrl === '') $baseUrl = '/' . trim($this->settings->string('admin.files.upload_path', 'uploads'), '/');
        $url = rtrim($baseUrl, '/') . '/' . rawurlencode($name);
        $this->audit->record('media.image_upload', 'upload', $name, null, ['mime' => $mime, 'size' => $size]);
        return json(['ok' => true, 'url' => $url]);
    }

    private function uploadRoot(): string
    {
        $relative = trim((string) preg_replace('/[^A-Za-z0-9_\/-]+/', '', $this->settings->string('admin.files.upload_path', 'uploads')), '/');
        return public_path() . ($relative !== '' ? $relative : 'uploads');
    }

    public function batch(): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            return response('请求已过期', 419);
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', []))))), 0, 200);
        $action = (string) $this->request->post('action', '');
        if ($ids === [] || !in_array($action, ['publish', 'draft', 'archive', 'move', 'series', 'merge', 'lock', 'unlock'], true)) {
            return response('请选择视频和批量操作', 422);
        }
        if ($action === 'merge') {
            try {
                $result = $this->mediaMerge->merge($ids);
            } catch (Throwable $exception) {
                return response($exception->getMessage(), 422);
            }
            $this->audit->record('media.batch_merge', 'media', (int) $result['primary_id'], null, $result);
            $this->frontendCache->invalidateHome();
            return redirect('/admin/vod?notice=' . rawurlencode('影片合并完成：主影片 #' . $result['primary_id'] . '，合并 ' . count($result['merged_ids']) . ' 条记录'));
        }
        $targetCategory = max(0, (int) $this->request->post('target_category_id', 0));
        if ($action === 'move' && ($targetCategory < 1 || Db::table('ffx_categories')->where('id', $targetCategory)->where('content_type', 'media')->whereNull('deleted_at')->count() !== 1)) {
            return response('请选择有效的目标分类', 422);
        }
        $series = mb_substr(trim((string) $this->request->post('series', '')), 0, 255);
        if ($action === 'series' && $series === '') return response('请填写系列名称', 422);
        $now = gmdate('Y-m-d H:i:s');
        foreach ($ids as $id) {
            $media = Media::where('id', $id)->whereNull('deleted_at')->find();
            if ($media === null) continue;
            $before = $media->toArray();
            if ($action === 'move') {
                $data = ['category_id' => $targetCategory, 'updated_at' => $now];
                Db::transaction(function () use ($media, $id, $targetCategory, $data): void {
                    $media->save($data);
                    Db::table('ffx_media_categories')->where('media_id', $id)->update(['is_primary' => 0]);
                    $existing = Db::table('ffx_media_categories')->where('media_id', $id)->where('category_id', $targetCategory)->find();
                    if ($existing === null) Db::table('ffx_media_categories')->insert(['media_id' => $id, 'category_id' => $targetCategory, 'is_primary' => 1, 'sort_order' => 0]);
                    else Db::table('ffx_media_categories')->where('media_id', $id)->where('category_id', $targetCategory)->update(['is_primary' => 1, 'sort_order' => 0]);
                });
            } elseif (in_array($action, ['series', 'lock', 'unlock'], true)) {
                $metadata = is_array($media->metadata) ? $media->metadata : (json_decode((string) $media->metadata, true) ?: []);
                if ($action === 'series') $metadata['series'] = $series;
                else $metadata['inputer'] = $action === 'lock' ? 'feifeicms' : '';
                $data = ['metadata' => $metadata, 'updated_at' => $now];
                $media->save($data);
            } else {
                $data = match ($action) {
                    'publish' => ['status' => 'published', 'published_at' => $media->published_at ?: $now, 'updated_at' => $now],
                    'draft' => ['status' => 'draft', 'updated_at' => $now],
                    default => ['status' => 'archived', 'deleted_at' => $now, 'updated_at' => $now],
                };
                $media->save($data);
            }
            $this->audit->record('media.batch_' . $action, 'media', $id, $before, $data);
        }
        $messages = ['publish' => '批量审核完成', 'draft' => '已取消审核', 'archive' => '批量归档完成', 'move' => '批量移动完成', 'series' => '系列设置完成', 'lock' => '批量锁定完成', 'unlock' => '批量解锁完成'];
        $this->frontendCache->invalidateHome();
        return redirect('/admin/vod?notice=' . rawurlencode($messages[$action] ?? '批量操作完成'));
    }

    public function quick(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $action = (string) $this->request->post('action', '');
        if (!in_array($action, ['publish', 'draft', 'archive', 'lock', 'unlock'], true)) return json(['ok' => false, 'message' => '操作无效'], 422);
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) return json(['ok' => false, 'message' => '影片不存在'], 404);
        $before = $media->toArray();
        $now = gmdate('Y-m-d H:i:s');
        if (in_array($action, ['lock', 'unlock'], true)) {
            $metadata = is_array($media->metadata) ? $media->metadata : (json_decode((string) $media->metadata, true) ?: []);
            $metadata['inputer'] = $action === 'lock' ? 'feifeicms' : '';
            $data = ['metadata' => $metadata, 'updated_at' => $now];
        } else {
            $data = match ($action) {
                'publish' => ['status' => 'published', 'published_at' => $media->published_at ?: $now, 'updated_at' => $now],
                'draft' => ['status' => 'draft', 'updated_at' => $now],
                default => ['status' => 'archived', 'deleted_at' => $now, 'updated_at' => $now],
            };
        }
        $media->save($data);
        $this->audit->record('media.quick_' . $action, 'media', $id, $before, $data);
        $this->frontendCache->invalidateHome();
        return json(['ok' => true, 'message' => '操作完成']);
    }

    public function weight(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        $weight = max(0, min(5, (int) $this->request->post('weight', 0)));
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) return json(['ok' => false, 'message' => '影片不存在'], 404);
        $before = ['weight' => (int) $media->weight];
        $media->save(['weight' => $weight, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->audit->record('media.weight', 'media', $id, $before, ['weight' => $weight]);
        $this->frontendCache->invalidateHome();
        return json(['ok' => true, 'message' => '权重已更新', 'data' => ['weight' => $weight]]);
    }

    public function collectScenarios(int $id): Response
    {
        if (!$this->csrf->verify($this->request->post('_token'))) return json(['ok' => false, 'message' => '请求已过期'], 419);
        try {
            $result = $this->collectionRunner->runScenariosForMedia($id);
            $this->audit->record('scenario.collect_media', 'media', $id, null, $result);
            return json(['ok' => true, 'message' => '处理 ' . $result['processed'] . ' 条来源，写入/更新 ' . $result['imported'] . ' 集剧情', 'data' => $result]);
        } catch (Throwable $exception) {
            return json(['ok' => false, 'message' => mb_substr($exception->getMessage(), 0, 200)], 422);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'title' => mb_substr(trim((string) $this->request->post('title', '')), 0, 255),
            'category_id' => (($categoryId = (int) $this->request->post('category_id', 0)) > 0) ? $categoryId : null,
            'status' => $this->request->post('status', '') === 'published' ? 'published' : 'draft',
            'release_year' => min(9999, max(0, (int) $this->request->post('release_year', 0))) ?: null,
            'original_title' => mb_substr(trim((string) $this->request->post('original_title', '')), 0, 255),
            'subtitle' => mb_substr(trim((string) $this->request->post('subtitle', '')), 0, 255),
            'douban_id' => mb_substr(trim((string) $this->request->post('douban_id', '')), 0, 32),
            'imdb_id' => mb_substr(trim((string) $this->request->post('imdb_id', '')), 0, 32),
            'area' => mb_substr(trim((string) $this->request->post('area', '')), 0, 80),
            'language' => mb_substr(trim((string) $this->request->post('language', '')), 0, 80),
            'poster_url' => mb_substr(trim((string) $this->request->post('poster_url', '')), 0, 1000),
            'backdrop_url' => mb_substr(trim((string) $this->request->post('backdrop_url', '')), 0, 1000),
            'media_type' => mb_substr(trim((string) $this->request->post('media_type', 'video')), 0, 32),
            'release_date' => (($date = trim((string) $this->request->post('release_date', ''))) !== '') ? $date : null,
            'episode_total' => (($total = (int) $this->request->post('episode_total', 0)) > 0) ? $total : null,
            'episode_label' => mb_substr(trim((string) $this->request->post('episode_label', '')), 0, 50),
            'is_completed' => (int) ((bool) $this->request->post('is_completed', 0)),
            'copyright_mode' => mb_substr(trim((string) $this->request->post('copyright_mode', 'normal')), 0, 30),
            'access_mode' => mb_substr(trim((string) $this->request->post('access_mode', 'free')), 0, 30),
            'price_points' => max(0, (int) $this->request->post('price_points', 0)),
            'rating' => (($rating = (float) $this->request->post('rating', 0)) > 0) ? min(10, $rating) : null,
            'rating_count' => max(0, (int) $this->request->post('rating_count', 0)),
            'view_count' => max(0, (int) $this->request->post('view_count', 0)),
            'like_count' => max(0, (int) $this->request->post('like_count', 0)),
            'dislike_count' => max(0, (int) $this->request->post('dislike_count', 0)),
            'weight' => (int) $this->request->post('weight', 0),
            'content' => trim((string) $this->request->post('content', '')),
            'metadata' => [
                'lines' => trim((string) $this->request->post('lines', '')),
                'watch' => trim((string) $this->request->post('watch', '')),
                'ending' => trim((string) $this->request->post('ending', '')),
                'trysee' => max(0, (int) $this->request->post('trysee', 0)),
                'weekday' => mb_substr(trim((string) $this->request->post('weekday', '')), 0, 80),
                'tv' => mb_substr(trim((string) $this->request->post('tv', '')), 0, 255),
                'length' => max(0, (int) $this->request->post('length', 0)),
                'copyright' => max(0, (int) $this->request->post('copyright', 0)),
                'source_ref' => mb_substr(trim((string) $this->request->post('source_ref', '')), 0, 500),
                'douban_score' => min(10, max(0, (float) $this->request->post('douban_score', 0))),
                'slide_image' => mb_substr(trim((string) $this->request->post('slide_image', '')), 0, 1000),
                'version' => mb_substr(trim((string) $this->request->post('version', '')), 0, 80),
                'type' => mb_substr(trim((string) $this->request->post('type', '')), 0, 255),
                'state' => mb_substr(trim((string) $this->request->post('state', '正片')), 0, 80),
                'skin' => mb_substr(trim((string) $this->request->post('skin', '')), 0, 80),
                'director' => mb_substr(trim((string) $this->request->post('director', '')), 0, 1000),
                'actor' => mb_substr(trim((string) $this->request->post('actor', '')), 0, 1000),
                'writer' => mb_substr(trim((string) $this->request->post('writer', '')), 0, 1000),
                'producer' => mb_substr(trim((string) $this->request->post('producer', '')), 0, 1000),
                'camera' => mb_substr(trim((string) $this->request->post('camera', '')), 0, 1000),
                'editor' => mb_substr(trim((string) $this->request->post('editor', '')), 0, 1000),
                'music' => mb_substr(trim((string) $this->request->post('music', '')), 0, 1000),
                'art' => mb_substr(trim((string) $this->request->post('art', '')), 0, 1000),
                'letter' => mb_substr(trim((string) $this->request->post('letter', '')), 0, 10),
                'inputer' => mb_substr(trim((string) $this->request->post('inputer', '')), 0, 80),
                'series' => mb_substr(trim((string) $this->request->post('series', '')), 0, 255),
                'jumpurl' => mb_substr(trim((string) $this->request->post('jumpurl', '')), 0, 1000),
                'hits_month' => max(0, (int) $this->request->post('hits_month', 0)),
                'hits_week' => max(0, (int) $this->request->post('hits_week', 0)),
                'hits_day' => max(0, (int) $this->request->post('hits_day', 0)),
            ],
        ];
    }

    private function categories(): array
    {
        return Category::where('content_type', 'media')->where('status', 'published')->order('sort_order')->select()->toArray();
    }

    /** @param iterable<int,Media> $items @param array<int,array<string,mixed>|Category> $categories */
    private function decorateListItems(iterable $items, array $categories): void
    {
        $ids = [];
        foreach ($items as $item) $ids[] = (int) $item->id;
        if ($ids === []) return;
        $categoryNames = [];
        foreach ($categories as $category) $categoryNames[(int) $category['id']] = (string) $category['name'];
        $sourceKeys = [];
        foreach (Db::table('ffx_play_sources')->whereIn('media_id', $ids)->where('status', 'enabled')->order('sort_order')->order('id')->field('media_id,source_key')->select()->toArray() as $source) {
            $sourceKeys[(int) $source['media_id']][] = (string) $source['source_key'];
        }
        $scenarioCounts = [];
        foreach (Db::table('ffx_scenarios')->whereIn('media_id', $ids)->whereNull('deleted_at')->field('media_id,COUNT(*) AS total')->group('media_id')->select()->toArray() as $row) {
            $scenarioCounts[(int) $row['media_id']] = (int) $row['total'];
        }
        foreach ($items as $item) {
            $metadata = is_array($item->metadata) ? $item->metadata : (json_decode((string) $item->metadata, true) ?: []);
            $continu = trim((string) ($item->episode_label ?? ''));
            $weekday = trim((string) ($metadata['weekday'] ?? ''));
            if ((int) $item->is_completed === 1) {
                $continu = (int) ($item->episode_total ?? 0) > 0 ? '已完结·全' . (int) $item->episode_total . '集' : ($continu !== '' ? $continu : '已完结');
            } elseif ($weekday !== '') {
                $continu = $weekday . '更新' . ($continu !== '' ? '·' . $continu : '');
            } elseif ($continu === '' && (int) ($item->episode_total ?? 0) > 0) {
                $continu = '更新至' . (int) $item->episode_total . '集';
            }
            $item->setAttr('legacy_category', $categoryNames[(int) ($item->category_id ?? 0)] ?? '未分类');
            $item->setAttr('legacy_type', trim((string) ($metadata['type'] ?? '')));
            $item->setAttr('legacy_sources', implode(' ', array_values(array_unique($sourceKeys[(int) $item->id] ?? []))));
            $item->setAttr('legacy_continu', $continu);
            $item->setAttr('legacy_date', str_replace('-', '.', substr((string) $item->updated_at, 0, 10)));
            $item->setAttr('legacy_locked', (int) (($metadata['inputer'] ?? '') === 'feifeicms'));
            $item->setAttr('scenario_count', $scenarioCounts[(int) $item->id] ?? 0);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function players(): array
    {
        return Db::table('ffx_players')->where('status', 'enabled')->order('sort_order')->order('id')->select()->toArray();
    }

    /** @return array<string, array<int, mixed>> */
    private function editorOptions(?int $categoryId): array
    {
        $defaults = [
            'type' => ['喜剧', '爱情', '恐怖', '动作', '科幻', '剧情', '战争', '警匪', '犯罪', '动画', '奇幻', '武侠', '冒险', '枪战', '悬疑', '惊悚', '经典', '青春', '文艺', '网络电影'],
            'area' => ['中国大陆', '美国', '中国香港', '中国台湾', '韩国', '日本', '法国', '英国', '德国', '泰国', '印度', '欧洲', '东南亚', '其他'],
            'language' => ['国语', '英语', '粤语', '闽南语', '韩语', '日语', '其它'],
            'version' => ['高清版', '剧场版', '抢先版', 'OVA', 'TV', '影院版'],
            'state' => ['正片', '预告片', '花絮'],
        ];
        if ($categoryId !== null && $categoryId > 0) {
            $raw = Db::table('ffx_categories')->where('id', $categoryId)->value('filter_options');
            $filters = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
            foreach (array_keys($defaults) as $key) {
                $values = $filters[$key] ?? [];
                if (is_string($values)) $values = explode(',', $values);
                if (is_array($values) && $values !== []) $defaults[$key] = array_values(array_filter(array_map('trim', $values)));
            }
        }
        $year = (int) date('Y');
        $defaults['year'] = range($year, max(2009, $year - 17));
        $defaults['tags'] = Db::table('ffx_tags')->alias('t')->leftJoin(['ffx_media_tags' => 'mt'], 'mt.tag_id=t.id')
            ->where('t.scope', 'media')->field('t.name,COUNT(mt.media_id) AS usage_count')->group('t.id,t.name')
            ->order('usage_count', 'desc')->order('t.name')->limit(10)->select()->toArray();
        return $defaults;
    }

    private function syncRelations(int $mediaId, ?int $primaryCategoryId): void
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('category_ids', [])), static fn (int $id): bool => $id > 0)));
        $categoryNames = preg_split('/[,\x{FF0C}\n]+/u', (string) $this->request->post('category_names', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('trim', $categoryNames)))), 0, 30) as $categoryName) {
            $categoryId = (int) Db::table('ffx_categories')->where('content_type', 'media')->where('name', $categoryName)->whereNull('deleted_at')->value('id');
            if ($categoryId > 0 && !in_array($categoryId, $categoryIds, true)) {
                $categoryIds[] = $categoryId;
            }
        }
        if ($primaryCategoryId !== null && !in_array($primaryCategoryId, $categoryIds, true)) {
            array_unshift($categoryIds, $primaryCategoryId);
        }
        $validCategories = $categoryIds === [] ? [] : Db::table('ffx_categories')->whereIn('id', $categoryIds)->where('content_type', 'media')->whereNull('deleted_at')->column('id');
        Db::table('ffx_media_categories')->where('media_id', $mediaId)->delete();
        foreach ($validCategories as $sort => $categoryId) {
            Db::table('ffx_media_categories')->insert([
                'media_id' => $mediaId,
                'category_id' => (int) $categoryId,
                'is_primary' => (int) ((int) $categoryId === $primaryCategoryId),
                'sort_order' => $sort,
            ]);
        }

        Db::table('ffx_media_tags')->where('media_id', $mediaId)->delete();
        $tagNames = preg_split('/[,\x{FF0C}\n]+/u', (string) $this->request->post('tags', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice(array_values(array_unique(array_filter(array_map('trim', $tagNames)))), 0, 30) as $tagName) {
            $tagName = mb_substr($tagName, 0, 100);
            $tagSlug = 'tag-' . substr(hash('sha256', mb_strtolower($tagName)), 0, 32);
            $tag = Db::table('ffx_tags')->where('scope', 'media')->where('slug', $tagSlug)->find();
            $tagId = $tag === null
                ? Db::table('ffx_tags')->insertGetId(['scope' => 'media', 'name' => $tagName, 'slug' => $tagSlug, 'created_at' => gmdate('Y-m-d H:i:s')])
                : (int) $tag['id'];
            Db::table('ffx_media_tags')->insert(['media_id' => $mediaId, 'tag_id' => $tagId]);
        }

        Db::table('ffx_media_people')->where('media_id', $mediaId)->delete();
        $lines = preg_split('/\r?\n/', (string) $this->request->post('credits', ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice($lines, 0, 100) as $sort => $line) {
            $parts = array_map('trim', explode('|', $line, 3));
            $personId = (int) ($parts[0] ?? 0);
            $creditType = mb_substr($parts[1] ?? 'actor', 0, 30);
            $character = mb_substr($parts[2] ?? '', 0, 255);
            if ($personId > 0 && Db::table('ffx_people')->where('id', $personId)->whereNull('deleted_at')->count() > 0) {
                Db::table('ffx_media_people')->insert([
                    'media_id' => $mediaId, 'person_id' => $personId, 'credit_type' => $creditType !== '' ? $creditType : 'actor',
                    'character_name' => $character, 'sort_order' => $sort,
                ]);
            }
        }
    }

    private function creditText(int $mediaId): string
    {
        $rows = Db::table('ffx_media_people')->alias('mp')->join(['ffx_people' => 'p'], 'p.id = mp.person_id')
            ->where('mp.media_id', $mediaId)->order('mp.sort_order')->field('mp.*,p.name')->select()->toArray();
        return implode("\n", array_map(static fn (array $row): string => $row['person_id'] . '|' . $row['credit_type'] . '|' . $row['character_name'], $rows));
    }

    /** @param array<string, mixed> $metadata
     *  @return array<string, mixed>
     */
    private function legacyMeta(array $metadata = []): array
    {
        $defaults = [
            'lines' => '', 'watch' => '', 'ending' => '', 'trysee' => 0,
            'weekday' => '', 'tv' => '', 'length' => 0, 'copyright' => 0, 'source_ref' => '',
            'douban_score' => 0, 'hits_month' => 0, 'hits_week' => 0, 'hits_day' => 0,
            'slide_image' => '', 'version' => '', 'type' => '', 'state' => '正片', 'skin' => '', 'director' => '',
            'actor' => '', 'writer' => '', 'producer' => '', 'camera' => '', 'editor' => '',
            'music' => '', 'art' => '', 'letter' => '', 'inputer' => '', 'series' => '', 'jumpurl' => '',
        ];
        return array_merge($defaults, array_intersect_key($metadata, $defaults));
    }

    /** @return array<int, array<string, mixed>> */
    private function legacyPlaySources(int $mediaId): array
    {
        $sources = Db::table('ffx_play_sources')->where('media_id', $mediaId)->order('sort_order')->order('id')->select()->toArray();
        foreach ($sources as &$source) {
            $episodes = Db::table('ffx_episodes')->where('source_id', (int) $source['id'])->order('sort_order')->order('episode_no')->select()->toArray();
            $source['legacy_urls'] = implode("\n", array_map(static function (array $episode): string {
                $line = (string) $episode['label'] . '$' . (string) $episode['media_url'];
                $metadata = is_string($episode['metadata'] ?? null) ? json_decode((string) $episode['metadata'], true) : ($episode['metadata'] ?? []);
                if (is_array($metadata) && !empty($metadata['legacy_extra'])) {
                    $line .= '$' . implode('$', (array) $metadata['legacy_extra']);
                }
                return $line;
            }, $episodes));
        }
        unset($source);
        return $sources === [] ? [[
            'id' => 0, 'source_key' => 'default', 'display_name' => '默认线路',
            'parser_key' => '', 'status' => 'enabled', 'legacy_urls' => '',
        ]] : $sources;
    }

    private function syncLegacyPlayback(int $mediaId): void
    {
        if ((int) $this->request->post('playback_editor_present', 0) !== 1) {
            return;
        }
        $ids = (array) $this->request->post('play_source_id', []);
        $keys = (array) $this->request->post('play_source_key', []);
        $names = (array) $this->request->post('play_source_name', []);
        $parsers = (array) $this->request->post('play_parser_key', []);
        $urlGroups = (array) $this->request->post('play_urls', []);
        $count = max(count($ids), count($keys), count($names), count($urlGroups));
        $now = gmdate('Y-m-d H:i:s');

        for ($index = 0; $index < $count; $index++) {
            $sourceId = (int) ($ids[$index] ?? 0);
            $key = strtolower(trim((string) ($keys[$index] ?? '')));
            $name = mb_substr(trim((string) ($names[$index] ?? '')), 0, 120);
            $rawUrls = trim((string) ($urlGroups[$index] ?? ''));
            if ($sourceId === 0 && $key === '' && $name === '' && $rawUrls === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,79}$/', $key)) {
                $key = 'line-' . ($index + 1);
            }
            if ($name === '') {
                $name = $key;
            }
            $existing = $sourceId > 0
                ? Db::table('ffx_play_sources')->where('id', $sourceId)->where('media_id', $mediaId)->find()
                : Db::table('ffx_play_sources')->where('media_id', $mediaId)->where('source_key', $key)->find();
            $sourceRow = [
                'source_key' => $key, 'display_name' => $name,
                'parser_key' => mb_substr(trim((string) ($parsers[$index] ?? '')), 0, 80),
                'sort_order' => $index, 'status' => 'enabled', 'updated_at' => $now,
            ];
            if ($existing === null) {
                $sourceId = (int) Db::table('ffx_play_sources')->insertGetId($sourceRow + ['media_id' => $mediaId, 'created_at' => $now]);
            } else {
                $sourceId = (int) $existing['id'];
                Db::table('ffx_play_sources')->where('id', $sourceId)->update($sourceRow);
            }

            Db::table('ffx_episodes')->where('source_id', $sourceId)->delete();
            $lines = preg_split('/\r?\n/', $rawUrls, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_slice($lines, 0, 1000) as $episodeIndex => $line) {
                $parts = array_map('trim', explode('$', $line));
                $label = mb_substr((string) ($parts[0] ?? ''), 0, 120);
                $url = (string) ($parts[1] ?? '');
                if ($url === '') {
                    $url = $label;
                    $label = '第' . ($episodeIndex + 1) . '集';
                }
                if ($url === '') {
                    continue;
                }
                Db::table('ffx_episodes')->insert([
                    'media_id' => $mediaId, 'source_id' => $sourceId, 'season_id' => null,
                    'episode_no' => $episodeIndex + 1, 'label' => $label, 'media_url' => $url,
                    'duration_seconds' => null, 'sort_order' => $episodeIndex, 'status' => 'enabled',
                    'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    'metadata' => count($parts) > 2 ? json_encode(['legacy_extra' => array_slice($parts, 2)], JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
        }
    }
}
