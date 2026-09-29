<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Tags extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $scope = (string) $this->request->param('scope', '');
        $keyword = mb_substr(trim((string) $this->request->param('wd', '')), 0, 80);
        $query = Db::table('ffx_tags')->order('id', 'desc');
        if (in_array($scope, ['media', 'article'], true)) {
            $query->where('scope', $scope);
        } else {
            $scope = '';
        }
        if ($keyword !== '') {
            $query->whereLike('name', '%' . $keyword . '%');
        }
        $items = $query->limit(500)->select()->toArray();
        foreach ($items as &$item) {
            $item['usage_count'] = $item['scope'] === 'article'
                ? Db::table('ffx_article_tags')->where('tag_id', (int) $item['id'])->count()
                : Db::table('ffx_media_tags')->where('tag_id', (int) $item['id'])->count();
        }
        unset($item);
        return view('/admin/tags/index', ['items' => $items, 'scope' => $scope, 'keyword' => $keyword, 'csrf' => $this->csrf->get()]);
    }

    public function store(): Response
    {
        $this->guardCsrf();
        $name = mb_substr(trim((string) $this->request->post('name', '')), 0, 100);
        $scope = (string) $this->request->post('scope', 'media');
        if ($name === '' || !in_array($scope, ['media', 'article'], true)) {
            return response('标签名称和类型不正确', 422);
        }
        $slug = 'tag-' . substr(hash('sha256', mb_strtolower($name)), 0, 32);
        $existing = Db::table('ffx_tags')->where('scope', $scope)->where('slug', $slug)->find();
        if ($existing === null) {
            $id = Db::table('ffx_tags')->insertGetId(['scope' => $scope, 'name' => $name, 'slug' => $slug, 'created_at' => gmdate('Y-m-d H:i:s')]);
            $this->audit->record('tag.create', 'tag', $id, null, ['scope' => $scope, 'name' => $name]);
        }
        return redirect('/admin/tags?scope=' . $scope);
    }

    public function delete(int $id): Response
    {
        $this->guardCsrf();
        $tag = Db::table('ffx_tags')->where('id', $id)->find();
        if ($tag === null) {
            throw new HttpException(404, '标签不存在');
        }
        Db::transaction(function () use ($id): void {
            Db::table('ffx_media_tags')->where('tag_id', $id)->delete();
            Db::table('ffx_article_tags')->where('tag_id', $id)->delete();
            Db::table('ffx_tags')->where('id', $id)->delete();
        });
        $this->audit->record('tag.delete', 'tag', $id, $tag, null);
        return redirect('/admin/tags');
    }

    public function update(int $id): Response
    {
        $this->guardCsrf();
        $tag = $this->requireTag($id);
        $name = mb_substr(trim((string) $this->request->post('name', '')), 0, 100);
        if ($name === '') return response('标签名称不能为空', 422);
        $slug = 'tag-' . substr(hash('sha256', mb_strtolower($name)), 0, 32);
        $duplicate = Db::table('ffx_tags')->where('scope', (string) $tag['scope'])->where('slug', $slug)->where('id', '<>', $id)->find();
        if ($duplicate !== null) return response('同类型下已存在该标签，请使用合并功能', 422);
        Db::table('ffx_tags')->where('id', $id)->update(['name' => $name, 'slug' => $slug]);
        $this->audit->record('tag.update', 'tag', $id, $tag, ['name' => $name, 'slug' => $slug]);
        return redirect('/admin/tags?scope=' . rawurlencode((string) $tag['scope']));
    }

    public function merge(): Response
    {
        $this->guardCsrf();
        $sourceId = (int) $this->request->post('source_id', 0);
        $targetId = (int) $this->request->post('target_id', 0);
        if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) return response('来源标签和目标标签无效', 422);
        $source = $this->requireTag($sourceId);
        $target = $this->requireTag($targetId);
        if ($source['scope'] !== $target['scope']) return response('只能合并相同类型的标签', 422);
        $relation = $source['scope'] === 'article' ? ['ffx_article_tags', 'article_id'] : ['ffx_media_tags', 'media_id'];
        Db::transaction(function () use ($relation, $sourceId, $targetId): void {
            foreach (Db::table($relation[0])->where('tag_id', $sourceId)->column($relation[1]) as $entityId) {
                if (Db::table($relation[0])->where($relation[1], $entityId)->where('tag_id', $targetId)->count() === 0) {
                    Db::table($relation[0])->insert([$relation[1] => $entityId, 'tag_id' => $targetId]);
                }
            }
            Db::table($relation[0])->where('tag_id', $sourceId)->delete();
            Db::table('ffx_tags')->where('id', $sourceId)->delete();
        });
        $this->audit->record('tag.merge', 'tag', $sourceId, $source, ['target_id' => $targetId, 'target' => $target]);
        return redirect('/admin/tags?scope=' . rawurlencode((string) $target['scope']));
    }

    private function requireTag(int $id): array
    {
        $tag = Db::table('ffx_tags')->where('id', $id)->find();
        if ($tag === null) throw new HttpException(404, '标签不存在');
        return $tag;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }
}
