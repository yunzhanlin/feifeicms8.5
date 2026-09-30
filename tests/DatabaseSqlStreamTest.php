<?php
declare(strict_types=1);

namespace tests;

use app\service\SqlStatementStream;
use PHPUnit\Framework\TestCase;

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
}
