<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\Media;
use app\service\AuditLogger;
use app\service\CsrfToken;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Playback extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit)
    {
        parent::__construct($app);
    }

    public function index(int $id): Response
    {
        $media = Media::where('id', $id)->whereNull('deleted_at')->find();
        if ($media === null) {
            throw new HttpException(404, '影片不存在');
        }
        $sources = Db::table('ffx_play_sources')->where('media_id', $id)->order('sort_order')->order('id')->select()->toArray();
        $episodes = Db::table('ffx_episodes')->where('media_id', $id)->order('source_id')->order('sort_order')->order('episode_no')->select()->toArray();
        $grouped = [];
        foreach ($episodes as $episode) {
            $grouped[(int) $episode['source_id']][] = $episode;
        }
        foreach ($sources as &$source) {
            $source['episodes'] = $grouped[(int) $source['id']] ?? [];
        }
        unset($source);

        return view('/admin/vod/playback', [
            'media' => $media->toArray(),
            'sources' => $sources,
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function storeSource(int $id): Response
    {
        $this->guardCsrf();
        $this->requireMedia($id);
        $key = strtolower(trim((string) $this->request->post('source_key', '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{1,79}$/', $key)) {
            return response('线路标识需为 2-80 位小写字母、数字、点、横线或下划线', 422);
        }
        $row = [
            'media_id' => $id,
            'source_key' => $key,
            'display_name' => mb_substr(trim((string) $this->request->post('display_name', '')), 0, 120),
            'parser_key' => mb_substr(trim((string) $this->request->post('parser_key', '')), 0, 80),
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $this->request->post('status', 'enabled') === 'enabled' ? 'enabled' : 'disabled',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($row['display_name'] === '') {
            $row['display_name'] = $key;
        }
        $sourceId = Db::table('ffx_play_sources')->insertGetId($row);
        $this->audit->record('play_source.create', 'play_source', $sourceId, null, $row);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    public function updateSource(int $id, int $sourceId): Response
    {
        $this->guardCsrf();
        $source = $this->requireSource($id, $sourceId);
        $row = [
            'display_name' => mb_substr(trim((string) $this->request->post('display_name', '')), 0, 120),
            'parser_key' => mb_substr(trim((string) $this->request->post('parser_key', '')), 0, 80),
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $this->request->post('status', 'disabled') === 'enabled' ? 'enabled' : 'disabled',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        Db::table('ffx_play_sources')->where('id', $sourceId)->update($row);
        $this->audit->record('play_source.update', 'play_source', $sourceId, $source, $row);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    public function deleteSource(int $id, int $sourceId): Response
    {
        $this->guardCsrf();
        $source = $this->requireSource($id, $sourceId);
        Db::table('ffx_play_sources')->where('id', $sourceId)->delete();
        $this->audit->record('play_source.delete', 'play_source', $sourceId, $source, null);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    public function storeEpisode(int $id, int $sourceId): Response
    {
        $this->guardCsrf();
        $this->requireSource($id, $sourceId);
        $row = $this->episodePayload($id, $sourceId);
        if ($row['label'] === '' || $row['media_url'] === '') {
            return response('剧集名称和播放地址不能为空', 422);
        }
        $row['created_at'] = gmdate('Y-m-d H:i:s');
        $row['updated_at'] = $row['created_at'];
        $episodeId = Db::table('ffx_episodes')->insertGetId($row);
        $this->audit->record('episode.create', 'episode', $episodeId, null, $row);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    public function updateEpisode(int $id, int $sourceId, int $episodeId): Response
    {
        $this->guardCsrf();
        $this->requireSource($id, $sourceId);
        $before = $this->requireEpisode($id, $sourceId, $episodeId);
        $row = $this->episodePayload($id, $sourceId);
        if ($row['label'] === '' || $row['media_url'] === '') {
            return response('剧集名称和播放地址不能为空', 422);
        }
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        Db::table('ffx_episodes')->where('id', $episodeId)->update($row);
        $this->audit->record('episode.update', 'episode', $episodeId, $before, $row);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    public function deleteEpisode(int $id, int $sourceId, int $episodeId): Response
    {
        $this->guardCsrf();
        $before = $this->requireEpisode($id, $sourceId, $episodeId);
        Db::table('ffx_episodes')->where('id', $episodeId)->delete();
        $this->audit->record('episode.delete', 'episode', $episodeId, $before, null);
        return redirect('/admin/vod/' . $id . '/playback');
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) {
            throw new HttpException(419, '请求已过期');
        }
    }

    private function requireMedia(int $id): array
    {
        $row = Db::table('ffx_media')->where('id', $id)->whereNull('deleted_at')->find();
        if ($row === null) {
            throw new HttpException(404, '影片不存在');
        }
        return $row;
    }

    private function requireSource(int $mediaId, int $sourceId): array
    {
        $row = Db::table('ffx_play_sources')->where('id', $sourceId)->where('media_id', $mediaId)->find();
        if ($row === null) {
            throw new HttpException(404, '线路不存在');
        }
        return $row;
    }

    private function requireEpisode(int $mediaId, int $sourceId, int $episodeId): array
    {
        $row = Db::table('ffx_episodes')->where('id', $episodeId)->where('media_id', $mediaId)->where('source_id', $sourceId)->find();
        if ($row === null) {
            throw new HttpException(404, '剧集不存在');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function episodePayload(int $mediaId, int $sourceId): array
    {
        return [
            'media_id' => $mediaId,
            'source_id' => $sourceId,
            'episode_no' => max(1, (int) $this->request->post('episode_no', 1)),
            'label' => mb_substr(trim((string) $this->request->post('label', '')), 0, 120),
            'media_url' => trim((string) $this->request->post('media_url', '')),
            'duration_seconds' => (($duration = (int) $this->request->post('duration_seconds', 0)) > 0) ? $duration : null,
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $this->request->post('status', 'disabled') === 'enabled' ? 'enabled' : 'disabled',
            'published_at' => $this->request->post('status', 'disabled') === 'enabled' ? gmdate('Y-m-d H:i:s') : null,
        ];
    }
}
