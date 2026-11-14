<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\facade\Db;

final class DramaMetadataImporter
{
    public function import(int $mediaId, array $data): array
    {
        return CollectionImportLock::run(['drama-metadata-people'], fn () => Db::transaction(function () use ($mediaId, $data): array {
            $media = Db::table('ffx_media')->where('id', $mediaId)->whereNull('deleted_at')->lock(true)->find();
            if ($media === null) throw new RuntimeException('本地影片已删除，请返回重新选择');

            $counts = ['plots' => 0, 'plots_skipped' => 0, 'actors' => 0, 'roles' => 0, 'credits' => 0];
            $now = gmdate('Y-m-d H:i:s');
            $current = Db::table('ffx_scenarios')->where('media_id', $mediaId)->lock(true)->column('episode_no');
            $existing = array_fill_keys($current, true);
            foreach ($data['episodes'] as $row) {
                $episode = (int) $row['episode_no'] + (int) $data['offset'];
                if ($episode < 1 || $episode > 100000 || isset($existing[$episode])) {
                    $counts['plots_skipped']++;
                    continue;
                }
                $title = mb_substr(trim($row['title']), 0, 255);
                Db::table('ffx_scenarios')->insert([
                    'media_id' => $mediaId, 'episode_no' => $episode, 'title' => $title !== '' ? $title : '第' . $episode . '集',
                    'content' => $row['content'], 'source_ref' => $row['url'], 'status' => $data['plot_status'],
                    'sort_order' => $episode, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $counts['plots']++;
                $existing[$episode] = true;
            }

            $sort = (int) Db::table('ffx_media_people')->where('media_id', $mediaId)->max('sort_order');
            $actors = [];
            foreach ($data['cast'] as $row) {
                if (!isset($actors[$row['id']])) {
                    [$personId, $created] = $this->actor($data['provider'], $row, $now);
                    $actors[$row['id']] = $personId;
                    $counts['actors'] += (int) $created;
                }
                $personId = $actors[$row['id']];
                if ($personId === 0) continue;
                $character = mb_substr($row['character'], 0, 255);
                $counts['credits'] += $this->credit($mediaId, $personId, 'actor', $character, ++$sort);
                if ($character === '') continue;
                [$roleId, $created] = $this->role($mediaId, $row, $character, $now);
                $counts['roles'] += (int) $created;
                if ($roleId !== 0) $counts['credits'] += $this->credit($mediaId, $roleId, 'character', mb_substr($row['name'], 0, 255), ++$sort);
            }
            return $counts;
        }));
    }

    private function actor(string $provider, array $row, string $now): array
    {
        $identity = Db::table('ffx_external_refs')->where('provider', $provider)
            ->where('entity_type', 'person')->where('external_id', $row['id']);
        $ref = $identity->find();
        if ($ref !== null) {
            $person = Db::table('ffx_people')->where('id', (int) $ref['entity_id'])->find();
            if ($person !== null) return [empty($person['deleted_at']) ? (int) $person['id'] : 0, false];
        }

        $name = mb_substr($row['name'], 0, 255);
        $sameName = Db::table('ffx_people')->where('kind', 'person')->where('name', $name)
            ->whereNull('deleted_at')->order('id')->limit(2)->column('id');
        $id = count($sameName) === 1 ? (int) $sameName[0] : 0;
        if ($id && Db::table('ffx_external_refs')->where('entity_type', 'person')->where('provider', $provider)
            ->where('entity_id', $id)->count() > 0) {
            $id = 0;
        }
        $created = false;
        if (!$id) {
            [$id, $created] = $this->person('person', $name, $provider . '-person-' . $row['id'], $row['photo'], '', $now);
        }
        if ($id) {
            $values = ['entity_id' => $id, 'source_url' => $row['url'], 'last_synced_at' => $now, 'updated_at' => $now];
            if ($ref === null) {
                Db::table('ffx_external_refs')->insert($values + [
                    'provider' => $provider, 'entity_type' => 'person', 'external_id' => $row['id'], 'created_at' => $now,
                ]);
            } else {
                $identity->update($values);
            }
        }
        return [$id, $created];
    }

    private function role(int $mediaId, array $row, string $character, string $now): array
    {
        $existing = Db::table('ffx_media_people')->alias('mp')->join(['ffx_people' => 'p'], 'p.id=mp.person_id')
            ->where('mp.media_id', $mediaId)->whereIn('p.kind', ['character', 'role'])->where('p.name', $character)
            ->field('p.id,p.deleted_at')->find();
        if ($existing !== null) return [empty($existing['deleted_at']) ? (int) $existing['id'] : 0, false];

        $slug = 'role-' . $mediaId . '-' . substr(hash('sha256', mb_strtolower($character)), 0, 32);
        return $this->person('character', $character, $slug, $row['photo'], $row['character_bio'], $now, $row['character_url']);
    }

    private function person(string $kind, string $name, string $slug, string $photo, string $bio, string $now, string $url = ''): array
    {
        $existing = Db::table('ffx_people')->where('slug', $slug)->find();
        if ($existing !== null) return [empty($existing['deleted_at']) ? (int) $existing['id'] : 0, false];

        $personId = Db::table('ffx_people')->insertGetId([
            'kind' => $kind, 'name' => $name, 'slug' => $slug, 'avatar_url' => mb_substr($photo, 0, 1000),
            'biography' => $bio === '' ? null : $bio, 'status' => 'published', 'created_at' => $now, 'updated_at' => $now,
            'metadata' => $url === '' ? null : json_encode(['source_ref' => $url], JSON_THROW_ON_ERROR),
        ]);
        return [(int) $personId, true];
    }

    private function credit(int $mediaId, int $personId, string $type, string $character, int $sort): int
    {
        $row = ['media_id' => $mediaId, 'person_id' => $personId, 'credit_type' => $type, 'character_name' => $character];
        if (Db::table('ffx_media_people')->where($row)->count() > 0) return 0;
        Db::table('ffx_media_people')->insert($row + ['sort_order' => $sort]);
        return 1;
    }
}
