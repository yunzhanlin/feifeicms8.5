<?php
declare(strict_types=1);

namespace tests;

use app\service\CollectionCategoryMap;
use app\service\CollectionHttpClient;
use app\service\CollectionPayloadNormalizer;
use app\service\CollectionResponseSizeGuard;
use app\service\CollectionRunner;
use app\service\CollectionSourceIdentity;
use app\service\EpisodeParser;
use app\service\SafeRemoteUrl;
use app\service\SiteSettings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CollectionRunnerMergeTest extends TestCase
{
    public function testAllowedPlayerParserUsesPcreUnicodeSyntax(): void
    {
        $method = new ReflectionMethod($this->runner(), 'allowedPlayers');
        self::assertSame([], $method->invoke($this->runner()));
    }

    public function testMergeDoesNotPublishDraftWhenAutoPublishIsDisabled(): void
    {
        $result = $this->merge(['status' => 'draft'], ['status' => 'draft']);

        self::assertSame('draft', $result['status']);
        self::assertArrayNotHasKey('published_at', $result);
    }

    public function testMergePublishesNewlyPublishedIncomingRecordAndKeepsPublishedRecordPublished(): void
    {
        $incoming = $this->merge(['status' => 'draft', 'published_at' => null], [
            'status' => 'published',
            'published_at' => '2026-09-29 01:00:00',
        ]);
        self::assertSame('published', $incoming['status']);
        self::assertSame('2026-09-29 01:00:00', $incoming['published_at']);

        $existing = $this->merge(['status' => 'published', 'published_at' => '2026-09-28 01:00:00'], ['status' => 'draft']);
        self::assertSame('published', $existing['status']);
        self::assertArrayNotHasKey('published_at', $existing);
    }

    public function testExistingReviewStatusIsPreservedBySourceNewDataPolicy(): void
    {
        $runner = $this->runner();
        $method = new ReflectionMethod($runner, 'mergeMediaData');
        $draft = $method->invoke($runner, $this->media(), array_merge($this->media(), ['status' => 'published', 'updated_at' => '2026-09-29 03:00:00']), true);
        self::assertSame('draft', $draft['status']);
        self::assertArrayNotHasKey('published_at', $draft);

        $published = $method->invoke($runner, array_merge($this->media(), ['status' => 'published']), array_merge($this->media(), ['status' => 'draft', 'updated_at' => '2026-09-29 03:00:00']), true);
        self::assertSame('published', $published['status']);
    }

    public function testSourceNewDataPolicyAcceptsAllThreeFeifeiModes(): void
    {
        $runner = $this->runner();
        $method = new ReflectionMethod($runner, 'sourceNewDataPolicy');
        foreach (['published', 'draft', 'update_only'] as $policy) {
            self::assertSame($policy, $method->invoke($runner, ['new_data_policy' => $policy]));
        }
    }

    /** @param array<string,mixed> $localOverrides @param array<string,mixed> $incomingOverrides */
    private function merge(array $localOverrides, array $incomingOverrides): array
    {
        $runner = $this->runner();
        $local = array_merge($this->media(), $localOverrides);
        $incoming = array_merge($this->media(), ['updated_at' => '2026-09-29 02:00:00'], $incomingOverrides);
        $method = new ReflectionMethod($runner, 'mergeMediaData');

        /** @var array<string,mixed> $result */
        $result = $method->invoke($runner, $local, $incoming);
        return $result;
    }

    private function runner(): CollectionRunner
    {
        return new CollectionRunner(
            new CollectionHttpClient(new SafeRemoteUrl(), new SiteSettings(), new CollectionResponseSizeGuard()),
            new EpisodeParser(),
            new CollectionCategoryMap(),
            new CollectionPayloadNormalizer(),
            new CollectionSourceIdentity(),
            new SiteSettings(),
        );
    }

    /** @return array<string,mixed> */
    private function media(): array
    {
        return [
            'status' => 'draft', 'published_at' => null, 'updated_at' => '2026-09-29 00:00:00',
            'episode_total' => null, 'episode_label' => '', 'is_completed' => 0,
            'original_title' => '', 'subtitle' => '', 'content' => '', 'poster_url' => '', 'backdrop_url' => '',
            'area' => '', 'language' => '', 'release_year' => null, 'release_date' => null,
            'douban_id' => '', 'imdb_id' => '', 'rating' => 0, 'rating_count' => 0,
            'view_count' => 0, 'like_count' => 0, 'dislike_count' => 0, 'weight' => 0,
            'access_mode' => 'free', 'price_points' => 0,
            'metadata' => json_encode(['source_ref' => '[fixture]=[1]'], JSON_THROW_ON_ERROR),
        ];
    }
}
