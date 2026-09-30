<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class WatchHistoryService
{
    /** @return list<array{record_did:int,record_did_sid:int,record_did_pid:int,record_time:int,record_name:string,record_url:string}> */
    public function records(int $userId, int $limit = 50): array
    {
        $history = Db::table('ffx_watch_history')->where('user_id', $userId)
            ->order('watched_at', 'desc')->limit(max(1, min(200, $limit)))->select()->toArray();
        if ($history === []) return [];

        $mediaIds = $this->uniqueIds(array_column($history, 'media_id'));
        $episodeIds = $this->uniqueIds(array_column($history, 'episode_id'));
        $mediaTitles = $mediaIds === [] ? [] : Db::table('ffx_media')->whereIn('id', $mediaIds)->column('title', 'id');

        $episodesById = [];
        if ($episodeIds !== []) {
            foreach (Db::table('ffx_episodes')->whereIn('id', $episodeIds)->field('id,source_id')->select()->toArray() as $episode) {
                $episodesById[(int) $episode['id']] = $episode;
            }
        }

        $sourcePositions = [];
        if ($mediaIds !== []) {
            $sourceRows = Db::table('ffx_play_sources')->whereIn('media_id', $mediaIds)->where('status', 'enabled')
                ->order('media_id')->order('sort_order')->order('id')->field('id,media_id')->select()->toArray();
            foreach ($sourceRows as $source) {
                $mediaId = (int) $source['media_id'];
                $sourcePositions[$mediaId][(int) $source['id']] = count($sourcePositions[$mediaId] ?? []);
            }
        }

        $episodePositions = [];
        $sourceIds = $this->uniqueIds(array_column($episodesById, 'source_id'));
        if ($sourceIds !== []) {
            $episodeRows = Db::table('ffx_episodes')->whereIn('source_id', $sourceIds)->where('status', 'enabled')
                ->order('source_id')->order('sort_order')->order('id')->field('id,source_id')->select()->toArray();
            foreach ($episodeRows as $episode) {
                $sourceId = (int) $episode['source_id'];
                $episodePositions[$sourceId][(int) $episode['id']] = count($episodePositions[$sourceId] ?? []);
            }
        }

        $records = [];
        foreach ($history as $record) {
            $mediaId = (int) $record['media_id'];
            $episodeId = (int) ($record['episode_id'] ?? 0);
            $episode = $episodesById[$episodeId] ?? null;
            $sourceId = (int) ($episode['source_id'] ?? 0);
            $sourceIndex = (int) ($sourcePositions[$mediaId][$sourceId] ?? 0);
            $episodeIndex = (int) ($episodePositions[$sourceId][$episodeId] ?? 0);
            $records[] = [
                'record_did' => $mediaId,
                'record_did_sid' => $sourceIndex,
                'record_did_pid' => $episodeIndex,
                'record_time' => strtotime((string) $record['watched_at']) ?: 0,
                'record_name' => (string) ($mediaTitles[$mediaId] ?? ('内容 ID：' . $mediaId)),
                'record_url' => ff_play_url($mediaId, $sourceIndex, $episodeIndex),
            ];
        }
        return $records;
    }

    /** @param array<int, mixed> $values @return list<int> */
    private function uniqueIds(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn (int $id): bool => $id > 0)));
    }
}
