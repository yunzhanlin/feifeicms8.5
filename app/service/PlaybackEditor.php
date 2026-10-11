<?php
declare(strict_types=1);

namespace app\service;

use think\exception\HttpException;
use think\facade\Db;

/** Text editor adapter. An ordinary save never recreates existing episodes. */
final class PlaybackEditor
{
    public const MAX_EPISODES = 1000;

    /** @return list<array{label:string,media_url:string}> */
    public static function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($lines) > self::MAX_EPISODES) throw new HttpException(422, '每条线路最多 1000 集；未保存，请拆分线路');
        $items = [];
        foreach ($lines as $line) {
            // Only the first dollar separates a label from a URL. Dollar signs
            // in signed URLs are data, not undocumented extra-field delimiters.
            $parts = explode('$', trim($line), 2);
            $label = count($parts) === 2 ? trim($parts[0]) : '第' . (count($items) + 1) . '集';
            $url = trim(count($parts) === 2 ? $parts[1] : $parts[0]);
            if ($url === '' || strlen($url) > 8192 || mb_strlen($label) > 120) throw new HttpException(422, '分集名称或播放地址无效；未保存');
            $items[] = ['label' => $label, 'media_url' => $url];
        }
        return $items;
    }

    /** Caller holds a transaction and locks the source row. */
    public function sync(int $mediaId, int $sourceId, array $items, bool $deleteMissing = false): void
    {
        if (count($items) > self::MAX_EPISODES) throw new HttpException(422, '分集数量超出限制，未保存');
        $old = Db::table('ffx_episodes')->where('source_id', $sourceId)->order('sort_order')->order('episode_no')->lock(true)->select()->toArray();
        if ($items === array_map(static fn (array $row): array => ['label' => $row['label'], 'media_url' => $row['media_url']], $old)) return;
        if ($items === [] && !$deleteMissing) return;
        $byId = array_column($old, null, 'id');
        $remaining = array_column($old, null, 'id');
        $matches = [];
        foreach ($items as $index => $item) {
            foreach ($remaining as $id => $episode) {
                if ($episode['media_url'] === $item['media_url'] && $episode['label'] === $item['label']) {
                    $matches[$index] = $id;
                    unset($remaining[$id]);
                    break;
                }
            }
        }
        // A renamed/reordered episode is still the same episode when its URL
        // remains unchanged. Reserve these before positional edits are matched.
        foreach ($items as $index => $item) {
            if (isset($matches[$index])) continue;
            foreach ($remaining as $id => $episode) {
                if ($episode['media_url'] === $item['media_url']) {
                    $matches[$index] = $id; unset($remaining[$id]); break;
                }
            }
        }
        foreach ($items as $index => $item) {
            if (isset($matches[$index])) continue;
            $candidates = array_keys(array_filter($remaining, static fn (array $episode): bool => $episode['label'] === $item['label']));
            if (count($candidates) === 1) { $matches[$index] = $candidates[0]; unset($remaining[$candidates[0]]); }
        }
        $plan = [];
        foreach ($items as $index => $item) {
            $match = $matches[$index] ?? null;
            if ($match === null) {
                foreach ($remaining as $id => $episode) {
                    if ((int) $episode['episode_no'] === $index + 1) { $match = $id; break; }
                }
            }
            $plan[] = [$match, $index + 1, $item];
            if ($match !== null) unset($remaining[$match]);
        }
        // Reserve temporary free numbers so reordering cannot violate the
        // (source_id,episode_no) unique key (MySQL unsigned INT).
        $occupied = array_fill_keys(array_map('intval', array_column($old, 'episode_no')), true);
        foreach ($plan as [, $number]) $occupied[$number] = true;
        $temporary = 4294967295;
        foreach ($old as $episode) {
            while (isset($occupied[$temporary]) && $temporary > 0) --$temporary;
            if ($temporary < 1) throw new HttpException(422, '分集编号空间不足，未保存');
            Db::table('ffx_episodes')->where('id', $episode['id'])->update(['episode_no' => $temporary, 'updated_at' => $episode['updated_at']]);
            $occupied[$temporary--] = true;
        }
        $now = gmdate('Y-m-d H:i:s');
        foreach ($plan as [$id, $number, $item]) {
            $data = $item + ['episode_no' => $number, 'sort_order' => $number - 1];
            if ($id !== null) {
                $previous = $byId[$id];
                $data['updated_at'] = $previous['updated_at'];
                if ($previous['label'] !== $item['label'] || $previous['media_url'] !== $item['media_url'] || (int) $previous['episode_no'] !== $number) $data['updated_at'] = $now;
                Db::table('ffx_episodes')->where('id', $id)->update($data);
            } else {
                Db::table('ffx_episodes')->insert($data + ['media_id' => $mediaId, 'source_id' => $sourceId, 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now, 'published_at' => $now]);
            }
        }
        if ($deleteMissing && $remaining !== []) {
            Db::table('ffx_episodes')->where('source_id', $sourceId)->whereIn('id', array_keys($remaining))->delete();
        } else {
            $number = count($items);
            foreach ($remaining as $episode) {
                Db::table('ffx_episodes')->where('id', $episode['id'])->update(['episode_no' => ++$number, 'sort_order' => $number - 1, 'updated_at' => $episode['updated_at']]);
            }
        }
    }
}
