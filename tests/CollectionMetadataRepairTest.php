<?php
declare(strict_types=1);

namespace tests;

use app\service\CollectionMetadataRepair;
use app\service\CollectionPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class CollectionMetadataRepairTest extends TestCase
{
    public function testSnapshotRepairIsFillOnlyAndIdempotent(): void
    {
        $repair = new CollectionMetadataRepair(new CollectionPayloadNormalizer());
        $media = ['metadata' => json_encode(['director' => '本地导演', 'actor' => '', 'custom' => '保留', 'inputer' => 'json_2']), 'release_date' => null];
        $snapshots = [
            ['vod_actor' => '源演员', 'vod_director' => '源导演', 'vod_tag' => '悬疑', 'vod_class' => '剧情', 'vod_pubdate' => '2026-10-01', 'vod_play_url' => '不能修改播放', 'vod_status' => 1],
            ['vod_actor' => '旧源演员', 'vod_tag' => '旧标签'],
        ];
        $patch = $repair->patch($media, $snapshots);
        $metadata = json_decode($patch['metadata'], true);
        self::assertSame('源演员', $metadata['actor']);
        self::assertSame('本地导演', $metadata['director']);
        self::assertSame('悬疑', $metadata['keywords']);
        self::assertSame('保留', $metadata['custom']);
        self::assertSame('json_2', $metadata['inputer']);
        self::assertSame('2026-10-01', $patch['release_date']);
        self::assertEqualsCanonicalizing(['metadata', 'release_date'], array_keys($patch));
        self::assertSame([], $repair->patch(array_replace($media, $patch), $snapshots));
    }

    public function testLockedExistingAndMissingSourceDataArePreserved(): void
    {
        $repair = new CollectionMetadataRepair(new CollectionPayloadNormalizer());
        self::assertSame([], $repair->patch(['metadata' => ['inputer' => 'feifeicms']], [['vod_actor' => '演员']]));
        self::assertSame([], $repair->patch(['metadata' => []], [['vod_actor' => '', 'vod_director' => '', 'vod_pubdate' => '0']]));
        self::assertSame([], $repair->patch(['metadata' => ['actor' => '本地演员'], 'release_date' => '2026-09-01'], [['vod_actor' => '源演员', 'vod_filmtime' => 1790784000]]));
    }

    public function testPartialReleaseTextIsRetainedWithoutFakeFullDate(): void
    {
        $patch = (new CollectionMetadataRepair(new CollectionPayloadNormalizer()))->patch(['metadata' => []], [['vod_pubdate' => '2026']]);
        self::assertSame(['pubdate' => '2026'], json_decode($patch['metadata'], true));
        self::assertArrayNotHasKey('release_date', $patch);
    }
}
