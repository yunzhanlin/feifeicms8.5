<?php
declare(strict_types=1);

namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

/** Uses disposable audit_* databases only; no live upstream requests. */
final class CollectionMetadataIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private array $env = [];
    private int $mediaId = 0;
    private int $sourceId = 0;

    protected function setUp(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn || !function_exists('proc_open')) self::markTestSkipped('Disposable MySQL and local CLI required');
        if (!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D', $dsn, $database)) self::fail('Refusing a non-audit database');
        preg_match('/host=([^;]+)/', $dsn, $host); preg_match('/;port=(\d+)/', $dsn, $port);
        $this->pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->env = getenv();
        $this->env['PHP_ENV_NAME'] = 'isolated-metadata-' . bin2hex(random_bytes(8));
        $this->env['PHP_DB_HOST'] = $host[1]; $this->env['PHP_DB_PORT'] = $port[1] ?? '3306';
        $this->env['PHP_DB_NAME'] = $database[1]; $this->env['PHP_DB_USER'] = getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root';
        $this->env['PHP_DB_PASS'] = getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '';
        $this->env['PHP_CACHE_DRIVER'] = 'file'; $this->env['PHP_SEARCH_DRIVER'] = 'mysql';
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare("INSERT INTO ffx_collection_sources(name,endpoint,source_type,status) VALUES (?,?,'maccms_json','enabled')")->execute(['metadata-' . $suffix, 'https://example.invalid/' . $suffix]);
        $this->sourceId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO ffx_media(title,slug,status,metadata,updated_at) VALUES (?,?,'published',?,'2026-01-01 00:00:00')")->execute(['metadata-' . $suffix, 'metadata-' . $suffix, '{"actor":"","director":null,"keywords":"","inputer":"json_2"}']);
        $this->mediaId = (int) $this->pdo->lastInsertId();
        $payload = ['vod_id' => 7, 'vod_name' => '测试视频', 'type_id' => 1, 'vod_actor' => '演员甲,演员乙', 'vod_director' => '导演甲', 'vod_keywords' => '', 'vod_tag' => '悬疑', 'vod_class' => '剧情', 'vod_pubdate' => '2026-10-01'];
        $this->env['FF_AUDIT_METADATA_PAYLOAD'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $this->env['FF_AUDIT_METADATA_SOURCE'] = (string) $this->sourceId;
        $this->env['FF_AUDIT_METADATA_ID'] = (string) $this->mediaId;
        $this->pdo->prepare("INSERT INTO ffx_external_refs(source_id,entity_type,entity_id,provider,external_id,payload) VALUES (?,'media',?,?,?,?)")->execute([$this->sourceId, $this->mediaId, 'collection-' . $this->sourceId, '7', $this->env['FF_AUDIT_METADATA_PAYLOAD']]);
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) return;
        if ($this->mediaId) {
            $this->pdo->prepare('DELETE FROM ffx_external_refs WHERE entity_type=\'media\' AND entity_id=?')->execute([$this->mediaId]);
            $this->pdo->prepare('DELETE FROM ffx_media WHERE id=?')->execute([$this->mediaId]);
        }
        if ($this->sourceId) $this->pdo->prepare('DELETE FROM ffx_collection_sources WHERE id=?')->execute([$this->sourceId]);
    }

    private function cli(array $arguments): string
    {
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $this->env);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $out . $err);
        return $out;
    }

    public function testRecollectionFillsExistingBlankFieldsAndFrontendAndProviderReceiveThem(): void
    {
        // Import the linked record directly to test the real transaction without network I/O.
        $result = json_decode($this->cli(['-r', <<<'PHP'
require 'vendor/autoload.php';
$app = new think\App(); $app->initialize();
$category = think\facade\Db::table('ffx_categories')->insertGetId(['name'=>'audit metadata','slug'=>'audit-metadata-'.bin2hex(random_bytes(8)),'content_type'=>'media']);
try {
    $runner = $app->make(app\service\CollectionRunner::class);
    $method = new ReflectionMethod($runner, 'importMedia');
    $created = $method->invoke($runner, (int)getenv('FF_AUDIT_METADATA_SOURCE'), json_decode(getenv('FF_AUDIT_METADATA_PAYLOAD'),true), ['1'=>$category], $category);
    $row = think\facade\Db::table('ffx_media')->where('id',(int)getenv('FF_AUDIT_METADATA_ID'))->find();
    $frontend = $app->make(app\service\FrontendData::class)->vod($row + ['category_name'=>'audit metadata']);
    $provider = (new ReflectionClass(app\controller\api\VodProvider::class))->newInstanceWithoutConstructor();
    $export = (new ReflectionMethod($provider,'mapBase'))->invoke($provider,$row);
    echo json_encode(['created'=>$created,'frontend'=>$frontend,'export'=>$export], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} finally {
    think\facade\Db::table('ffx_categories')->where('id',$category)->delete();
}
PHP]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($result['created']);
        foreach (['frontend', 'export'] as $side) {
            self::assertSame('演员甲,演员乙', $result[$side]['vod_actor']);
            self::assertSame('导演甲', $result[$side]['vod_director']);
            self::assertSame('悬疑', $result[$side]['vod_keywords']);
            self::assertSame('2026-10-01', $result[$side]['vod_pubdate']);
        }
    }

    public function testSnapshotCommandIsDryRunByDefaultBacksUpAndIsIdempotent(): void
    {
        $this->pdo->prepare("INSERT INTO ffx_play_sources(media_id,source_key,parser_key,display_name) VALUES (?,'fixture','m3u8','本地线路')")->execute([$this->mediaId]);
        $lineId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO ffx_episodes(media_id,source_id,episode_no,label,media_url) VALUES (?,?,1,'第1集','https://example.invalid/local.m3u8')")->execute([$this->mediaId, $lineId]);
        $episodeId = (int) $this->pdo->lastInsertId();
        $command = ['think', 'feifei:collection:repair-metadata'];
        $preview = json_decode($this->cli($command), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('dry-run', $preview['mode']);
        self::assertNull($preview['backup']);
        self::assertNull($this->pdo->query('SELECT release_date FROM ffx_media WHERE id=' . $this->mediaId)->fetchColumn());
        $applied = json_decode($this->cli(array_merge($command, ['--apply'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('apply', $applied['mode']);
        self::assertSame('2026-10-01', $this->pdo->query('SELECT release_date FROM ffx_media WHERE id=' . $this->mediaId)->fetchColumn());
        self::assertSame('2026-01-01 00:00:00.000000', $this->pdo->query('SELECT updated_at FROM ffx_media WHERE id=' . $this->mediaId)->fetchColumn());
        self::assertFileExists($applied['backup']);
        self::assertSame(0600, fileperms($applied['backup']) & 0777);
        $backups = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($applied['backup'], FILE_IGNORE_NEW_LINES));
        $original = array_values(array_filter($backups, fn(array $row): bool => (int)$row['id'] === $this->mediaId));
        self::assertCount(1, $original);
        self::assertNull($original[0]['release_date']);
        self::assertSame('', json_decode($original[0]['metadata'], true)['actor']);
        self::assertSame('https://example.invalid/local.m3u8', $this->pdo->query('SELECT media_url FROM ffx_episodes WHERE id=' . $episodeId)->fetchColumn());
        self::assertSame((string) $lineId, (string) $this->pdo->query('SELECT source_id FROM ffx_episodes WHERE id=' . $episodeId)->fetchColumn());
        $again = json_decode($this->cli($command), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $again['changed']);
        $legacyEntry = json_decode($this->cli(['scripts/backfill_collection_metadata.php']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $legacyEntry['changed']);
        self::assertSame('dry-run', $legacyEntry['mode']);
    }
}
