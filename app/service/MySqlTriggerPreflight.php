<?php
declare(strict_types=1);

namespace app\service;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

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
        $stage = '建表';
        try {
            $pdo->exec('CREATE TABLE `' . $table . '` (id INT PRIMARY KEY, probe_value INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
            $created = true;
            $stage = '创建触发器';
            $pdo->exec('CREATE TRIGGER `' . $trigger . '` BEFORE INSERT ON `' . $table . '` FOR EACH ROW SET NEW.probe_value=1');
            $stage = '触发器执行及读写';
            $pdo->exec('INSERT INTO `' . $table . '` (id) VALUES (1)');
            $cursor = $pdo->query('SELECT probe_value FROM `' . $table . '` WHERE id=1');
            $value = $cursor->fetchColumn();
            $cursor->closeCursor();
            if ((int) $value !== 1) throw new RuntimeException('数据库触发器未正常执行');
            $stage = '删除测试触发器';
            $pdo->exec('DROP TRIGGER `' . $trigger . '`');
        } catch (PDOException $error) {
            $number = (int) ($error->errorInfo[1] ?? 0);
            $failure = new RuntimeException($this->failureMessage($pdo, $number, $stage));
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

    private function failureMessage(PDO $pdo, int $number, string $stage): string
    {
        $message = '数据库操作未开始，既有表未修改：' . $stage . '预检失败'
            . ($number > 0 ? '（MySQL ' . $number . '）' : '') . '。';
        if ($number !== 1419) {
            $permission = match ($stage) {
                '建表' => 'CREATE',
                '创建触发器', '删除测试触发器' => 'TRIGGER',
                default => 'INSERT、SELECT',
            };
            return $message . (in_array($number, [1044, 1142, 1143, 1227, 1370], true)
                ? "\n请数据库管理员检查站点账号对目标数据库的 " . $permission . ' 权限；不要向站点账号授予全局 SUPER 权限。'
                : "\n请检查数据库运行状态、资源限制及账号授权；该错误不能直接判定为二进制日志限制。");
        }

        // Diagnose the connection which actually rejected CREATE TRIGGER, not
        // the panel's configuration file. Reading diagnostics must never hide
        // the original failure, prevent cleanup, or change global settings.
        $state = $this->connectionState($pdo);
        $binlog = $this->flag($state['log_bin'] ?? null);
        $trust = $this->flag($state['trust_creators'] ?? null);
        $version = substr(preg_replace('/[^A-Za-z0-9.+_-]/', '', (string) ($state['version'] ?? '')) ?? '', 0, 80);
        $hostname = substr(preg_replace('/[^A-Za-z0-9.:_-]/', '', (string) ($state['hostname'] ?? '')) ?? '', 0, 128);
        $port = (int) ($state['port'] ?? 0);
        $message .= "\n当前连接：MySQL " . ($version ?: '版本未知')
            . '，实例 ' . ($hostname ?: '未知') . ':' . ($port > 0 && $port <= 65535 ? $port : '未知')
            . '，log_bin=' . $binlog . '，log_bin_trust_function_creators=' . $trust . '。';

        if ($binlog === 'ON' && $trust === 'OFF') {
            return $message . "\n当前实例开启了二进制日志，并限制普通账号创建触发器。请数据库管理员确认服务器策略后执行："
                . "\nSET GLOBAL log_bin_trust_function_creators = 1;"
                . "\n该设置影响整个实例，重启后需通过服务器配置保留。程序不会自动修改全局设置，也不要求关闭二进制日志。";
        }
        if ($binlog === 'OFF') {
            $message .= "\n当前连接实例的二进制日志已经关闭，却仍返回 1419。请核对安装表单的数据库主机、端口及数据库代理或兼容服务；不要继续调整日志开关。";
        } elseif ($trust === 'ON') {
            $message .= "\n当前实例已允许普通账号创建触发器，却仍返回 1419。请数据库管理员核对实际连接实例、当前账号授权及数据库服务的兼容性。";
        } else {
            $message .= "\n无法完整读取实例状态，不能确定二进制日志是否开启。请使用安装表单中的同一主机、端口和账号查询：";
        }
        return $message . "\nSHOW GLOBAL VARIABLES LIKE 'log_bin';"
            . "\nSHOW GLOBAL VARIABLES LIKE 'log_bin_trust_function_creators';"
            . "\n处理后重新提交安装参数；无需删除数据库或既有表。";
    }

    /** @return array<string, mixed> */
    private function connectionState(PDO $pdo): array
    {
        try {
            $cursor = $pdo->query('SELECT VERSION() AS version, @@GLOBAL.hostname AS hostname, @@GLOBAL.port AS port, @@GLOBAL.log_bin AS log_bin, @@GLOBAL.log_bin_trust_function_creators AS trust_creators');
            if ($cursor === false) return [];
            try {
                $state = $cursor->fetch(PDO::FETCH_ASSOC);
                return is_array($state) ? $state : [];
            } finally {
                $cursor->closeCursor();
            }
        } catch (Throwable) {
            return [];
        }
    }

    private function flag(mixed $value): string
    {
        return match (strtoupper((string) $value)) {
            '1', 'ON' => 'ON',
            '0', 'OFF' => 'OFF',
            default => '未知',
        };
    }
}
