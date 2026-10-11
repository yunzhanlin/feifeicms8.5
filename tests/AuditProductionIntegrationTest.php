<?php
declare(strict_types=1);
namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Only disposable audit_* schemas; never load the site's .env. */
final class AuditProductionIntegrationTest extends TestCase
{
    private array $env;
    private PDO $pdo;
    private string $prefix;
    protected function setUp(): void
    {
        $dsn=getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if(!$dsn || !getenv('FEIFEI_AUDIT_REDIS_HOST') || !getenv('FEIFEI_AUDIT_MEILI_HOST')) self::markTestSkipped('Disposable MySQL, Redis and Meilisearch required');
        if(!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D',$dsn,$db)) self::fail('Refusing non-audit database');
        preg_match('/host=([^;]+)/',$dsn,$host); preg_match('/;port=(\d+)/',$dsn,$port);
        $this->pdo=new PDO($dsn,getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root',getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->prefix='integrity-'.bin2hex(random_bytes(8)); $this->env=getenv();
        foreach(['ENV_NAME'=>'isolated-'.$this->prefix,'DB_HOST'=>$host[1],'DB_PORT'=>$port[1] ?? '3306','DB_NAME'=>$db[1],'DB_USER'=>getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root','DB_PASS'=>getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', 'CACHE_DRIVER'=>'file','SEARCH_DRIVER'=>'mysql'] as $key=>$value) $this->env['PHP_'.$key]=$value;
        $this->env['FF_INTEGRITY_PREFIX']=$this->prefix;
    }
    private function start(string $code): array
    {
        $boot="require 'vendor/autoload.php'; \$app=new think\\App(); \$app->initialize(); \$prefix=getenv('FF_INTEGRITY_PREFIX');\n";
        $process=proc_open([PHP_BINARY,'-r',$boot.$code],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__),$this->env);
        self::assertIsResource($process); return [$process,$pipes];
    }
    private function finish(array $job): array
    {
        [$process,$pipes]=$job; $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); self::assertSame(0,proc_close($process),$out.$err); return json_decode($out,true,512,JSON_THROW_ON_ERROR);
    }
    private function cli(string $code): array { return $this->finish($this->start($code)); }

    public function testRealEditorPreservesIdsMetadataAndForeignKeys(): void
    {
        $result=$this->cli(<<<'PHP'
use think\facade\Db;
$player=Db::table('ffx_players')->insertGetId(['player_key'=>$prefix,'name'=>$prefix,'status'=>'enabled']);
$media=Db::table('ffx_media')->insertGetId(['title'=>$prefix,'slug'=>$prefix,'status'=>'published']);
$source=Db::table('ffx_play_sources')->insertGetId(['media_id'=>$media,'source_key'=>$prefix,'parser_key'=>$prefix,'display_name'=>'test','status'=>'disabled']);
$episode=Db::table('ffx_episodes')->insertGetId(['media_id'=>$media,'source_id'=>$source,'episode_no'=>1,'label'=>'第1集','media_url'=>'https://example.invalid/test.m3u8?token=a$b','duration_seconds'=>321,'status'=>'disabled','metadata'=>'{"legacy_extra":"retained"}']);
$asset=Db::table('ffx_media_assets')->insertGetId(['media_id'=>$media,'episode_id'=>$episode,'asset_type'=>'subtitle','url'=>'https://example.invalid/a.srt']);
$history=Db::table('ffx_watch_history')->insertGetId(['media_id'=>$media,'episode_id'=>$episode]);
$comment=Db::table('ffx_comments')->insertGetId(['target_type'=>'media','target_id'=>$media,'episode_id'=>$episode,'content'=>'audit']);
$before=Db::table('ffx_episodes')->where('id',$episode)->find();
$app->request->withPost(['playback_editor_present'=>'1','play_source_id'=>[$source],'play_source_key'=>[$prefix],'play_source_name'=>['test'],'play_parser_key'=>[$prefix],'play_urls'=>['第1集$https://example.invalid/test.m3u8?token=a$b']]);
$controller=$app->make(app\controller\admin\Vod::class);
Db::transaction(fn()=> (new ReflectionMethod($controller,'syncLegacyPlayback'))->invoke($controller,$media));
echo json_encode(['before'=>$before,'after'=>Db::table('ffx_episodes')->where('id',$episode)->find(),'asset'=>Db::table('ffx_media_assets')->where('id',$asset)->count(),'history'=>Db::table('ffx_watch_history')->where('id',$history)->value('episode_id'),'comment'=>Db::table('ffx_comments')->where('id',$comment)->value('episode_id'),'source_status'=>Db::table('ffx_play_sources')->where('id',$source)->value('status')]);
Db::table('ffx_comments')->where('id',$comment)->delete(); Db::table('ffx_media')->where('id',$media)->delete(); Db::table('ffx_players')->where('id',$player)->delete();
PHP);
        self::assertSame($result['before'],$result['after']); self::assertSame(1,$result['asset']); self::assertSame((int)$result['before']['id'],(int)$result['history']); self::assertSame((int)$result['before']['id'],(int)$result['comment']); self::assertSame('disabled',$result['source_status']);
    }

    public function testRedisClearKeepsOtherPrefixesAndPartialButtonsStayLocal(): void
    {
        $result=$this->cli(<<<'PHP'
$host=getenv('FEIFEI_AUDIT_REDIS_HOST');
$raw=new Predis\Client(['host'=>$host]);
$raw->set($prefix.':foreign','keep');
$cache=new app\cache\PredisRedis(['host'=>$host,'prefix'=>$prefix.':site:']);
for($i=0;$i<1600;$i++) $cache->set('key'.$i,'remove');
$cache->clear();
echo json_encode(['foreign'=>$raw->get($prefix.':foreign'),'ours'=>$raw->keys($prefix.':site:*')]);
$raw->del([$prefix.':foreign']);
PHP);
        self::assertSame('keep',$result['foreign']); self::assertSame([],$result['ours']);
    }

    public function testParallelSourcesImportExactlyOneVideo(): void
    {
        $category=(int)$this->pdo->query("SELECT id FROM ffx_categories WHERE content_type='media' LIMIT 1")->fetchColumn();
        $sources=[];
        foreach(['a','b'] as $part) { $this->pdo->prepare("INSERT INTO ffx_collection_sources(name,endpoint,source_type,status) VALUES (?,?,'video','enabled')")->execute([$this->prefix.$part,'https://example.invalid/'.$this->prefix.$part]); $sources[]=(int)$this->pdo->lastInsertId(); }
        $this->pdo->prepare("INSERT INTO ffx_players(player_key,name,status) VALUES (?,?,'enabled')")->execute([$this->prefix,$this->prefix]); $player=(int)$this->pdo->lastInsertId();
        $gate=tempnam(sys_get_temp_dir(),'ff-gate-'); unlink($gate);
        $this->env['FF_INTEGRITY_GATE']=$gate; $this->env['FF_INTEGRITY_CATEGORY']=(string)$category;
        // This fixture widens the missing-row race deterministically. It exists
        // only in the disposable audit DB and is removed before any backup.
        $trigger='ffx_audit_delay_'.bin2hex(random_bytes(4));
        $this->pdo->exec('CREATE TRIGGER `'.$trigger.'` BEFORE INSERT ON ffx_media FOR EACH ROW DO SLEEP(0.2)');
        try {
            $code=<<<'PHP'
while(!is_file(getenv('FF_INTEGRITY_GATE'))) usleep(1000);
$runner=$app->make(app\service\CollectionRunner::class);
$row=['vod_id'=>7,'vod_name'=>$prefix,'vod_year'=>'2026','vod_area'=>'中国大陆','vod_lang'=>'国语','vod_play_from'=>$prefix,'vod_play_url'=>'第1集$https://example.invalid/a.m3u8'];
$created=(new ReflectionMethod($runner,'importMedia'))->invoke($runner,(int)getenv('FF_INTEGRITY_SOURCE'),$row,['1'=>(int)getenv('FF_INTEGRITY_CATEGORY')],(int)getenv('FF_INTEGRITY_CATEGORY'));
echo json_encode(['created'=>$created]);
PHP;
            $this->env['FF_INTEGRITY_SOURCE']=(string)$sources[0]; $first=$this->start($code);
            $this->env['FF_INTEGRITY_SOURCE']=(string)$sources[1]; $second=$this->start($code);
            file_put_contents($gate,'go'); $a=$this->finish($first); $b=$this->finish($second);
            self::assertSame(1,(int)$a['created']+(int)$b['created']);
            $query=$this->pdo->prepare('SELECT id FROM ffx_media WHERE title=?'); $query->execute([$this->prefix]); $ids=$query->fetchAll(PDO::FETCH_COLUMN); self::assertCount(1,$ids);
            $id=(int)$ids[0]; self::assertSame(2,(int)$this->pdo->query('SELECT COUNT(*) FROM ffx_play_sources WHERE media_id='.$id)->fetchColumn());
            self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM ffx_external_refs WHERE entity_type='media' AND entity_id=".$id)->fetchColumn());
        } finally {
            $this->pdo->exec('DROP TRIGGER IF EXISTS `'.$trigger.'`'); if(is_file($gate)) unlink($gate);
            $query=$this->pdo->prepare('DELETE FROM ffx_external_refs WHERE source_id IN (?,?)'); $query->execute($sources);
            $this->pdo->prepare('DELETE FROM ffx_media WHERE title=?')->execute([$this->prefix]);
            foreach($sources as $id) $this->pdo->exec('DELETE FROM ffx_collection_sources WHERE id='.$id);
            $this->pdo->exec('DELETE FROM ffx_players WHERE id='.$player);
        }
    }

    public function testRestoreClearsCacheAndForcesDatabaseSearchUntilFullRebuild(): void
    {
        $result=$this->cli(<<<'PHP'
use think\facade\Db;
$put=function($key,$value) { $row=['setting_key'=>$key,'setting_value'=>json_encode($value),'is_public'=>0]; if(Db::table('ffx_site_settings')->where('setting_key',$key)->count()) Db::table('ffx_site_settings')->where('setting_key',$key)->update($row); else Db::table('ffx_site_settings')->insert($row); };
$put('admin.cache.search_driver','meilisearch'); $put('admin.cache.search_host',getenv('FEIFEI_AUDIT_MEILI_HOST')); $put('admin.cache.search_index',$prefix); $put('admin.cache.search_fallback','none'); $put('admin.base.site_name','A-站点-'.$prefix);
$settings=$app->make(app\service\SiteSettings::class); $settings->forget();
$media=Db::table('ffx_media')->insertGetId(['title'=>'A-剧集-'.$prefix,'slug'=>$prefix,'status'=>'published']);
$indexer=$app->make(app\service\SearchIndexer::class); $indexer->sync(1);
$backupDir=root_path().'runtime/backups'; if(!is_dir($backupDir)) mkdir($backupDir,0700,true);
$name='feifeicms-v4-20261011-120000-'.bin2hex(random_bytes(3)).'.sql'; $path=$backupDir.'/'.$name;
$cfg=config('database.connections.mysql'); $pdo=new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['hostport'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password']);
$tables=array_column(Db::query("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'ffx\\_%'"),'name');
$handle=fopen($path,'wb'); (new app\service\MySqlBackup())->dump($pdo,$handle,$tables); fclose($handle);
Db::table('ffx_media')->where('id',$media)->update(['title'=>'B-剧集-'.$prefix]); $put('admin.base.site_name','B-站点-'.$prefix); $settings->forget(); $indexer->sync(1);
$cache=$app->make(app\service\ResilientCache::class); $cache->set('audit:restore','stale',600); $settings->string('admin.base.site_name');
$csrf=$app->make(app\service\CsrfToken::class); $app->request->withPost(['confirm'=>'RESTORE','_token'=>$csrf->get()]);
$app->make(app\controller\admin\Database::class)->restore($name);
$marker=new app\service\SearchConsistency(); $marked=$marker->generation()!=='';
$after=$app->make(app\service\search\SearchManager::class)->search('A-剧集-'.$prefix,1,20);
$values=['marker'=>$marked,'cached'=>$cache->get('audit:restore'),'name'=>$settings->string('admin.base.site_name'),'driver'=>$after->driver,'total'=>$after->total];
$indexer->sync(1); $values['rebuilt']=$marker->generation()===''; $values['indexed']=$app->make(app\service\search\SearchManager::class)->search('A-剧集-'.$prefix,1,20)->driver;
echo json_encode($values);
unlink($path); Db::table('ffx_media')->where('id',$media)->delete(); $app->make(app\service\MeilisearchClientFactory::class)->client()->deleteIndex($prefix);
foreach(['admin.cache.search_driver','admin.cache.search_host','admin.cache.search_index','admin.cache.search_fallback','admin.base.site_name'] as $key) Db::table('ffx_site_settings')->where('setting_key',$key)->delete(); $settings->forget();
PHP);
        self::assertTrue($result['marker']); self::assertNull($result['cached']); self::assertSame('A-站点-'.$this->prefix,$result['name']); self::assertSame('mysql',$result['driver']); self::assertSame(1,$result['total']); self::assertTrue($result['rebuilt']); self::assertSame('meilisearch',$result['indexed']);
    }

    public function testVipRenewalPreservesUtcRemainderAndConfirmIsIdempotent(): void
    {
        $result=$this->cli(<<<'PHP'
use think\facade\Db;
date_default_timezone_set('Asia/Shanghai');
$expires=time()+3600;
$user=Db::table('ffx_users')->insertGetId(['username'=>$prefix,'password_hash'=>'fixture','expires_at'=>gmdate('Y-m-d H:i:s',$expires)]);
$order=Db::table('ffx_orders')->insertGetId(['order_no'=>$prefix,'user_id'=>$user,'product_type'=>'vip','quantity'=>1,'amount'=>'1.00']);
$settings=$app->make(app\service\SiteSettings::class);
(new ReflectionProperty($settings,'values'))->setValue($settings,['admin.pay.pay_enabled'=>'1','admin.pay.user_pay_vip_ext'=>'30']);
$access=$app->make(app\service\MediaAccess::class)->status(['access_mode'=>'member'],$user);
$csrf=$app->make(app\service\CsrfToken::class); $app->request->withPost(['_token'=>$csrf->get(),'status'=>'confirmed']);
$billing=$app->make(app\controller\admin\Billing::class); $billing->updateOrder($order);
$first=Db::table('ffx_users')->where('id',$user)->value('expires_at'); $billing->updateOrder($order);
$second=Db::table('ffx_users')->where('id',$user)->value('expires_at');
echo json_encode(['allowed'=>$access['allowed'],'expected'=>$expires+30*86400,'actual'=>app\service\UtcClock::timestamp($first),'same'=>$first===$second]);
Db::table('ffx_audit_logs')->where('target_type','order')->where('target_id',(string)$order)->delete(); Db::table('ffx_orders')->where('id',$order)->delete(); Db::table('ffx_users')->where('id',$user)->delete();
PHP);
        self::assertTrue($result['allowed']); self::assertSame($result['expected'],$result['actual']); self::assertTrue($result['same']);
    }
}
