<?php
declare(strict_types=1);

namespace tests;

use app\service\SqlStatementStream;
use app\service\SearchOutboxTriggers;
use app\controller\admin\Database;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DatabaseSqlStreamTest extends TestCase
{
    public function testItStreamsStatementsWithoutSplittingQuotedSemicolons(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'feifei-sql-');
        self::assertIsString($path);
        file_put_contents($path, "SET NAMES utf8mb4;\nINSERT INTO `ffx_test` (`value`) VALUES ('a;b'),('it''s fine');\n");
        try {
            $statements = iterator_to_array((new SqlStatementStream())->fromFile($path, true), false);
            self::assertSame([
                'SET NAMES utf8mb4',
                "INSERT INTO `ffx_test` (`value`) VALUES ('a;b'),('it''s fine')",
            ], $statements);
        } finally {
            @unlink($path);
        }
    }

    public function testItSeparatesSameLineMigrationStatements(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/012_media_admin_filter_indexes.sql';
        $statements = iterator_to_array((new SqlStatementStream())->fromFile($path, true), false);
        self::assertCount(34, $statements);
        self::assertSame('PREPARE ffx_statement FROM @ffx_sql', $statements[2]);
        self::assertSame('EXECUTE ffx_statement', $statements[3]);
        self::assertStringContainsString("VALUES (12, 'indexed media administration filters')", $statements[33]);
    }

    public function testItRejectsAnUnterminatedBackupStatement(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'feifei-sql-');
        self::assertIsString($path);
        file_put_contents($path, "SET NAMES utf8mb4");
        try {
            $this->expectException(\RuntimeException::class);
            iterator_to_array((new SqlStatementStream())->fromFile($path, true), false);
        } finally {
            @unlink($path);
        }
    }

    public function testRestoredSearchTriggersAreValidSingleStatements(): void
    {
        $controller = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $allowed = new ReflectionMethod(Database::class, 'isAllowedBackupStatement');
        foreach (SearchOutboxTriggers::statements() as $statement) {
            self::assertTrue($allowed->invoke($controller, $statement));
            $path = tempnam(sys_get_temp_dir(), 'feifei-trigger-');
            self::assertIsString($path);
            try {
                file_put_contents($path, $statement . ";\n");
                self::assertSame([$statement], iterator_to_array((new SqlStatementStream())->fromFile($path, true), false));
            } finally {
                @unlink($path);
            }
        }
    }

    public function testRestoreRejectsUnrelatedTriggerStatements(): void
    {
        $controller = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $allowed = new ReflectionMethod(Database::class, 'isAllowedBackupStatement');
        self::assertFalse($allowed->invoke($controller, 'CREATE TRIGGER evil AFTER INSERT ON users FOR EACH ROW DELETE FROM users'));
    }

    public function testRestoredSearchTriggerBodiesMatchMigrationFifteen(): void
    {
        $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/015_authorization_search_outbox.sql');
        foreach (SearchOutboxTriggers::statements() as $name => $statement) {
            self::assertStringContainsString('CREATE TRIGGER ' . $name . ' ', $migration);
            $body = preg_replace('/^CREATE TRIGGER `?ffx_[a-z0-9_]+`? (?:BEFORE|AFTER) (?:INSERT|UPDATE|DELETE) ON `?ffx_media`? FOR EACH ROW\s*/', '', $statement);
            self::assertIsString($body);
            self::assertStringContainsString($body, $migration);
        }
    }
}
