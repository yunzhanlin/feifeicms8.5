<?php
declare(strict_types=1);

namespace tests;

use app\controller\api\VodProvider;
use app\service\CollectionPayloadNormalizer;
use app\service\FrontendCache;
use app\service\FrontendData;
use app\service\ResilientCache;
use app\service\SiteSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class FrontendMediaMetadataTest extends TestCase
{
    private function frontend(): FrontendData
    {
        $cache = new FrontendCache(new ResilientCache());
        $settings = new SiteSettings($cache);
        (new ReflectionProperty(SiteSettings::class, 'values'))->setValue($settings, []);
        return new FrontendData($settings, $cache);
    }

    public function testEmptyTagsDoNotHideCollectedKeywordsOrGenreFallback(): void
    {
        $frontend = $this->frontend();
        foreach ([['keywords' => '悬疑', 'type' => '剧情'], ['keywords' => '', 'type' => '悬疑']] as $metadata) {
            $vod = $frontend->vod(['tags_text' => '', 'metadata' => $metadata]);
            self::assertSame('悬疑', $vod['vod_keywords']);
            self::assertSame('悬疑', $vod['vod_tag']);
        }
        self::assertSame('手工标签', $frontend->vod(['tags_text' => '手工标签', 'metadata' => ['keywords' => '采集标签']])['vod_keywords']);
    }

    public function testFrontendCastAndReleaseDateAliasesRemainCompatible(): void
    {
        $vod = $this->frontend()->vod(['release_date' => '2026-10-01', 'metadata' => json_encode(['actor' => '演员甲', 'director' => '导演甲', 'pubdate' => '2026'])]);
        self::assertSame('演员甲', $vod['vod_actor']);
        self::assertSame('导演甲', $vod['vod_director']);
        self::assertSame('2026-10-01', $vod['vod_pubdate']);
        self::assertSame(strtotime('2026-10-01'), $vod['vod_filmtime']);
        $partial = $this->frontend()->vod(['metadata' => ['pubdate' => '2026']]);
        self::assertSame('2026', $partial['vod_pubdate']);
        self::assertSame(0, $partial['vod_filmtime']);
    }

    public function testProviderMetadataCanBeRecollectedWithoutLeakingPrivateMetadata(): void
    {
        $provider = (new ReflectionClass(VodProvider::class))->newInstanceWithoutConstructor();
        $map = new ReflectionMethod(VodProvider::class, 'mapBase');
        foreach ([['actor' => '演员甲', 'director' => '导演甲', 'keywords' => '悬疑', 'type' => '剧情', 'pubdate' => '2026', 'private_token' => 'do-not-export']] as $metadata) {
            foreach ([$metadata, json_encode($metadata)] as $value) {
                $export = $map->invoke($provider, ['id' => 1, 'title' => '样片', 'release_date' => '2026-10-01', 'metadata' => $value]);
                $mapped = (new CollectionPayloadNormalizer())->media($export);
                self::assertSame('演员甲', $mapped['metadata']['actor']);
                self::assertSame('导演甲', $mapped['metadata']['director']);
                self::assertSame('悬疑', $mapped['metadata']['keywords']);
                self::assertSame('剧情', $mapped['metadata']['type']);
                self::assertSame('2026-10-01', $mapped['release_date']);
                self::assertArrayNotHasKey('metadata', $export);
                self::assertStringNotContainsString('do-not-export', json_encode($export));
            }
        }
    }
}
