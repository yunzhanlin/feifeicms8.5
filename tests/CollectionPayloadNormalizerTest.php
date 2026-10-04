<?php
declare(strict_types=1);

namespace tests;

use app\service\CollectionPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class CollectionPayloadNormalizerTest extends TestCase
{
    private CollectionPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CollectionPayloadNormalizer();
    }

    public function testFeifei73RequestAndEnvelopeContract(): void
    {
        self::assertSame(
            ['g' => 'plus', 'm' => 'api', 'a' => 'json', 'p' => 2, 'limit' => 30, 'cid' => '3', 'vodids' => '8,9'],
            $this->normalizer->requestParams('feifei_json', 'video_detail', ['page' => 2, 'limit' => 30, 't' => 3, 'ids' => '8,9'])
        );
        self::assertSame('scenario', $this->normalizer->requestParams('feifei_json', 'scenario', ['page' => 1])['a']);
        self::assertSame('youku', $this->normalizer->requestParams('feifei_json', 'video_detail', ['play' => 'youku'])['play']);
        self::assertSame('m3u8', $this->normalizer->requestParams('maccms_json', 'video_detail', ['play' => 'm3u8'])['play']);
        $result = $this->normalizer->envelope([
            'status' => 200,
            'page' => ['pageindex' => 2, 'pagecount' => 4, 'pagesize' => 30, 'recordcount' => 91],
            'list' => [['list_id' => 2, 'list_name' => '电视剧']],
            'data' => [['vod_id' => 9, 'vod_name' => '样片']],
        ], 'feifei_json');
        self::assertSame('feifei_json', $result['protocol']);
        self::assertSame(2, $result['page']);
        self::assertSame(4, $result['pagecount']);
        self::assertSame([['id' => '2', 'parent_id' => '0', 'name' => '电视剧']], $result['categories']);
        self::assertSame('样片', $result['rows'][0]['vod_name']);
    }

    public function testMaccmsFieldsAndRequestedNormalizations(): void
    {
        $mapped = $this->normalizer->media([
            'vod_id' => 7, 'vod_name' => '合并样片', 'type_id' => 2, 'vod_class' => '剧情／悬疑，剧情',
            'vod_area' => '大陆,香港,中国台湾,台湾,中国香港', 'vod_lang' => '汉语/普通话/汉语普通话/英语',
            'vod_douban_id' => '1292052', 'vod_imdb_id' => 'tt0111161', 'vod_actor' => '演员甲,演员乙',
            'vod_ispay' => 1, 'vod_price' => 8,
            'vod_play_from' => 'm3u8', 'vod_play_url' => '第1集$https://example.test/1.m3u8',
        ]);
        self::assertSame('中国大陆,中国香港,中国台湾', $mapped['area']);
        self::assertSame('国语,英语', $mapped['language']);
        self::assertSame('剧情,悬疑', $mapped['metadata']['type']);
        self::assertSame('1292052', $mapped['douban_id']);
        self::assertSame('tt0111161', $mapped['imdb_id']);
        self::assertSame('m3u8', $mapped['play_from']);
        self::assertSame('member', $mapped['access_mode']);
        self::assertSame(8, $mapped['price_points']);
    }

    public function testPlainMetadataDecodesNestedEntitiesAndRemovesMarkup(): void
    {
        $mapped = $this->normalizer->media([
            'vod_id' => 8,
            'vod_name' => '<b>妈妈发疯了</b>',
            'vod_sub' => 'Mission: Mom&amp;amp;amp;#039;s Crazy <em>限定</em>',
            'vod_actor' => '<span>演员甲</span>',
        ]);

        self::assertSame('妈妈发疯了', $mapped['title']);
        self::assertSame("Mission: Mom's Crazy 限定", $mapped['subtitle']);
        self::assertSame('演员甲', $mapped['metadata']['actor']);
    }

    public function testFeifeiScenarioInfoBecomesIndependentEpisodeRows(): void
    {
        $rows = $this->normalizer->scenarios(['info' => [
            ['scenario_pid' => 1, 'scenario_name' => '相遇', 'scenario_content' => '第一集剧情'],
            2 => '第二集剧情',
        ]]);
        self::assertSame([
            ['episode_no' => 1, 'title' => '相遇', 'content' => '第一集剧情'],
            ['episode_no' => 2, 'title' => '第2集', 'content' => '第二集剧情'],
        ], $rows);
    }

    public function testLegacy43JsonScenarioListKeepsEveryEpisodeNumber(): void
    {
        $rows = $this->normalizer->scenarios('{"info":["第一集剧情","第二集剧情","第三集剧情"]}');
        self::assertSame([
            ['episode_no' => 1, 'title' => '第1集', 'content' => '第一集剧情'],
            ['episode_no' => 2, 'title' => '第2集', 'content' => '第二集剧情'],
            ['episode_no' => 3, 'title' => '第3集', 'content' => '第三集剧情'],
        ], $rows);
    }
}
