<?php
declare(strict_types=1);

namespace app\service;

use RuntimeException;
use think\db\Query;
use think\facade\Db;

final class ActorProfileCompletion
{
    public function __construct(private readonly TvznMetadata $tvzn, private readonly TmdbMetadata $tmdb) {}

    /** @return list<int> */
    public function candidates(int $after, int $limit, ?int $upperId = null): array
    {
        $query = Db::table('ffx_people')->alias('p')
            ->where('p.id', '>', $after)->where('p.kind', 'person')->whereNull('p.deleted_at')
            ->whereRaw("EXISTS (SELECT 1 FROM ffx_external_refs r WHERE r.entity_type='person' AND r.entity_id=p.id AND r.provider IN ('tvzn','tmdb'))")
            ->whereRaw("(COALESCE(TRIM(p.biography), '') = '' OR p.birthday IS NULL OR TRIM(p.profession) = '' OR TRIM(p.gender) = '' OR TRIM(p.avatar_url) = '')")
            ->order('p.id')->limit($limit);
        if ($upperId !== null) $query->where('p.id', '<=', $upperId);
        return array_map('intval', $query->column('p.id'));
    }

    /** @return list<string> Filled field names. */
    public function complete(int $personId): array
    {
        $ref = Db::table('ffx_external_refs')->where('entity_type', 'person')->where('entity_id', $personId)
            ->whereIn('provider', ['tvzn', 'tmdb'])
            ->orderRaw("CASE WHEN provider = 'tvzn' THEN 0 ELSE 1 END")->order('id')->select()->toArray();
        $person = $this->actor($personId)->find();
        if ($person === null || !$this->needsDetails($person)) return [];
        $filled = [];
        $read = false;
        $failure = null;
        foreach ($ref as $identity) {
            try {
                $source = $identity['provider'] === 'tvzn' ? $this->tvzn : $this->tmdb;
                $profile = $source->profile((string) $identity['external_id']);
                $read = true;
            } catch (\Exception $error) {
                $failure = $error;
                continue;
            }
            $filled = array_merge($filled, Db::transaction(function () use ($personId, $identity, $profile): array {
                $person = $this->actor($personId)->lock(true)->find();
                if ($person === null || !Db::table('ffx_external_refs')->where('id', $identity['id'])
                    ->where('entity_type', 'person')->where('entity_id', $personId)->where('provider', $identity['provider'])->where('external_id', $identity['external_id'])->count()) return [];
                $updates = $this->missingFields($person, $profile);
                if ($updates === []) return [];
                $metadata = json_decode((string) ($person['metadata'] ?? ''), true);
                $metadata = is_array($metadata) ? $metadata : [];
                foreach (['native_place', 'birthplace', 'school'] as $key) {
                    if (trim((string) ($metadata[$key] ?? '')) === '' && ($profile[$key] ?? '') !== '') {
                        $metadata[$key] = mb_substr($profile[$key], 0, 160);
                    }
                }
                if (!isset($metadata['profile_sources']) || is_array($metadata['profile_sources'])) {
                    $metadata['profile_sources'][$identity['provider']] ??= $profile['source_url'];
                }
                $this->actor($personId)->update($updates + ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
                return array_keys($updates);
            }));
        }
        if (!$read && $failure !== null) throw new RuntimeException('无法读取已绑定来源的演员档案', 0, $failure);
        return $filled;
    }

    private function actor(int $personId): Query
    {
        return Db::table('ffx_people')->where('id', $personId)->where('kind', 'person')->whereNull('deleted_at');
    }

    private function needsDetails(array $person): bool
    {
        foreach (['biography', 'birthday', 'gender', 'profession', 'avatar_url'] as $key) {
            if (trim((string) ($person[$key] ?? '')) === '') return true;
        }
        return false;
    }

    private function missingFields(array $person, array $profile): array
    {
        $updates = [];
        foreach (['aliases' => 500, 'gender' => 30, 'nationality' => 80, 'profession' => 255, 'avatar_url' => 1000] as $key => $limit) {
            $value = trim($profile[$key] ?? '');
            if (trim((string) ($person[$key] ?? '')) === '' && $value !== '') $updates[$key] = mb_substr($value, 0, $limit);
        }
        if (empty($person['birthday']) && preg_match('/^(\d{4})(?:-|年)(\d{1,2})(?:-|月)(\d{1,2})日?$/uD', $profile['birthday'] ?? '', $date)
            && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            $updates['birthday'] = sprintf('%04d-%02d-%02d', $date[1], $date[2], $date[3]);
        }
        $biography = trim($profile['biography'] ?? '');
        if (trim((string) ($person['biography'] ?? '')) === '' && $biography !== '') {
            $updates['biography'] = nl2br(htmlspecialchars(mb_substr($biography, 0, 18000), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        }
        return $updates;
    }
}
