<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use PDOException;
use RuntimeException;

/** Check actual effective privileges without changing any application table. */
final class MySqlTriggerPreflight
{
    public function check(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new RuntimeException('数据库权限检查需要独立的 MySQL 连接，操作未开始');
        }
        // Deliberately outside ffx_*: concurrent application backups never include it.
        $table = 'feifei_privilege_probe_' . bin2hex(random_bytes(12));
        $trigger = $table . '_insert';
        $created = false;
        $failure = null;
        try {
            $pdo->exec('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, probe_value INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
            $created = true;
            $pdo->exec('CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $table . '` FOR EACH ROW SET NEW.probe_value=1');
            $pdo->exec('INSERT INTO `' . $table . '` (id) VALUES (1)');
            $cursor = $pdo->query('SELECT probe_value FROM `' . $table . '` WHERE id=1');
            $value = $cursor->fetchColumn();
            $cursor->closeCursor();
            if ((int) $value !== 1) throw new RuntimeException('数据库触发器未正常执行');
            $pdo->exec('DROP TRIGGER `' . $trigger . '`');
        } catch (PDOException $error) {
            $number = (int) ($error->errorInfo[1] ?? 0);
            $failure = new RuntimeException($number === 1419
                ? '数据库操作未开始，既有表未修改：MySQL 二进制日志限制了触发器创建（1419）。请数据库管理员处理 log_bin_trust_function_creators 或使用具备相应权限的账号后重试。'
                : '数据库操作未开始，既有表未修改：站点账号未通过建表、触发器及读写权限检查，请数据库管理员检查授权。');
        } catch (RuntimeException) {
            $failure = new RuntimeException('数据库操作未开始，既有表未修改：触发器执行检查失败。');
        } finally {
            if ($created) {
                try { $pdo->exec('DROP TABLE `' . $table . '`'); }
                catch (PDOException) {
                    $failure = new RuntimeException('数据库操作未开始，既有表未修改：无法清理权限检查表 ' . $table . '，请数据库管理员清理并检查 DROP 权限。');
                }
            }
        }
        if ($failure !== null) throw $failure;
    }
}
