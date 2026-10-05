<?php
declare(strict_types=1);

namespace tests;

use PDO;
use app\controller\admin\Database;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MySqlIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        // These tests write fixtures. Never infer authority to use the live
        // website's .env merely because a developer runs composer test.
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn) self::markTestSkipped('Disposable MySQL audit database is not configured');
        if (!preg_match('/;dbname=audit_[a-z0-9_]+(?:;|$)/D', $dsn)) self::fail('Refusing to modify a non-audit database');
        try {
            $this->pdo = new PDO(
                $dsn,
                getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root',
                getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable $exception) {
            self::markTestSkipped('MySQL integration database is unavailable: ' . $exception->getMessage());
        }
    }

    public function testGeneratedAdministrationFiltersAndIndexesExist(): void
    {
        $columns = $this->pdo?->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_media' AND COLUMN_NAME LIKE 'admin_%'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(['admin_weekday', 'admin_state', 'admin_series', 'admin_inputer'], $columns);
        $indexes = $this->pdo?->query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ffx_media' AND INDEX_NAME LIKE 'idx_ffx_media_admin_%'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertEqualsCanonicalizing(['idx_ffx_media_admin_weekday', 'idx_ffx_media_admin_state', 'idx_ffx_media_admin_inputer'], $indexes);
    }

    public function testDatabaseBackupSerializesInstalledSearchTriggers(): void
    {
        self::assertNotNull($this->pdo);
        $controller = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Database::class, 'writeBackupTriggers');
        $handle = fopen('php://temp', 'w+');
        self::assertIsResource($handle);
        try {
            $method->invoke($controller, $handle, $this->pdo, ['ffx_media']);
            rewind($handle);
            $sql = stream_get_contents($handle);
            self::assertIsString($sql);
            foreach (['ffx_media_search_insert', 'ffx_media_search_update', 'ffx_media_search_delete'] as $trigger) {
                self::assertStringContainsString('CREATE TRIGGER `' . $trigger . '`', $sql);
            }
        } finally {
            fclose($handle);
        }
    }

    public function testAQueuedCollectionJobCanOnlyBeClaimedOnce(): void
    {
        self::assertNotNull($this->pdo);
        $suffix = bin2hex(random_bytes(8));
        $source = $this->pdo->prepare('INSERT INTO ffx_collection_sources (name,endpoint,source_type,status) VALUES (?,?,?,?)');
        $source->execute(['integration-' . $suffix, 'https://example.test/' . $suffix, 'maccms_json', 'enabled']);
        $sourceId = (int) $this->pdo->lastInsertId();
        $jobId = 0;
        try {
            $job = $this->pdo->prepare('INSERT INTO ffx_collection_jobs (source_id,idempotency_key,mode,state) VALUES (?,?,?,?)');
            $job->execute([$sourceId, 'integration-' . $suffix, 'manual', 'queued']);
            $jobId = (int) $this->pdo->lastInsertId();
            $claim = $this->pdo->prepare("UPDATE ffx_collection_jobs SET state='running',started_at=NOW(6) WHERE id=? AND state='queued'");
            $claim->execute([$jobId]);
            self::assertSame(1, $claim->rowCount());
            $claim->execute([$jobId]);
            self::assertSame(0, $claim->rowCount());
        } finally {
            if ($jobId > 0) $this->pdo->prepare('DELETE FROM ffx_collection_jobs WHERE id=?')->execute([$jobId]);
            $this->pdo->prepare('DELETE FROM ffx_collection_sources WHERE id=?')->execute([$sourceId]);
        }
    }

    public function testVideoAndScenarioSourcesCanShareEndpointAndStayLinked(): void
    {
        self::assertNotNull($this->pdo);
        $suffix = bin2hex(random_bytes(8));
        $endpoint = 'https://example.test/shared-' . $suffix;
        $insert = $this->pdo->prepare('INSERT INTO ffx_collection_sources (name,endpoint,source_type,resource_type,media_source_id,status) VALUES (?,?,?,?,?,?)');
        $videoId = $scenarioId = 0;
        try {
            $insert->execute(['video-' . $suffix, $endpoint, 'feifei_json', 'video', null, 'enabled']);
            $videoId = (int) $this->pdo->lastInsertId();
            $insert->execute(['scenario-' . $suffix, $endpoint, 'feifei_json', 'scenario', $videoId, 'enabled']);
            $scenarioId = (int) $this->pdo->lastInsertId();
            $source = $this->pdo->query('SELECT resource_type,media_source_id FROM ffx_collection_sources WHERE id=' . $scenarioId)->fetch(PDO::FETCH_ASSOC);
            self::assertSame('scenario', $source['resource_type'] ?? null);
            self::assertSame($videoId, (int) ($source['media_source_id'] ?? 0));
        } finally {
            if ($scenarioId > 0) $this->pdo->prepare('DELETE FROM ffx_collection_sources WHERE id=?')->execute([$scenarioId]);
            if ($videoId > 0) $this->pdo->prepare('DELETE FROM ffx_collection_sources WHERE id=?')->execute([$videoId]);
        }
    }
}
