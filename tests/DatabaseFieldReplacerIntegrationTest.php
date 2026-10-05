<?php
declare(strict_types=1);

namespace tests;

use app\service\DatabaseFieldReplacer;
use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseFieldReplacerIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $table;
    private array $receipts = [];

    protected function setUp(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn) self::markTestSkipped('Disposable MySQL audit database is not configured');
        if (!preg_match('/;dbname=audit_[a-z0-9_]+(?:;|$)/D', $dsn)) self::fail('Refusing to modify a non-audit database');
        $this->pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $this->table = 'ffx_audit_replace_' . bin2hex(random_bytes(6));
        $this->pdo->exec('CREATE TABLE `' . $this->table . '` (id INT PRIMARY KEY,body LONGTEXT NULL,short_value VARCHAR(8) NULL,slug VARCHAR(100) NULL UNIQUE,metadata JSON NULL,password_hash VARCHAR(255) NULL,flag INT DEFAULT 0,updated_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),derived VARCHAR(100) GENERATED ALWAYS AS (LEFT(body,100)) STORED) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) return;
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        $delete = $this->pdo->prepare('DELETE FROM ffx_jobs WHERE idempotency_key=?');
        foreach ($this->receipts as $receipt) $delete->execute(['database.replace:' . $receipt]);
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->table . '`');
    }

    private function seed(array $bodies): void
    {
        $query = $this->pdo->prepare('INSERT INTO `' . $this->table . '` (id,body) VALUES (?,?)');
        foreach ($bodies as $id => $body) $query->execute([$id, $body]);
    }

    private function input(string $search, string $replacement, string $field = 'body', string $condition = ''): array
    {
        return ['table' => $this->table, 'field' => $field, 'search' => $search, 'replacement' => $replacement, 'condition' => $condition];
    }

    private function receipt(): string
    {
        $receipt = bin2hex(random_bytes(32)); $this->receipts[] = $receipt; return $receipt;
    }

    private function bodies(): array
    {
        return $this->pdo->query('SELECT id,body FROM `' . $this->table . '` ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public function testLiteralChineseQuotesWildcardsAndNullAreNotSql(): void
    {
        $this->seed([1 => "前面 %_'\\ 普通话 %_'\\", 2 => "其他 %x'\\", 3 => null]);
        $input = $this->input("%_'\\", "国语'; DROP TABLE anything; --");
        $service = new DatabaseFieldReplacer();
        $preview = $service->preview($this->pdo, $input);
        self::assertSame(1, $preview['matched']);
        self::assertSame("前面 %_'\\ 普通话 %_'\\", $this->bodies()[1]);
        $result = $service->replace($this->pdo, $input, 1, $this->receipt());
        self::assertSame(1, $result['changed']);
        self::assertSame(str_replace($input['search'], $input['replacement'], $preview['samples'][0]['original']), $this->bodies()[1]);
        self::assertSame("其他 %x'\\", $this->bodies()[2]);
        self::assertNull($this->bodies()[3]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testPreviewAndReplaceHaveTheSameCaseSensitiveSemantics(): void
    {
        $this->seed([1 => 'ABC', 2 => 'abc abc', 3 => 'ábć', 4 => 'xxabcxx']);
        $input = $this->input('abc', '国语');
        $service = new DatabaseFieldReplacer();
        self::assertSame(2, $service->preview($this->pdo, $input)['matched']);
        self::assertSame(2, $service->replace($this->pdo, $input, 2, $this->receipt())['changed']);
        self::assertSame([1 => 'ABC', 2 => '国语 国语', 3 => 'ábć', 4 => 'xx国语xx'], $this->bodies());
    }

    public function testConditionScopesAndEmptyReplacementDeleteOnlyMatchingText(): void
    {
        $this->seed([1 => 'aaXaa', 2 => 'aaXaa', 3 => 'aaXaa']);
        $input = $this->input('aa', '', 'body', 'id>=2 AND id<3 AND short_value IS NULL');
        $service = new DatabaseFieldReplacer();
        self::assertSame(1, $service->preview($this->pdo, $input)['matched']);
        $service->replace($this->pdo, $input, 1, $this->receipt());
        self::assertSame([1 => 'aaXaa', 2 => 'X', 3 => 'aaXaa'], $this->bodies());
        self::assertSame('X', $this->pdo->query('SELECT derived FROM `' . $this->table . '` WHERE id=2')->fetchColumn());
    }

    public function testLongTextPreviewIsBoundedAndShowsContextAroundTheMatch(): void
    {
        $this->seed(array_fill(1, 15, str_repeat('前', 20000) . '标记' . str_repeat('后', 20000)));
        $preview = (new DatabaseFieldReplacer())->preview($this->pdo, $this->input('标记', '替换'));
        self::assertSame(15, $preview['matched']);
        self::assertCount(10, $preview['samples']);
        self::assertStringContainsString('标记', $preview['samples'][0]['original']);
        self::assertStringContainsString('替换', $preview['samples'][0]['replacement']);
        self::assertLessThanOrEqual(800, mb_strlen($preview['samples'][0]['original']));
        self::assertGreaterThan(40000, mb_strlen($this->bodies()[1]));
    }

    public function testJsonContentIsSupportedAndInvalidJsonRollsBack(): void
    {
        $this->seed([1 => 'body']);
        $this->pdo->exec('UPDATE `' . $this->table . '` SET metadata=\'{"language":"普通话"}\' WHERE id=1');
        $service = new DatabaseFieldReplacer();
        $input = $this->input('普通话', '国语', 'metadata');
        self::assertSame(1, $service->preview($this->pdo, $input)['matched']);
        $service->replace($this->pdo, $input, 1, $this->receipt());
        self::assertSame('国语', $this->pdo->query('SELECT JSON_UNQUOTE(JSON_EXTRACT(metadata,\'$.language\')) FROM `' . $this->table . '`')->fetchColumn());
        $before = $this->pdo->query('SELECT metadata FROM `' . $this->table . '`')->fetchColumn();
        try {
            $service->replace($this->pdo, $this->input('国语', '"', 'metadata'), 1, $this->receipt());
            self::fail('Invalid JSON must not be committed');
        } catch (PDOException) {
            self::assertSame($before, $this->pdo->query('SELECT metadata FROM `' . $this->table . '`')->fetchColumn());
        }
    }

    public function testNonStrictServerCannotSilentlyTruncateReplacement(): void
    {
        $this->seed([1 => 'unchanged', 2 => 'unchanged']);
        $this->pdo->exec('UPDATE `' . $this->table . '` SET short_value=\'abc\'');
        $originalMode = $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $this->pdo->exec("SET SESSION sql_mode=''");
        try {
            (new DatabaseFieldReplacer())->replace($this->pdo, $this->input('abc', 'too-long-for-field', 'short_value'), 2, $this->receipt());
            self::fail('Oversize replacement must not silently truncate');
        } catch (PDOException) {
            self::assertSame(['abc', 'abc'], $this->pdo->query('SELECT short_value FROM `' . $this->table . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame('', $this->pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        } finally {
            $this->pdo->prepare('SET SESSION sql_mode=?')->execute([$originalMode]);
        }
    }

    public function testUniqueKeyConflictAndAuditFailureRollBackBothDataAndReceipt(): void
    {
        $this->seed([1 => 'abc', 2 => 'abc']);
        $this->pdo->exec('UPDATE `' . $this->table . '` SET slug=IF(id=1,\'abc\',\'ab\')');
        $receipt = $this->receipt();
        try {
            (new DatabaseFieldReplacer())->replace($this->pdo, $this->input('c', '', 'slug'), 1, $receipt);
            self::fail('Unique conflict must fail atomically');
        } catch (PDOException) {
            self::assertSame(['abc', 'ab'], $this->pdo->query('SELECT slug FROM `' . $this->table . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        }
        $receipt = $this->receipt();
        try {
            (new DatabaseFieldReplacer())->replace($this->pdo, $this->input('abc', 'new'), 2, $receipt, static function (): void { throw new RuntimeException('fixture audit failure'); });
            self::fail('Audit failure must roll back');
        } catch (RuntimeException $error) {
            self::assertSame('fixture audit failure', $error->getMessage());
            self::assertSame([1 => 'abc', 2 => 'abc'], $this->bodies());
            $query = $this->pdo->prepare('SELECT COUNT(*) FROM ffx_jobs WHERE idempotency_key=?');
            $query->execute(['database.replace:' . $receipt]);
            self::assertSame(0, (int) $query->fetchColumn());
        }
    }

    public function testDuplicateConfirmationNeverExpandsTheTextTwice(): void
    {
        $this->seed([1 => 'a']);
        $input = $this->input('a', 'aa'); $receipt = $this->receipt();
        $service = new DatabaseFieldReplacer(); $auditCalls = 0;
        $audit = static function () use (&$auditCalls): void { $auditCalls++; };
        self::assertFalse($service->replace($this->pdo, $input, 1, $receipt, $audit)['replayed']);
        self::assertTrue($service->replace($this->pdo, $input, 1, $receipt, $audit)['replayed']);
        self::assertSame('aa', $this->bodies()[1]);
        self::assertSame(1, $auditCalls);
        $this->expectException(InvalidArgumentException::class);
        $service->replace($this->pdo, $this->input('a', 'different'), 1, $receipt);
    }

    public function testChangedCountRequiresNewPreviewAndCurrentTextIsNotOverwritten(): void
    {
        $this->seed([1 => 'old abc']);
        $service = new DatabaseFieldReplacer(); $input = $this->input('abc', 'new');
        self::assertSame(1, $service->preview($this->pdo, $input)['matched']);
        $this->pdo->exec('UPDATE `' . $this->table . '` SET body=\'latest abc suffix\' WHERE id=1');
        $service->replace($this->pdo, $input, 1, $this->receipt());
        self::assertSame('latest new suffix', $this->bodies()[1]);
        $this->seed([2 => 'new']);
        try {
            $service->replace($this->pdo, $this->input('new', 'result'), 1, $this->receipt());
            self::fail('Changed scope must require a new preview');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('匹配数量已变化', $error->getMessage());
            self::assertSame([1 => 'latest new suffix', 2 => 'new'], $this->bodies());
        }
    }

    public function testParallelDuplicateConfirmationExecutesOnce(): void
    {
        if (!function_exists('proc_open')) self::markTestSkipped('Parallel replacement regression requires proc_open');
        $this->seed([1 => 'a']);
        $input = $this->input('a', 'aa'); $receipt = $this->receipt();
        $code = <<<'PHP'
require getcwd() . '/vendor/autoload.php';
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$pdo = new PDO(getenv('FEIFEI_AUDIT_MYSQL_DSN'), getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try { echo json_encode((new app\service\DatabaseFieldReplacer())->replace($pdo, $data['input'], 1, $data['receipt']), JSON_THROW_ON_ERROR); }
catch (Throwable $error) { fwrite(STDERR, $error->getMessage()); exit(1); }
PHP;
        $workers = [];
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT body FROM `' . $this->table . '` WHERE id=1 FOR UPDATE')->fetchColumn();
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), getenv());
                self::assertIsResource($process);
                fwrite($pipes[0], json_encode(['input' => $input, 'receipt' => $receipt], JSON_THROW_ON_ERROR)); fclose($pipes[0]);
                $workers[] = [$process, $pipes];
            }
            $waiting = 0;
            for ($i = 0; $i < 80; $i++) {
                $query = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID<>CONNECTION_ID() AND DB=DATABASE() AND (INFO LIKE ? OR INFO LIKE 'INSERT INTO ffx_jobs%')");
                $query->execute(['UPDATE `' . $this->table . '`%']);
                $waiting = (int) $query->fetchColumn();
                if ($waiting >= 2) break;
                usleep(50_000);
            }
            self::assertSame(2, $waiting, 'Both requests must overlap while the fixture row is locked');
            $this->pdo->commit();
            $replays = [];
            foreach ($workers as [$process, $pipes]) {
                $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $error);
                $replays[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR)['replayed'];
            }
            sort($replays);
            self::assertSame([false, true], $replays);
            self::assertSame('aa', $this->bodies()[1]);
        } finally {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        }
    }

    public function testFieldWhitelistRejectsGeneratedNumericSensitiveAndForeignTables(): void
    {
        $service = new DatabaseFieldReplacer();
        $fields = array_column($service->fields($this->pdo, $this->table), null, 'name');
        foreach (['id', 'flag', 'password_hash', 'derived', 'updated_at'] as $field) self::assertFalse($fields[$field]['editable']);
        foreach (['body', 'metadata', 'slug'] as $field) self::assertTrue($fields[$field]['editable']);
        foreach (['id', 'password_hash', 'derived', 'missing', 'body`=NULL --'] as $field) {
            try { $service->preview($this->pdo, $this->input('x', 'y', $field)); self::fail('Unsafe field accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach (['mysql.user', 'ffx_jobs', 'ffx_admins', $this->table . '`'] as $table) {
            $input = $this->input('x', 'y'); $input['table'] = $table;
            try { $service->preview($this->pdo, $input); self::fail('Unsafe table accepted'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testNoMatchesAndIdenticalTextCannotBeExecuted(): void
    {
        $this->seed([1 => 'abc']);
        $service = new DatabaseFieldReplacer();
        self::assertSame(0, $service->preview($this->pdo, $this->input('missing', 'new'))['matched']);
        $this->expectException(InvalidArgumentException::class);
        $service->preview($this->pdo, $this->input('abc', 'abc'));
    }

    public function testVideoReplacementPreservesDurableSearchSync(): void
    {
        $create = $this->pdo->prepare("INSERT INTO ffx_media (title,slug,status) VALUES (?,?,'published')");
        $create->execute(['fixture 普通话', 'audit-replace-' . bin2hex(random_bytes(8))]);
        $id = (int) $this->pdo->lastInsertId();
        try {
            $this->pdo->prepare('DELETE FROM ffx_search_outbox WHERE media_id=?')->execute([$id]);
            $input = ['table' => 'ffx_media', 'field' => 'title', 'search' => '普通话', 'replacement' => '国语', 'condition' => 'id=' . $id];
            $service = new DatabaseFieldReplacer();
            self::assertSame(1, $service->preview($this->pdo, $input)['matched']);
            $service->replace($this->pdo, $input, 1, $this->receipt());
            $query = $this->pdo->prepare('SELECT revision FROM ffx_search_outbox WHERE media_id=?');
            $query->execute([$id]);
            self::assertGreaterThanOrEqual(1, (int) $query->fetchColumn());
            $query = $this->pdo->prepare('SELECT title FROM ffx_media WHERE id=?');
            $query->execute([$id]); self::assertSame('fixture 国语', $query->fetchColumn());
        } finally {
            $this->pdo->prepare('DELETE FROM ffx_media WHERE id=?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM ffx_search_outbox WHERE media_id=?')->execute([$id]);
        }
    }

    public function testRowLimitRejectsOversizePreviewWithoutModifyingAnything(): void
    {
        $digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $this->pdo->exec('INSERT INTO `' . $this->table . '` (id,body) SELECT 1+a.n+10*b.n+100*c.n+1000*d.n+10000*e.n,\'cap\' FROM ' . $digits . ' a CROSS JOIN ' . $digits . ' b CROSS JOIN ' . $digits . ' c CROSS JOIN ' . $digits . ' d CROSS JOIN ' . $digits . ' e LIMIT 50001');
        try { (new DatabaseFieldReplacer())->preview($this->pdo, $this->input('cap', 'new')); self::fail('Unbounded replacement must be rejected'); }
        catch (InvalidArgumentException $error) {
            self::assertStringContainsString('50000', $error->getMessage());
            self::assertSame(50001, (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $this->table . '` WHERE body=\'cap\'')->fetchColumn());
        }
    }
}
