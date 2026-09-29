<?php
declare(strict_types=1);

use app\service\CollectionRunner;
use think\App;
use think\facade\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new App();
$app->initialize();
$runner = $app->make(CollectionRunner::class);
$import = \Closure::bind(
    static function (CollectionRunner $runner, int $sourceId, array $row, array $mapping, int $categoryId): bool {
        $method = new ReflectionMethod($runner, 'importMedia');
        return (bool) $method->invoke($runner, $sourceId, $row, $mapping, $categoryId);
    },
    null,
    CollectionRunner::class
);

$token = bin2hex(random_bytes(6));
$title = '__ff_collection_merge_verify_' . $token;
$sourceIds = [];
$mediaId = 0;
try {
    $categoryId = (int) Db::table('ffx_categories')->where('content_type', 'media')->whereNull('deleted_at')->order('id')->value('id');
    if ($categoryId < 1) throw new RuntimeException('没有可用于验收的影片分类');
    foreach (['feifei_json', 'maccms_json'] as $index => $protocol) {
        $sourceIds[] = (int) Db::table('ffx_collection_sources')->insertGetId([
            'name' => '__verify_' . $protocol . '_' . $token,
            'endpoint' => 'https://verify-' . $token . '-' . $index . '.invalid/api',
            'source_type' => $protocol, 'category_mapping' => '{}', 'status' => 'enabled',
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
    $douban = 'verify-db-' . $token;
    $imdb = 'tt' . substr(preg_replace('/[^0-9]/', '', hash('crc32b', $token)) . '12345678', 0, 8);
    $created = $import($runner, $sourceIds[0], [
        'vod_id' => 'ff-100', 'vod_cid' => '1', 'vod_name' => $title, 'vod_year' => '2026',
        'vod_area' => '大陆,香港,中国台湾', 'vod_language' => '汉语普通话',
        'vod_actor' => '演员甲,演员乙', 'vod_director' => '导演甲',
        'vod_douban_id' => $douban, 'vod_imdb_id' => $imdb,
        'vod_content' => '飞飞来源正文', 'vod_play' => 'ffm3u8',
        'vod_url' => "第1集\$https://verify.invalid/ff-1.m3u8\r第2集\$https://verify.invalid/ff-2.m3u8",
        'vod_reurl' => '[verify]=[ff-100]',
        'vod_scenario' => ['info' => [1 => '第一集剧情', 2 => '第二集剧情']],
    ], ['1' => $categoryId], $categoryId);
    $updated = $import($runner, $sourceIds[1], [
        'vod_id' => 'mac-200', 'type_id' => '2', 'vod_name' => $title, 'vod_year' => '2026',
        'vod_area' => '内地/台湾', 'vod_lang' => '普通话', 'vod_actor' => '演员甲', 'vod_director' => '导演甲',
        'vod_douban_id' => $douban, 'vod_imdb_id' => $imdb,
        'vod_content' => 'MacCMS 来源正文不得覆盖已有正文', 'vod_play_from' => 'm3u8',
        'vod_play_url' => '正片$https://verify.invalid/mac.m3u8',
    ], ['2' => $categoryId], $categoryId);

    $rows = Db::table('ffx_media')->where('title', $title)->select()->toArray();
    if (count($rows) !== 1) throw new RuntimeException('跨来源视频没有合并为一条');
    $media = $rows[0];
    $mediaId = (int) $media['id'];
    $refs = Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', $mediaId)->count();
    $sources = Db::table('ffx_play_sources')->where('media_id', $mediaId)->count();
    $owners = Db::table('ffx_play_sources')->where('media_id', $mediaId)->whereNotNull('collection_source_id')->count();
    $scenarios = Db::table('ffx_scenarios')->where('media_id', $mediaId)->count();
    if (!$created || $updated || $refs !== 2 || $sources !== 2 || $owners !== 2 || $scenarios !== 2) {
        throw new RuntimeException("验收计数不符 created={$created} updated={$updated} refs={$refs} sources={$sources} owners={$owners} scenarios={$scenarios}");
    }
    if ($media['area'] !== '中国大陆,中国香港,中国台湾' || $media['language'] !== '国语' || $media['content'] !== '飞飞来源正文') {
        throw new RuntimeException('标准化或合并资料保护不符合预期');
    }
    $metadata = is_string($media['metadata'] ?? null) ? json_decode((string) $media['metadata'], true) : ($media['metadata'] ?? []);
    $sourceRef = (string) ($metadata['source_ref'] ?? '');
    if (!str_contains($sourceRef, '[verify]=[ff-100]') || !str_contains($sourceRef, '=[mac-200]')) {
        throw new RuntimeException('合并后没有保留两个来源标识：' . $sourceRef);
    }
    echo json_encode(['media_rows' => 1, 'external_refs' => $refs, 'play_sources' => $sources, 'scenario_rows' => $scenarios, 'area' => $media['area'], 'language' => $media['language'], 'source_ref' => $sourceRef, 'content_preserved' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($mediaId > 0) {
        Db::table('ffx_external_refs')->where('entity_type', 'media')->where('entity_id', $mediaId)->delete();
        Db::table('ffx_media')->where('id', $mediaId)->where('title', $title)->delete();
    }
    if ($sourceIds !== []) Db::table('ffx_collection_sources')->whereIn('id', $sourceIds)->delete();
}
