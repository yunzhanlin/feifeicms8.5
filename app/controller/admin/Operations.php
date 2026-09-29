<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Operations extends BaseController
{
    private const DEFINITIONS = [
        'players' => ['table' => 'ffx_players', 'label' => '播放器', 'title' => 'name'],
        'slides' => ['table' => 'ffx_slides', 'label' => '轮播图', 'title' => 'name'],
        'links' => ['table' => 'ffx_links', 'label' => '友情链接', 'title' => 'name'],
        'navigation' => ['table' => 'ffx_navigation', 'label' => '导航', 'title' => 'title'],
        'ads' => ['table' => 'ffx_ads', 'label' => '广告位', 'title' => 'name'],
    ];

    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit)
    {
        parent::__construct($app);
    }

    public function index(string $type): Response
    {
        $def = $this->definition($type);
        $items = Db::table($def['table'])->order($type === 'ads' ? 'id' : 'sort_order')->order('id')->select()->toArray();
        if ($type === 'navigation') {
            $titles = array_column($items, 'title', 'id');
            foreach ($items as &$item) $item['parent_title'] = !empty($item['parent_id']) ? ($titles[$item['parent_id']] ?? ('#' . $item['parent_id'])) : '顶级导航';
            unset($item);
        }
        return view('/admin/operations/index', ['type' => $type, 'label' => $def['label'], 'titleField' => $def['title'], 'items' => $items, 'csrf' => $this->csrf->get()]);
    }

    public function create(string $type): Response
    {
        return $this->form($type, null);
    }

    public function edit(string $type, int $id): Response
    {
        $def = $this->definition($type);
        return $this->form($type, $this->requireItem($def['table'], $id));
    }

    public function store(string $type): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $data = $this->payload($type);
        if (trim((string) ($data[$def['title']] ?? '')) === '') {
            return response($def['label'] . '名称不能为空', 422);
        }
        if (in_array($type, ['players', 'ads'], true)) {
            $data['created_at'] = gmdate('Y-m-d H:i:s');
            $data['updated_at'] = $data['created_at'];
        }
        $id = Db::table($def['table'])->insertGetId($data);
        $this->audit->record('operations.create', $type, $id, null, $data);
        return redirect('/admin/operations/' . $type . '/' . $id . '/edit');
    }

    public function update(string $type, int $id): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $before = $this->requireItem($def['table'], $id);
        $data = $this->payload($type);
        if (trim((string) ($data[$def['title']] ?? '')) === '') {
            return response($def['label'] . '名称不能为空', 422);
        }
        if ($type === 'navigation' && ((int) ($data['parent_id'] ?? 0) === $id || $this->navigationDescendant($id, (int) ($data['parent_id'] ?? 0)))) {
            return response('导航不能移到自己或子导航下', 422);
        }
        if (in_array($type, ['players', 'ads'], true)) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        Db::table($def['table'])->where('id', $id)->update($data);
        $this->audit->record('operations.update', $type, $id, $before, $data);
        return redirect('/admin/operations/' . $type . '/' . $id . '/edit');
    }

    public function delete(string $type, int $id): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $before = $this->requireItem($def['table'], $id);
        Db::table($def['table'])->where('id', $id)->delete();
        $this->audit->record('operations.delete', $type, $id, $before, null);
        return redirect('/admin/operations/' . $type);
    }

    public function batch(string $type): Response
    {
        $this->guardCsrf();
        $def = $this->definition($type);
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->request->post('ids', [])), static fn (int $id): bool => $id > 0)));
        $action = (string) $this->request->post('action', 'save_sort');
        if (!in_array($action, ['save_sort', 'enable', 'disable', 'delete'], true)) return response('批量操作无效', 422);
        $sort = (array) $this->request->post('sort', []);
        if ($action === 'save_sort') {
            foreach ($sort as $id => $order) {
                if ((int) $id > 0 && $type !== 'ads') Db::table($def['table'])->where('id', (int) $id)->update(['sort_order' => (int) $order]);
            }
            $this->audit->record('operations.sort', $type, '', null, ['sort' => $sort]);
            return redirect('/admin/operations/' . $type);
        }
        if ($ids === []) return response('请选择记录', 422);
        $ids = array_slice($ids, 0, 500);
        $before = Db::table($def['table'])->whereIn('id', $ids)->select()->toArray();
        if ($action === 'delete') {
            if ($type === 'navigation') Db::table('ffx_navigation')->whereIn('parent_id', $ids)->update(['parent_id' => null]);
            Db::table($def['table'])->whereIn('id', $ids)->delete();
            $after = null;
        } else {
            $after = ['status' => $action === 'enable' ? 'enabled' : 'disabled'];
            if (in_array($type, ['players', 'ads'], true)) $after['updated_at'] = gmdate('Y-m-d H:i:s');
            Db::table($def['table'])->whereIn('id', $ids)->update($after);
        }
        $this->audit->record('operations.batch.' . $action, $type, implode(',', $ids), $before, $after);
        return redirect('/admin/operations/' . $type);
    }

    private function form(string $type, ?array $item): Response
    {
        $def = $this->definition($type);
        $defaults = match ($type) {
            'players' => ['id' => 0, 'player_key' => '', 'name' => '', 'parser_url' => '', 'config' => '{}', 'sort_order' => 0, 'status' => 'enabled'],
            'slides' => ['id' => 0, 'name' => '', 'image_url' => '', 'mobile_image_url' => '', 'target_url' => '', 'description' => '', 'sort_order' => 0, 'status' => 'enabled', 'starts_at' => '', 'ends_at' => ''],
            'links' => ['id' => 0, 'name' => '', 'url' => '', 'logo_url' => '', 'link_type' => 'text', 'sort_order' => 0, 'status' => 'enabled'],
            'navigation' => ['id' => 0, 'parent_id' => null, 'title' => '', 'url' => '', 'target' => '_self', 'sort_order' => 0, 'status' => 'enabled'],
            'ads' => ['id' => 0, 'slot_key' => '', 'name' => '', 'content' => '', 'status' => 'disabled', 'starts_at' => '', 'ends_at' => ''],
        };
        if ($item !== null && $type === 'players' && is_string($item['config'] ?? null)) {
            $item['config'] = json_encode(json_decode((string) $item['config'], true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        }
        return view('/admin/operations/edit', [
            'type' => $type, 'label' => $def['label'], 'item' => $item ?? $defaults,
            'parents' => $type === 'navigation' ? Db::table('ffx_navigation')->order('sort_order')->select()->toArray() : [],
            'csrf' => $this->csrf->get(), 'isNew' => $item === null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $type): array
    {
        $enabled = $this->request->post('status', 'disabled') === 'enabled' ? 'enabled' : 'disabled';
        return match ($type) {
            'players' => [
                'player_key' => mb_substr(strtolower(trim((string) $this->request->post('player_key', ''))), 0, 80),
                'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 120),
                'parser_url' => mb_substr(trim((string) $this->request->post('parser_url', '')), 0, 1000),
                'config' => $this->jsonField('config'), 'sort_order' => (int) $this->request->post('sort_order', 0), 'status' => $enabled,
            ],
            'slides' => [
                'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 255), 'image_url' => mb_substr(trim((string) $this->request->post('image_url', '')), 0, 1000),
                'mobile_image_url' => mb_substr(trim((string) $this->request->post('mobile_image_url', '')), 0, 1000), 'target_url' => mb_substr(trim((string) $this->request->post('target_url', '')), 0, 1000),
                'description' => mb_substr(trim((string) $this->request->post('description', '')), 0, 500), 'sort_order' => (int) $this->request->post('sort_order', 0),
                'status' => $enabled, 'starts_at' => $this->nullable('starts_at'), 'ends_at' => $this->nullable('ends_at'),
            ],
            'links' => [
                'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 255), 'url' => mb_substr(trim((string) $this->request->post('url', '')), 0, 1000),
                'logo_url' => mb_substr(trim((string) $this->request->post('logo_url', '')), 0, 1000), 'link_type' => $this->request->post('link_type', 'text') === 'image' ? 'image' : 'text',
                'sort_order' => (int) $this->request->post('sort_order', 0), 'status' => $enabled,
            ],
            'navigation' => [
                'parent_id' => (($parent = (int) $this->request->post('parent_id', 0)) > 0) ? $parent : null,
                'title' => mb_substr(trim((string) $this->request->post('title', '')), 0, 100), 'url' => mb_substr(trim((string) $this->request->post('url', '')), 0, 1000),
                'target' => $this->request->post('target', '_self') === '_blank' ? '_blank' : '_self', 'sort_order' => (int) $this->request->post('sort_order', 0), 'status' => $enabled,
            ],
            'ads' => [
                'slot_key' => mb_substr(strtolower(trim((string) $this->request->post('slot_key', ''))), 0, 100),
                'name' => mb_substr(trim((string) $this->request->post('name', '')), 0, 255), 'content' => (string) $this->request->post('content', ''),
                'status' => $enabled, 'starts_at' => $this->nullable('starts_at'), 'ends_at' => $this->nullable('ends_at'),
            ],
        };
    }

    private function jsonField(string $name): ?string
    {
        $text = trim((string) $this->request->post($name, ''));
        if ($text === '') {
            return null;
        }
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            throw new HttpException(422, $name . ' 必须是合法 JSON');
        }
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullable(string $name): ?string
    {
        $value = trim((string) $this->request->post($name, ''));
        return $value === '' ? null : str_replace('T', ' ', $value);
    }

    private function definition(string $type): array
    {
        if (!isset(self::DEFINITIONS[$type])) {
            throw new HttpException(404, '运营模块不存在');
        }
        return self::DEFINITIONS[$type];
    }

    private function requireItem(string $table, int $id): array
    {
        $row = Db::table($table)->where('id', $id)->find();
        if ($row === null) {
            throw new HttpException(404, '记录不存在');
        }
        return $row;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }

    private function navigationDescendant(int $id, int $parentId): bool
    {
        $seen = [];
        while ($parentId > 0 && !isset($seen[$parentId])) {
            if ($parentId === $id) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = (int) Db::table('ffx_navigation')->where('id', $parentId)->value('parent_id');
        }
        return false;
    }
}
