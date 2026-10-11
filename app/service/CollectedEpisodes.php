<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

/** Incremental collection never assigns identity from the response position. */
final class CollectedEpisodes
{
    /** Caller holds the media transaction/identity lock. Missing episodes stay. */
    public function sync(int $mediaId, int $sourceId, array $items, string $now): void
    {
        $old = Db::table('ffx_episodes')->where('source_id', $sourceId)->lock(true)
            ->field('id,episode_no,label,media_url')->order('episode_no')->select()->toArray();
        $byId = array_column($old, null, 'id');
        $exact = $labels = $urls = $occupied = [];
        $maximum = 0;
        foreach ($old as $episode) {
            $id = (int) $episode['id'];
            $exact[$this->identity($episode['label'], $episode['media_url'])][] = $id;
            $labels[$episode['label']][] = $id;
            $urls[$episode['media_url']][] = $id;
            $occupied[(int) $episode['episode_no']] = true;
            $maximum = max($maximum, (int) $episode['episode_no']);
        }
        $matches = $used = $seen = [];
        foreach ($items as $index => $item) {
            $identity = $this->identity($item['label'], $item['url']);
            if (isset($seen[$identity])) { unset($items[$index]); continue; }
            $seen[$identity] = true;
            $match = ($item['label_explicit'] ?? true) ? $this->unique($exact[$identity] ?? [], $used) : null;
            if ($match !== null) { $matches[$index] = $match; $used[$match] = true; }
        }
        $incomingLabels = array_count_values(array_column($items, 'label'));
        // Reserve strong matches before considering changed URLs or names.
        foreach ($items as $index => $item) {
            if (isset($matches[$index])) continue;
            $match = $this->unique($urls[$item['url']] ?? [], $used);
            if ($match !== null) { $matches[$index] = $match; $used[$match] = true; }
        }
        foreach ($items as $index => $item) {
            if (isset($matches[$index])) continue;
            $match = ($item['label_explicit'] ?? true) && ($incomingLabels[$item['label']] ?? 0) === 1 ? $this->unique($labels[$item['label']] ?? [], $used) : null;
            if ($match !== null) { $matches[$index] = $match; $used[$match] = true; }
        }
        foreach ($items as $index => $item) {
            $data = ['label' => mb_substr((string) $item['label'], 0, 120), 'media_url' => (string) $item['url'], 'updated_at' => $now];
            if (isset($matches[$index])) {
                $previous = $byId[$matches[$index]];
                if (!($item['label_explicit'] ?? true)) $data['label'] = $previous['label'];
                if ($previous['label'] !== $data['label'] || $previous['media_url'] !== $data['media_url']) {
                    // Keep episode_no, status, season, duration and all references.
                    Db::table('ffx_episodes')->where('id', $matches[$index])->update($data);
                }
                continue;
            }
            $number = ($item['label_explicit'] ?? true) && preg_match('/^(?:第\s*)?([0-9]+)\s*(?:集|话|期|章|回)?$/u', trim($data['label']), $m) ? (int) $m[1] : 0;
            if ($number < 1 || $number > 4294967295 || isset($occupied[$number])) $number = $maximum + 1;
            if ($number > 4294967295) throw new \RuntimeException('分集编号空间不足，采集未保存');
            $occupied[$number] = true;
            $maximum = max($maximum, $number);
            if (!($item['label_explicit'] ?? true)) $data['label'] = '第' . $number . '集';
            Db::table('ffx_episodes')->insert($data + ['media_id' => $mediaId, 'source_id' => $sourceId,
                'episode_no' => $number, 'sort_order' => $number - 1, 'status' => 'enabled', 'published_at' => $now, 'created_at' => $now]);
        }
    }

    private function identity(string $label, string $url): string
    {
        return hash('sha256', $label . "\0" . $url);
    }

    private function unique(array $ids, array $used): ?int
    {
        $available = array_values(array_filter($ids, static fn (int $id): bool => !isset($used[$id])));
        return count($available) === 1 ? $available[0] : null;
    }
}
