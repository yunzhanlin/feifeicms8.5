<?php
declare(strict_types=1);

namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Real collection transactions and API assembly; disposable audit_* DBs only. */
final class CollectionPlayerIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private array $env = [];
    private array $sourceIds = [];
    private array $playerIds = [];
    private int $categoryId = 0;
    private string $prefix = '';

    protected function setUp(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn || !function_exists('proc_open')) self::markTestSkipped('Disposable MySQL and local CLI required');
        if (!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D', $dsn, $database)) self::fail('Refusing a non-audit database');
        preg_match('/host=([^;]+)/', $dsn, $host); preg_match('/;port=(\d+)/', $dsn, $port);
        $this->pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->prefix = 'player-audit-' . bin2hex(random_bytes(8));
        $this->env = getenv();
        $this->env['PHP_ENV_NAME'] = 'isolated-' . $this->prefix;
        $this->env['PHP_DB_HOST'] = $host[1]; $this->env['PHP_DB_PORT'] = $port[1] ?? '3306';
        $this->env['PHP_DB_NAME'] = $database[1]; $this->env['PHP_DB_USER'] = getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root';
        $this->env['PHP_DB_PASS'] = getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '';
        $this->env['PHP_CACHE_DRIVER'] = 'file'; $this->env['PHP_SEARCH_DRIVER'] = 'mysql';
        $this->pdo->prepare("INSERT INTO ffx_categories(name,slug,content_type,status) VALUES (?,?,'media','published')")->execute([$this->prefix, $this->prefix]);
        $this->categoryId = (int) $this->pdo->lastInsertId();
        foreach (['a', 'b'] as $suffix) {
            $this->pdo->prepare("INSERT INTO ffx_collection_sources(name,endpoint,source_type,status) VALUES (?,?,'maccms_json','enabled')")->execute([$this->prefix . '-' . $suffix, 'https://example.invalid/' . $this->prefix . '/' . $suffix]);
            $this->sourceIds[] = (int) $this->pdo->lastInsertId();
        }
        foreach (['enabled', 'disabled'] as $status) {
            $this->pdo->prepare('INSERT INTO ffx_players(player_key,name,status) VALUES (?,?,?)')->execute([$this->prefix . '-' . $status, $this->prefix, $status]);
            $this->playerIds[] = (int) $this->pdo->lastInsertId();
        }
        $this->env['FF_AUDIT_PLAYER_FIXTURE'] = json_encode(['prefix' => $this->prefix, 'category' => $this->categoryId, 'sources' => $this->sourceIds, 'players' => $this->playerIds], JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) return;
        $ids = $this->pdo->query('SELECT id FROM ffx_media WHERE category_id=' . $this->categoryId)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->pdo->prepare("DELETE FROM ffx_external_refs WHERE entity_type='media' AND entity_id=?")->execute([$id]);
            $this->pdo->prepare('DELETE FROM ffx_media WHERE id=?')->execute([$id]);
        }
        foreach ($this->sourceIds as $id) $this->pdo->prepare('DELETE FROM ffx_collection_sources WHERE id=?')->execute([$id]);
        foreach ($this->playerIds as $id) $this->pdo->prepare('DELETE FROM ffx_players WHERE id=?')->execute([$id]);
        if ($this->categoryId) $this->pdo->prepare('DELETE FROM ffx_categories WHERE id=?')->execute([$this->categoryId]);
    }

    private function cli(string $code): array
    {
        $bootstrap = <<<'PHP'
require 'vendor/autoload.php';
$app = new think\App(); $app->initialize();
$fixture = json_decode(getenv('FF_AUDIT_PLAYER_FIXTURE'), true);
$key = $fixture['prefix'].'-enabled'; $disabled = $fixture['prefix'].'-disabled';
$runner = $app->make(app\service\CollectionRunner::class);
$import = new ReflectionMethod($runner, 'importMedia');
$row = ['vod_id'=>7,'vod_name'=>$fixture['prefix'],'type_id'=>1,'vod_year'=>'2026','vod_actor'=>'audit actor','vod_director'=>'audit director','vod_play_from'=>$key,'vod_play_url'=>'第1集$https://example.invalid/valid.m3u8'];
$collect = static function(array $row, int $source = 0) use ($import, $runner, $fixture): bool {
    return $import->invoke($runner, $fixture['sources'][$source], $row, ['1'=>$fixture['category']], $fixture['category']);
};
$export = static function(int $mediaId): array {
    $provider = (new ReflectionClass(app\controller\api\VodProvider::class))->newInstanceWithoutConstructor();
    return (new ReflectionMethod($provider,'playbackByMedia'))->invoke($provider,[$mediaId])[$mediaId];
};
PHP;
        $process = proc_open([PHP_BINARY, '-r', $bootstrap . "\n" . $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $this->env);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMixedSourceOnlyImportsRegisteredEnabledPlayerWithEmptyRestriction(): void
    {
        $result = $this->cli(<<<'PHP'
$row['vod_play_from'] = 'unknown$$$'.strtoupper($key).'$$$'.$disabled;
$row['vod_play_url'] = '正片$https://example.invalid/unknown.m3u8$$$第1集$https://example.invalid/valid.m3u8$$$正片$https://example.invalid/disabled.m3u8';
$created = $collect($row);
$media = think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->find();
$sources = think\facade\Db::table('ffx_play_sources')->where('media_id',$media['id'])->select()->toArray();
echo json_encode(['created'=>$created,'keys'=>array_column($sources,'parser_key'),'export'=>$export((int)$media['id'])]);
PHP);
        self::assertTrue($result['created']);
        self::assertSame([$this->prefix . '-enabled'], $result['keys']);
        self::assertSame($this->prefix . '-enabled', $result['export']['vod_play_from']);
        self::assertSame('第1集$https://example.invalid/valid.m3u8', $result['export']['vod_play_url']);
    }

    public function testUnknownDisabledMissingAndMalformedPlayersDoNotWriteAnyRows(): void
    {
        $result = $this->cli(<<<'PHP'
$skips = [];
foreach (['unknown',$disabled,$key.'-c2-2',$key.'@',''] as $index=>$bad) {
    $invalid = $row; $invalid['vod_id'] = 100+$index; $invalid['vod_play_from'] = $bad;
    try { $collect($invalid); $skips[] = false; } catch (app\service\CollectionSkipException) { $skips[] = true; }
}
think\facade\Db::table('ffx_players')->where('id',$fixture['players'][0])->update(['status'=>'disabled']);
try { $collect($row); $skips[] = false; } catch (app\service\CollectionSkipException) { $skips[] = true; }
echo json_encode(['skips'=>$skips,'media'=>think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->count(),'refs'=>think\facade\Db::table('ffx_external_refs')->whereIn('source_id',$fixture['sources'])->count(),'lines'=>think\facade\Db::table('ffx_play_sources')->whereIn('collection_source_id',$fixture['sources'])->count()]);
PHP);
        self::assertSame(array_fill(0, 6, true), $result['skips']);
        foreach (['media', 'refs', 'lines'] as $field) self::assertSame(0, $result[$field]);
    }

    public function testOptionalWhitelistDoesNotBypassRegistrationOrAllowEverythingWhenIntersectionIsEmpty(): void
    {
        $result = $this->cli(<<<'PHP'
$settings = $app->make(app\service\SiteSettings::class);
$property = new ReflectionProperty($settings,'values');
$skips = [];
foreach (['unknown',$disabled,$key.'-c2-2'] as $restriction) {
    $property->setValue($settings,['admin.collection.api_allowed_players'=>$restriction]);
    try { $collect($row); $skips[] = false; } catch (app\service\CollectionSkipException) { $skips[] = true; }
}
$property->setValue($settings,['admin.collection.api_allowed_players'=>"unknown，".strtoupper($key)]);
$created = $collect($row);
echo json_encode(['skips'=>$skips,'created'=>$created,'media'=>think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->count()]);
PHP);
        self::assertSame([true, true, true], $result['skips']);
        self::assertTrue($result['created']);
        self::assertSame(1, $result['media']);
    }

    public function testRejectedUpdatePreservesVideoEpisodesAndCollectionSnapshot(): void
    {
        $result = $this->cli(<<<'PHP'
$collect($row);
$id = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->value('id');
$before = [think\facade\Db::table('ffx_media')->where('id',$id)->find(),think\facade\Db::table('ffx_episodes')->where('media_id',$id)->select()->toArray(),think\facade\Db::table('ffx_external_refs')->where('entity_type','media')->where('entity_id',$id)->select()->toArray()];
$row['vod_play_from'] = 'unknown'; $row['vod_actor'] = 'must not write'; $skipped = false;
try { $collect($row); } catch (app\service\CollectionSkipException) { $skipped = true; }
$after = [think\facade\Db::table('ffx_media')->where('id',$id)->find(),think\facade\Db::table('ffx_episodes')->where('media_id',$id)->select()->toArray(),think\facade\Db::table('ffx_external_refs')->where('entity_type','media')->where('entity_id',$id)->select()->toArray()];
echo json_encode(['skipped'=>$skipped,'unchanged'=>$before===$after]);
PHP);
        self::assertTrue($result['skipped']);
        self::assertTrue($result['unchanged']);
    }

    public function testCollisionExportAndRecollectionKeepBothLinesAndEpisodeIds(): void
    {
        $result = $this->cli(<<<'PHP'
$collect($row); $second = $row; $second['vod_play_url']='第1集$https://example.invalid/second.m3u8'; $collect($second,1);
$id = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->value('id');
$before = think\facade\Db::table('ffx_episodes')->where('media_id',$id)->order('id')->column('id');
$firstExport = $export($id);
$collect($row); $collect($second,1);
$after = think\facade\Db::table('ffx_episodes')->where('media_id',$id)->order('id')->column('id');
echo json_encode(['export'=>$firstExport,'second_export'=>$export($id),'ids_unchanged'=>$before===$after,'episodes'=>count($after),'media'=>think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->count()]);
PHP);
        $key = $this->prefix . '-enabled';
        self::assertSame($key . '$$$' . $key, $result['export']['vod_play_from']);
        self::assertSame('第1集$https://example.invalid/valid.m3u8$$$第1集$https://example.invalid/second.m3u8', $result['export']['vod_play_url']);
        self::assertSame($result['export'], $result['second_export']);
        self::assertTrue($result['ids_unchanged']);
        self::assertSame(2, $result['episodes']);
        self::assertSame(1, $result['media']);
    }

    public function testHistoricalUnknownDisabledAndEmptyLinesAreExcludedAndMergeAliasesUseRealPlayer(): void
    {
        $result = $this->cli(<<<'PHP'
$collect($row);
$id = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->value('id');
foreach ([['unknown','unknown'],[$disabled,$disabled],[$key.'-empty',$key],[$key.'_merge_42',$key]] as [$lineKey,$parser]) {
    $line = think\facade\Db::table('ffx_play_sources')->insertGetId(['media_id'=>$id,'source_key'=>$lineKey,'parser_key'=>$parser,'display_name'=>$lineKey]);
    if (!str_ends_with($lineKey,'-empty')) think\facade\Db::table('ffx_episodes')->insert(['media_id'=>$id,'source_id'=>$line,'episode_no'=>1,'label'=>'第1集','media_url'=>'https://example.invalid/'.$line.'.m3u8']);
}
$before = $export($id);
think\facade\Db::table('ffx_players')->where('id',$fixture['players'][0])->update(['status'=>'disabled']);
$disabledExport = $export($id);
echo json_encode(['before'=>$before,'disabled'=>$disabledExport,'stored_lines'=>think\facade\Db::table('ffx_play_sources')->where('media_id',$id)->count()]);
PHP);
        $key = $this->prefix . '-enabled';
        self::assertSame($key . '$$$' . $key, $result['before']['vod_play_from']);
        self::assertSame(2, count(explode('$$$', $result['before']['vod_play_url'])));
        self::assertSame(['vod_play_from' => '', 'vod_play_url' => ''], $result['disabled']);
        self::assertSame(5, $result['stored_lines']);
    }

    public function testBackofficeValidatesPlayersAndAddingSamePlayerDoesNotReplaceExistingEpisodes(): void
    {
        $result = $this->cli(<<<'PHP'
$collect($row);
$id = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->value('id');
$oldEpisode = think\facade\Db::table('ffx_episodes')->where('media_id',$id)->find();
$request = $app->request; $request->setMethod('POST');
$controller = $app->make(app\controller\admin\Vod::class);
$sync = new ReflectionMethod($controller,'syncLegacyPlayback');
$input = ['playback_editor_present'=>1,'play_source_id'=>[0],'play_source_key'=>['unknown'],'play_source_name'=>['test'],'play_parser_key'=>['unknown'],'play_urls'=>['第1集$https://example.invalid/manual.m3u8']];
$request->withPost($input); $blocked = false;
try { think\facade\Db::transaction(fn()=>$sync->invoke($controller,$id)); } catch (think\exception\HttpException $e) { $blocked = $e->getStatusCode()===422; }
$input['play_source_key']=[$key]; $input['play_parser_key']=[$key]; $request->withPost($input);
think\facade\Db::transaction(fn()=>$sync->invoke($controller,$id));
$after = think\facade\Db::table('ffx_episodes')->where('id',$oldEpisode['id'])->find();
$token = $app->make(app\service\CsrfToken::class)->get();
$request->withPost(['_token'=>$token,'source_key'=>'unknown','parser_key'=>'unknown','status'=>'enabled']);
$playback = $app->make(app\controller\admin\Playback::class);
$denied = $playback->storeSource($id);
echo json_encode(['blocked'=>$blocked,'original_unchanged'=>$oldEpisode===$after,'denied_status'=>$denied->getCode(),'export'=>$export($id),'lines'=>think\facade\Db::table('ffx_play_sources')->where('media_id',$id)->count()]);
PHP);
        self::assertTrue($result['blocked']);
        self::assertTrue($result['original_unchanged']);
        self::assertSame(422, $result['denied_status']);
        self::assertSame(2, $result['lines']);
        self::assertSame($this->prefix . '-enabled$$$' . $this->prefix . '-enabled', $result['export']['vod_play_from']);
        self::assertEqualsCanonicalizing(['第1集$https://example.invalid/valid.m3u8', '第1集$https://example.invalid/manual.m3u8'], explode('$$$', $result['export']['vod_play_url']));
    }

    public function testManualMergeRetainsEpisodeIdsAndExportsOnlyTheRegisteredPlayer(): void
    {
        $result = $this->cli(<<<'PHP'
$collect($row);
$primary = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->value('id');
$child = $row; $child['vod_name']=$fixture['prefix'].' child'; $child['vod_play_url']='第1集$https://example.invalid/child.m3u8'; $collect($child,1);
$childId = (int)think\facade\Db::table('ffx_media')->where('category_id',$fixture['category'])->where('id','<>',$primary)->value('id');
think\facade\Db::table('ffx_media')->where('id',$childId)->update(['status'=>'draft']);
$before = think\facade\Db::table('ffx_episodes')->whereIn('media_id',[$primary,$childId])->order('id')->column('id');
$result = $app->make(app\service\MediaMerge::class)->merge([$primary,$childId]);
$after = think\facade\Db::table('ffx_episodes')->where('media_id',$primary)->order('id')->column('id');
echo json_encode(['result'=>$result,'ids_unchanged'=>$before===$after,'export'=>$export($primary),'archived'=>think\facade\Db::table('ffx_media')->where('id',$childId)->value('status')]);
PHP);
        self::assertTrue($result['ids_unchanged']);
        self::assertSame('archived', $result['archived']);
        self::assertSame(2, $result['result']['play_sources']);
        self::assertSame($this->prefix . '-enabled$$$' . $this->prefix . '-enabled', $result['export']['vod_play_from']);
        self::assertSame('第1集$https://example.invalid/valid.m3u8$$$第1集$https://example.invalid/child.m3u8', $result['export']['vod_play_url']);
    }
}
