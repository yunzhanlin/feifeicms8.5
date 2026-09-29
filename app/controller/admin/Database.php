<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use think\exception\HttpException;
use think\facade\Db;
use think\Response;

final class Database extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit)
    {
        parent::__construct($app);
    }

    public function index(): Response
    {
        $tables = Db::query("SELECT table_name AS name, table_rows AS row_count, data_length + index_length AS bytes, ENGINE AS engine, table_collation AS collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%' ORDER BY table_name");
        $backupDir = $this->backupDir();
        $backups = [];
        foreach (array_reverse(glob($backupDir . '/*.sql') ?: []) as $file) {
            $backups[] = ['name' => basename($file), 'size' => filesize($file) ?: 0, 'created_at' => gmdate('Y-m-d H:i:s', filemtime($file) ?: time())];
        }
        $version = (string) (Db::query('SELECT VERSION() AS version')[0]['version'] ?? '未知');
        return view('/admin/database/index', [
            'tables' => $tables, 'backups' => $backups, 'version' => $version,
            'message' => mb_substr((string) $this->request->get('message', ''), 0, 300),
            'csrf' => $this->csrf->get(),
        ]);
    }

    public function backup(): Response
    {
        $this->guardCsrf();
        $tables = $this->selectedTables();
        if ($tables === []) return response('请选择至少一张表', 422);
        $filename = 'feifeicms-v4-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
        $path = $this->backupDir() . '/' . $filename;
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new HttpException(500, '无法创建备份文件');
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $pdo = Db::connect()->getPdo();
        foreach ($tables as $table) {
            $createRows = Db::query('SHOW CREATE TABLE `' . $table . '`');
            $create = (string) ($createRows[0]['Create Table'] ?? '');
            fwrite($handle, 'DROP TABLE IF EXISTS `' . $table . "`;\n" . $create . ";\n\n");
            foreach (Db::query('SELECT * FROM `' . $table . '`') as $row) {
                $columns = array_map(static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`', array_keys($row));
                $values = array_map(static function (mixed $value) use ($pdo): string {
                    if ($value === null) return 'NULL';
                    return $pdo->quote((string) $value);
                }, array_values($row));
                fwrite($handle, 'INSERT INTO `' . $table . '` (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }
            fwrite($handle, "\n");
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
        chmod($path, 0600);
        $this->audit->record('database.backup', 'database', $filename, null, ['tables' => $tables, 'bytes' => filesize($path)]);
        return redirect('/admin/database#backups');
    }

    public function optimize(): Response
    {
        $this->guardCsrf();
        $tables = $this->selectedTables();
        if ($tables === []) return response('请选择至少一张表', 422);
        foreach ($tables as $table) {
            Db::query('OPTIMIZE TABLE `' . $table . '`');
        }
        $this->audit->record('database.optimize', 'database', 'tables', null, ['tables' => $tables]);
        return redirect('/admin/database#optimize');
    }

    public function check(): Response
    {
        $this->guardCsrf();
        $tables = $this->selectedTables();
        if ($tables === []) return response('请选择至少一张表', 422);
        $failed = [];
        foreach ($tables as $table) {
            foreach (Db::query('CHECK TABLE `' . $table . '`') as $result) {
                if (strtolower((string) ($result['Msg_type'] ?? '')) === 'error') {
                    $failed[] = $table . ': ' . (string) ($result['Msg_text'] ?? '检查失败');
                }
            }
        }
        $this->audit->record('database.check', 'database', 'tables', null, ['tables' => $tables, 'failed' => $failed]);
        $message = $failed === [] ? '检查完成，' . count($tables) . ' 张表状态正常' : '检查发现异常：' . implode('；', array_slice($failed, 0, 3));
        return redirect('/admin/database?message=' . rawurlencode($message) . '#diagnostics');
    }

    public function repair(): Response
    {
        $this->guardCsrf();
        $tables = $this->selectedTables();
        if ($tables === []) return response('请选择至少一张表', 422);
        $engines = [];
        foreach (Db::query("SELECT table_name AS name, ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'") as $row) {
            $engines[(string) $row['name']] = strtoupper((string) $row['engine']);
        }
        foreach ($tables as $table) {
            if (($engines[$table] ?? '') === 'MYISAM') Db::query('REPAIR TABLE `' . $table . '`');
            else Db::query('ANALYZE TABLE `' . $table . '`');
        }
        $this->audit->record('database.repair', 'database', 'tables', null, [
            'tables' => $tables, 'engines' => array_intersect_key($engines, array_flip($tables)),
        ]);
        return redirect('/admin/database?message=' . rawurlencode('修复维护完成，共处理 ' . count($tables) . ' 张表') . '#diagnostics');
    }

    public function restore(string $name): Response
    {
        $this->guardCsrf();
        if ((string) $this->request->post('confirm') !== 'RESTORE') throw new HttpException(422, '缺少恢复确认');
        $path = $this->backupPath($name);
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > 134_217_728) throw new HttpException(422, '备份文件大小异常');
        $sql = file_get_contents($path);
        if ($sql === false) throw new HttpException(500, '无法读取备份文件');
        $statements = $this->splitSql($sql);
        if ($statements === []) throw new HttpException(422, '备份文件为空');
        foreach ($statements as $statement) {
            if (!$this->isAllowedBackupStatement($statement)) throw new HttpException(422, '备份包含不允许执行的语句');
        }
        foreach ($statements as $statement) Db::execute($statement);
        $this->audit->record('database.restore', 'database', $name, null, ['statements' => count($statements), 'bytes' => $size]);
        return redirect('/admin/database#backups');
    }

    public function delete(string $name): Response
    {
        $this->guardCsrf();
        $path = $this->backupPath($name);
        if (!unlink($path)) throw new HttpException(500, '无法删除备份文件');
        $this->audit->record('database.backup.delete', 'database', $name);
        return redirect('/admin/database#backups');
    }

    public function download(string $name): Response
    {
        $path = $this->backupPath($name);
        return download($path, $name);
    }

    /** @return list<string> */
    private function selectedTables(): array
    {
        $allowed = array_column(Db::query("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'"), 'name');
        $selected = array_values(array_unique(array_map('strval', (array) $this->request->post('ids', []))));
        return array_values(array_intersect($selected, $allowed));
    }

    private function backupDir(): string
    {
        $dir = root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'backups';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new HttpException(500, '无法创建备份目录');
        return $dir;
    }

    private function backupPath(string $name): string
    {
        if (!preg_match('/^feifeicms-v(?:2|4)-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.sql$/', $name)) throw new HttpException(404, '备份不存在');
        $path = $this->backupDir() . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) throw new HttpException(404, '备份不存在');
        return $path;
    }

    /** @return list<string> */
    private function splitSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = null;
        $escaped = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            $buffer .= $character;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($quote !== null && $character === '\\') {
                $escaped = true;
                continue;
            }
            if ($quote !== null && $character === $quote) {
                if ($index + 1 < $length && $sql[$index + 1] === $quote) {
                    $buffer .= $sql[++$index];
                    continue;
                }
                $quote = null;
                continue;
            }
            if ($quote === null && ($character === "'" || $character === '"' || $character === '`')) {
                $quote = $character;
                continue;
            }
            if ($quote === null && $character === ';') {
                $statement = trim(substr($buffer, 0, -1));
                if ($statement !== '') $statements[] = $statement;
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') throw new HttpException(422, '备份 SQL 不完整');
        return $statements;
    }

    private function isAllowedBackupStatement(string $statement): bool
    {
        return preg_match('/^SET\s+NAMES\s+utf8mb4$/i', $statement) === 1
            || preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement) === 1
            || preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`ffx_[a-z0-9_]+`$/i', $statement) === 1
            || preg_match('/^CREATE\s+TABLE\s+`ffx_[a-z0-9_]+`\s*\(/is', $statement) === 1
            || preg_match('/^INSERT\s+INTO\s+`ffx_[a-z0-9_]+`\s*\(/is', $statement) === 1;
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
