<?php
declare(strict_types=1);
namespace tests;

use app\service\{AuditLogger, CommentPolicy, CommentReplies, FrontendCache, LocalRedirect, MediaAccess, PlaybackEditor, ResilientCache, SearchConsistency, SiteSettings, UserSession, UtcClock};
use PHPUnit\Framework\Attributes\{RunTestsInSeparateProcesses, PreserveGlobalState};
use PHPUnit\Framework\TestCase;
use think\facade\{Db, Session};

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuditIntegrityTest extends TestCase
{
    private \think\App $app;
    private \PDO $pdo;
    private string $database;

    protected function setUp(): void
    {
        putenv('PHP_ENV_NAME=isolated-audit-unit');
        $this->app = new \think\App(); $this->app->initialize();
        $this->database=tempnam(sys_get_temp_dir(),'ff-audit-');
        $this->app->config->set(['default'=>'audit','connections'=>['audit'=>['type'=>'sqlite','database'=>$this->database,'prefix'=>'','fields_strict'=>true,'auto_timestamp'=>false]]], 'database');
        $this->app->config->set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/ff-audit-'.bin2hex(random_bytes(8))]]], 'cache');
        // ORM boot resolves the original cache driver before these overrides.
        // Replace the manager too, so cold-cache tests cannot reuse site caches.
        $this->app->instance('cache',new \think\Cache($this->app));
        $this->pdo = Db::connect()->connect(); $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    protected function tearDown(): void { if(isset($this->database) && is_file($this->database)) unlink($this->database); }

    private function settings(array $values): SiteSettings
    {
        $settings = new SiteSettings(new FrontendCache(new ResilientCache()));
        (new \ReflectionProperty($settings, 'values'))->setValue($settings, $values);
        return $settings;
    }

    private function episodes(): void
    {
        $this->pdo->exec("CREATE TABLE ffx_episodes(id INTEGER PRIMARY KEY AUTOINCREMENT,media_id INTEGER,source_id INTEGER,season_id INTEGER,episode_no INTEGER,label TEXT,media_url TEXT,sort_order INTEGER,duration_seconds INTEGER,status TEXT,published_at TEXT,created_at TEXT,updated_at TEXT,metadata TEXT, UNIQUE(source_id,episode_no));
            CREATE TABLE assets(id INTEGER PRIMARY KEY,episode_id INTEGER REFERENCES ffx_episodes(id) ON DELETE CASCADE);
            CREATE TABLE history(id INTEGER PRIMARY KEY,episode_id INTEGER REFERENCES ffx_episodes(id) ON DELETE SET NULL);
            INSERT INTO ffx_episodes VALUES(1,1,1,9,1,'第1集','https://example.invalid/1.m3u8?token=a\$b',1,123,'disabled','2026-01-01','2026-01-01','2026-01-01','{\"legacy_extra\":\"old\"}');
            INSERT INTO ffx_episodes VALUES(2,1,1,9,2,'第2集','https://example.invalid/2.m3u8',2,234,'enabled','2026-01-01','2026-01-01','2026-01-01',NULL);
            INSERT INTO assets VALUES(1,1); INSERT INTO history VALUES(1,1);");
    }

    public function testUnchangedPlaybackRetainsEveryColumnAndAssociation(): void
    {
        $this->episodes(); $before = Db::table('ffx_episodes')->order('id')->select()->toArray();
        $editor = new PlaybackEditor();
        $items = $editor->parse('第1集$https://example.invalid/1.m3u8?token=a$b'."\n".'第2集$https://example.invalid/2.m3u8');
        Db::transaction(fn()=> $editor->sync(1,1,$items));
        self::assertSame($before, Db::table('ffx_episodes')->order('id')->select()->toArray());
        self::assertSame(1, Db::table('assets')->count()); self::assertSame(1, (int) Db::table('history')->value('episode_id'));
    }

    public function testReorderingAndRemovingTextDoNotDestroyEpisodeIdentity(): void
    {
        $this->episodes(); $editor = new PlaybackEditor();
        Db::transaction(fn()=> $editor->sync(1,1,$editor->parse('第2集$https://example.invalid/2.m3u8'."\n".'第1集$https://example.invalid/1.m3u8?token=a$b')));
        self::assertSame(2, (int) Db::table('ffx_episodes')->where('id',1)->value('episode_no'));
        self::assertSame(1, (int) Db::table('ffx_episodes')->where('id',2)->value('episode_no'));
        Db::transaction(fn()=> $editor->sync(1,1,$editor->parse('第2集$https://example.invalid/2.m3u8')));
        self::assertSame(2, Db::table('ffx_episodes')->count()); self::assertSame(1, Db::table('assets')->count());
        Db::transaction(fn()=> $editor->sync(1,1,$editor->parse('第2集$https://example.invalid/2.m3u8'), true));
        self::assertSame(1, Db::table('ffx_episodes')->count()); self::assertSame(0, Db::table('assets')->count());
    }

    public function testOversizedPlaybackIsRejectedBeforeMutation(): void
    {
        $this->episodes();
        try { (new PlaybackEditor())->parse(str_repeat("第1集\$https://example.invalid/a.m3u8\n",1001)); self::fail('must reject'); }
        catch (\think\exception\HttpException $error) { self::assertSame(422,$error->getStatusCode()); }
        self::assertSame(2,Db::table('ffx_episodes')->count());
    }

    public function testSettingsFailureDoesNotOpenPaidContentOrCacheFailure(): void
    {
        $settings = new SiteSettings(new FrontendCache(new ResilientCache()));
        try { (new MediaAccess($settings))->status(['access_mode'=>'member'],0); self::fail('must fail closed'); }
        catch (\think\exception\HttpException $error) { self::assertSame(503,$error->getStatusCode()); }
        $this->pdo->exec('CREATE TABLE ffx_site_settings(setting_key TEXT,setting_value TEXT);');
        $this->pdo->exec("INSERT INTO ffx_site_settings VALUES('admin.pay.pay_enabled','\"1\"')");
        self::assertFalse((new MediaAccess($settings))->status(['access_mode'=>'member'],0)['allowed']);
    }

    public function testLoaderExceptionRunsOnceAndDoesNotBecomeFallback(): void
    {
        $calls=0;
        try { (new ResilientCache())->remember('throw-'.bin2hex(random_bytes(8)),30,function() use (&$calls) { $calls++; throw new \RuntimeException('business failure'); }); self::fail('must throw'); }
        catch (\RuntimeException $error) { self::assertSame('business failure',$error->getMessage()); }
        self::assertSame(1,$calls);
    }

    public function testUtcExpiryAndLocalFormRoundTrip(): void
    {
        date_default_timezone_set('Asia/Shanghai'); $value=gmdate('Y-m-d H:i:s',time()+3600);
        self::assertEqualsWithDelta(time()+3600,UtcClock::timestamp($value),1);
        self::assertSame('2026-10-11 02:30:00',UtcClock::fromLocalInput('2026-10-11T10:30'));
        self::assertSame('2026-10-11T10:30',UtcClock::localInput('2026-10-11 02:30:00'));
        date_default_timezone_set('UTC'); self::assertSame('2026-10-11 10:30:00',UtcClock::fromLocalInput('2026-10-11T10:30'));
    }

    public function testUserSessionRevocationRejectsBanArchiveAndPasswordChange(): void
    {
        $this->pdo->exec("CREATE TABLE ffx_users(id INTEGER PRIMARY KEY,username TEXT,password_hash TEXT,session_version INTEGER,status TEXT,deleted_at TEXT); INSERT INTO ffx_users VALUES(1,'audit','fake-hash',1,'active',NULL)");
        $user=Db::table('ffx_users')->where('id',1)->find(); UserSession::establish($user); self::assertSame(1,UserSession::id());
        foreach ([['status'=>'disabled'],['deleted_at'=>'2026-10-11'],['password_hash'=>'changed'],['session_version'=>2]] as $mutation) {
            Db::table('ffx_users')->where('id',1)->update($user); UserSession::establish($user);
            Db::table('ffx_users')->where('id',1)->update($mutation);
            try { UserSession::id(); self::fail('old session accepted'); } catch (\think\exception\HttpException $error) { self::assertSame(401,$error->getStatusCode()); }
            self::assertNull(Session::get('user_id'));
        }
    }

    public function testAuditRedactsOldAndNewNestedCredentials(): void
    {
        $this->pdo->exec('CREATE TABLE ffx_audit_logs(admin_id INTEGER,action TEXT,target_type TEXT,target_id TEXT,before_data TEXT,after_data TEXT,ip_address TEXT,request_id TEXT,result TEXT,created_at TEXT)');
        $logger=new AuditLogger(); $logger->record('settings.update','settings','1',['smtp_password'=>'old-secret','nested'=>['api_key'=>'old-key']],['cookie'=>'new-cookie','name'=>'kept']);
        $encoded=json_encode(Db::table('ffx_audit_logs')->where('action','settings.update')->find());
        foreach(['old-secret','old-key','new-cookie'] as $secret) self::assertStringNotContainsString($secret,$encoded);
        self::assertStringContainsString('kept',$encoded);
    }

    public function testHistoricalAuditRedactionIsPreviewableAndIdempotent(): void
    {
        $this->pdo->exec('CREATE TABLE ffx_audit_logs(id INTEGER PRIMARY KEY,before_data TEXT,after_data TEXT)');
        Db::table('ffx_audit_logs')->insert(['id'=>1,'before_data'=>json_encode(['admin.mail.smtp_password'=>'old-dummy-secret','site_name'=>'keep']),'after_data'=>json_encode(['nested'=>['cookie'=>'dummy-cookie']])]);
        $redactor=new \app\service\AuditLogRedactor();
        self::assertSame(1,$redactor->run(false)['changed']);
        self::assertStringContainsString('old-dummy-secret',(string)Db::table('ffx_audit_logs')->where('id',1)->value('before_data'));
        self::assertSame(1,$redactor->run(true)['changed']);
        $json=json_encode(Db::table('ffx_audit_logs')->where('id',1)->find());
        self::assertStringNotContainsString('old-dummy-secret',$json);
        self::assertStringNotContainsString('dummy-cookie',$json);
        self::assertStringContainsString('keep',$json);
        self::assertSame(0,$redactor->run(true)['changed']);
    }

    public function testRedirectEncodingVariantsStayOnSite(): void
    {
        foreach(['//evil.invalid','/%5Cevil.invalid','/%255cevil.invalid','/\\evil.invalid','/\x00evil.invalid','https://evil.invalid'] as $url) self::assertSame('/',LocalRedirect::allow($url,'/'));
        self::assertSame('/user/center?q=hello',LocalRedirect::allow('/user/center?q=hello','/'));
    }

    public function testCommentPolicySharedRulesAndBoundedReplies(): void
    {
        $policy=new CommentPolicy($this->settings(['admin.comments.guest_enabled'=>'0']));
        try { $policy->content('hello','8.8.8.8',0); self::fail('guest allowed'); } catch (\think\exception\HttpException $e) { self::assertSame(401,$e->getStatusCode()); }
        $policy=new CommentPolicy($this->settings(['admin.comments.blocked_ips'=>'8.8.8.8','admin.comments.user_replace'=>'bad']));
        try { $policy->content('hello','8.8.8.8',1); self::fail('blocked IP allowed'); } catch (\think\exception\HttpException $e) { self::assertSame(403,$e->getStatusCode()); }
        self::assertSame('***', $policy->content('BAD','1.1.1.1',1));
        $this->pdo->exec('CREATE TABLE ffx_comments(id INTEGER PRIMARY KEY,parent_id INTEGER,target_type TEXT,status TEXT,content TEXT,created_at TEXT,author_name TEXT,deleted_at TEXT)');
        for($i=1;$i<=100;$i++) Db::table('ffx_comments')->insert(['id'=>$i,'parent_id'=>999,'target_type'=>'site','status'=>'approved','content'=>'hello','created_at'=>'2026-10-11','author_name'=>'audit']);
        $rows=CommentReplies::recent([999]); self::assertCount(20,$rows[999]); self::assertSame(81,(int)$rows[999][0]['id']);
    }

    public function testRestoreMarkerSurvivesWrongGenerationAndCompletesOnlyMatchingSync(): void
    {
        $dir=sys_get_temp_dir().'/ff-marker-'.bin2hex(random_bytes(8)); mkdir($dir,0700);
        try { $marker=new SearchConsistency($dir); $marker->requireRebuild(); $first=$marker->generation(); $marker->requireRebuild(); $second=$marker->generation(); self::assertNotSame($first,$second); $marker->complete($first); self::assertSame($second,$marker->generation()); $marker->complete($second); self::assertSame('',$marker->generation()); }
        finally { if(is_file($dir.'/search-rebuild.required')) unlink($dir.'/search-rebuild.required'); rmdir($dir); }
    }

    public function testPartialScenarioResponsesKeepEarlierEpisodesAndLocalEdits(): void
    {
        $this->pdo->exec('CREATE TABLE ffx_scenarios(id INTEGER PRIMARY KEY AUTOINCREMENT,media_id INTEGER,episode_no INTEGER,title TEXT,content TEXT,source_ref TEXT,status TEXT,updated_at TEXT,deleted_at TEXT,sort_order INTEGER,created_at TEXT, UNIQUE(media_id,episode_no))');
        $runner=(new \ReflectionClass(\app\service\CollectionRunner::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($runner,'normalizer'))->setValue($runner,new \app\service\CollectionPayloadNormalizer());
        $sync=new \ReflectionMethod($runner,'syncScenarioPayload');
        $one=['episode_no'=>1,'title'=>'第1集','content'=>'one']; $two=['episode_no'=>2,'title'=>'第2集','content'=>'two'];
        self::assertSame(2,$sync->invoke($runner,1,[$one,$two],1,'remote'));
        $ids=Db::table('ffx_scenarios')->column('id','episode_no');
        $two['content']='changed'; $sync->invoke($runner,1,[$two],1,'remote'); $sync->invoke($runner,1,[],1,'remote');
        self::assertSame($ids,Db::table('ffx_scenarios')->column('id','episode_no'));
        self::assertSame('one',Db::table('ffx_scenarios')->where('episode_no',1)->value('content'));
        Db::table('ffx_scenarios')->where('episode_no',1)->update(['source_ref'=>'local','content'=>'local edit']);
        $sync->invoke($runner,1,[$one],1,'remote'); self::assertSame('local edit',Db::table('ffx_scenarios')->where('episode_no',1)->value('content'));
    }

    public function testLegacyReverseParentsAcrossBatchesAndChecksumSkip(): void
    {
        $this->pdo->exec('CREATE TABLE ffx_categories(id INTEGER PRIMARY KEY AUTOINCREMENT,parent_id INTEGER,content_type TEXT,name TEXT,slug TEXT,description TEXT,seo_title TEXT,seo_keywords TEXT,filter_options TEXT,sort_order INTEGER,status TEXT,updated_at TEXT,deleted_at TEXT,created_at TEXT);
            CREATE TABLE ffx_legacy_map(entity_type TEXT,legacy_id INTEGER,new_id INTEGER,checksum TEXT,parent_legacy_id INTEGER,migrated_at TEXT,PRIMARY KEY(entity_type,legacy_id))');
        $migrator=new \app\plugin\Legacy43\Legacy43Migrator(new \app\service\EpisodeParser(),new FrontendCache(new ResilientCache()),new \app\service\CollectionPayloadNormalizer());
        $method=new \ReflectionMethod($migrator,'migrateCategories');
        $child=['list_id'=>1,'list_pid'=>2,'list_name'=>'child','list_dir'=>'child']; $parent=['list_id'=>2,'list_pid'=>3,'list_name'=>'parent','list_dir'=>'parent']; $root=['list_id'=>3,'list_pid'=>0,'list_name'=>'root','list_dir'=>'root'];
        foreach([$child,$parent,$root] as $row) Db::transaction(fn()=> $method->invoke($migrator,$row));
        $ids=Db::table('ffx_legacy_map')->column('new_id','legacy_id');
        self::assertSame((int)$ids[2],(int)Db::table('ffx_categories')->where('id',$ids[1])->value('parent_id'));
        self::assertSame((int)$ids[3],(int)Db::table('ffx_categories')->where('id',$ids[2])->value('parent_id'));
        self::assertSame('skipped',$method->invoke($migrator,$child));
        self::assertSame((int)$ids[2],(int)Db::table('ffx_categories')->where('id',$ids[1])->value('parent_id'));
    }
}
