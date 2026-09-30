<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\FrontendCache;
use app\service\Slugger;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Categories extends BaseController
{
    private const TYPES = ['media', 'article', 'topic', 'person', 'comment', 'page'];

    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly Slugger $slugger, private readonly FrontendCache $frontendCache)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $rows = Db::table('ffx_categories')->whereNull('deleted_at')->order('sort_order')->order('id')->select()->toArray();
        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        foreach ($rows as &$row) {
            $row['parent_name'] = $names[(int) ($row['parent_id'] ?? 0)] ?? '顶级';
        }
        unset($row);
        return view('/admin/categories/index', ['items' => $rows, 'csrf' => $this->csrf->get()]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        $row = $this->requireCategory($id);
        return $this->form($row);
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $data = $this->payload();
        if ($data['name'] === '') {
            return response('分类名称不能为空', 422);
        }
        $this->validateParentType((int) ($data['parent_id'] ?? 0), (string) $data['content_type']);
        $data['slug'] = $this->slugger->make((string) $this->request->post('slug', ''), 'category');
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $id = Db::table('ffx_categories')->insertGetId($data);
        $this->audit->record('category.create', 'category', $id, null, $data);
        $this->frontendCache->invalidateCategories();
        return redirect('/admin/categories/' . $id . '/edit');
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireCategory($id);
        $data = $this->payload();
        if ($data['name'] === '') {
            return response('分类名称不能为空', 422);
        }
        if ((int) ($data['parent_id'] ?? 0) === $id || $this->isDescendant($id, (int) ($data['parent_id'] ?? 0))) {
            return response('不能把分类移动到自己或自己的子分类下面', 422);
        }
        $this->validateParentType((int) ($data['parent_id'] ?? 0), (string) $data['content_type']);
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_categories')->where('id', $id)->update($data);
        $this->audit->record('category.update', 'category', $id, $before, $data);
        $this->frontendCache->invalidateCategories();
        return redirect('/admin/categories/' . $id . '/edit');
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $before = $this->requireCategory($id);
        if (Db::table('ffx_categories')->where('parent_id', $id)->whereNull('deleted_at')->count() > 0) {
            return response('请先处理子分类', 422);
        }
        $used = Db::table('ffx_media')->where('category_id', $id)->whereNull('deleted_at')->count()
            + Db::table('ffx_articles')->where('category_id', $id)->whereNull('deleted_at')->count()
            + Db::table('ffx_topics')->where('category_id', $id)->whereNull('deleted_at')->count();
        if ($used > 0) {
            return response('该分类仍有关联内容，请先转移内容', 422);
        }
        $data = ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        Db::table('ffx_categories')->where('id', $id)->update($data);
        $this->audit->record('category.archive', 'category', $id, $before, $data);
        $this->frontendCache->invalidateCategories();
        $this->frontendCache->invalidateCategories();
        return redirect('/admin/categories');
    }

    public function batch(): Response
    {
        $this->guardCsrf();
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', []))))), 0, 200);
        $action = (string) $this->request->post('action', 'update');
        if ($ids === []) {
            return response('请选择分类', 422);
        }
        if ($action === 'update') {
            $names = (array) $this->request->post('names', []);
            $sortOrders = (array) $this->request->post('sort_orders', []);
            $statuses = (array) $this->request->post('statuses', []);
            foreach ($ids as $id) {
                $before = $this->requireCategory($id);
                $name = mb_substr(trim((string) ($names[$id] ?? $before['name'])), 0, 100);
                $data = ['name' => $name !== '' ? $name : $before['name'], 'sort_order' => (int) ($sortOrders[$id] ?? $before['sort_order']), 'status' => ($statuses[$id] ?? '') === 'published' ? 'published' : 'draft', 'updated_at' => gmdate('Y-m-d H:i:s')];
                Db::table('ffx_categories')->where('id', $id)->update($data);
                $this->audit->record('category.batch_update', 'category', $id, $before, $data);
            }
        } elseif ($action === 'delete') {
            foreach ($ids as $id) {
                $before = $this->requireCategory($id);
                $used = Db::table('ffx_categories')->where('parent_id', $id)->whereNull('deleted_at')->count()
                    + Db::table('ffx_media')->where('category_id', $id)->whereNull('deleted_at')->count()
                    + Db::table('ffx_articles')->where('category_id', $id)->whereNull('deleted_at')->count()
                    + Db::table('ffx_topics')->where('category_id', $id)->whereNull('deleted_at')->count();
                if ($used > 0) continue;
                $data = ['status' => 'archived', 'deleted_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
                Db::table('ffx_categories')->where('id', $id)->update($data);
                $this->audit->record('category.batch_archive', 'category', $id, $before, $data);
            }
        }
        return redirect('/admin/categories');
    }

    private function form(?array $item): Response
    {
        return view('/admin/categories/edit', [
            'item' => $item ?? ['id' => 0, 'name' => '', 'slug' => '', 'parent_id' => max(0, (int) $this->request->param('parent_id', 0)) ?: null, 'content_type' => 'media', 'description' => '', 'filter_options' => '', 'sort_order' => 0, 'status' => 'published'],
            'parents' => Db::table('ffx_categories')->whereNull('deleted_at')->order('sort_order')->select()->toArray(),
            'types' => self::TYPES,
            'csrf' => $this->csrf->get(),
            'isNew' => $item === null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $type = (string) $this->request->post('content_type', 'media');
        $rawFilters = trim((string) $this->request->post('filter_options', ''));
        $filters = $rawFilters === '' ? null : json_decode($rawFilters, true);
        if ($rawFilters !== '' && !is_array($filters)) {
            throw new HttpException(422, '筛选项必须是合法 JSON 对象');
        }
        return [
            'parent_id' => (($parent = (int) $this->request->post('parent_id', 0)) > 0) ? $parent : null,
            'content_type' => in_array($type, self::TYPES, true) ? $type : 'media',
            'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 100),
            'description' => mb_substr(trim((string) $this->request->post('description', '')), 0, 500),
            'seo_title' => mb_substr(trim((string) $this->request->post('seo_title', '')), 0, 255),
            'seo_keywords' => mb_substr(trim((string) $this->request->post('seo_keywords', '')), 0, 500),
            'filter_options' => $filters === null ? null : json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $this->request->post('status', 'draft') === 'published' ? 'published' : 'draft',
        ];
    }

    private function requireCategory(int $id): array
    {
        $row = Db::table('ffx_categories')->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '分类不存在');
        }
        if (is_string($row['filter_options'] ?? null)) {
            $row['filter_options'] = json_encode(json_decode((string) $row['filter_options'], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return $row;
    }

    private function isDescendant(int $id, int $parentId): bool
    {
        $seen = [];
        while ($parentId > 0 && !isset($seen[$parentId])) {
            if ($parentId === $id) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = (int) Db::table('ffx_categories')->where('id', $parentId)->value('parent_id');
        }
        return false;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }

    private function validateParentType(int $parentId, string $contentType): void
    {
        if ($parentId < 1) {
            return;
        }
        $parent = Db::table('ffx_categories')->where('id', $parentId)->whereNull('deleted_at')->find();
        if ($parent === null || (string) $parent['content_type'] !== $contentType) {
            throw new HttpException(422, '上级分类必须存在且内容类型一致');
        }
    }
}
