<?php
declare(strict_types=1);

namespace tests;

use PDO;
use PHPUnit\Framework\TestCase;

final class CollectionScheduleConcurrencyTest extends TestCase
{
    public function testTwoSchedulersQueueTheSameDueTaskExactlyOnce(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn || !function_exists('proc_open')) self::markTestSkipped('Disposable audit database and proc_open are required');
        if (!preg_match('/;dbname=(audit_[a-z0-9_]+)(?:;|$)/D', $dsn, $database)) self::fail('Refusing to modify a non-audit database');
        $pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sources = $pdo->prepare('INSERT INTO ffx_collection_sources (name,endpoint,source_type,status) VALUES (?,?,?,?)');
        $sources->execute(['audit-scheduler', 'https://audit.invalid/' . bin2hex(random_bytes(8)), 'maccms_json', 'enabled']);
        $sourceId = (int) $pdo->lastInsertId();
        $taskId = 0;
        $workers = [];
        try {
            $due = gmdate('Y-m-d H:i:s', time() - 60);
            $insert = $pdo->prepare('INSERT INTO ffx_cron_tasks (name,source_id,schedule_type,interval_minutes,payload,status,next_run_at) VALUES (?,?,?,?,?,?,?)');
            $insert->execute(['audit-scheduler', $sourceId, 'interval', 60, '{}', 'enabled', $due]);
            $taskId = (int) $pdo->lastInsertId();
            $pdo->beginTransaction();
            $pdo->query('SELECT id FROM ffx_cron_tasks WHERE id=' . $taskId . ' FOR UPDATE')->closeCursor();
            $env = getenv();
            $env['PHP_ENV_NAME'] = 'isolated-cron-' . bin2hex(random_bytes(8));
            preg_match('/(?:^mysql:|;)host=([^;]+)/', $dsn, $host);
            preg_match('/;port=([0-9]+)/', $dsn, $port);
            $env['PHP_DB_HOST'] = $host[1] ?? '127.0.0.1';
            $env['PHP_DB_PORT'] = $port[1] ?? '3306';
            $env['PHP_DB_NAME'] = $database[1];
            $env['PHP_DB_USER'] = getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root';
            $env['PHP_DB_PASS'] = getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '';
            $env['PHP_CACHE_DRIVER'] = 'file';
            $env['PHP_REDIS_HOST'] = '127.0.0.1'; $env['PHP_REDIS_PORT'] = '1';
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/think', 'feifei:collection:schedule'],
                    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            // Both workers must have read the due list and be waiting on the
            // same task row before releasing the barrier. No timing-only race.
            $blocked = 0;
            $deadline = microtime(true) + 8;
            do {
                $blocked = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND INFO LIKE '%ffx_cron_tasks%' AND INFO LIKE '%FOR UPDATE%' AND ID<>CONNECTION_ID()")->fetchColumn();
                if ($blocked >= 2) break;
                usleep(20_000);
            } while (microtime(true) < $deadline);
            $pdo->commit();
            self::assertSame(2, $blocked, 'Both schedulers should reach the row-lock barrier');
            $queued = [];
            foreach ($workers as [$process, $pipes]) {
                $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $out . $error);
                self::assertMatchesRegularExpression('/\[OK\] queued=[01]/', $out);
                preg_match('/queued=([01])/', $out, $matches); $queued[] = (int) $matches[1];
            }
            $workers = [];
            sort($queued);
            self::assertSame([0, 1], $queued);
            self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM ffx_collection_jobs WHERE source_id=' . $sourceId)->fetchColumn());
            $next = (string) $pdo->query('SELECT next_run_at FROM ffx_cron_tasks WHERE id=' . $taskId)->fetchColumn();
            self::assertGreaterThan(gmdate('Y-m-d H:i:s'), $next);
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            foreach ($workers as [$process, $pipes]) {
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            }
            $pdo->exec('DELETE FROM ffx_collection_jobs WHERE source_id=' . $sourceId);
            if ($taskId > 0) $pdo->exec('DELETE FROM ffx_cron_tasks WHERE id=' . $taskId);
            $pdo->exec('DELETE FROM ffx_collection_sources WHERE id=' . $sourceId);
        }
    }
}
