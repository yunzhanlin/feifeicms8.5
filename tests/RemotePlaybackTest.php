<?php
declare(strict_types=1);
namespace tests;

use app\service\{FrontendCache, MediaUrlGuard, PlayerResolver, PublicJsonClient, RemoteDanmaku, ResilientCache, SafeRemoteUrl, SiteSettings};
use GuzzleHttp\{Client, HandlerStack, Middleware};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\{RunTestsInSeparateProcesses, PreserveGlobalState};
use PHPUnit\Framework\TestCase;
use think\facade\Db;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RemotePlaybackTest extends TestCase
{
    private SiteSettings $settings;
    private array $requests=[];
    private string $database;

    protected function setUp(): void
    {
        putenv('PHP_ENV_NAME=isolated-remote-unit'); $app=new \think\App(); $app->initialize();
        $this->database=tempnam(sys_get_temp_dir(),'ff-remote-');
        $app->config->set(['default'=>'audit','connections'=>['audit'=>['type'=>'sqlite','database'=>$this->database,'prefix'=>'','fields_strict'=>true,'auto_timestamp'=>false]]], 'database');
        $app->config->set(['default'=>'file','stores'=>['file'=>['type'=>'File','path'=>sys_get_temp_dir().'/ff-remote-'.bin2hex(random_bytes(8))]]], 'cache');
        $app->instance('cache',new \think\Cache($app));
        Db::connect()->connect()->exec('CREATE TABLE ffx_players(player_key TEXT,name TEXT,status TEXT,parser_url TEXT,config TEXT)');
        $this->settings=new SiteSettings(new FrontendCache(new ResilientCache()));
        $this->values(['admin.player.parser_allowlist'=>'8.8.8.8']);
    }

    protected function tearDown(): void { if(isset($this->database) && is_file($this->database)) unlink($this->database); }

    private function values(array $values): void { (new \ReflectionProperty($this->settings,'values'))->setValue($this->settings,$values); }
    private function http(array $responses): PublicJsonClient
    {
        $stack=HandlerStack::create(new MockHandler($responses)); $stack->push(Middleware::history($this->requests));
        return new PublicJsonClient(new SafeRemoteUrl(),new Client(['handler'=>$stack]));
    }
    private function player(array $config=[], string $endpoint='https://8.8.8.8/parse'): void
    {
        Db::table('ffx_players')->insert(['player_key'=>'audit','name'=>'audit','status'=>'enabled','parser_url'=>$endpoint,'config'=>json_encode($config)]);
    }

    public function testJsonParserUsesRegisteredPlayerEndpointAndKeepsSignedUrl(): void
    {
        $this->player(); $http=$this->http([new Response(200,[],json_encode(['code'=>200,'data'=>['url'=>'https://example.invalid/stream?token=a$b','type'=>'m3u8']]))]);
        $resolver=new PlayerResolver($this->settings,$http,new MediaUrlGuard(),new SafeRemoteUrl());
        self::assertSame(['url'=>'https://example.invalid/stream?token=a$b','type'=>'m3u8'],$resolver->resolve('audit','https://example.invalid/platform/16'));
        parse_str($this->requests[0]['request']->getUri()->getQuery(),$query); self::assertSame('https://example.invalid/platform/16',$query['url']);
        self::assertFalse($this->requests[0]['options']['allow_redirects']);
    }

    public function testNoExtensionDirectTypeRequiresExplicitPlayerConfiguration(): void
    {
        $this->player(['direct_type'=>'m3u8'],'');
        $resolver=new PlayerResolver($this->settings,$this->http([]),new MediaUrlGuard(),new SafeRemoteUrl());
        self::assertSame(['url'=>'https://example.invalid/no-extension','type'=>'m3u8'],$resolver->resolve('audit','https://example.invalid/no-extension')); self::assertCount(0,$this->requests);
    }

    public function testIframeParserRetainsQueryAndPublicSameOriginBoundary(): void
    {
        $this->player(['parser_mode'=>'iframe']); $resolver=new PlayerResolver($this->settings,$this->http([]),new MediaUrlGuard(),new SafeRemoteUrl());
        $data=$resolver->resolve('audit','https://example.invalid/video'); self::assertSame('iframe',$data['type']); self::assertSame('https://8.8.8.8',PlayerResolver::origin($data['url']));
    }

    public function testUnknownPlayerAndPrivateEndpointAreRejected(): void
    {
        $resolver=new PlayerResolver($this->settings,$this->http([]),new MediaUrlGuard(),new SafeRemoteUrl());
        try { $resolver->resolve('missing','https://example.invalid/a.m3u8'); self::fail('unknown accepted'); } catch (\RuntimeException $e) { self::assertNotSame('',$e->getMessage()); }
        $this->player([], 'http://127.0.0.1/private'); $this->values(['admin.player.parser_allowlist'=>'127.0.0.1']);
        $this->expectException(\RuntimeException::class); $resolver->resolve('audit','https://example.invalid/platform');
    }

    public function testHttpErrorsOversizedResponsesAndInvalidJsonAreNotSuccess(): void
    {
        foreach([new Response(301,['Location'=>'https://evil.invalid']),new Response(200,[],str_repeat('x',101)),new Response(200,[],'not json')] as $response) {
            try { $this->http([$response])->get('https://8.8.8.8/data',[],[],1,100); self::fail('invalid accepted'); } catch (\RuntimeException|\JsonException $error) { self::assertNotSame('',$error->getMessage()); }
        }
    }

    public function testRemoteDanmakuUsesConfigurationTokenLimitsAndCache(): void
    {
        $this->values(['admin.player.player_remote_danmu_enabled'=>'1','admin.player.player_remote_danmu_api'=>'https://8.8.8.8/danmaku','admin.player.player_remote_danmu_token'=>'fixture-token','admin.player.player_remote_danmu_max'=>1]);
        $http=$this->http([new Response(200,[],json_encode(['data'=>[[1,0,'#FF0000','user','hello'],[2,1,'#FFFFFF','user','second']]]))]);
        $remote=new RemoteDanmaku($this->settings,$http,new ResilientCache()); $first=$remote->fetch('audit title',2); self::assertCount(1,$first); self::assertSame('hello',$first[0]['text']);
        self::assertSame($first,$remote->fetch('audit title',2)); self::assertCount(1,$this->requests); self::assertSame('Bearer fixture-token',$this->requests[0]['request']->getHeaderLine('Authorization'));
        parse_str($this->requests[0]['request']->getUri()->getQuery(),$query); self::assertSame('2',$query['episode']);
    }

    public function testRemoteMatchCannotForwardTokenToAnotherOrigin(): void
    {
        $this->values(['admin.player.player_remote_danmu_enabled'=>'1','admin.player.player_remote_danmu_api'=>'https://8.8.8.8/danmaku','admin.player.player_remote_danmu_token'=>'fixture-token']);
        $remote=new RemoteDanmaku($this->settings,$this->http([new Response(200,[],json_encode(['danmaku_url'=>'https://1.1.1.1/data']))]),new ResilientCache());
        try { $remote->fetch('audit',1); self::fail('cross-origin allowed'); } catch (\RuntimeException $e) { self::assertCount(1,$this->requests); }
    }

    public function testRemoteDisabledAndMalformedRowsDoNotProduceFalseDanmaku(): void
    {
        self::assertSame([], (new RemoteDanmaku($this->settings,$this->http([]),new ResilientCache()))->fetch('audit',1));
        self::assertSame([],RemoteDanmaku::normalize([['text'=>'','time'=>0],['text'=>'bad','time'=>-1],['text'=>'bad','time'=>INF]],100));
    }

    public function testRemoteTupleFormatsAndIntegerColors(): void
    {
        $rows=RemoteDanmaku::normalize([[1,0,16711680,'four'],[2,1,'#00FF00','user','five']],10);
        self::assertSame(['four','five'],array_column($rows,'text'));
        self::assertSame(['#FF0000','#00FF00'],array_column($rows,'color'));
    }
}
