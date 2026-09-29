<?php
declare(strict_types=1);

namespace app\service;

final class EpisodeParser
{
    /**
     * Preserve FeiFeiCMS' historical $$$ / # / $ playback contract.
     * The returned indexes are zero-based because the legacy sid/pid URLs are zero-based.
     *
     * @return array<int, array{index:int,key:string,name:string,episodes:array<int,array{index:int,label:string,url:string}>}>
     */
    public function parse(?string $play, ?string $urls): array
    {
        $sourceKeys = $this->splitSources($play);
        $sourceUrls = $this->splitSources($urls);
        $sourceCount = max(count($sourceKeys), count($sourceUrls));
        $result = [];

        for ($sourceIndex = 0; $sourceIndex < $sourceCount; $sourceIndex++) {
            $rawKey = trim($sourceKeys[$sourceIndex] ?? '');
            $key = $rawKey !== '' ? $rawKey : 'source-' . ($sourceIndex + 1);
            $episodes = [];
            $rawEpisodes = preg_split('/[#\r\n]+/', (string) ($sourceUrls[$sourceIndex] ?? '')) ?: [];

            foreach ($rawEpisodes as $episodeIndex => $rawEpisode) {
                $rawEpisode = trim($rawEpisode);
                if ($rawEpisode === '') {
                    continue;
                }

                [$label, $url] = $this->splitEpisode($rawEpisode, $episodeIndex);
                if ($url === '') {
                    continue;
                }

                $episodes[] = [
                    'index' => $episodeIndex,
                    'label' => $label,
                    'url' => $url,
                ];
            }

            if ($episodes !== []) {
                $result[] = [
                    'index' => $sourceIndex,
                    'key' => $key,
                    'name' => $rawKey !== '' ? $rawKey : '线路 ' . ($sourceIndex + 1),
                    'episodes' => $episodes,
                ];
            }
        }

        return $result;
    }

    /** @return array<int, string> */
    private function splitSources(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return explode('$$$', $value);
    }

    /** @return array{0:string,1:string} */
    private function splitEpisode(string $rawEpisode, int $episodeIndex): array
    {
        $parts = explode('$', $rawEpisode, 2);
        if (count($parts) === 1) {
            return ['第' . ($episodeIndex + 1) . '集', trim($parts[0])];
        }

        $label = trim($parts[0]);
        $url = trim($parts[1]);

        return [$label !== '' ? $label : '第' . ($episodeIndex + 1) . '集', $url];
    }
}
