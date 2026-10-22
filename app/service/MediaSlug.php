<?php
declare(strict_types=1);
namespace app\service;

use think\facade\Db;

/** Readable video aliases, with durable compatibility for URLs already published. */
final class MediaSlug
{
    public function base(string $title, string $preferred = ''): string
    {
        if (trim($preferred) === '') return (new Slugger())->fromTitle($title);
        return preg_match('/[^\x00-\x7F]/', $preferred) ? (new Slugger())->fromTitle($preferred) : (new Slugger())->make($preferred, 'media');
    }

    /** Caller holds the corresponding slug identity lock until its write commits. */
    public function allocate(string $title, string $preferred = '', int $excludeId = 0): string
    {
        $base = $this->base($title, $preferred);
        for ($suffix = 1; $suffix <= 1000; $suffix++) {
            $slug = $base . ($suffix === 1 ? '' : '-' . $suffix);
            if (Db::table('ffx_media')->where('slug', $slug)->where('id', '<>', $excludeId)->count() > 0) continue;
            if (Db::table('ffx_external_refs')->where('provider', 'media-slug-alias')->where('entity_type', 'media')
                ->where('external_id', $slug)->where('entity_id', '<>', $excludeId)->count() > 0) continue;
            return $slug;
        }
        throw new \RuntimeException('同名链接别名过多，请手工指定不同的别名');
    }

    public function isGenerated(string $slug): bool
    {
        return preg_match('/^(?:source-\d+-[A-Za-z0-9_-]+|vod-\d+|media-\d{14}-[a-f0-9]{6})$/D', $slug) === 1;
    }

    public function remember(string $slug, int $mediaId): void
    {
        if ($slug === '') return;
        $query = Db::table('ffx_external_refs')->where('provider', 'media-slug-alias')->where('entity_type', 'media')->where('external_id', $slug);
        $existing = $query->find();
        if ($existing !== null) {
            if ((int) $existing['entity_id'] !== $mediaId) throw new \RuntimeException('旧链接别名已关联其他影片');
            return;
        }
        Db::table('ffx_external_refs')->insert(['provider' => 'media-slug-alias', 'entity_type' => 'media',
            'entity_id' => $mediaId, 'external_id' => $slug, 'payload' => '{}']);
    }

    public function repair(int $mediaId, bool $onlyGenerated = true): bool
    {
        $row = Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')->field('id,title,slug')->find();
        if ($row === null || ($onlyGenerated && !$this->isGenerated((string) $row['slug']))) return false;
        return CollectionImportLock::run(['slug:' . $this->base((string) $row['title'])], function () use ($mediaId, $onlyGenerated): bool {
            return Db::transaction(function () use ($mediaId, $onlyGenerated): bool {
                $row = Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')->lock(true)->find();
                if ($row === null || ($onlyGenerated && !$this->isGenerated((string) $row['slug']))) return false;
                $slug = $this->allocate((string) $row['title'], '', $mediaId);
                if ($slug === (string) $row['slug']) return false;
                $this->remember((string) $row['slug'], $mediaId);
                // Alias maintenance must not make an old video appear recently updated.
                Db::table('ffx_media')->where('id', $mediaId)->update(['slug' => $slug, 'updated_at' => $row['updated_at']]);
                return true;
            });
        });
    }
}
