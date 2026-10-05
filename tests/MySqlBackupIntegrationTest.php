<?php
declare(strict_types=1);

namespace tests;

use app\service\MySqlBackup;
use app\service\SqlStatementStream;
use PDO;
use PHPUnit\Framework\TestCase;

/** Explicitly opt in with a disposable audit_* database, never the site's .env. */
final class MySqlBackupIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $table;
    private string $other;
    private string $trigger;
    private string $file;

    protected function setUp(): void
    {
        $dsn = getenv('FEIFEI_AUDIT_MYSQL_DSN');
        if (!$dsn) self::markTestSkipped('Disposable MySQL audit database is not configured');
        if (!preg_match('/;dbname=audit_[a-z0-9_]+(?:;|$)/D', $dsn)) self::fail('Refusing to modify a non-audit database');
        $this->pdo = new PDO($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(6));
        $this->table = 'ffx_audit_backup_' . $suffix;
        $this->other = 'ffx_audit_other_' . $suffix;
        $this->trigger = 'ffx_audit_trigger_' . $suffix;
        $this->file = (string) tempnam(sys_get_temp_dir(), 'feifei-backup-');
        $this->pdo->exec('CREATE TABLE `' . $this->table . '` (id INT PRIMARY KEY, text_value TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, derived INT GENERATED ALWAYS AS (id+1) STORED) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->pdo->exec('CREATE TABLE `' . $this->other . '` (id INT PRIMARY KEY, text_value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $statement = $this->pdo->prepare('INSERT INTO `' . $this->table . '` (id,text_value,created_at) VALUES (?,?,?)');
        $statement->execute([1, "国语; 'quoted' \\ newline\n内容", '2001-02-03 04:05:06']);
        $this->pdo->exec('INSERT INTO `' . $this->other . "` VALUES (1,'before')");
        $this->pdo->exec('CREATE TRIGGER `' . $this->trigger . '` BEFORE INSERT ON `' . $this->table . '` FOR EACH ROW SET NEW.text_value=CONCAT(NEW.text_value,\' trigger\')');
    }

    protected function tearDown(): void
    {
        if ($this->pdo === null) return;
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->table . '`');
        $this->pdo->exec('DROP TABLE IF EXISTS `' . $this->other . '`');
        if (is_file($this->file)) unlink($this->file);
    }

    public function testGeneratedColumnsDefaultTimestampsAndTriggersSurviveRestore(): void
    {
        self::assertNotNull($this->pdo);
        $before = $this->pdo->query('SELECT * FROM `' . $this->table . '`')->fetchAll(PDO::FETCH_ASSOC);
        $out = fopen($this->file, 'wb');
        try { (new MySqlBackup())->dump($this->pdo, $out, [$this->table, $this->other]); }
        finally { fclose($out); }
        $sql = (string) file_get_contents($this->file);
        self::assertStringContainsString('(`id`,`text_value`,`created_at`) VALUES', $sql);
        self::assertStringContainsString('2001-02-03 04:05:06', $sql);
        self::assertStringNotContainsString('`derived`) VALUES', $sql);
        foreach ((new SqlStatementStream())->fromFile($this->file, true) as $statement) $this->pdo->exec($statement);
        self::assertSame($before, $this->pdo->query('SELECT * FROM `' . $this->table . '`')->fetchAll(PDO::FETCH_ASSOC));
        $this->pdo->exec('INSERT INTO `' . $this->table . "` (id,text_value) VALUES (2,'after')");
        self::assertSame('after trigger', $this->pdo->query('SELECT text_value FROM `' . $this->table . '` WHERE id=2')->fetchColumn());
        self::assertSame(3, (int) $this->pdo->query('SELECT derived FROM `' . $this->table . '` WHERE id=2')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testConcurrentDataChangeDoesNotMixTwoSnapshots(): void
    {
        $dsn = (string) getenv('FEIFEI_AUDIT_MYSQL_DSN');
        $connection = new BackupHookPdo($dsn, getenv('FEIFEI_AUDIT_MYSQL_USER') ?: 'root', getenv('FEIFEI_AUDIT_MYSQL_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $connection->hookTable = $this->other;
        $connection->hook = function (): void {
            $this->pdo->beginTransaction();
            $this->pdo->exec('UPDATE `' . $this->table . "` SET text_value='changed' WHERE id=1");
            $this->pdo->exec('UPDATE `' . $this->other . "` SET text_value='changed' WHERE id=1");
            $this->pdo->commit();
        };
        $out = fopen($this->file, 'wb');
        try { (new MySqlBackup())->dump($connection, $out, [$this->table, $this->other]); }
        finally { fclose($out); }
        self::assertNull($connection->hook);
        $sql = (string) file_get_contents($this->file);
        self::assertStringContainsString("'before'", $sql);
        self::assertStringNotContainsString("'changed'", $sql);
        self::assertSame('changed', $this->pdo->query('SELECT text_value FROM `' . $this->other . '`')->fetchColumn());
    }
}

final class BackupHookPdo extends PDO
{
    public string $hookTable;
    public ?\Closure $hook = null;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        if ($this->hook !== null && $query === 'SHOW CREATE TABLE `' . $this->hookTable . '`') {
            $callback = $this->hook; $this->hook = null; $callback();
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
