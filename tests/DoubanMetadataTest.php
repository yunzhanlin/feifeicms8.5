<?php
declare(strict_types=1);

namespace tests;

use app\service\DoubanMetadata;
use PHPUnit\Framework\TestCase;

final class DoubanMetadataTest extends TestCase
{
    public function testMapsDoubanPayloadToVideoEditorFields(): void
    {
        $mapped = (new DoubanMetadata())->mapPayload('1292052', [
            'title' => '测试影片',
            'original_title' => 'Test Film',
            'aka' => ['测试别名'],
            'year' => 2026,
            'release_date' => ['2026-09-29(中国大陆)'],
            'countries' => ['中国大陆'],
            'languages' => ['汉语普通话'],
            'genres' => ['剧情', '悬疑'],
            'tags' => [['name' => '经典'], ['name' => '推理']],
            'directors' => [['name' => '导演甲']],
            'actors' => [['name' => '演员甲'], ['name' => '演员乙']],
            'writers' => [['name' => '编剧甲']],
            'imdb_id' => 'IMDb: tt1234567',
            'pic' => ['large' => 'https://img.example.test/poster.jpg'],
            'rating' => ['value' => 8.7, 'count' => 1234],
            'durations' => ['98分钟'],
            'episodes_count' => 12,
            'episodes_info' => '更新至第8集',
            'intro' => '影片简介',
        ]);

        self::assertSame('1292052', $mapped['douban_id']);
        self::assertSame('测试影片', $mapped['title']);
        self::assertSame('Test Film / 测试别名', $mapped['subtitle']);
        self::assertSame('2026-09-29', $mapped['release_date']);
        self::assertSame('剧情,悬疑', $mapped['type']);
        self::assertSame('经典,推理', $mapped['tags']);
        self::assertSame('导演甲', $mapped['director']);
        self::assertSame('演员甲,演员乙', $mapped['actor']);
        self::assertSame('编剧甲', $mapped['writer']);
        self::assertSame('tt1234567', $mapped['imdb_id']);
        self::assertSame(5880, $mapped['length']);
        self::assertSame('[douban]=[1292052]', $mapped['source_ref']);
    }
}
