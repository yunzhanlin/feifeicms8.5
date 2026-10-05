<?php
declare(strict_types=1);

namespace app\controller\admin;

use app\BaseController;
use app\service\AuditLogger;
use app\service\CsrfToken;
use app\service\SqlStatementStream;
use app\service\SearchOutboxTriggers;
use app\service\MySqlBackup;
use app\service\DatabaseFieldReplacer;
use app\service\FrontendCache;
use InvalidArgumentException;
use PDO;
use PDOException;
use think\exception\HttpException;
use think\facade\Db;
use think\facade\Session;
use think\Response;

final class Database extends BaseController
{
    public function __construct(\think\App $app, private readonly CsrfToken $csrf, private readonly AuditLogger $audit, private readonly SqlStatementStream $sql, private readonly DatabaseFieldReplacer $replacer, private readonly FrontendCache $frontendCache)
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

    public function replace(): Response
    {
        $input = ['table' => (string) $this->request->get('table', 'ffx_media'), 'field' => '', 'search' => '', 'replacement' => '', 'condition' => ''];
        return $this->replacementView($input, mb_substr((string) $this->request->get('message', ''), 0, 300));
    }

    public function replacementFields(): Response
    {
        try {
            return json(['fields' => $this->replacer->fields($this->pdo(), (string) $this->request->get('table', ''))])
                ->header(['Cache-Control' => 'no-store, private']);
        } catch (InvalidArgumentException $error) {
            return json(['error' => $error->getMessage()], 422);
        }
    }

    public function previewReplace(): Response
    {
        $this->guardCsrf();
        $input = [];
        foreach (['table', 'field', 'search', 'replacement', 'condition'] as $key) {
            $value = $this->request->post($key, '');
            if (!is_string($value)) throw new HttpException(422, '替换参数无效');
            $input[$key] = $value;
        }
        Session::delete('database.replace_preview');
        try {
            $preview = $this->replacer->preview($this->pdo(), $input);
            $preview['receipt'] = bin2hex(random_bytes(32));
            Session::set('database.replace_preview', [
                'input' => $input, 'matched' => $preview['matched'], 'receipt' => $preview['receipt'], 'expires' => time() + 600,
            ]);
            return $this->replacementView($input, '', $preview);
        } catch (InvalidArgumentException $error) {
            return $this->replacementView($input, $error->getMessage(), [], true)->code(422);
        } catch (PDOException) {
            return $this->replacementView($input, '预览未完成，请检查字段与条件，或缩小范围后重试。', [], true)->code(422);
        }
    }

    public function runReplace(): Response
    {
        $this->guardCsrf();
        $preview = Session::get('database.replace_preview');
        $receipt = $this->request->post('receipt');
        if (!is_array($preview) || !is_string($receipt) || !hash_equals((string) $preview['receipt'], $receipt)
            || (int) $preview['expires'] < time() || $this->request->post('confirm') !== 'REPLACE') {
            throw new HttpException(422, '替换确认无效或已过期，请重新预览');
        }
        $input = $preview['input'];
        try {
            $result = $this->replacer->replace($this->pdo(), $input, (int) $preview['matched'], $receipt, function (int $changed) use ($input, $preview): void {
                $this->audit->record('database.field_replace', 'database', $input['table'] . '.' . $input['field'], null, [
                    'matched' => $preview['matched'], 'changed' => $changed,
                    'search_sha256' => hash('sha256', $input['search']), 'replacement_sha256' => hash('sha256', $input['replacement']),
                    'condition_sha256' => hash('sha256', $input['condition']),
                ]);
            });
            if ($result['changed'] > 0) {
                $this->frontendCache->invalidateCategories();
                $this->frontendCache->invalidateSettings();
            }
            $message = ($result['replayed'] ? '这次替换已经完成，未重复执行：' : '替换完成：') . '匹配 ' . $result['matched'] . ' 条，更新 ' . $result['changed'] . ' 条。';
            return redirect('/admin/database/replace?table=' . rawurlencode($input['table']) . '&message=' . rawurlencode($message));
        } catch (InvalidArgumentException $error) {
            return $this->replacementView($input, $error->getMessage(), [], true)->code(422);
        } catch (PDOException) {
            return $this->replacementView($input, '替换未能正常确认。字段长度、唯一键或 JSON 错误会整批回滚；若连接中断，请用同一确认重试，不要立即另建一批替换。', [
                'matched' => $preview['matched'], 'samples' => [], 'receipt' => $receipt,
            ], true)->code(422);
        }
    }

    private function replacementView(array $input, string $message = '', array $preview = [], bool $error = false): Response
    {
        $pdo = $this->pdo();
        $tables = $this->replacer->tables($pdo);
        $fields = [];
        try { $fields = $this->replacer->fields($pdo, $input['table']); }
        catch (InvalidArgumentException $invalid) { $message = $invalid->getMessage(); $error = true; }
        return view('/admin/database/replace', [
            'tables' => $tables, 'fields' => $fields, 'input' => $input, 'preview' => $preview,
            'message' => $message, 'isError' => $error, 'csrf' => $this->csrf->get(),
        ])->header(['Cache-Control' => 'no-store, private']);
    }

    private function pdo(): PDO
    {
        $pdo = Db::connect()->getPdo();
        if (!$pdo instanceof PDO) throw new HttpException(500, '无法连接数据库');
        return $pdo;
    }

    public function backup(): Response
    {
        $this->guardCsrf();
        $tables = $this->selectedTables();
        if ($tables === []) return response('请选择至少一张表', 422);
        $engines = Db::query("SELECT table_name AS name, ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'ffx\\_%'");
        foreach ($engines as $table) {
            if (in_array((string) $table['name'], $tables, true) && strtoupper((string) $table['engine']) !== 'INNODB') {
                throw new HttpException(422, '一致性备份仅支持 InnoDB 表：' . $table['name']);
            }
        }
        $filename = 'feifeicms-v4-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
        $path = $this->backupDir() . '/' . $filename;
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new HttpException(500, '无法创建备份文件');
        $complete = false;
        try {
            $pdo = Db::connect()->getPdo();
            if (!$pdo instanceof \PDO) throw new HttpException(500, '无法连接数据库进行备份');
            (new MySqlBackup())->dump($pdo, $handle, $tables);
            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete) @unlink($path);
        }
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
        if ($size === false || $size < 1) throw new HttpException(422, '备份文件大小异常');
        $statementCount = 0;
        foreach ($this->sql->fromFile($path, true) as $statement) {
            if (!$this->isAllowedBackupStatement($statement)) throw new HttpException(422, '备份包含不允许执行的语句');
            $statementCount++;
        }
        if ($statementCount === 0) throw new HttpException(422, '备份文件为空');
        foreach ($this->sql->fromFile($path, true) as $statement) Db::execute($statement);
        SearchOutboxTriggers::ensure();
        $this->audit->record('database.restore', 'database', $name, null, ['statements' => $statementCount, 'bytes' => $size]);
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
        if (!is_file($path) || is_link($path) || is_link($this->backupDir())) throw new HttpException(404, '备份不存在');
        return $path;
    }

    private function isAllowedBackupStatement(string $statement): bool
    {
        return preg_match('/^SET\s+NAMES\s+utf8mb4$/i', $statement) === 1
            || preg_match('/^SET\s+FOREIGN_KEY_CHECKS\s*=\s*[01]$/i', $statement) === 1
            || preg_match('/^DROP\s+TABLE\s+IF\s+EXISTS\s+`ffx_[a-z0-9_]+`$/i', $statement) === 1
            || preg_match('/^CREATE\s+TABLE\s+`ffx_[a-z0-9_]+`\s*\(/is', $statement) === 1
            || preg_match('/^INSERT\s+INTO\s+`ffx_[a-z0-9_]+`\s*\(/is', $statement) === 1
            || preg_match('/^CREATE\s+TRIGGER\s+`ffx_[a-z0-9_]+`\s+(?:BEFORE|AFTER)\s+(?:INSERT|UPDATE|DELETE)\s+ON\s+`ffx_[a-z0-9_]+`\s+FOR\s+EACH\s+ROW\s+/is', $statement) === 1;
    }

    /** @param resource $handle @param list<string> $tables */
    private function writeBackupTriggers($handle, \PDO $pdo, array $tables): void
    {
        (new MySqlBackup())->writeTriggers($handle, $pdo, $tables);
    }

    private function guardCsrf(): void
    {
        if (!$this->csrf->verify($this->request->post('_token'))) throw new HttpException(419, '请求已过期');
    }
}
