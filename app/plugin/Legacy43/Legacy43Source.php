<?php
declare(strict_types=1);

namespace app\plugin\Legacy43;

use PDO;
use PDOException;
use RuntimeException;

final class Legacy43Source
{
    private PDO $pdo;
    private string $prefix;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('PHP 未安装 pdo_mysql 扩展。');
        }
        $this->prefix = trim((string) ($config['prefix'] ?? 'ff_'));
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $this->prefix) !== 1) {
            throw new RuntimeException('旧表前缀只能包含字母、数字和下划线。');
        }
        if (strtolower($this->prefix) === 'ffx_') {
            throw new RuntimeException('ffx_ 是 8.5 新表前缀，不能作为 4.3 旧表前缀。');
        }
        $host = trim((string) ($config['host'] ?? '127.0.0.1'));
        $port = max(1, min(65535, (int) ($config['port'] ?? 3306)));
        $database = trim((string) ($config['database'] ?? ''));
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $charset = strtolower((string) ($config['charset'] ?? 'utf8mb4'));
        if (!in_array($charset, ['utf8mb4', 'utf8'], true)) $charset = 'utf8mb4';
        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('请完整填写旧数据库主机、库名和用户名。');
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,255}$/', $host) !== 1) {
            throw new RuntimeException('旧数据库主机格式不正确。');
        }
        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database) !== 1) {
            throw new RuntimeException('旧数据库名称格式不正确。');
        }
        try {
            // PHP 8.5 deprecates the old PDO::MYSQL_ATTR_* aliases. The
            // namespaced Pdo\\Mysql constants are available on newer PHP
            // versions; keep the legacy fallback for PHP 8.0-8.3.
            $multiStatementsAttribute = defined('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
                ? constant('Pdo\\Mysql::ATTR_MULTI_STATEMENTS')
                : constant('PDO::MYSQL_ATTR_MULTI_STATEMENTS');
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset),
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    $multiStatementsAttribute => false,
                ]
            );
        } catch (PDOException $exception) {
            throw new RuntimeException('旧数据库连接失败：' . $exception->getMessage(), 0, $exception);
        }
    }

    public function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) throw new RuntimeException('旧表名称无效。');
        return '`' . $this->prefix . $name . '`';
    }

    public function exists(string $name): bool
    {
        // MySQL does not accept a native prepared placeholder in SHOW TABLES
        // on all 8.x versions. information_schema keeps this check prepared,
        // portable and scoped to the selected source database.
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$this->prefix . $name]);
        return (int) $statement->fetchColumn() > 0;
    }

    public function count(string $name): int
    {
        if (!$this->exists($name)) return 0;
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $this->table($name))->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function batch(string $name, string $idColumn, int $cursor, int $limit): array
    {
        if (!$this->exists($name)) return [];
        if (preg_match('/^[a-z][a-z0-9_]*$/', $idColumn) !== 1) throw new RuntimeException('旧表主键无效。');
        $limit = max(1, min(500, $limit));
        $statement = $this->pdo->prepare(sprintf(
            'SELECT * FROM %s WHERE `%s` > ? ORDER BY `%s` ASC LIMIT %d',
            $this->table($name), $idColumn, $idColumn, $limit
        ));
        $statement->execute([$cursor]);
        return $statement->fetchAll() ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function offsetBatch(string $name, int $offset, int $limit): array
    {
        if (!$this->exists($name)) return [];
        $offset = max(0, $offset);
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(sprintf('SELECT * FROM %s ORDER BY `tag_id`,`tag_list`,`tag_name` LIMIT %d OFFSET %d', $this->table($name), $limit, $offset))->fetchAll() ?: [];
    }

    public function prefix(): string
    {
        return $this->prefix;
    }
}
