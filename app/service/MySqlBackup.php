<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use RuntimeException;

/** One consistent, unbuffered SQL backup implementation for admin and updater. */
final class MySqlBackup
{
    /** @param resource $handle @param list<string> $tables */
    public function dump(PDO $pdo, $handle, array $tables): void
    {
        if ($tables === [] || $pdo->inTransaction()) throw new RuntimeException('备份需要独立连接和至少一张表');
        foreach ($tables as $table) {
            if (!preg_match('/^ffx_[a-z0-9_]+$/D', $table)) throw new RuntimeException('无效备份表名');
            $statement = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $statement->execute([$table]);
            $engine = strtoupper((string) $statement->fetchColumn());
            $statement->closeCursor();
            if ($engine !== 'INNODB') throw new RuntimeException('一致性备份仅支持 InnoDB 表：' . $table);
        }
        $attribute = (int) constant(class_exists('Pdo\\Mysql') ? 'Pdo\\Mysql::ATTR_USE_BUFFERED_QUERY' : 'PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
        $buffered = $pdo->getAttribute($attribute);
        $pdo->setAttribute($attribute, false);
        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->beginTransaction();
            $snapshot = $pdo->query('SELECT 1 FROM `' . $tables[0] . '` LIMIT 1');
            if ($snapshot !== false) $snapshot->closeCursor();
            $this->writeAll($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($tables as $table) $this->writeTable($pdo, $handle, $table);
            $this->writeTriggers($handle, $pdo, $tables);
            $this->writeAll($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            if (!fflush($handle)) throw new RuntimeException('无法写完备份文件');
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->setAttribute($attribute, $buffered);
        }
    }

    /** Generated columns remain in CREATE TABLE but must not appear in INSERT. */
    private function writeTable(PDO $pdo, $handle, string $table): void
    {
        $statement = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
        $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : false;
        if ($statement !== false) $statement->closeCursor();
        $create = (string) ($row['Create Table'] ?? '');
        if ($create === '') throw new RuntimeException('无法读取数据表结构：' . $table);
        $this->writeAll($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create . ";\n");
        $columns = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COALESCE(GENERATION_EXPRESSION,'')='' ORDER BY ORDINAL_POSITION");
        $columns->execute([$table]);
        $names = $columns->fetchAll(PDO::FETCH_COLUMN);
        $columns->closeCursor();
        if ($names === []) throw new RuntimeException('数据表没有可备份的普通列：' . $table);
        $escaped = implode(',', array_map(static fn (string $name): string => '`' . str_replace('`', '``', $name) . '`', $names));
        $cursor = $pdo->query('SELECT ' . $escaped . ' FROM `' . $table . '`');
        if ($cursor === false) throw new RuntimeException('无法读取数据表：' . $table);
        try {
            while (($values = $cursor->fetch(PDO::FETCH_NUM)) !== false) {
                $values = array_map(static fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value), $values);
                $this->writeAll($handle, 'INSERT INTO `' . $table . '` (' . $escaped . ') VALUES (' . implode(',', $values) . ");\n");
            }
        } finally {
            $cursor->closeCursor();
        }
        $this->writeAll($handle, "\n");
    }

    /** @param resource $handle @param list<string> $tables */
    public function writeTriggers($handle, PDO $pdo, array $tables): void
    {
        $query = $pdo->query("SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'ffx\\_%' ORDER BY TRIGGER_NAME");
        $triggers = $query !== false ? $query->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($query !== false) $query->closeCursor();
        foreach ($triggers as $trigger) {
            $name = (string) $trigger['TRIGGER_NAME'];
            $table = (string) $trigger['EVENT_OBJECT_TABLE'];
            if (!in_array($table, $tables, true)) continue;
            $when = strtoupper((string) $trigger['ACTION_TIMING']);
            $event = strtoupper((string) $trigger['EVENT_MANIPULATION']);
            $body = trim((string) $trigger['ACTION_STATEMENT']);
            // Backups use single statements accepted by SqlStatementStream.
            // Reject compound custom triggers instead of producing a bad dump.
            if (!preg_match('/^ffx_[a-z0-9_]+$/D', $name) || !preg_match('/^ffx_[a-z0-9_]+$/D', $table)
                || !in_array($when, ['BEFORE', 'AFTER'], true) || !in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)
                || $body === '' || str_contains($body, ';')) throw new RuntimeException('无法安全备份触发器：' . $name);
            $this->writeAll($handle, 'CREATE TRIGGER `' . $name . '` ' . $when . ' ' . $event . ' ON `' . $table . '` FOR EACH ROW ' . $body . ";\n");
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $written = 0;
        $length = strlen($contents);
        while ($written < $length) {
            $bytes = fwrite($handle, substr($contents, $written));
            if ($bytes === false || $bytes === 0) throw new RuntimeException('备份文件写入失败');
            $written += $bytes;
        }
    }
}
