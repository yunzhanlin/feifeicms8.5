<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\model\Media;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\PlaybackPlayerRegistry;
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
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/D', $key)) {
            return response('线路标识需为 1-80 位小写字母、数字、点、横线或下划线', 422);
        }
        $parserKey = PlaybackPlayerRegistry::sourceKey(['source_key' => $key, 'parser_key' => $this->request->post('parser_key', '')]);
        if (!in_array($parserKey, PlaybackPlayerRegistry::enabledKeys(), true)) return response('请先在后台添加并启用对应播放器', 422);
        $row = [
            'media_id' => $id,
            'source_key' => PlaybackPlayerRegistry::uniqueLineKey($id, $key),
            'display_name' => mb_substr(trim((string) $this->request->post('display_name', '')), 0, 120),
            'parser_key' => $parserKey,
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
        $parserKey = PlaybackPlayerRegistry::sourceKey(['source_key' => $source['source_key'], 'parser_key' => $this->request->post('parser_key', '')]);
        $status = $this->request->post('status', 'disabled') === 'enabled' ? 'enabled' : 'disabled';
        if ($status === 'enabled' && !in_array($parserKey, PlaybackPlayerRegistry::enabledKeys(), true)) return response('请先在后台添加并启用对应播放器', 422);
        $row = [
            'display_name' => mb_substr(trim((string) $this->request->post('display_name', '')), 0, 120),
            'parser_key' => $parserKey,
            'sort_order' => (int) $this->request->post('sort_order', 0),
            'status' => $status,
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
        return $this->saveEpisode($id, $sourceId);
    }

    public function updateEpisode(int $id, int $sourceId, int $episodeId): Response
    {
        $this->guardCsrf();
        return $this->saveEpisode($id, $sourceId, $episodeId);
    }

    private function saveEpisode(int $id, int $sourceId, ?int $episodeId = null): Response
    {
        $row = $this->episodePayload($id, $sourceId);
        if ($row['label'] === '' || $row['media_url'] === '') return response('剧集名称和播放地址不能为空', 422);
        if ($row['episode_no'] > 4294967295) return response('剧集编号超出范围', 422);

        return Db::transaction(function () use ($id, $sourceId, $episodeId, $row): Response {
            $this->requireSource($id, $sourceId, true);
            $before = $episodeId === null ? null : $this->requireEpisode($id, $sourceId, $episodeId);
            $occupied = Db::table('ffx_episodes')->where('source_id', $sourceId)->where('episode_no', $row['episode_no'])->value('id');
            if ($occupied !== null && (int) $occupied !== $episodeId) return response('该线路已存在相同集号', 422);

            $row['updated_at'] = gmdate('Y-m-d H:i:s');
            if ($episodeId === null) {
                $row['created_at'] = $row['updated_at'];
                $episodeId = Db::table('ffx_episodes')->insertGetId($row);
            } else {
                if ($row['status'] === 'enabled' && !empty($before['published_at'])) $row['published_at'] = $before['published_at'];
                Db::table('ffx_episodes')->where('id', $episodeId)->update($row);
            }
            $this->audit->record($before === null ? 'episode.create' : 'episode.update', 'episode', (int) $episodeId, $before, $row);
            return redirect('/admin/vod/' . $id . '/playback');
        });
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

    private function requireSource(int $mediaId, int $sourceId, bool $lock = false): array
    {
        $row = Db::table('ffx_play_sources')->where('id', $sourceId)->where('media_id', $mediaId)->lock($lock)->find();
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
